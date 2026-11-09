<?php
declare(strict_types=1);

namespace StretchEffect\Tests;

require_once __DIR__ . '/Support/FakeTestCase.php';

use StretchEffect\Mcp\{McpEndpoint, Redactor, SchemaValidator, ToolInputError, Tools};
use StretchEffect\Tests\Support\FakeTestCase;

/**
 * Written against mutation testing (see docs/TESTING.md): exact values and exact boundaries for the MCP transport,
 * the rate limits, the idempotency claim, the daily credit cap and the tool outputs.
 */
final class MutationMcpTest extends FakeTestCase
{
    private const PING = '{"jsonrpc":"2.0","id":1,"method":"ping"}';
    private const META = 'https://studio.test/.well-known/oauth-protected-resource';

    private int $connects = 0;

    private function pdo(): \PDO { return $this->svc->database->pdo; }

    private function token(?array $scopes = null): string
    {
        $this->pdo()->exec("DELETE FROM rate_limits WHERE bucket LIKE 'oauth_%'");
        if ($this->connects++ > 0) {
            $this->svc->advance(30); // a TOTP step cannot be used twice
        }
        return $this->connect($scopes)['access'];
    }

    /** @return list<array<string,mixed>> */
    private function auditRows(?string $action = null): array
    {
        $sql = 'SELECT * FROM audit_log' . ($action !== null ? ' WHERE action = ' . $this->pdo()->quote($action) : '') . ' ORDER BY rowid';
        return $this->pdo()->query($sql)->fetchAll();
    }

    private function lastAudit(string $action): array
    {
        $rows = $this->auditRows($action);
        $this->assertNotEmpty($rows, "no audit row for $action");
        return end($rows);
    }

    private function seedBooking(string $id, string $start, string $status = 'confirmed', ?string $userId = null): void
    {
        $st = new \DateTimeImmutable($start, new \DateTimeZone('UTC'));
        $this->pdo()->prepare('INSERT INTO bookings (id,user_id,start_at,end_at,status,created_by,created_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([$id, $userId ?? $this->svc->clientId, $st->format('Y-m-d H:i:s'), $st->modify('+60 minutes')->format('Y-m-d H:i:s'), $status, 'client', '2026-11-01 08:00:00']);
    }

    private function tool(string $token, string $name, array $args): array
    {
        return $this->jsonBody($this->mcpRaw($token, json_encode(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => (object) $args]])));
    }

    private function keepAlive(): void
    {
        $this->pdo()->exec("UPDATE oauth_tokens SET expires_at = '2099-01-01 00:00:00'");
    }

    private function key(): string { return 'key-' . bin2hex(random_bytes(6)); }

    // ------------------------------------------------------------ transport

    public function testConnectorOffGives503WithRetryAfter(): void
    {
        $this->svc->cfg->data['connector']['enabled'] = false;
        $r = $this->mcpRaw(null, self::PING);
        $this->assertSame(503, $r->status);
        $this->assertSame('3600', $r->headers['Retry-After']);
        $this->assertSame('no-store', $r->headers['Cache-Control']);
        $this->assertSame('nosniff', $r->headers['X-Content-Type-Options']);
        $this->assertSame(['error' => 'temporarily_unavailable', 'error_description' => 'The connector is switched off.'], $this->jsonBody($r));
    }

    public function testPathMatching(): void
    {
        $t = $this->token();
        $ep = new McpEndpoint();
        $this->assertNull($ep->handle($this->req('POST', '/mcpx', [], [], [], self::PING), $this->svc));
        $this->assertNull($ep->handle($this->req('POST', '/mc', [], [], [], self::PING), $this->svc));
        $r = $ep->handle($this->req('POST', '/mcp/', [], [], ['authorization' => 'Bearer ' . $t, 'content-type' => 'application/json'], self::PING), $this->svc);
        $this->assertSame(200, $r->status);
    }

    public function testMethodNotAllowedBody(): void
    {
        $r = $this->mcpRaw($this->token(), '', [], 'GET');
        $this->assertSame(['error' => 'method_not_allowed'], $this->jsonBody($r));
        $this->assertSame('no-store', $r->headers['Cache-Control']);
    }

    public function testOriginRefusalIsAudited(): void
    {
        $t = $this->token();
        $r = $this->mcpRaw($t, self::PING, ['origin' => 'https://evil.example']);
        $this->assertSame(['error' => 'forbidden', 'error_description' => 'Origin not allowed.'], $this->jsonBody($r));
        $a = $this->lastAudit('mcp_origin');
        $this->assertSame('anonymous', $a['actor']);
        $this->assertSame('denied', $a['status']);
        $this->assertSame('request refused: Origin not allowed', $a['summary']);
        $this->assertSame('203.0.113.9', $a['ip']);
        $this->assertNull($a['grant_id']);
        // look-alike origins are refused
        foreach (['https://studio.test:444', 'https://chatgpt.com/', 'https://chatgpt.com.evil.example', 'http://studio.test', 'HTTPS://chatgpt.com'] as $o) {
            $this->assertSame(403, $this->mcpRaw($t, self::PING, ['origin' => $o])->status, $o);
        }
    }

    public function testUnauthorizedBodyHeaderAndAuditThrottle(): void
    {
        $r = $this->mcpRaw(null, self::PING);
        $this->assertSame(401, $r->status);
        $this->assertSame(['error' => 'invalid_token', 'error_description' => 'Missing, invalid, expired or revoked access token.'], $this->jsonBody($r));
        for ($i = 0; $i < 14; $i++) {
            $this->mcpRaw(null, self::PING);
        }
        $rows = $this->auditRows('mcp_auth');
        $this->assertCount(10, $rows, 'the 10th unauthenticated request per minute is audited, the 11th is not');
        $this->assertSame('denied', $rows[0]['status']);
        $this->assertSame('missing or invalid access token', $rows[0]['summary']);
        $this->assertSame('anonymous', $rows[0]['actor']);
        $this->assertSame('2026-11-09 08:01:00', $this->pdo()->query("SELECT expires_at FROM rate_limits WHERE bucket = 'mcp_unauth_audit:203.0.113.9:" . $this->svc->database->now->getTimestamp() . "'")->fetchColumn());
        $this->svc->advance(60);
        $this->mcpRaw(null, self::PING);
        $this->assertCount(11, $this->auditRows('mcp_auth'));
    }

    public function testBearerFormatBoundaries(): void
    {
        $t = $this->token();
        $h = fn(string $v) => $this->mcpRaw(null, self::PING, ['authorization' => $v])->status;
        $this->assertSame(200, $h('Bearer ' . $t));
        $this->assertSame(401, $h('bearer ' . $t));
        $this->assertSame(401, $h('Bearer  ' . $t));
        $this->assertSame(401, $h('Bearer ' . $t . ' '));
        $this->assertSame(401, $h('Bearer ' . $t . "\n"));
        $this->assertSame(401, $h('Basic ' . $t));
        $this->assertSame(401, $h('X Bearer ' . $t));
        $this->assertSame(401, $h('xBearer ' . $t));
        $this->assertSame(401, $h('Bearer ' . str_repeat('a', 19)));
        $this->assertSame(401, $h('Bearer ' . str_repeat('a', 20)), 'well-formed but unknown');
        $this->assertSame(401, $h('Bearer ' . str_repeat('a', 200)));
        $this->assertSame(401, $h('Bearer ' . str_repeat('a', 201)));
        $this->assertSame(401, $h('Bearer ' . substr($t, 0, 30) . '+' . substr($t, 31)));
    }

    public function testPerGrantRateLimitIs60PerMinute(): void
    {
        $t = $this->token();
        for ($i = 1; $i <= 60; $i++) {
            $this->assertSame(200, $this->mcpRaw($t, self::PING)->status, "request $i");
        }
        $r = $this->mcpRaw($t, self::PING);
        $this->assertSame(429, $r->status);
        $this->assertSame('30', $r->headers['Retry-After']);
        $this->assertSame(['error' => 'rate_limited', 'error_description' => 'Too many requests. Slow down.'], $this->jsonBody($r));
        $a = $this->lastAudit('mcp_request');
        $this->assertSame('rate_limited', $a['status']);
        $this->assertSame('more than 60 requests in a minute', $a['summary']);
        $this->assertStringStartsWith('chatgpt:', $a['actor']);
        $this->assertNotNull($a['grant_id']);
        $grant = $this->pdo()->query('SELECT id FROM oauth_grants')->fetchColumn();
        $this->assertEquals(60, $this->pdo()->query("SELECT hits FROM rate_limits WHERE bucket = 'mcp_calls:$grant:" . $this->svc->database->now->getTimestamp() . "'")->fetchColumn());
        $this->assertSame('2026-11-09 08:01:00', $this->pdo()->query("SELECT expires_at FROM rate_limits WHERE bucket = 'mcp_calls:$grant:" . $this->svc->database->now->getTimestamp() . "'")->fetchColumn());
        $this->assertSame('chatgpt:' . $grant, $a['actor']);
        $this->svc->advance(60);
        $this->assertSame(200, $this->mcpRaw($t, self::PING)->status, 'the next minute opens a new window');
    }

    public function testProtocolVersionHeader(): void
    {
        $t = $this->token();
        foreach (['2025-06-18', '2025-11-25', '2026-07-28'] as $v) {
            $this->assertSame(200, $this->mcpRaw($t, self::PING, ['mcp-protocol-version' => $v])->status, $v);
        }
        $r = $this->mcpRaw($t, self::PING, ['mcp-protocol-version' => '2024-01-01']);
        $this->assertSame(400, $r->status);
        $this->assertSame(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32022, 'message' => 'Unsupported protocol version.',
            'data' => ['supported' => ['2025-06-18', '2025-11-25', '2026-07-28']]]], $this->jsonBody($r));
        $this->assertSame(McpEndpoint::VERSIONS, ['2025-06-18', '2025-11-25', '2026-07-28']);
        $this->assertSame('2026-07-28', McpEndpoint::LATEST);
        $this->assertSame(65536, McpEndpoint::MAX_BODY);
    }

    public function testContentTypeAndBodySizeBoundaries(): void
    {
        $t = $this->token();
        $r = $this->mcpRaw($t, self::PING, ['content-type' => 'text/plain']);
        $this->assertSame(415, $r->status);
        $this->assertSame(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Content-Type must be application/json.']], $this->jsonBody($r));
        $this->assertSame(200, $this->mcpRaw($t, self::PING, ['content-type' => 'Application/JSON; charset=utf-8'])->status);

        $base = '{"jsonrpc":"2.0","id":1,"method":"ping"}';
        $ok = $base . str_repeat(' ', 65536 - strlen($base));
        $this->assertSame(65536, strlen($ok));
        $this->assertSame(200, $this->mcpRaw($t, $ok)->status);
        $r = $this->mcpRaw($t, $ok . ' ');
        $this->assertSame(413, $r->status);
        $this->assertSame(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Request body too large (64 KB limit).']], $this->jsonBody($r));
    }

    public function testJsonRpcEnvelopeErrors(): void
    {
        $t = $this->token();
        $err = function (string $body, int $status, int $code, string $msg, $id = null) use ($t) {
            $r = $this->mcpRaw($t, $body);
            $this->assertSame($status, $r->status, $body);
            $this->assertSame(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $msg]], $this->jsonBody($r), $body);
        };
        $err('', 400, -32700, 'Parse error.');
        $err('{bad', 400, -32700, 'Parse error.');
        $err('"text"', 400, -32700, 'Parse error.');
        $err('5', 400, -32700, 'Parse error.');
        $err('[]', 400, -32600, 'Batch requests are not supported. Send one request per POST.');
        $err('[{"jsonrpc":"2.0","id":1,"method":"ping"}]', 400, -32600, 'Batch requests are not supported. Send one request per POST.');
        $err('{"jsonrpc":"2.0","id":1.5,"method":"ping"}', 400, -32600, 'Invalid id.');
        $err('{"jsonrpc":"2.0","id":null,"method":"ping"}', 400, -32600, 'Invalid id.');
        $err('{"jsonrpc":"2.0","id":[1],"method":"ping"}', 400, -32600, 'Invalid id.');
        $err('{"jsonrpc":"2.0","id":true,"method":"ping"}', 400, -32600, 'Invalid id.');
        $err('{"jsonrpc":"1.0","id":3,"method":"ping"}', 400, -32600, 'Invalid JSON-RPC request.', 3);
        $err('{"id":"abc","method":"ping"}', 400, -32600, 'Invalid JSON-RPC request.', 'abc');
        $err('{"jsonrpc":"2.0","id":4}', 400, -32600, 'Invalid JSON-RPC request.', 4);
        $err('{"jsonrpc":"2.0","id":5,"method":7}', 400, -32600, 'Invalid JSON-RPC request.', 5);
        $err('{"jsonrpc":"2.0","method":7}', 400, -32600, 'Invalid JSON-RPC request.');
        $err('{"jsonrpc":"2.0","id":6,"method":"ping","params":"x"}', 200, -32602, 'params must be an object.', 6);
        $err('{"jsonrpc":"2.0","id":8,"method":"nope"}', 200, -32601, 'Method not found.', 8);
        $err('{"jsonrpc":"2.0","id":"s-1","method":"tools/nope"}', 200, -32601, 'Method not found.', 's-1');
    }

    public function testJsonDepthLimitIs32(): void
    {
        $t = $this->token();
        $nest = static fn(int $n) => str_repeat('[', $n) . str_repeat(']', $n);
        $mk = static fn(int $n) => '{"jsonrpc":"2.0","id":1,"method":"ping","params":{"a":' . $nest($n) . '}}';
        $this->assertSame(200, $this->mcpRaw($t, $mk(29))->status, 'nesting depth 32 is allowed');
        $r = $this->mcpRaw($t, $mk(30));
        $this->assertSame(400, $r->status, 'nesting depth 33 is refused');
        $this->assertSame(-32700, $this->jsonBody($r)['error']['code']);
    }

    public function testNotificationGets202WithNoBody(): void
    {
        $r = $this->mcpRaw($this->token(), '{"jsonrpc":"2.0","method":"notifications/initialized"}');
        $this->assertSame(202, $r->status);
        $this->assertSame('', $r->body);
        $this->assertSame('no-store', $r->headers['Cache-Control']);
    }

    public function testTouchRecordsLastUse(): void
    {
        $t = $this->connect();
        $this->assertNull($this->pdo()->query('SELECT last_used_at FROM oauth_grants')->fetchColumn());
        $this->svc->advance(5);
        $this->mcpRaw($t['access'], self::PING);
        $this->assertSame('2026-11-09 08:00:05', $this->pdo()->query('SELECT last_used_at FROM oauth_grants')->fetchColumn());
    }

    public function testPingInitializeAndDiscoverExactResults(): void
    {
        $t = $this->token();
        $r = $this->mcpRaw($t, self::PING);
        $this->assertSame('{"jsonrpc":"2.0","id":1,"result":{}}', $r->body);
        $info = ['name' => 'the-stretch-effect', 'title' => 'The Stretch Effect bookings', 'version' => '1.0.0'];
        $caps = ['tools' => []];
        foreach (['2025-06-18', '2025-11-25', '2026-07-28'] as $v) {
            $d = $this->rpc($t, 'initialize', ['protocolVersion' => $v]);
            $this->assertSame($v, $d['result']['protocolVersion']);
            $this->assertSame($info, $d['result']['serverInfo']);
            $this->assertSame($caps, $d['result']['capabilities']);
            $this->assertSame(McpEndpoint::INSTRUCTIONS, $d['result']['instructions']);
        }
        $this->assertSame('2026-07-28', $this->rpc($t, 'initialize', ['protocolVersion' => '2020-01-01'])['result']['protocolVersion']);
        $this->assertSame('2026-07-28', $this->rpc($t, 'initialize', ['protocolVersion' => 5])['result']['protocolVersion']);
        $this->assertSame('2026-07-28', $this->rpc($t, 'initialize')['result']['protocolVersion']);
        $d = $this->rpc($t, 'server/discover')['result'];
        $this->assertSame(['2025-06-18', '2025-11-25', '2026-07-28'], $d['supportedVersions']);
        $this->assertSame($info, $d['serverInfo']);
        $this->assertSame($caps, $d['capabilities']);
        $this->assertSame(McpEndpoint::INSTRUCTIONS, $d['instructions']);
        $this->assertStringContainsString('untrusted data', McpEndpoint::INSTRUCTIONS);
        $this->assertStringContainsString('idempotency_key', McpEndpoint::INSTRUCTIONS);
        $this->assertSame('1.0.0', McpEndpoint::SERVER_VERSION);
    }

    public function testToolsListMatchesTheGoldenFile(): void
    {
        $t = $this->token();
        $got = json_encode($this->rpc($t, 'tools/list')['result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        $file = __DIR__ . '/fixtures/mcp-tools-list.json';
        if (getenv('UPDATE_GOLDEN') === '1') {
            file_put_contents($file, $got);
        }
        $this->assertSame(file_get_contents($file), $got, 'the tool contract changed: review it, then run with UPDATE_GOLDEN=1');
        $tools = $this->rpc($t, 'tools/list')['result']['tools'];
        $this->assertCount(11, $tools);
        $by = array_column($tools, null, 'name');
        $this->assertTrue($by['get_schedule']['annotations']['readOnlyHint']);
        $this->assertFalse($by['add_session_credits']['annotations']['readOnlyHint']);
        $this->assertTrue($by['cancel_booking']['annotations']['destructiveHint']);
        $this->assertTrue($by['remove_session_credits']['annotations']['destructiveHint']);
        $this->assertFalse($by['create_booking']['annotations']['destructiveHint']);
    }

    // ------------------------------------------------------------ scope and argument checks

    public function testEveryToolIsGatedByItsScope(): void
    {
        $scopeOf = array_map(static fn($d) => $d['scope'], Tools::definitions());
        $this->assertSame([
            'get_schedule' => 'bookings:read', 'list_bookings' => 'bookings:read', 'find_free_slots' => 'bookings:read',
            'search_clients' => 'clients:read', 'get_client' => 'clients:read', 'get_business_summary' => 'bookings:read',
            'add_session_credits' => 'credits:write', 'remove_session_credits' => 'credits:write',
            'create_booking' => 'bookings:write', 'cancel_booking' => 'bookings:write', 'reschedule_booking' => 'bookings:write',
        ], $scopeOf);
        $all = ['bookings:read', 'bookings:write', 'clients:read', 'credits:write'];
        $args = ['get_schedule' => [], 'list_bookings' => ['from' => '2026-11-09', 'to' => '2026-11-09'], 'find_free_slots' => ['from' => '2026-11-09', 'to' => '2026-11-09'],
            'search_clients' => ['query' => 'Th'], 'get_client' => ['client_id' => 'abc'], 'get_business_summary' => ['period' => 'week']];
        foreach ($scopeOf as $tool => $scope) {
            $others = array_values(array_diff($all, [$scope]));
            $t = $this->token($others);
            $d = $this->tool($t, $tool, $args[$tool] ?? ['client_id' => 'abc']);
            $this->assertSame(-32001, $d['error']['code'], $tool);
            $this->assertSame('This connection was not given the "' . $scope . '" permission.', $d['error']['message']);
            $a = $this->lastAudit($tool);
            $this->assertSame('denied', $a['status']);
            $this->assertSame('missing scope ' . $scope, $a['summary']);
        }
        $r = $this->mcpRaw($this->token(['bookings:read']), json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'remove_session_credits', 'arguments' => (object) []]]));
        $this->assertSame(403, $r->status);
        $this->assertSame('Bearer error="insufficient_scope", scope="credits:write", resource_metadata="' . self::META . '"', $r->headers['WWW-Authenticate']);
    }

    public function testUnknownToolAndBadArgumentsContainer(): void
    {
        $t = $this->token();
        $call = fn($params) => $this->jsonBody($this->mcpRaw($t, json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => $params])));
        $this->assertSame(['code' => -32602, 'message' => 'Unknown tool.'], $call(['name' => 'drop_tables', 'arguments' => (object) []])['error']);
        $a = $this->lastAudit('tools/call');
        $this->assertSame('error', $a['status']);
        $this->assertSame('unknown tool', $a['summary']);
        $this->assertSame('[]', $a['args_json']);
        $this->assertSame('Unknown tool.', $call(['name' => 5])['error']['message']);
        $this->assertSame('Unknown tool.', $call((object) [])['error']['message']);
        $this->assertSame('Unknown tool.', $call(['name' => 'toString'])['error']['message']);
        $this->assertSame(['code' => -32602, 'message' => 'arguments must be an object.'], $call(['name' => 'get_schedule', 'arguments' => [1]])['error']);
        $this->assertSame('arguments must be an object.', $call(['name' => 'get_schedule', 'arguments' => 'x'])['error']['message']);
        $a = $this->lastAudit('get_schedule');
        $this->assertSame('arguments must be an object', $a['summary']);
        $this->assertSame('error', $a['status']);
        $this->assertArrayHasKey('result', $call(['name' => 'get_schedule', 'arguments' => []]));
        $this->assertArrayHasKey('result', $call(['name' => 'get_schedule']));
    }

    public function testInvalidArgumentsAreReportedByFieldAndAudited(): void
    {
        $t = $this->token();
        $d = $this->tool($t, 'get_schedule', ['date' => '2026-02-30']);
        $this->assertSame(['content' => [['type' => 'text', 'text' => 'Invalid arguments: "date" is not a real date (expected YYYY-MM-DD).']], 'isError' => true], $d['result']);
        $a = $this->lastAudit('get_schedule');
        $this->assertSame('invalid arguments', $a['summary']);
        $this->assertSame('error', $a['status']);
        $this->assertStringStartsWith('chatgpt:', $a['actor']);
        $this->assertStringContainsString('"date":"2026-02-30"', $a['args_json']);
    }

    // ------------------------------------------------------------ SchemaValidator

    public function testSchemaValidatorBoundaries(): void
    {
        $schema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['s', 'n'], 'properties' => [
            's' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 4],
            'n' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 3],
            'b' => ['type' => 'boolean'],
            'e' => ['type' => 'string', 'enum' => ['x', 'y']],
            'p' => ['type' => 'string', 'pattern' => '^[a-z]+$'],
            'd' => ['type' => 'string', 'format' => 'date', 'pattern' => '^\d{4}-\d{2}-\d{2}$'],
            'l' => ['type' => 'string', 'format' => 'local-datetime', 'pattern' => '^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$'],
            'z' => ['type' => 'number'],
        ]];
        $fails = function (array $args, string $msg) use ($schema) {
            try {
                SchemaValidator::validate($schema, $args);
                $this->fail('expected failure: ' . $msg);
            } catch (ToolInputError $e) {
                $this->assertSame($msg, $e->getMessage());
            }
        };
        $this->assertSame(['s' => 'ab', 'n' => 1], SchemaValidator::validate($schema, ['s' => 'ab', 'n' => 1]));
        $this->assertSame(['s' => 'abcd', 'n' => 3], SchemaValidator::validate($schema, ['s' => 'abcd', 'n' => 3]));
        $this->assertSame(['s' => 'äö', 'n' => 2], SchemaValidator::validate($schema, ['s' => 'äö', 'n' => 2]), 'length is counted in characters');
        $fails(['s' => 'a', 'n' => 1], 'Invalid arguments: "s" must be at least 2 characters.');
        $fails(['s' => 'abcde', 'n' => 1], 'Invalid arguments: "s" must be at most 4 characters.');
        $fails(['s' => 'ab', 'n' => 0], 'Invalid arguments: "n" must be at least 1.');
        $fails(['s' => 'ab', 'n' => 4], 'Invalid arguments: "n" must be at most 3.');
        $fails(['s' => 'ab', 'n' => '2'], 'Invalid arguments: "n" must be a whole number.');
        $fails(['s' => 'ab', 'n' => 2.0], 'Invalid arguments: "n" must be a whole number.');
        $fails(['s' => 5, 'n' => 2], 'Invalid arguments: "s" must be a string.');
        $fails(['s' => "a\x01b", 'n' => 2], 'Invalid arguments: "s" contains control characters.');
        $fails(['s' => "a\x7fb", 'n' => 2], 'Invalid arguments: "s" contains control characters.');
        $fails(['s' => "a\x00b", 'n' => 2], 'Invalid arguments: "s" contains control characters.');
        $fails(['s' => "a\x08b", 'n' => 2], 'Invalid arguments: "s" contains control characters.');
        $fails(['s' => "a\x0bb", 'n' => 2], 'Invalid arguments: "s" contains control characters.');
        $fails(['s' => "a\x0eb", 'n' => 2], 'Invalid arguments: "s" contains control characters.');
        $fails(['s' => "a\x1fb", 'n' => 2], 'Invalid arguments: "s" contains control characters.');
        $this->assertSame(['s' => "a\tb", 'n' => 2], SchemaValidator::validate($schema, ['s' => "a\tb", 'n' => 2]), 'tab, LF and CR are allowed');
        $this->assertSame(['s' => "a\nb", 'n' => 2], SchemaValidator::validate($schema, ['s' => "a\nb", 'n' => 2]));
        $this->assertSame(['s' => "a\rb", 'n' => 2], SchemaValidator::validate($schema, ['s' => "a\rb", 'n' => 2]));
        $fails(['s' => 'ab', 'n' => 1, 'b' => 'yes'], 'Invalid arguments: "b" must be true or false.');
        $fails(['s' => 'ab', 'n' => 1, 'e' => 'z'], 'Invalid arguments: "e" must be one of: x, y.');
        $fails(['s' => 'ab', 'n' => 1, 'p' => 'ab1'], 'Invalid arguments: "p" has the wrong format.');
        $fails(['s' => 'ab', 'n' => 1, 'p' => "ab\n"], 'Invalid arguments: "p" has the wrong format.');
        $fails(['s' => 'ab', 'n' => 1, 'd' => 'nope'], 'Invalid arguments: "d" has the wrong format (expected date).');
        $fails(['s' => 'ab', 'n' => 1, 'd' => '2026-13-01'], 'Invalid arguments: "d" is not a real date (expected YYYY-MM-DD).');
        $fails(['s' => 'ab', 'n' => 1, 'l' => '2026-11-09T25:00'], 'Invalid arguments: "l" is not a real date and time (expected YYYY-MM-DDTHH:MM).');
        $fails(['s' => 'ab', 'n' => 1, 'z' => 1], 'Invalid arguments: "z" has an unsupported type.');
        $fails(['n' => 1], 'Invalid arguments: "s" is required.');
        $fails(['s' => null, 'n' => 1], 'Invalid arguments: "s" is required.');
        $fails(['s' => 'ab', 'n' => 1, 'extra' => 1], 'Invalid arguments: unknown argument "extra".');
        $fails(['s' => 'ab', 'n' => 1, 'bad key!' => 1], 'Invalid arguments: unknown argument "bad?key?".');
        $fails(['s' => 'ab', 'n' => 1, str_repeat('k', 40) => 1], 'Invalid arguments: unknown argument "' . str_repeat('k', 30) . '".');
        $fails([], 'Invalid arguments: "s" is required; "n" is required.');
        $fails(['x' => 1, 'n' => 9], 'Invalid arguments: unknown argument "x"; "s" is required; "n" must be at most 3.');
        $this->assertSame(['s' => 'ab', 'n' => 1], SchemaValidator::validate($schema, ['s' => 'ab', 'n' => 1, 'b' => null]), 'null optional is dropped');

        $mb = ['type' => 'object', 'properties' => ['m' => ['type' => 'string', 'maxLength' => 2]]];
        $this->assertSame(['m' => 'äö'], SchemaValidator::validate($mb, ['m' => 'äö']));
        try {
            SchemaValidator::validate($mb, [str_repeat('é', 40) => 1]);
            $this->fail('unknown key expected');
        } catch (ToolInputError $e) {
            $this->assertSame('Invalid arguments: unknown argument "' . str_repeat('é', 30) . '".', $e->getMessage());
        }
        $this->assertFalse(SchemaValidator::validDate('x2026-11-09'));
        $this->assertFalse(SchemaValidator::validDate('2026-11-09x'));
        $this->assertFalse(SchemaValidator::validLocalDateTime('x2026-11-09T10:00'));
        $this->assertFalse(SchemaValidator::validLocalDateTime('2026-11-09T10:00x'));
        $this->assertTrue(SchemaValidator::validDate('2024-02-29'));
        $this->assertFalse(SchemaValidator::validDate('2026-02-29'));
        $this->assertFalse(SchemaValidator::validDate('2026-11-09 '));
        $this->assertFalse(SchemaValidator::validDate("2026-11-09\n"));
        $this->assertFalse(SchemaValidator::validDate('2026-1-09'));
        $this->assertFalse(SchemaValidator::validDate('2026-11-31'));
        $this->assertTrue(SchemaValidator::validLocalDateTime('2026-11-09T23:59'));
        $this->assertFalse(SchemaValidator::validLocalDateTime('2026-11-09T24:00'));
        $this->assertFalse(SchemaValidator::validLocalDateTime('2026-11-09T10:60'));
        $this->assertFalse(SchemaValidator::validLocalDateTime("2026-11-09T10:00\n"));
        $this->assertFalse(SchemaValidator::validLocalDateTime('2026-11-09 10:00'));
        $this->assertFalse(SchemaValidator::validLocalDateTime('2026-02-30T10:00'));
    }

    public function testToolSchemaBoundariesThroughTheEndpoint(): void
    {
        $t = $this->token();
        $this->seedClientCredits(0);
        $cid = $this->svc->clientId;
        $bad = function (string $tool, array $args, string $needle) use ($t) {
            $d = $this->tool($t, $tool, $args);
            $this->assertTrue($d['result']['isError'], $tool . json_encode($args));
            $this->assertStringContainsString($needle, $d['result']['content'][0]['text']);
        };
        $good = function (string $tool, array $args) use ($t) {
            $d = $this->tool($t, $tool, $args);
            $this->assertFalse($d['result']['isError'] ?? true, $tool . json_encode($args) . ' ' . json_encode($d));
        };
        $k = fn() => $this->key();
        $good('add_session_credits', ['client_id' => $cid, 'sessions' => 1, 'reason' => 'r', 'idempotency_key' => '12345678']);
        $good('add_session_credits', ['client_id' => $cid, 'sessions' => 20, 'reason' => str_repeat('r', 200), 'idempotency_key' => str_repeat('k', 64)]);
        $bad('add_session_credits', ['client_id' => $cid, 'sessions' => 0, 'reason' => 'r', 'idempotency_key' => $k()], '"sessions" must be at least 1');
        $bad('add_session_credits', ['client_id' => $cid, 'sessions' => 21, 'reason' => 'r', 'idempotency_key' => $k()], '"sessions" must be at most 20');
        $bad('add_session_credits', ['client_id' => $cid, 'sessions' => 1, 'reason' => '', 'idempotency_key' => $k()], '"reason" must be at least 1 characters');
        $bad('add_session_credits', ['client_id' => $cid, 'sessions' => 1, 'reason' => str_repeat('r', 201), 'idempotency_key' => $k()], '"reason" must be at most 200 characters');
        $bad('add_session_credits', ['client_id' => $cid, 'sessions' => 1, 'reason' => 'r', 'idempotency_key' => '1234567'], '"idempotency_key" must be at least 8 characters');
        $bad('add_session_credits', ['client_id' => $cid, 'sessions' => 1, 'reason' => 'r', 'idempotency_key' => str_repeat('k', 65)], '"idempotency_key" must be at most 64 characters');
        $bad('add_session_credits', ['client_id' => $cid, 'sessions' => 1, 'reason' => 'r', 'idempotency_key' => 'bad key here'], '"idempotency_key" has the wrong format');
        $bad('add_session_credits', ['client_id' => '', 'sessions' => 1, 'reason' => 'r', 'idempotency_key' => $k()], '"client_id" must be at least 1 characters');
        $bad('add_session_credits', ['client_id' => str_repeat('a', 65), 'sessions' => 1, 'reason' => 'r', 'idempotency_key' => $k()], '"client_id" must be at most 64 characters');
        $bad('add_session_credits', ['client_id' => 'a b', 'sessions' => 1, 'reason' => 'r', 'idempotency_key' => $k()], '"client_id" has the wrong format');
        $bad('add_session_credits', ['client_id' => $cid, 'sessions' => 1, 'reason' => 'r', 'idempotency_key' => $k(), 'payment_reference' => 'ab'], '"payment_reference" must be at least 3 characters');
        $bad('add_session_credits', ['client_id' => $cid, 'sessions' => 1, 'reason' => 'r', 'idempotency_key' => $k(), 'payment_reference' => str_repeat('p', 61)], '"payment_reference" must be at most 60 characters');
        $this->assertSame('Client not found.', $this->tool($t, 'add_session_credits', ['client_id' => str_repeat('a', 64), 'sessions' => 1, 'reason' => 'r', 'idempotency_key' => $k()])['result']['content'][0]['text']);
        $bad('search_clients', ['query' => 'a'], '"query" must be at least 2 characters');
        $bad('search_clients', ['query' => str_repeat('a', 61)], '"query" must be at most 60 characters');
        $good('search_clients', ['query' => str_repeat('a', 60)]);
        $good('search_clients', ['query' => 'ab']);
        $bad('get_business_summary', ['period' => 'year'], '"period" must be one of: week, month');
        $bad('list_bookings', ['from' => '2026-11-09', 'to' => '2026-11-09', 'status' => 'gone'], '"status" must be one of: confirmed, cancelled, completed, no_show');
        foreach (['confirmed', 'cancelled', 'completed', 'no_show'] as $st) {
            $good('list_bookings', ['from' => '2026-11-09', 'to' => '2026-11-09', 'status' => $st]);
        }
        $bad('cancel_booking', ['booking_id' => 'x', 'refund_credit' => 'yes', 'reason' => 'r', 'idempotency_key' => $k()], '"refund_credit" must be true or false');
        $bad('create_booking', ['client_id' => $cid, 'start' => '2026-11-10 09:00', 'idempotency_key' => $k()], '"start" has the wrong format (expected local-datetime)');
        $bad('get_client', [], '"client_id" is required');
        $bad('get_client', ['client_id' => 'x', 'zzz' => 1], 'unknown argument "zzz"');
    }

    // ------------------------------------------------------------ read tools: exact output

    public function testGetScheduleExactOutputAndTimezone(): void
    {
        $t = $this->token();
        $this->seedBooking('b1', '2026-11-09 07:00:00');
        $this->seedBooking('b2', '2026-11-09 09:00:00', 'cancelled');
        $this->seedBooking('b3', '2026-11-10 07:00:00');
        $this->seedBooking('b4', '2026-11-09 11:00:00');
        $d = $this->tool($t, 'get_schedule', [])['result'];
        $this->assertFalse($d['isError']);
        $this->assertSame('2026-11-09: 3 booking(s), 2 confirmed.', $d['content'][0]['text']);
        $this->assertSame([['type' => 'text', 'text' => '2026-11-09: 3 booking(s), 2 confirmed.']], $d['content']);
        $sc = $d['structuredContent'];
        $this->assertSame('2026-11-09', $sc['date']);
        $this->assertFalse($sc['truncated']);
        $this->assertSame(['untrusted_client_name'], $sc['untrusted_fields']);
        $this->assertSame([
            'booking_id' => 'b1', 'client_id' => $this->svc->clientId, 'untrusted_client_name' => 'Thandi Test',
            'start' => '2026-11-09T07:00:00Z', 'end' => '2026-11-09T08:00:00Z', 'start_local' => '2026-11-09 09:00', 'end_local' => '2026-11-09 10:00',
            'status' => 'confirmed', 'created_by' => 'client',
        ], $sc['bookings'][0]);
        $this->assertSame('b2', $sc['bookings'][1]['booking_id']);
        $this->assertSame('cancelled', $sc['bookings'][1]['status']);
        $this->assertCount(3, $sc['bookings']);
        $e = $this->tool($t, 'get_schedule', ['date' => '2026-11-10'])['result']['structuredContent'];
        $this->assertSame('2026-11-10', $e['date']);
        $this->assertSame('b3', $e['bookings'][0]['booking_id']);
        // 22:30 UTC is already tomorrow in Johannesburg
        $this->keepAlive();
        $this->svc->database->now = new \DateTimeImmutable('2026-11-09 22:30:00', new \DateTimeZone('UTC'));
        $this->assertSame('2026-11-10', $this->tool($t, 'get_schedule', [])['result']['structuredContent']['date']);
    }

    public function testListBookingsRangeBoundariesAndTruncation(): void
    {
        $t = $this->token();
        $d = $this->tool($t, 'list_bookings', ['from' => '2026-11-09', 'to' => '2026-02-01']);
        $this->assertSame('"to" must not be before "from".', $d['result']['content'][0]['text']);
        $this->assertTrue($d['result']['isError']);
        $same = $this->tool($t, 'list_bookings', ['from' => '2026-11-09', 'to' => '2026-11-09']);
        $this->assertFalse($same['result']['isError']);
        $this->assertSame('0 booking(s) from 2026-11-09 to 2026-11-09.', $same['result']['content'][0]['text']);
        $ok = $this->tool($t, 'list_bookings', ['from' => '2026-01-01', 'to' => '2026-04-02']); // 91 days apart = 92 days inclusive
        $this->assertFalse($ok['result']['isError']);
        $over = $this->tool($t, 'list_bookings', ['from' => '2026-01-01', 'to' => '2026-04-03']);
        $this->assertTrue($over['result']['isError']);
        $this->assertSame('The range may be at most 92 days.', $over['result']['content'][0]['text']);
        for ($i = 0; $i < 51; $i++) {
            $this->seedBooking('lb' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), '2026-11-09 ' . sprintf('%02d:%02d:00', intdiv($i, 4), ($i % 4) * 15));
        }
        $this->seedBooking('zz', '2026-11-12 07:00:00', 'completed');
        $r = $this->tool($t, 'list_bookings', ['from' => '2026-11-09', 'to' => '2026-11-09'])['result'];
        $this->assertSame('50 booking(s) from 2026-11-09 to 2026-11-09 (first 50 shown).', $r['content'][0]['text']);
        $this->assertTrue($r['structuredContent']['truncated']);
        $this->assertCount(50, $r['structuredContent']['bookings']);
        $this->assertSame(['untrusted_client_name'], $r['structuredContent']['untrusted_fields']);
        $f = $this->tool($t, 'list_bookings', ['from' => '2026-11-09', 'to' => '2026-11-15', 'status' => 'completed'])['result'];
        $this->assertSame('1 booking(s) from 2026-11-09 to 2026-11-15.', $f['content'][0]['text']);
        $this->assertFalse($f['structuredContent']['truncated']);
        $this->assertSame('zz', $f['structuredContent']['bookings'][0]['booking_id']);
        // exactly 50 rows is not truncated
        $this->pdo()->exec("DELETE FROM bookings WHERE id = 'lb50'");
        $r = $this->tool($t, 'list_bookings', ['from' => '2026-11-09', 'to' => '2026-11-09'])['result'];
        $this->assertFalse($r['structuredContent']['truncated']);
        $this->assertSame('50 booking(s) from 2026-11-09 to 2026-11-09.', $r['content'][0]['text']);
    }

    public function testFindFreeSlotsRangeAndOutput(): void
    {
        $t = $this->token();
        $r = $this->tool($t, 'find_free_slots', ['from' => '2026-11-09', 'to' => '2026-11-09'])['result'];
        $this->assertSame('2 free slot(s) from 2026-11-09 to 2026-11-09.', $r['content'][0]['text']);
        $this->assertSame([
            ['start' => '2026-11-09T07:00:00Z', 'end' => '2026-11-09T08:00:00Z', 'start_local' => '2026-11-09 09:00'],
            ['start' => '2026-11-09T09:00:00Z', 'end' => '2026-11-09T10:00:00Z', 'start_local' => '2026-11-09 11:00'],
        ], $r['structuredContent']['slots']);
        $this->assertFalse($r['structuredContent']['truncated']);
        $this->assertArrayNotHasKey('error', $this->tool($t, 'find_free_slots', ['from' => '2026-11-01', 'to' => '2026-11-14']));
        $this->assertFalse($this->tool($t, 'find_free_slots', ['from' => '2026-11-01', 'to' => '2026-11-14'])['result']['isError']);
        $o = $this->tool($t, 'find_free_slots', ['from' => '2026-11-01', 'to' => '2026-11-15'])['result'];
        $this->assertTrue($o['isError']);
        $this->assertSame('The range may be at most 14 days.', $o['content'][0]['text']);
        $b = $this->tool($t, 'find_free_slots', ['from' => '2026-11-09', 'to' => '2026-11-08'])['result'];
        $this->assertSame('"to" must not be before "from".', $b['content'][0]['text']);
    }

    public function testClientTools(): void
    {
        $t = $this->token();
        $this->seedClientCredits(5);
        $cid = $this->svc->clientId;
        $r = $this->tool($t, 'search_clients', ['query' => 'thandi'])['result'];
        $this->assertSame('1 client(s) match.', $r['content'][0]['text']);
        $this->assertSame([['client_id' => $cid, 'untrusted_name' => 'Thandi Test', 'untrusted_email' => 'thandi@example.com', 'balance' => 5, 'upcoming_bookings' => 0]], $r['structuredContent']['clients']);
        $this->assertSame(['untrusted_name', 'untrusted_email'], $r['structuredContent']['untrusted_fields']);
        for ($i = 0; $i < 25; $i++) {
            $this->svc->addUser('client', "Bulk Person $i", "bulk$i@example.com", 'pw-pw-pw-pw');
        }
        $this->assertSame('20 client(s) match.', $this->tool($t, 'search_clients', ['query' => 'Bulk Person'])['result']['content'][0]['text']);
        $this->assertSame('0 client(s) match.', $this->tool($t, 'search_clients', ['query' => 'nobody-here'])['result']['content'][0]['text']);

        for ($i = 1; $i <= 12; $i++) {
            $this->pdo()->prepare('INSERT INTO credit_ledger (id,user_id,delta,reason,note,actor,created_at) VALUES (?,?,?,?,?,?,?)')
                ->execute(["l$i", $cid, 0 + 1, 'owner_adjust', "note $i", 'a', sprintf('2026-11-0%d 08:00:00', min($i, 9)) ]);
        }
        $this->seedBooking('gc1', '2026-11-12 07:00:00');
        $g = $this->tool($t, 'get_client', ['client_id' => $cid])['result'];
        $sc = $g['structuredContent'];
        $this->assertSame("Client $cid: 17 session(s) left, 0 upcoming booking(s).", $g['content'][0]['text']);
        $this->assertSame($cid, $sc['client_id']);
        $this->assertSame('Thandi Test', $sc['untrusted_name']);
        $this->assertSame('thandi@example.com', $sc['untrusted_email']);
        $this->assertSame(17, $sc['balance']);
        $this->assertSame(0, $sc['pending_pack_requests']);
        $this->assertCount(10, $sc['recent_credit_changes'], 'only the 10 newest ledger rows');
        $this->assertSame(['delta', 'reason', 'untrusted_note', 'at'], array_keys($sc['recent_credit_changes'][0]));
        $this->assertSame(['untrusted_name', 'untrusted_email', 'untrusted_note'], $sc['untrusted_fields']);
        $this->assertSame([], $sc['recent_bookings']);
        $this->assertSame('Client not found.', $this->tool($t, 'get_client', ['client_id' => 'nope'])['result']['content'][0]['text']);
        $this->assertTrue($this->tool($t, 'get_client', ['client_id' => 'nope'])['result']['isError']);
    }

    public function testBusinessSummary(): void
    {
        $t = $this->token();
        $r = $this->tool($t, 'get_business_summary', ['period' => 'week'])['result'];
        $this->assertSame('Business summary for the week: 3 booked, 1 completed, 0 cancelled.', $r['content'][0]['text']);
        $this->assertSame(['period' => 'week', 'from' => '2026-11-09', 'to' => '2026-11-15', 'bookedSessions' => 3, 'completedSessions' => 1,
            'cancelledSessions' => 0, 'freeSlotHours' => 20, 'creditsSold' => 10, 'newClients' => 1, 'untrusted_fields' => []], $r['structuredContent']);
    }

    public function testTextHelper(): void
    {
        $this->assertSame('a b', Tools::text("a\x00\x01 b"));
        $this->assertSame('a b c', Tools::text("  a \n\t b\u{2028}c  "));
        $this->assertSame('', Tools::text(['x']));
        $this->assertSame('', Tools::text(null));
        $this->assertSame('12', Tools::text(12));
        $this->assertSame('1', Tools::text(true));
        $this->assertSame(str_repeat('x', 120), Tools::text(str_repeat('x', 130)));
        $this->assertSame(str_repeat('x', 5), Tools::text(str_repeat('x', 9), 5));
        $this->assertSame('abcd', Tools::text('abcd e', 5), 'a space left at the cut is trimmed');
        $this->assertSame('äöü', Tools::text('äöü', 3));
        $this->assertSame('äö', Tools::text('äöü', 2));
        $this->assertSame('x', Tools::text("x\u{200b}"), 'zero-width space is a control/format character');
        $this->assertSame(60, Tools::NAME_MAX);
        $this->assertSame(40, Tools::DAILY_CREDIT_CAP);
        $this->assertSame(20, Tools::MAX_CREDITS_PER_CALL);
    }

    public function testUntrustedFieldLists(): void
    {
        $this->assertSame(['untrusted_client_name'], Tools::untrustedFields('create_booking'));
        $this->assertSame(['untrusted_client_name'], Tools::untrustedFields('cancel_booking'));
        $this->assertSame(['untrusted_client_name'], Tools::untrustedFields('reschedule_booking'));
        $this->assertSame(['untrusted_client_name'], Tools::untrustedFields('remove_session_credits'));
        $this->assertSame(['untrusted_client_name'], Tools::untrustedFields('add_session_credits'));
        $this->assertSame(['untrusted_name', 'untrusted_email'], Tools::untrustedFields('search_clients'));
        $this->assertSame(['untrusted_name', 'untrusted_email', 'untrusted_note'], Tools::untrustedFields('get_client'));
        $this->assertSame([], Tools::untrustedFields('get_business_summary'));
        $this->assertSame([], Tools::untrustedFields('find_free_slots'));
        $this->expectException(ToolInputError::class);
        (new Tools($this->svc))->execute('nope', [], 'g');
    }

    // ------------------------------------------------------------ write tools: credits

    private function addArgs(int $n, array $more = []): array
    {
        return array_replace(['client_id' => $this->svc->clientId, 'sessions' => $n, 'reason' => 'paid EFT', 'idempotency_key' => $this->key()], $more);
    }

    public function testAddCreditsExactOutputLedgerAndAudit(): void
    {
        $t = $this->token();
        $d = $this->tool($t, 'add_session_credits', $this->addArgs(3, ['payment_reference' => '  pay  100 ']))['result'];
        $cid = $this->svc->clientId;
        $this->assertFalse($d['isError']);
        $this->assertSame("Added 3 session(s) to client $cid. New balance: 3.", $d['content'][0]['text']);
        $this->assertSame(['client_id' => $cid, 'untrusted_client_name' => 'Thandi Test', 'added' => 3, 'new_balance' => 3, 'untrusted_fields' => ['untrusted_client_name']], $d['structuredContent']);
        $row = $this->pdo()->query("SELECT * FROM credit_ledger WHERE reason = 'chatgpt_adjust'")->fetch();
        $this->assertSame(3, (int) $row['delta']);
        $this->assertSame('paid EFT [ref PAY 100]', $row['note']);
        $this->assertStringStartsWith('chatgpt:', $row['actor']);
        $this->assertSame('PAY 100', $this->pdo()->query('SELECT ref FROM payment_references')->fetchColumn());
        $this->assertSame('chatgpt', $this->pdo()->query('SELECT source FROM payment_references')->fetchColumn());
        $this->assertSame($cid, $this->pdo()->query('SELECT user_id FROM payment_references')->fetchColumn());
        $this->assertSame('2026-11-09 08:00:00', $this->pdo()->query('SELECT created_at FROM payment_references')->fetchColumn());
        $a = $this->lastAudit('add_session_credits');
        $this->assertSame('ok', $a['status']);
        $this->assertSame("Added 3 session(s) to client $cid. New balance: 3.", $a['summary']);
        $args = json_decode($a['args_json'], true);
        $this->assertSame(3, $args['sessions']);
        $this->assertSame('paid EFT', $args['reason']);
        $this->assertArrayHasKey('_args_hash', $args);
        // no reference: plain reason
        $this->tool($t, 'add_session_credits', $this->addArgs(1));
        $this->assertSame('paid EFT', $this->pdo()->query("SELECT note FROM credit_ledger WHERE delta = 1 AND reason = 'chatgpt_adjust'")->fetchColumn());
        // a blank reference is treated as none
        $this->tool($t, 'add_session_credits', $this->addArgs(1, ['payment_reference' => '   ']));
        $this->assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM payment_references')->fetchColumn());
        // control characters in the reason are removed before storage; a reason that is only control characters is refused
        $e = $this->tool($t, 'add_session_credits', $this->addArgs(1, ['reason' => "\u{200b}\u{2028}"]))['result'];
        $this->assertTrue($e['isError']);
        $this->assertSame('A reason is required.', $e['content'][0]['text']);
        $this->assertSame('Client not found.', $this->tool($t, 'add_session_credits', ['client_id' => 'ghost'] + $this->addArgs(1))['result']['content'][0]['text']);
    }

    public function testDailyCapIs40(): void
    {
        $t = $this->token();
        for ($i = 0; $i < 2; $i++) {
            $this->assertFalse($this->tool($t, 'add_session_credits', $this->addArgs(20))['result']['isError']);
        }
        $this->assertSame(40, $this->svc->credits()->chatgptAddedToday());
        $r = $this->tool($t, 'add_session_credits', $this->addArgs(1))['result'];
        $this->assertTrue($r['isError']);
        $this->assertSame('Daily limit: at most 40 sessions can be added through ChatGPT per day (40 already added today). Use the admin page for larger changes.', $r['content'][0]['text']);
        $this->assertSame(40, $this->svc->credits()->balance($this->svc->clientId));
        $this->assertSame('error', $this->lastAudit('add_session_credits')['status']);
        // removals do not count against, or free, the daily cap
        $this->assertFalse($this->tool($t, 'remove_session_credits', $this->addArgs(5))['result']['isError']);
        $this->assertTrue($this->tool($t, 'add_session_credits', $this->addArgs(1))['result']['isError']);
    }

    public function testDailyCapBoundaryExactlyForty(): void
    {
        $t = $this->token();
        $this->assertFalse($this->tool($t, 'add_session_credits', $this->addArgs(20))['result']['isError']);
        $this->assertFalse($this->tool($t, 'add_session_credits', $this->addArgs(19))['result']['isError']);
        $this->assertFalse($this->tool($t, 'add_session_credits', $this->addArgs(1))['result']['isError'], 'the 40th session is allowed');
        $this->assertTrue($this->tool($t, 'add_session_credits', $this->addArgs(1))['result']['isError'], 'the 41st is refused');
    }

    public function testRemoveCreditsBalanceBoundary(): void
    {
        $t = $this->token();
        $this->seedClientCredits(3);
        $cid = $this->svc->clientId;
        $r = $this->tool($t, 'remove_session_credits', $this->addArgs(4))['result'];
        $this->assertTrue($r['isError']);
        $this->assertSame('The client only has 3 session(s); the balance cannot go below zero.', $r['content'][0]['text']);
        $a = $this->lastAudit('remove_session_credits');
        $this->assertSame('error', $a['status']);
        $this->assertSame('no_credits: The client only has 3 session(s); the balance cannot go below zero.', $a['summary']);
        $ok = $this->tool($t, 'remove_session_credits', $this->addArgs(3))['result'];
        $this->assertFalse($ok['isError']);
        $this->assertSame("Removed 3 session(s) from client $cid. New balance: 0.", $ok['content'][0]['text']);
        $this->assertSame(['client_id' => $cid, 'untrusted_client_name' => 'Thandi Test', 'removed' => 3, 'new_balance' => 0, 'untrusted_fields' => ['untrusted_client_name']], $ok['structuredContent']);
        $row = $this->pdo()->query("SELECT delta, reason FROM credit_ledger WHERE reason = 'chatgpt_adjust'")->fetch();
        $this->assertSame(-3, (int) $row['delta']);
    }

    public function testPaymentReferenceIsOnceOnlyAcrossCases(): void
    {
        $t = $this->token();
        $this->assertFalse($this->tool($t, 'add_session_credits', $this->addArgs(1, ['payment_reference' => 'EFT-77']))['result']['isError']);
        foreach (['eft-77', ' EFT-77 ', "eft-77\t", 'Eft-77'] as $dup) {
            $r = $this->tool($t, 'add_session_credits', $this->addArgs(1, ['payment_reference' => $dup]))['result'];
            $this->assertTrue($r['isError'], $dup);
            $this->assertSame('That payment reference has already been used.', $r['content'][0]['text']);
        }
        $this->assertSame(1, $this->svc->credits()->balance($this->svc->clientId));
    }

    // ------------------------------------------------------------ idempotency

    public function testIdempotentReplayReturnsSameResultWithoutRepeating(): void
    {
        $t = $this->token();
        $args = $this->addArgs(2);
        $first = $this->tool($t, 'add_session_credits', $args)['result'];
        $again = $this->tool($t, 'add_session_credits', $args)['result'];
        $this->assertFalse($first['structuredContent']['new_balance'] === 0);
        $this->assertArrayNotHasKey('replayed', $first['structuredContent']);
        $this->assertTrue($again['structuredContent']['replayed']);
        unset($again['structuredContent']['replayed']);
        $this->assertSame($first, $again);
        $this->assertSame(2, $this->svc->credits()->balance($this->svc->clientId));
        $a = $this->lastAudit('add_session_credits');
        $this->assertSame('replayed', $a['status']);
        $this->assertStringStartsWith('replayed: Added 2 session(s)', $a['summary']);
        // the same key but different arguments is refused and changes nothing
        $r = $this->tool($t, 'add_session_credits', ['sessions' => 3] + $args)['result'];
        $this->assertTrue($r['isError']);
        $this->assertSame('That idempotency_key was already used for a different request. Use a new key for a new change.', $r['content'][0]['text']);
        $this->assertSame('idempotency_key reused with different arguments', $this->lastAudit('add_session_credits')['summary']);
        $this->assertSame(2, $this->svc->credits()->balance($this->svc->clientId));
        // the claim is scoped to the grant and to the tool
        $t2 = $this->token();
        $this->assertFalse($this->tool($t2, 'add_session_credits', $args)['result']['isError']);
        $this->assertSame(4, $this->svc->credits()->balance($this->svc->clientId));
        $rm = ['idempotency_key' => $args['idempotency_key']] + $this->addArgs(1);
        $this->assertFalse($this->tool($t, 'remove_session_credits', $rm)['result']['isError']);
        $this->assertSame(3, $this->svc->credits()->balance($this->svc->clientId));
    }

    public function testPendingClaimBlocksUntilItIsStale(): void
    {
        $t = $this->token();
        $args = $this->addArgs(1);
        $grant = $this->pdo()->query('SELECT id FROM oauth_grants')->fetchColumn();
        $key = $grant . ':add_session_credits:' . $args['idempotency_key'];
        $hash = hash('sha256', json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->pdo()->prepare('INSERT INTO idempotency_keys (scope_key,result_json,created_at) VALUES (?,?,?)')
            ->execute([$key, json_encode(['pending' => true, 'args_hash' => $hash]), '2026-11-09 08:00:00']);
        $r = $this->tool($t, 'add_session_credits', $args)['result'];
        $this->assertSame('A request with this idempotency_key is still being processed. Wait a moment and check before retrying.', $r['content'][0]['text']);
        $this->assertSame('idempotency_key still being processed', $this->lastAudit('add_session_credits')['summary']);
        $this->svc->advance(120);
        $this->assertTrue($this->tool($t, 'add_session_credits', $args)['result']['isError'], 'exactly 120 s old is still pending');
        $this->svc->advance(1);
        $this->assertFalse($this->tool($t, 'add_session_credits', $args)['result']['isError'], 'older than 120 s is released and runs');
        $this->assertSame(1, $this->svc->credits()->balance($this->svc->clientId));
        // a corrupt claim row counts as pending
        $args2 = $this->addArgs(1);
        $this->pdo()->prepare('INSERT INTO idempotency_keys (scope_key,result_json,created_at) VALUES (?,?,?)')
            ->execute([$grant . ':add_session_credits:' . $args2['idempotency_key'], 'not json', '2026-11-09 08:00:00']);
        $this->assertStringContainsString('still being processed', $this->tool($t, 'add_session_credits', $args2)['result']['content'][0]['text']);
    }

    public function testFailedCallReleasesItsIdempotencyKey(): void
    {
        $t = $this->token();
        $args = $this->addArgs(1, ['client_id' => 'ghost']);
        $this->assertTrue($this->tool($t, 'add_session_credits', $args)['result']['isError']);
        $this->assertSame(0, (int) $this->pdo()->query("SELECT COUNT(*) FROM idempotency_keys WHERE scope_key LIKE '%:add_session_credits:%'")->fetchColumn());
        $fixed = ['client_id' => $this->svc->clientId] + $args;
        $this->assertFalse($this->tool($t, 'add_session_credits', $fixed)['result']['isError'], 'the key can be reused after a failure');
    }

    public function testWriteRateLimitIs20PerHour(): void
    {
        $t = $this->token();
        $this->keepAlive();
        for ($i = 1; $i <= 20; $i++) {
            $this->assertFalse($this->tool($t, 'add_session_credits', $this->addArgs(1))['result']['isError'], "write $i");
            if ($i % 50 === 0) {
                $this->svc->advance(1);
            }
        }
        $args = $this->addArgs(1);
        $r = $this->tool($t, 'add_session_credits', $args)['result'];
        $this->assertTrue($r['isError']);
        $this->assertSame('Write limit reached: at most 20 changes per hour through ChatGPT. Try again later or use the admin page.', $r['content'][0]['text']);
        $a = $this->lastAudit('add_session_credits');
        $this->assertSame('rate_limited', $a['status']);
        $this->assertSame('write limit of 20 per hour reached', $a['summary']);
        $grant = $this->pdo()->query('SELECT id FROM oauth_grants')->fetchColumn();
        $this->assertSame('2026-11-09 09:00:00', $this->pdo()->query("SELECT expires_at FROM rate_limits WHERE bucket = 'mcp_writes:$grant:" . $this->svc->database->now->getTimestamp() . "'")->fetchColumn());
        $again = $this->tool($t, 'add_session_credits', $args)['result'];
        $this->assertStringStartsWith('Write limit reached', $again['content'][0]['text'], 'the key was released, not left pending');
        // reads are not writes: 60/min applies, not 20/h
        $this->assertFalse($this->tool($t, 'get_schedule', [])['result']['isError']);
        // the refused call released its key, and the next hour opens a new window
        $this->svc->advance(3600);
        $this->assertFalse($this->tool($t, 'add_session_credits', $args)['result']['isError']);
    }

    public function testUnexpectedFailureIsGenericAndNothingLeaks(): void
    {
        $prev = ini_set('error_log', '/dev/null'); // keep the expected error_log() lines off stderr
        $t = $this->token();
        $this->pdo()->exec('DROP TABLE credit_ledger');
        $d = $this->tool($t, 'search_clients', ['query' => 'Thandi'])['result'];
        $this->assertTrue($d['isError']);
        $this->assertSame('The server could not complete that. Nothing was changed. Try again, or use the admin page.', $d['content'][0]['text']);
        $a = $this->lastAudit('search_clients');
        $this->assertSame('internal error', $a['summary']);
        $this->assertSame('error', $a['status']);
        $args = $this->addArgs(1);
        $this->assertStringStartsWith('The server could not complete', $this->tool($t, 'add_session_credits', $args)['result']['content'][0]['text']);
        $this->assertStringStartsWith('The server could not complete', $this->tool($t, 'add_session_credits', $args)['result']['content'][0]['text'], 'the claim is released after a crash');
        ini_set('error_log', (string) $prev);
    }

    // ------------------------------------------------------------ booking tools

    public function testCreateRescheduleCancelExactOutput(): void
    {
        $t = $this->token();
        $this->seedClientCredits(2);
        $cid = $this->svc->clientId;
        $c = $this->tool($t, 'create_booking', ['client_id' => $cid, 'start' => '2026-11-10T09:00', 'idempotency_key' => $this->key()])['result'];
        $this->assertFalse($c['isError']);
        $sc = $c['structuredContent'];
        $this->assertSame("Booked client $cid for 2026-11-10 09:00. Balance: 1.", $c['content'][0]['text']);
        $this->assertSame(1, $sc['new_balance']);
        $this->assertSame('chatgpt', $sc['created_by']);
        $this->assertSame('confirmed', $sc['status']);
        $this->assertSame('2026-11-10T07:00:00Z', $sc['start']);
        $this->assertSame('2026-11-10T08:00:00Z', $sc['end']);
        $this->assertSame('2026-11-10 09:00', $sc['start_local']);
        $this->assertSame($cid, $sc['client_id']);
        $this->assertSame(['untrusted_client_name'], $sc['untrusted_fields']);
        $bid = $sc['booking_id'];
        $this->assertSame('Client not found.', $this->tool($t, 'create_booking', ['client_id' => 'ghost', 'start' => '2026-11-10T09:00', 'idempotency_key' => $this->key()])['result']['content'][0]['text']);

        $m = $this->tool($t, 'reschedule_booking', ['booking_id' => $bid, 'new_start' => '2026-11-11T10:30', 'idempotency_key' => $this->key()])['result'];
        $this->assertSame("Moved booking $bid to 2026-11-11 10:30.", $m['content'][0]['text']);
        $this->assertSame($bid, $m['structuredContent']['booking_id']);
        $this->assertSame('2026-11-11T08:30:00Z', $m['structuredContent']['start']);
        $this->assertSame('Booking not found.', $this->tool($t, 'reschedule_booking', ['booking_id' => 'ghost', 'new_start' => '2026-11-11T10:30', 'idempotency_key' => $this->key()])['result']['content'][0]['text']);

        $x = $this->tool($t, 'cancel_booking', ['booking_id' => $bid, 'refund_credit' => true, 'reason' => 'sick', 'idempotency_key' => $this->key()])['result'];
        $this->assertSame("Cancelled booking $bid and refunded the session.", $x['content'][0]['text']);
        $this->assertTrue($x['structuredContent']['refunded']);
        $this->assertSame('cancelled', $x['structuredContent']['status']);
        $this->assertSame($bid, $x['structuredContent']['booking_id']);
        $this->assertSame(2, $this->svc->credits()->balance($cid));
        $this->assertSame('sick', $this->pdo()->query("SELECT cancel_reason FROM bookings WHERE id = '$bid'")->fetchColumn());

        $c2 = $this->tool($t, 'create_booking', ['client_id' => $cid, 'start' => '2026-11-10T09:00', 'idempotency_key' => $this->key()])['result']['structuredContent']['booking_id'];
        $y = $this->tool($t, 'cancel_booking', ['booking_id' => $c2, 'refund_credit' => false, 'reason' => 'no show', 'idempotency_key' => $this->key()])['result'];
        $this->assertSame("Cancelled booking $c2 without a refund.", $y['content'][0]['text']);
        $this->assertFalse($y['structuredContent']['refunded']);
        $this->assertSame('Booking not found.', $this->tool($t, 'cancel_booking', ['booking_id' => 'ghost', 'refund_credit' => true, 'reason' => 'r', 'idempotency_key' => $this->key()])['result']['content'][0]['text']);
        // the cancelled-but-already error is a DomainError and uses its own message
        $z = $this->tool($t, 'cancel_booking', ['booking_id' => $c2, 'refund_credit' => true, 'reason' => 'again', 'idempotency_key' => $this->key()])['result'];
        $this->assertSame('Only confirmed bookings can be cancelled.', $z['content'][0]['text']);
        $this->assertSame('conflict: Only confirmed bookings can be cancelled.', $this->lastAudit('cancel_booking')['summary']);
    }

    public function testNoCreditsBookingErrorIsAudited(): void
    {
        $t = $this->token();
        $r = $this->tool($t, 'create_booking', ['client_id' => $this->svc->clientId, 'start' => '2026-11-10T09:00', 'idempotency_key' => $this->key()])['result'];
        $this->assertTrue($r['isError']);
        $this->assertSame('The client has no sessions left.', $r['content'][0]['text']);
        $a = $this->lastAudit('create_booking');
        $this->assertSame('no_credits: The client has no sessions left.', $a['summary']);
        $this->assertSame('error', $a['status']);
    }

    // ------------------------------------------------------------ Redactor

    public function testRedactorExactBehaviour(): void
    {
        $r = Redactor::redact(['client_id' => str_repeat('a', 70), 'idempotency_key' => str_repeat('b', 65), 'reason' => str_repeat('r', 41),
            'date' => str_repeat('2', 21), 'sessions' => 5, 'ok' => true, 'ratio' => 1.5, 'nothing' => null, 'list' => [1], 'start' => '2026-11-09T09:00', 'status' => "a\nb"]);
        $this->assertSame(str_repeat('a', 64) . '...', $r['client_id']);
        $this->assertSame(str_repeat('b', 64) . '...', $r['idempotency_key']);
        $this->assertSame(str_repeat('r', 40) . '...', $r['reason']);
        $this->assertSame(str_repeat('2', 20) . '...', $r['date']);
        $this->assertSame(5, $r['sessions']);
        $this->assertTrue($r['ok']);
        $this->assertSame(1.5, $r['ratio']);
        $this->assertNull($r['nothing']);
        $this->assertSame('[omitted]', $r['list']);
        $this->assertSame('2026-11-09T09:00', $r['start']);
        $this->assertSame('a b', $r['status']);
        $this->assertSame(16, strlen($r['_args_hash']));
        $exact = Redactor::redact(['reason' => str_repeat('r', 40), 'client_id' => str_repeat('a', 64), 'from' => str_repeat('d', 20), 'to' => 'x', 'new_start' => 'y', 'period' => 'week']);
        $this->assertSame(str_repeat('r', 40), $exact['reason']);
        $this->assertSame(str_repeat('a', 64), $exact['client_id']);
        $this->assertSame(str_repeat('d', 20), $exact['from']);
        $this->assertSame(str_repeat('c', 64) . '...', Redactor::redact(['booking_id' => str_repeat('c', 65)])['booking_id']);
        $this->assertSame(substr(hash('sha256', json_encode(['a' => 1])), 0, 16), Redactor::redact(['a' => 1])['_args_hash']);
        $this->assertSame(20 + 1, count(Redactor::redact(array_fill_keys(array_map(fn($i) => "k$i", range(1, 30)), 1))), '20 keys plus the hash');
        $this->assertSame(20, Redactor::MAX_KEYS);
        $this->assertSame(40, Redactor::FREE_TEXT_MAX);
        $keys = array_keys(Redactor::redact(['bad key!' => 1, str_repeat('k', 50) => 2]));
        $this->assertSame(['bad?key?', str_repeat('k', 40), '_args_hash'], $keys);
        $this->assertSame('x y', Redactor::redact(['note' => "x\x00\x1f\x7fy"])['note']);
        $this->assertSame([str_repeat('é', 40), '_args_hash'], array_keys(Redactor::redact([str_repeat('é', 45) => 1])));
        $this->assertSame(str_repeat('é', 30), Redactor::redact(['note' => str_repeat('é', 30)])['note'], 'length is counted in characters');
        foreach (['from', 'to', 'start', 'new_start', 'period', 'status', 'date'] as $k) {
            $this->assertSame(str_repeat('s', 20) . '...', Redactor::redact([$k => str_repeat('s', 25)])[$k], $k);
        }
        foreach (['idempotency_key', 'client_id', 'booking_id', 'x_id'] as $k) {
            $this->assertSame(str_repeat('s', 50), Redactor::redact([$k => str_repeat('s', 50)])[$k], $k);
        }
        $this->assertSame(str_repeat('s', 40) . '...', Redactor::redact(['reason' => str_repeat('s', 50)])['reason']);
        $this->assertSame(str_repeat('s', 40) . '...', Redactor::redact(['status_x' => str_repeat('s', 50)])['status_x']);
    }
}
