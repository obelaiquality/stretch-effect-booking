<?php
declare(strict_types=1);

namespace StretchEffect\Tests\Fuzz;

use StretchEffect\Contracts\HttpResponse;
use StretchEffect\Mcp\Tools;

/**
 * Fuzzes POST /mcp through the real Kernel with a bearer token from a real OAuth grant.
 * Invariants: never 500, always a well-formed JSON-RPC (or specified HTTP) answer, no PHP warnings, no leaked internals,
 * one audit row per tools/call, ledger and bookings change only as a successful write tool says, caps and idempotency hold.
 */
final class McpFuzzTest extends FuzzCase
{
    private FuzzWorld $w;
    private string $readOnlyGrant = '';
    /** @var list<array{body:string,tool:string}> successful write calls, for replays */
    private array $writes = [];
    /** @var list<string> idempotency keys used so far */
    private array $keys = [];
    private int $keySeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->w = FuzzWorld::create();
        $this->w->connect();
        $this->readOnlyGrant = $this->w->connect(['bookings:read']);
        $this->w->connect(); // current grant = full scopes again
        $this->w->advance(3600);
    }

    // ------------------------------------------------------------------------------------------------ helpers

    /** @param array<string,string> $headers */
    private function post(string $body, array $headers, string $ip = '198.51.100.7'): HttpResponse
    {
        $this->describe('POST', '/mcp', $body, $headers);
        return $this->w->send(FuzzWorld::request('POST', '/mcp', $headers, [], $body, [], $ip));
    }

    /** @return array<string,string> */
    private function authHeaders(string $token, bool $origin = false): array
    {
        $h = ['content-type' => 'application/json', 'authorization' => 'Bearer ' . $token];
        if ($origin) {
            $h['origin'] = 'https://chatgpt.com';
        }
        return $h;
    }

    private function day(int $offset): string
    {
        return $this->w->now()->setTimezone(new \DateTimeZone('Africa/Johannesburg'))->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');
    }

    private function newKey(): string
    {
        if ($this->keys !== [] && $this->rng->chance(0.12)) {
            return $this->rng->pick($this->keys); // reuse: replay or mismatch
        }
        $k = 'fz' . ($this->keySeq++) . $this->rng->hex(3);
        $this->keys[] = $k;
        return $k;
    }

    private function clientId(): string
    {
        return $this->rng->pick($this->w->clients)['id'];
    }

    /** A free slot, as the local "YYYY-MM-DDTHH:MM" the tools take. */
    private function freeSlot(): ?string
    {
        for ($try = 0; $try < 4; $try++) {
            $d = $this->day($this->rng->int(0, 12));
            $slots = $this->w->app->bookings()->availability($d, $d);
            if ($slots !== []) {
                $utc = $this->rng->pick($slots)['start'];
                return (new \DateTimeImmutable($utc))->setTimezone(new \DateTimeZone('Africa/Johannesburg'))->format('Y-m-d\TH:i');
            }
        }
        return null;
    }

    private function bookingId(): string
    {
        $ids = array_column($this->w->app->db()->all('SELECT id FROM bookings ORDER BY rowid'), 'id');
        return $ids === [] ? $this->rng->hex(8) : $this->rng->pick($ids);
    }

    /** @return array<string,mixed> */
    private function validArgs(string $tool): array
    {
        $reasons = ['paid EFT for 5-pack', 'correction', 'Cash at the studio', 'welcome', "O'Brien's gift"];
        switch ($tool) {
            case 'get_schedule':
                return $this->rng->chance(0.3) ? [] : ['date' => $this->day($this->rng->int(-3, 10))];
            case 'list_bookings':
                return ['from' => $this->day($this->rng->int(-10, 0)), 'to' => $this->day($this->rng->int(0, 30))] + ($this->rng->chance(0.4) ? ['status' => $this->rng->pick(['confirmed', 'cancelled', 'completed', 'no_show'])] : []);
            case 'find_free_slots':
                $a = $this->rng->int(0, 6);
                return ['from' => $this->day($a), 'to' => $this->day($a + $this->rng->int(0, 13))];
            case 'search_clients':
                return ['query' => $this->rng->pick(['Fuzz', 'Zanele', 'fuzz1@', 'Ignore', 'example.com', 'Pieter'])];
            case 'get_client':
                return ['client_id' => $this->clientId()];
            case 'get_business_summary':
                return ['period' => $this->rng->pick(['week', 'month'])];
            case 'add_session_credits':
                return ['client_id' => $this->clientId(), 'sessions' => $this->rng->int(1, 20), 'reason' => $this->rng->pick($reasons), 'idempotency_key' => $this->newKey()]
                    + ($this->rng->chance(0.4) ? ['payment_reference' => $this->rng->pick(['PAY-1', 'pay-1 ', 'PAY-2', 'ref  3', 'REF 3', 'EFT-' . $this->rng->int(1, 500)])] : []);
            case 'remove_session_credits':
                return ['client_id' => $this->clientId(), 'sessions' => $this->rng->int(1, 8), 'reason' => $this->rng->pick($reasons), 'idempotency_key' => $this->newKey()];
            case 'create_booking':
                return ['client_id' => $this->clientId(), 'start' => $this->freeSlot() ?? '2026-11-10T09:00', 'idempotency_key' => $this->newKey()];
            case 'cancel_booking':
                return ['booking_id' => $this->bookingId(), 'refund_credit' => $this->rng->chance(0.5), 'reason' => $this->rng->pick($reasons), 'idempotency_key' => $this->newKey()];
            default: // reschedule_booking
                return ['booking_id' => $this->bookingId(), 'new_start' => $this->freeSlot() ?? '2026-11-10T09:00', 'idempotency_key' => $this->newKey()];
        }
    }

    /** @return array<string,mixed> audit ids */
    private function auditIds(): array
    {
        return array_column($this->w->app->db()->all('SELECT id FROM audit_log'), 'id');
    }

    private function snapshot(): array
    {
        $app = $this->w->app;
        return ['bal' => Invariants::balances($app), 'ledger' => Invariants::ledgerSig($app), 'book' => Invariants::bookingSig($app), 'audit' => $this->auditIds(),
            'bookings' => $app->db()->all('SELECT id, user_id, start_at, status, refunded FROM bookings ORDER BY id')];
    }

    // ------------------------------------------------------------------------------------------------ tests

    public function testToolsCallWithMutatedArguments(): void
    {
        $tools = array_keys(Tools::definitions());
        $writeTools = ['add_session_credits', 'remove_session_credits', 'create_booking', 'cancel_booking', 'reschedule_booking'];
        $ro = $this->w->freshAccessToken($this->readOnlyGrant);
        for ($i = 0; $i < $this->iterations; $i++) {
            $this->caseNo = $i;
            $this->w->advance((int) $this->rng->weighted(['0' => 20, '3' => 30, '31' => 25, '700' => 25]));
            $tool = $this->rng->pick($tools);
            $valid = $this->validArgs($tool);
            $mode = $this->rng->weighted(['valid' => 22, 'mutated' => 50, 'badname' => 6, 'badargs' => 6, 'replay' => 9, 'readonly' => 7]);

            $token = $this->w->freshAccessToken();
            if ($mode === 'readonly') {
                $token = $this->w->freshAccessToken($this->readOnlyGrant);
                $tool = $this->rng->pick($writeTools);
                $valid = $this->validArgs($tool);
            }
            $name = $tool;
            $args = $valid;
            if ($mode === 'mutated') {
                $args = Hostile::finish(Hostile::mutate($this->rng, $valid));
            } elseif ($mode === 'badname') {
                $name = $this->rng->chance(0.5) ? Hostile::string($this->rng) : $this->rng->pick(['', 'tools/call', 'GET_SCHEDULE', 'get_schedule ', "get_schedule\0", 'get_schedule/../x', 'add_session_credits,remove_session_credits']);
            } elseif ($mode === 'badargs') {
                $args = $this->rng->pick([null, 'x', 7, true, [1, 2], [[]], [], new \stdClass(), Hostile::value($this->rng)]);
            }
            $params = ['name' => $name, 'arguments' => $args];
            if ($mode === 'badargs' && $this->rng->chance(0.2)) {
                unset($params['arguments']);
            }
            $id = $this->rng->pick([1, 'abc', 0, -5, 2 ** 40, 'x' . $this->rng->hex(4)]);
            $body = Json::encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => $params]);
            $headers = $this->authHeaders($token, $this->rng->chance(0.5));
            $isReplay = false;
            if ($mode === 'replay' && $this->writes !== []) {
                $w = $this->rng->pick($this->writes);
                $body = $w['body'];
                $name = $w['tool'];
                $isReplay = true;
            }

            $before = $this->snapshot();
            $res = $this->post($body, $headers);
            $after = $this->snapshot();
            $this->verifyResponse($res, $name, $mode, $isReplay, $before, $after, $body, $token);
            if ($isReplay) {
                $this->tally('replay');
            }
            $this->tally($mode);
            $this->endCase();
        }
        $this->ensure($this->ran !== [], 'no cases ran');
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $after */
    private function verifyResponse(HttpResponse $res, string $tool, string $mode, bool $isReplay, array $before, array $after, string $body, string $token): void
    {
        $this->ensure($res->status !== 500 && $res->status < 500, "HTTP {$res->status} from /mcp: {$res->body}");
        $this->assertNoLeak($res->body, '/mcp response');
        $this->ensure(!str_contains($res->body, $token), 'the bearer token was echoed');
        $this->ensure(!str_contains($res->body, (string) $this->w->owner['email']) && !str_contains($res->body, (string) $this->w->owner['secret']), 'owner email/secret in response');
        foreach ($res->headers as $k => $v) {
            $this->ensure(preg_match('/[\r\n]/', $k . $v) !== 1, "header $k contains a line break");
        }
        $j = json_decode($res->body, true);
        $this->ensure(is_array($j), "response is not JSON: {$res->body}");
        $this->ensure(mb_check_encoding($res->body, 'UTF-8'), 'response is not valid UTF-8');

        $defs = Tools::definitions();
        $isWrite = isset($defs[$tool]) && $defs[$tool]['write'];
        $rpcOk = isset($j['jsonrpc']) && $j['jsonrpc'] === '2.0' && (array_key_exists('result', $j) xor array_key_exists('error', $j));
        if ($res->status === 429 || $res->status === 401) {
            $this->ensure(isset($j['error']) && is_string($j['error']), "{$res->status} body is not an OAuth-style error");
        } else {
            $this->ensure($rpcOk, 'response is not valid JSON-RPC 2.0: ' . $res->body);
        }
        if (is_array($j['result'] ?? null) && ($j['result']['isError'] ?? true) === false) {
            $this->tally('ok:' . $tool . (($j['result']['structuredContent']['replayed'] ?? false) ? ':replayed' : ''));
        } elseif ($res->status === 200 && isset($j['result'])) {
            $this->tally('toolerr');
        } else {
            $this->tally('http' . $res->status);
        }
        if ($res->status === 403) {
            $this->ensure(isset($res->headers['WWW-Authenticate']) && str_contains($res->headers['WWW-Authenticate'], 'insufficient_scope'), '403 without insufficient_scope challenge');
        }

        // audit: a tools/call that got past parsing and the rate limit writes exactly one non-booking row for this grant
        $newIds = array_diff($this->auditIds(), $before['audit']);
        $rows = [];
        if ($newIds !== []) {
            $in = implode(',', array_fill(0, count($newIds), '?'));
            $rows = $this->w->app->db()->all("SELECT * FROM audit_log WHERE id IN ($in)", array_values($newIds));
        }
        $mine = array_values(array_filter($rows, static fn(array $r): bool => !str_starts_with((string) $r['action'], 'booking.')));
        $code = $j['error']['code'] ?? 0;
        $parseLevel = in_array($code, [-32700, -32600, -32022], true); // never reached tools/call
        $this->ensure($parseLevel ? count($mine) <= 1 : count($mine) === 1, 'expected exactly 1 audit row for this tools/call, got ' . count($mine) . ': ' . json_encode(array_map(static fn($r) => $r['action'] . '/' . $r['status'], $mine)) . ' response=' . substr($res->body, 0, 200));
        foreach ($mine as $r) {
            $this->ensure(!str_contains((string) $r['args_json'], $token), 'token in audit args');
            $this->ensure(strlen((string) $r['args_json']) < 3000, 'audit args_json is huge');
            $this->ensure(!preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', (string) $r['summary']), 'control character in audit summary');
        }

        $result = $j['result'] ?? null;
        $isError = !is_array($result) || ($result['isError'] ?? true) === true;
        $sc = is_array($result) ? ($result['structuredContent'] ?? null) : null;
        $replayed = is_array($sc) && ($sc['replayed'] ?? false) === true;

        if ($isReplay && $res->status === 200) {
            $this->ensure($replayed, 'a replayed write was not reported as replayed: ' . $res->body);
        }

        $changed = $before['ledger'] !== $after['ledger'] || $before['book'] !== $after['book'];
        if ($res->status !== 200 || $isError || $replayed || !$isWrite) {
            $this->ensure(!$changed, "ledger/bookings changed although the call did not report a successful new write (status {$res->status}, isError=" . var_export($isError, true) . ', replayed=' . var_export($replayed, true) . ')');
        } else {
            $this->verifyWriteEffect($tool, $sc, $before, $after, $body);
            $this->writes[] = ['body' => $body, 'tool' => $tool];
        }
        if (!$isError && is_array($result)) {
            $text = (string) ($result['content'][0]['text'] ?? '');
            foreach ($this->w->clients as $c) {
                $this->ensure(!str_contains($text, (string) $c['name']) && !str_contains($text, (string) $c['email']), 'a client name/email is in the one-line content text (prompt-injection channel)');
            }
            $this->ensure(is_array($sc) && isset($sc['untrusted_fields']) && is_array($sc['untrusted_fields']), 'structuredContent has no untrusted_fields list');
        }

        $v = Invariants::all($this->w->app);
        $this->ensure($v === [], 'database invariant broken: ' . implode('; ', $v));
        // idempotency never applied twice: one ledger row per (grant, tool, key)
        $dupe = $this->w->app->db()->val("SELECT COUNT(*) FROM (SELECT note FROM credit_ledger WHERE reason = 'chatgpt_adjust' AND note LIKE '%[ref %' GROUP BY substr(note, instr(note, '[ref ')) HAVING COUNT(*) > 1)");
        $this->ensure((int) $dupe === 0, 'a payment reference was applied twice');
    }

    /** @param array<string,mixed>|null $sc @param array<string,mixed> $before @param array<string,mixed> $after */
    private function verifyWriteEffect(string $tool, ?array $sc, array $before, array $after, string $body): void
    {
        $this->ensure(is_array($sc), 'write succeeded without structuredContent');
        $delta = [];
        foreach ($after['bal'] as $id => $b) {
            if ($b !== ($before['bal'][$id] ?? 0)) {
                $delta[$id] = $b - ($before['bal'][$id] ?? 0);
            }
        }
        switch ($tool) {
            case 'add_session_credits':
            case 'remove_session_credits':
                $n = $tool === 'add_session_credits' ? ($sc['added'] ?? null) : -($sc['removed'] ?? 0);
                $this->ensure(is_int($n) && $n !== 0 && abs($n) >= 1 && abs($n) <= 20, "credit change outside 1..20: " . var_export($n, true));
                $this->ensure($delta === [$sc['client_id'] => $n], 'balances moved by ' . json_encode($delta) . " instead of {$n} on {$sc['client_id']}");
                $this->ensure(($sc['new_balance'] ?? null) === $after['bal'][$sc['client_id']], 'new_balance in the result differs from the ledger');
                break;
            case 'create_booking':
                $this->ensure(array_values($delta) === [-1], 'create_booking did not take exactly one credit: ' . json_encode($delta));
                $this->ensure(count($after['bookings']) === count($before['bookings']) + 1, 'create_booking did not add exactly one booking');
                break;
            case 'cancel_booking':
                $refunded = ($sc['refunded'] ?? null);
                $this->ensure($delta === ($refunded ? [$sc['client_id'] ?? '' => 1] : []) || ($refunded && array_values($delta) === [1]), 'cancel refund flag and ledger disagree: ' . json_encode($delta));
                break;
            case 'reschedule_booking':
                $this->ensure($delta === [], 'reschedule moved credits');
                break;
        }
    }

    public function testEnvelopesRawBodiesAndHeaders(): void
    {
        $methods = ['initialize', 'ping', 'server/discover', 'tools/list', 'tools/call', 'notifications/initialized', 'resources/list', 'prompts/list', 'completion/complete', 'logging/setLevel', '', 'tools/call ', 'TOOLS/LIST', 'tools\\list', "tools/list\0"];
        for ($i = 0; $i < $this->iterations; $i++) {
            $this->caseNo = $i;
            $this->w->advance((int) $this->rng->weighted(['0' => 15, '2' => 35, '40' => 50]));
            $token = $this->w->freshAccessToken();
            $headers = $this->authHeaders($token, $this->rng->chance(0.3));
            $kind = $this->rng->weighted(['envelope' => 45, 'raw' => 20, 'headers' => 20, 'bignum' => 5, 'get' => 5, 'initialize' => 5]);
            $method = 'POST';
            switch ($kind) {
                case 'raw':
                    $body = Hostile::brokenBody($this->rng);
                    break;
                case 'headers':
                    $body = Json::encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $this->rng->pick(['ping', 'tools/list']), 'params' => new \stdClass()]);
                    $headers = $this->mutateHeaders($headers, $token);
                    break;
                case 'bignum':
                    $body = '{"jsonrpc":"2.0","id":' . $this->rng->pick(['1e999', '99999999999999999999', '-0', '1.5', '[1]', '{"a":1}', 'true', 'null', '"' . str_repeat('x', 70000) . '"']) . ',"method":"ping"}';
                    break;
                case 'get':
                    $method = $this->rng->pick(['GET', 'DELETE', 'PUT', 'PATCH', 'HEAD', 'OPTIONS', 'TRACE']);
                    $body = '';
                    break;
                case 'initialize':
                    $body = Json::encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => Hostile::mutate($this->rng, ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'x', 'version' => '1']])]);
                    break;
                default:
                    $env = ['jsonrpc' => '2.0', 'id' => 1, 'method' => $this->rng->chance(0.7) ? $this->rng->pick($methods) : Hostile::string($this->rng), 'params' => Hostile::value($this->rng)];
                    $env = Hostile::mutate($this->rng, $env);
                    $body = Json::encode(Hostile::finish($env));
            }
            $this->describe($method, '/mcp', $body, $headers);
            $before = Invariants::stateSig($this->w->app);
            $res = $this->w->send(FuzzWorld::request($method, '/mcp', $headers, [], $body));
            $this->ensure($res->status < 500, "HTTP {$res->status} from /mcp: " . substr($res->body, 0, 300));
            $this->assertNoLeak($res->body, '/mcp response');
            foreach ($res->headers as $k => $v) {
                $this->ensure(preg_match('/[\r\n]/', $k . $v) !== 1, "header $k contains a line break");
            }
            if ($res->status === 202) {
                $this->ensure($res->body === '', '202 must have an empty body');
            } elseif ($res->status === 405) {
                $this->ensure(($res->headers['Allow'] ?? '') === 'POST', '405 without Allow: POST');
            } else {
                $j = json_decode($res->body, true);
                $this->ensure(is_array($j), 'not JSON: ' . substr($res->body, 0, 200));
                if (isset($j['jsonrpc'])) {
                    $this->ensure($j['jsonrpc'] === '2.0' && (array_key_exists('result', $j) xor array_key_exists('error', $j)), 'malformed JSON-RPC: ' . substr($res->body, 0, 200));
                    if (isset($j['error'])) {
                        $this->ensure(is_int($j['error']['code'] ?? null) && is_string($j['error']['message'] ?? null), 'JSON-RPC error without code/message');
                    }
                } else {
                    $this->ensure(is_string($j['error'] ?? null), 'status ' . $res->status . ' without an error field');
                }
            }
            $this->ensure(Invariants::stateSig($this->w->app) === $before, 'a non-tool request changed ledger/bookings/settings');
            $this->tally($kind);
            $this->endCase();
        }
    }

    /** @param array<string,string> $h @return array<string,string> */
    private function mutateHeaders(array $h, string $token): array
    {
        $op = $this->rng->int(0, 14);
        switch ($op) {
            case 0:
                unset($h['authorization']);
                break;
            case 1:
                $h['authorization'] = 'Bearer ' . substr($token, 0, -1);
                break;
            case 2:
                $h['authorization'] = 'bearer ' . $token;
                break;
            case 3:
                $h['authorization'] = 'Bearer  ' . $token;
                break;
            case 4:
                $h['authorization'] = 'Bearer ' . $token . ' ';
                break;
            case 5:
                $h['authorization'] = 'Basic ' . base64_encode('a:b');
                break;
            case 6:
                $h['authorization'] = 'Bearer ' . Hostile::string($this->rng);
                break;
            case 7:
                $h['origin'] = $this->rng->pick(['https://evil.test', 'null', 'https://chatgpt.com.evil.test', 'http://localhost:8090', 'http://localhost:8090/', 'https://CHATGPT.com', '', Hostile::string($this->rng)]);
                break;
            case 8:
                $h['mcp-protocol-version'] = $this->rng->pick(['2025-06-18', '2025-11-25', '2026-07-28', '1999-01-01', '', Hostile::string($this->rng)]);
                break;
            case 9:
                $h['content-type'] = $this->rng->pick(['application/json; charset=utf-8', 'APPLICATION/JSON', 'text/plain', 'application/x-www-form-urlencoded', 'application/jsonx', '', 'multipart/form-data']);
                break;
            case 10:
                unset($h['content-type']);
                break;
            case 11:
                $h['authorization'] = 'Bearer ' . str_repeat('A', $this->rng->pick([19, 20, 200, 201, 5000]));
                break;
            case 12:
                $h['authorization'] = 'Bearer ' . strtoupper($token);
                break;
            case 13:
                $h['x-forwarded-for'] = Hostile::string($this->rng);
                break;
            default:
                $h['authorization'] = 'Bearer ' . $this->w->refreshToken; // a refresh token is not an access token
        }
        return $h;
    }

    /** Expired, revoked and cross-grant tokens never work. */
    public function testExpiredRevokedAndForeignTokens(): void
    {
        $iterations = min($this->iterations, 400);
        $mainGrant = $this->w->grantId;
        for ($i = 0; $i < $iterations; $i++) {
            $this->caseNo = $i;
            $this->w->advance(40);
            $this->w->grantId = $mainGrant;
            $ttl = \StretchEffect\OAuth\TokenStore::ACCESS_TTL;
            $tok = $this->w->freshAccessToken();
            $kind = $this->rng->pick(['expiry_edge', 'revoked', 'user_not_owner', 'resource_mismatch']);
            $body = Json::encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);
            $h = $this->authHeaders($tok);
            $expectOk = true;
            switch ($kind) {
                case 'expiry_edge':
                    $edge = $this->rng->pick([-2, -1, 0, 1]);
                    $this->w->advance($ttl + $edge);
                    $expectOk = $edge < 0;
                    break;
                case 'revoked':
                    $g = $this->w->directGrant(['bookings:read']);
                    $tok = $this->w->freshAccessToken($g);
                    $h = $this->authHeaders($tok);
                    $this->w->app->grants()->revoke($g);
                    $expectOk = false;
                    break;
                case 'user_not_owner':
                    $g = $this->w->directGrant(['bookings:read']);
                    $tok = $this->w->freshAccessToken($g);
                    $h = $this->authHeaders($tok);
                    $this->w->app->db()->run("UPDATE users SET role = 'client' WHERE id = ?", [$this->w->owner['id']]);
                    $expectOk = false;
                    break;
                default:
                    $g = $this->w->directGrant(['bookings:read']);
                    $tok = $this->w->freshAccessToken($g);
                    $h = $this->authHeaders($tok);
                    $this->w->app->db()->run('UPDATE oauth_grants SET resource = ? WHERE id = ?', [$this->rng->pick(['http://localhost:8090/mcp/', 'http://localhost:8090/other', 'HTTP://LOCALHOST:8090/mcp', 'http://localhost:8090/MCP']), $g]);
                    $expectOk = false;
            }
            $this->describe('POST', '/mcp', $body, $h);
            $res = $this->w->send(FuzzWorld::request('POST', '/mcp', $h, [], $body));
            if ($kind === 'user_not_owner') {
                $this->w->app->db()->run("UPDATE users SET role = 'owner' WHERE id = ?", [$this->w->owner['id']]);
            }
            if ($expectOk) {
                $this->ensure($res->status === 200, "valid token refused ({$kind}): {$res->status} {$res->body}");
            } else {
                $this->ensure($res->status === 401, "{$kind} token accepted or wrong status: {$res->status} {$res->body}");
                $this->ensure(str_contains($res->headers['WWW-Authenticate'] ?? '', 'resource_metadata='), '401 without resource_metadata');
            }
            $this->tally($kind);
            $this->endCase();
        }
    }
}
