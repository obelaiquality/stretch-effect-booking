<?php
declare(strict_types=1);

namespace StretchEffect\Tests\Support;

use PHPUnit\Framework\TestCase;
use StretchEffect\Contracts\HttpRequest;
use StretchEffect\Contracts\HttpResponse;
use StretchEffect\Mcp\McpEndpoint;
use StretchEffect\OAuth\CimdFetcher;
use StretchEffect\OAuth\OAuthRoutes;

require_once __DIR__ . '/autoload.php';

final class FakeCimdFetcher implements CimdFetcher
{
    /** @var array<string,array<string,mixed>> */
    public array $docs = [];
    public int $calls = 0;
    public function fetch(string $url): array
    {
        $this->calls++;
        if (!isset($this->docs[$url])) {
            throw new \RuntimeException('no such document');
        }
        return $this->docs[$url];
    }
}

/** Shared helpers: build requests, run the OAuth flow against FakeServices, call /mcp. */
abstract class FakeTestCase extends TestCase
{
    protected FakeServices $svc;
    protected FakeCimdFetcher $cimd;
    public const REDIRECT = 'https://chatgpt.com/connector_platform_oauth_redirect';

    protected function setUp(): void
    {
        $this->svc = new FakeServices();
        $this->cimd = new FakeCimdFetcher();
    }

    /** @param array<string,string> $query @param array<string,mixed> $form @param array<string,string> $headers */
    protected function req(string $method, string $path, array $query = [], array $form = [], array $headers = [], string $body = '', string $ip = '203.0.113.9'): HttpRequest
    {
        $h = [];
        foreach ($headers as $k => $v) {
            $h[strtolower($k)] = $v;
        }
        if ($form !== [] && !isset($h['content-type'])) {
            $h['content-type'] = 'application/x-www-form-urlencoded';
        }
        return new HttpRequest($method, $path, $h, $query, $body, $form, $ip);
    }

    protected function oauth(): OAuthRoutes { return new OAuthRoutes($this->cimd); }

    protected function doOauth(HttpRequest $r): HttpResponse
    {
        $resp = $this->oauth()->handle($r, $this->svc);
        $this->assertNotNull($resp, 'route not handled: ' . $r->path);
        return $resp;
    }

    /** @return array<string,mixed> */
    protected function jsonBody(HttpResponse $r): array
    {
        $d = json_decode($r->body, true);
        $this->assertIsArray($d, 'not json: ' . $r->body);
        return $d;
    }

    protected function registerClient(string $name = 'ChatGPT', ?array $redirects = null): string
    {
        $r = $this->doOauth($this->req('POST', '/oauth/register', [], [], ['content-type' => 'application/json'],
            json_encode(['client_name' => $name, 'redirect_uris' => $redirects ?? [self::REDIRECT], 'token_endpoint_auth_method' => 'none'])));
        $this->assertSame(201, $r->status, $r->body);
        return $this->jsonBody($r)['client_id'];
    }

    /** @return array{string,string} verifier, challenge */
    protected function pkce(): array
    {
        $v = rtrim(strtr(base64_encode(random_bytes(40)), '+/', '-_'), '=');
        return [$v, rtrim(strtr(base64_encode(hash('sha256', $v, true)), '+/', '-_'), '=')];
    }

    /** @param array<string,string> $over */
    protected function authorizeQuery(string $clientId, string $challenge, array $over = []): array
    {
        return $over + [
            'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => self::REDIRECT,
            'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'state' => 'st-' . bin2hex(random_bytes(4)),
            'resource' => 'https://studio.test/mcp',
        ];
    }

    /** @return array{req:string,csrf:string} */
    protected function hidden(string $html): array
    {
        preg_match('/name="req" value="([^"]+)"/', $html, $a);
        preg_match('/name="csrf" value="([^"]+)"/', $html, $b);
        $this->assertNotEmpty($a, 'no req field in page');
        return ['req' => html_entity_decode($a[1]), 'csrf' => html_entity_decode($b[1])];
    }

    /** @param array<string,mixed> $over */
    protected function consentForm(array $hidden, array $over = []): array
    {
        $time = $this->svc->database->now->getTimestamp();
        return $over + $hidden + [
            'decision' => 'allow', 'email' => FakeServices::OWNER_EMAIL, 'password' => FakeServices::OWNER_PASSWORD,
            'totp' => FakeTotp::code(FakeServices::TOTP_SECRET, $time), 'scope' => ['bookings:read', 'bookings:write', 'clients:read', 'credits:write'],
        ];
    }

    /** @return array<string,string> query params of a Location header */
    protected function locationParams(HttpResponse $r): array
    {
        $this->assertSame(302, $r->status, $r->body);
        $loc = $r->headers['Location'];
        parse_str((string) parse_url($loc, PHP_URL_QUERY), $q);
        return $q;
    }

    /**
     * Whole authorization-code flow. Returns tokens and bookkeeping.
     * @param list<string>|null $scopes
     * @return array{access:string,refresh:string,client_id:string,verifier:string,grant_id:string}
     */
    protected function connect(?array $scopes = null, ?string $clientId = null): array
    {
        $clientId ??= $this->registerClient();
        [$verifier, $challenge] = $this->pkce();
        $page = $this->doOauth($this->req('GET', '/oauth/authorize', $this->authorizeQuery($clientId, $challenge)));
        $this->assertSame(200, $page->status);
        $form = $this->consentForm($this->hidden($page->body), $scopes !== null ? ['scope' => $scopes] : []);
        $resp = $this->doOauth($this->req('POST', '/oauth/authorize', [], $form));
        $q = $this->locationParams($resp);
        $this->assertArrayHasKey('code', $q, json_encode($q));
        $tok = $this->doOauth($this->req('POST', '/oauth/token', [], [
            'grant_type' => 'authorization_code', 'code' => $q['code'], 'redirect_uri' => self::REDIRECT,
            'client_id' => $clientId, 'code_verifier' => $verifier, 'resource' => 'https://studio.test/mcp',
        ]));
        $this->assertSame(200, $tok->status, $tok->body);
        $j = $this->jsonBody($tok);
        $grant = $this->svc->database->pdo->query('SELECT id FROM oauth_grants ORDER BY created_at DESC, rowid DESC LIMIT 1')->fetchColumn();
        return ['access' => $j['access_token'], 'refresh' => $j['refresh_token'], 'client_id' => $clientId, 'verifier' => $verifier, 'grant_id' => (string) $grant];
    }

    /** @param array<string,string> $headers */
    protected function mcpRaw(?string $token, string $body, array $headers = [], string $method = 'POST'): HttpResponse
    {
        $h = $headers + ['content-type' => 'application/json'];
        if ($token !== null) {
            $h['authorization'] = 'Bearer ' . $token;
        }
        $resp = (new McpEndpoint())->handle($this->req($method, '/mcp', [], [], $h, $body), $this->svc);
        $this->assertNotNull($resp);
        return $resp;
    }

    /** @param array<string,mixed> $params @return array<string,mixed> decoded JSON-RPC response */
    protected function rpc(string $token, string $method, array $params = [], int $id = 1): array
    {
        $r = $this->mcpRaw($token, json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => (object) $params]));
        return $this->jsonBody($r);
    }

    /** @param array<string,mixed> $args @return array<string,mixed> the tool result */
    protected function call(string $token, string $tool, array $args = []): array
    {
        $d = $this->rpc($token, 'tools/call', ['name' => $tool, 'arguments' => (object) $args]);
        $this->assertArrayHasKey('result', $d, json_encode($d));
        return $d['result'];
    }

    protected function seedClientCredits(int $n): void
    {
        $this->svc->creditSvc->adjust($this->svc->clientId, $n, 'owner_adjust', 'seed', 'seed');
    }
}
