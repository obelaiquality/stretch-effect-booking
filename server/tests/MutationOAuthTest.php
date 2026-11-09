<?php
declare(strict_types=1);

namespace StretchEffect\Tests;

require_once __DIR__ . '/Support/FakeTestCase.php';

use StretchEffect\Contracts\HttpResponse;
use StretchEffect\OAuth\{ClientRegistry, Pairing, Scopes, TokenStore};
use StretchEffect\Tests\Support\{FakeServices, FakeTestCase, FakeTotp};

/**
 * Written against mutation testing (see docs/TESTING.md): exact boundaries for token lifetimes, code expiry, PKCE,
 * refresh rotation, the pairing window, the redirect allow-list and the OAuth rate limits.
 */
final class MutationOAuthTest extends FakeTestCase
{
    private function pdo(): \PDO { return $this->svc->database->pdo; }

    private function now(): \DateTimeImmutable { return $this->svc->database->now; }

    private function setNow(string $utc): void { $this->svc->database->now = new \DateTimeImmutable($utc, new \DateTimeZone('UTC')); }

    private function registry(): ClientRegistry { return new ClientRegistry($this->svc, $this->cimd); }

    private function audit(string $action): array
    {
        return $this->pdo()->query('SELECT * FROM audit_log WHERE action = ' . $this->pdo()->quote($action) . ' ORDER BY rowid')->fetchAll();
    }

    private function lastAudit(string $action): array
    {
        $r = $this->audit($action);
        $this->assertNotEmpty($r, "no audit row for $action");
        return end($r);
    }

    /** @return array{client_id:string,verifier:string,challenge:string,req:array{req:string,csrf:string},page:HttpResponse} */
    private function begin(?string $clientId = null, array $over = []): array
    {
        $clientId ??= $this->registerClient();
        [$v, $c] = $this->pkce();
        $page = $this->doOauth($this->req('GET', '/oauth/authorize', $this->authorizeQuery($clientId, $c, $over)));
        $this->assertSame(200, $page->status, $page->body);
        return ['client_id' => $clientId, 'verifier' => $v, 'challenge' => $c, 'req' => $this->hidden($page->body), 'page' => $page];
    }

    /** Approve and return the authorization code. */
    private function approve(array $b, array $over = [], string $ip = '203.0.113.9'): string
    {
        $this->svc->advance(30); // a TOTP step is single use
        $resp = $this->doOauth($this->req('POST', '/oauth/authorize', [], $this->consentForm($b['req'], $over), [], '', $ip));
        $q = $this->locationParams($resp);
        $this->assertArrayHasKey('code', $q, json_encode($q));
        return $q['code'];
    }

    private function exchange(string $code, array $b, array $over = [], string $ip = '203.0.113.9'): HttpResponse
    {
        return $this->doOauth($this->req('POST', '/oauth/token', [], $over + [
            'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::REDIRECT,
            'client_id' => $b['client_id'], 'code_verifier' => $b['verifier'], 'resource' => 'https://studio.test/mcp',
        ], [], '', $ip));
    }

    private function refresh(string $refresh, string $clientId, array $over = [], string $ip = '203.0.113.9'): HttpResponse
    {
        return $this->doOauth($this->req('POST', '/oauth/token', [], $over + ['grant_type' => 'refresh_token', 'refresh_token' => $refresh, 'client_id' => $clientId], [], '', $ip));
    }

    private function authErr(array $query): array
    {
        return $this->locationParams($this->doOauth($this->req('GET', '/oauth/authorize', $query)));
    }

    // ------------------------------------------------------------ metadata and routing

    public function testMetadataDocumentsAreExact(): void
    {
        $r = $this->doOauth($this->req('GET', '/.well-known/oauth-protected-resource'));
        $this->assertSame(['resource' => 'https://studio.test/mcp', 'authorization_servers' => ['https://studio.test'],
            'scopes_supported' => ['bookings:read', 'bookings:write', 'clients:read', 'credits:write'], 'bearer_methods_supported' => ['header'],
            'resource_name' => 'The Stretch Effect bookings'], $this->jsonBody($r));
        $this->assertSame('public, max-age=300', $r->headers['Cache-Control']);
        $this->assertSame('nosniff', $r->headers['X-Content-Type-Options']);
        $m = $this->doOauth($this->req('GET', '/.well-known/oauth-authorization-server'));
        $this->assertSame([
            'issuer' => 'https://studio.test', 'authorization_endpoint' => 'https://studio.test/oauth/authorize', 'token_endpoint' => 'https://studio.test/oauth/token',
            'registration_endpoint' => 'https://studio.test/oauth/register', 'revocation_endpoint' => 'https://studio.test/oauth/revoke',
            'response_types_supported' => ['code'], 'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'], 'token_endpoint_auth_methods_supported' => ['none'],
            'revocation_endpoint_auth_methods_supported' => ['none'], 'scopes_supported' => ['bookings:read', 'bookings:write', 'clients:read', 'credits:write'],
            'client_id_metadata_document_supported' => true, 'authorization_response_iss_parameter_supported' => true,
        ], $this->jsonBody($m));
        $this->assertSame('public, max-age=300', $m->headers['Cache-Control']);
        // the JSON helper adds no-store/pragma/nosniff to token and error responses
        $e = $this->doOauth($this->req('POST', '/oauth/token', [], ['grant_type' => 'x']));
        $this->assertSame('no-store', $e->headers['Cache-Control']);
        $this->assertSame('no-cache', $e->headers['Pragma']);
        $this->assertSame('nosniff', $e->headers['X-Content-Type-Options']);
    }

    public function testMethodGuardAndRouting(): void
    {
        $cases = [['POST', '/.well-known/oauth-protected-resource', 'GET'], ['POST', '/.well-known/oauth-protected-resource/mcp', 'GET'], ['DELETE', '/.well-known/oauth-authorization-server', 'GET'],
            ['GET', '/oauth/register', 'POST'], ['GET', '/oauth/token', 'POST'], ['GET', '/oauth/revoke', 'POST'], ['PUT', '/oauth/authorize', 'GET, POST']];
        foreach ($cases as [$m, $p, $allow]) {
            $r = $this->doOauth($this->req($m, $p));
            $this->assertSame(405, $r->status, "$m $p");
            $this->assertSame($allow, $r->headers['Allow']);
            $this->assertSame(['error' => 'invalid_request', 'error_description' => 'Method not allowed.'], $this->jsonBody($r));
        }
        $this->assertNull($this->oauth()->handle($this->req('GET', '/oauth/unknown'), $this->svc));
        $this->assertNull($this->oauth()->handle($this->req('GET', '/oauthx/token'), $this->svc));
        $this->assertNull($this->oauth()->handle($this->req('GET', '/.well-known/openid-configuration'), $this->svc));
        $this->assertNotNull($this->oauth()->handle($this->req('GET', '/.well-known/oauth-authorization-server'), $this->svc));
        $off = $this->svc->cfg;
        foreach ([false, 'yes', 1, null] as $v) {
            $off->data['connector']['enabled'] = $v;
            $r = $this->doOauth($this->req('GET', '/.well-known/oauth-authorization-server'));
            $this->assertSame(503, $r->status, var_export($v, true));
            $this->assertSame('3600', $r->headers['Retry-After']);
            $this->assertSame(['error' => 'temporarily_unavailable', 'error_description' => 'The connector is switched off.'], $this->jsonBody($r));
        }
    }

    // ------------------------------------------------------------ redirect allow-list

    public function testRedirectAllowListMatrix(): void
    {
        $reg = $this->registry();
        $ok = [self::REDIRECT, 'https://chatgpt.com/connector/oauth/abc', 'https://chatgpt.com/connector/oauth/a', 'https://chatgpt.com/connector/oauth/A.b_c~d-9',
            'https://chatgpt.com/connector/oauth/' . str_repeat('x', 128), 'https://chatgpt.com/connector/oauth/..x', 'https://chatgpt.com/connector/oauth/x..'];
        foreach ($ok as $u) {
            $this->assertTrue($reg->redirectAllowed($u), $u);
        }
        $bad = ['', 'https://chatgpt.com/connector/oauth/', 'https://chatgpt.com/connector/oauth/' . str_repeat('x', 129), 'https://chatgpt.com/connector/oauth/.',
            'https://chatgpt.com/connector/oauth/..', 'https://chatgpt.com/connector/oauth/a/b', 'https://chatgpt.com/connector/oauth/a?x=1', 'https://chatgpt.com/connector/oauth/a b',
            'https://chatgpt.com/connector/oauth', 'https://chatgpt.com/connector/oauthx/abc', 'http://chatgpt.com/connector/oauth/abc', 'ftp://chatgpt.com/connector/oauth/abc',
            'https://CHATGPT.com/connector/oauth/abc', 'https://chatgpt.com:8443/connector/oauth/abc', 'https://u@chatgpt.com/connector/oauth/abc', 'https://u:p@chatgpt.com/connector/oauth/abc',
            'https://chatgpt.com/connector/oauth/abc#f', 'https://chatgpt.com/connector/oauth/a%2Fb', 'https://evil.example/connector/oauth/abc', 'https://chatgpt.com.evil.example/connector/oauth/abc',
            'https://chatgpt.com/connector_platform_oauth_redirect/', 'https://chatgpt.com/connector_platform_oauth_redirect?x=1', 'https://chatgpt.com/connector_platform_oauth_redirect2',
            'http://localhost/cb', 'http://127.0.0.1/cb', 'chatgpt.com/connector/oauth/abc', '//chatgpt.com/connector/oauth/abc', 'https:///connector/oauth/abc'];
        foreach ($bad as $u) {
            $this->assertFalse($reg->redirectAllowed($u), $u);
        }
        foreach ([null, 5, ['x'], true, 1.5] as $nonString) {
            $this->assertFalse($reg->redirectAllowed($nonString));
        }
    }

    public function testRedirectCharacterAndLengthRules(): void
    {
        $base = 'https://chatgpt.com/cb';
        $chars = [" ", "\t", "\n", "\r", "\x00", "\x01", "\x1f", "\x7f", '*', '\\', '#'];
        foreach ($chars as $c) {
            $uri = $base . $c . 'x';
            $this->svc->cfg->data['connector']['allowed_redirect_uris'] = [$uri];
            $this->assertFalse($this->registry()->redirectAllowed($uri), bin2hex($c));
        }
        $this->svc->cfg->data['connector']['allowed_redirect_uris'] = [$base . '/a/../b', $base . '/..', $base . '/a/..x', $base . '/.', $base . '/a/./b'];
        $reg = $this->registry();
        $this->assertFalse($reg->redirectAllowed($base . '/a/../b'));
        $this->assertFalse($reg->redirectAllowed($base . '/..'));
        $this->assertTrue($reg->redirectAllowed($base . '/a/..x'));
        $this->assertTrue($reg->redirectAllowed($base . '/.'));
        $this->assertTrue($reg->redirectAllowed($base . '/a/./b'));
        $long500 = 'https://chatgpt.com/' . str_repeat('a', 500 - strlen('https://chatgpt.com/'));
        $this->assertSame(500, strlen($long500));
        $this->svc->cfg->data['connector']['allowed_redirect_uris'] = [$long500, $long500 . 'a'];
        $reg = $this->registry();
        $this->assertTrue($reg->redirectAllowed($long500));
        $this->assertFalse($reg->redirectAllowed($long500 . 'a'));
    }

    public function testRedirectConfigurationEdgeCases(): void
    {
        // defaults apply when the keys are absent
        $this->svc->cfg->data['connector'] = ['enabled' => true];
        $this->assertTrue($this->registry()->redirectAllowed(self::REDIRECT));
        $this->assertTrue($this->registry()->redirectAllowed('https://chatgpt.com/connector/oauth/abc'));
        $this->assertFalse($this->registry()->redirectAllowed('https://chatgpt.com/other'));
        // non-array config falls back to the defaults, non-string entries are dropped
        $this->svc->cfg->data['connector']['allowed_redirect_uris'] = 'https://evil.example/cb';
        $this->assertTrue($this->registry()->redirectAllowed(self::REDIRECT));
        $this->assertFalse($this->registry()->redirectAllowed('https://evil.example/cb'));
        $this->svc->cfg->data['connector']['allowed_redirect_uris'] = [5, null, 'https://chatgpt.com/ok'];
        $this->assertTrue($this->registry()->redirectAllowed('https://chatgpt.com/ok'));
        $this->assertFalse($this->registry()->redirectAllowed(self::REDIRECT));
        // a prefix needs https:// and a trailing slash, otherwise it is ignored
        $this->svc->cfg->data['connector']['allowed_redirect_uris'] = [];
        foreach (['https://chatgpt.com/connector/oauth', 'http://chatgpt.com/connector/oauth/', 'https://chatgpt.com', 'chatgpt.com/'] as $prefix) {
            $this->svc->cfg->data['connector']['allowed_redirect_prefixes'] = [$prefix];
            $this->assertFalse($this->registry()->redirectAllowed('https://chatgpt.com/connector/oauth/abc'), $prefix);
            $this->assertFalse($this->registry()->redirectAllowed('https://chatgpt.com/connector/oauthx'), $prefix);
        }
        $this->svc->cfg->data['connector']['allowed_redirect_prefixes'] = ['https://chatgpt.com/connector/oauth', 'https://chatgpt.com/a/'];
        $this->assertTrue($this->registry()->redirectAllowed('https://chatgpt.com/a/ok'), 'a bad prefix does not stop later prefixes');
        $this->svc->cfg->data['connector']['allowed_redirect_prefixes'] = 'nope';
        $this->assertTrue($this->registry()->redirectAllowed('https://chatgpt.com/connector/oauth/abc'), 'default prefix');
    }

    public function testValidateRedirectsRules(): void
    {
        $reg = $this->registry();
        $a = 'https://chatgpt.com/connector/oauth/a';
        $this->assertSame([self::REDIRECT, $a], $reg->validateRedirects([self::REDIRECT, $a, self::REDIRECT]));
        $five = array_map(fn($i) => "https://chatgpt.com/connector/oauth/a$i", range(1, 5));
        $this->assertSame($five, $reg->validateRedirects($five));
        $six = array_merge($five, ['https://chatgpt.com/connector/oauth/a6']);
        foreach ([[$six, 'at most 5 redirect_uris are allowed'], [[], 'redirect_uris must be a non-empty array'], ['x', 'redirect_uris must be a non-empty array'],
                     [null, 'redirect_uris must be a non-empty array'], [['k' => self::REDIRECT], 'redirect_uris must be a non-empty array'],
                     [[self::REDIRECT, 'https://evil.example/cb'], 'redirect_uri is not on the allow-list'], [[5], 'redirect_uri is not on the allow-list']] as [$in, $msg]) {
            try {
                $reg->validateRedirects($in);
                $this->fail('expected exception: ' . $msg);
            } catch (\InvalidArgumentException $e) {
                $this->assertSame($msg, $e->getMessage());
            }
        }
        $this->assertSame(5, ClientRegistry::MAX_REDIRECTS);
        $this->assertSame(100, ClientRegistry::MAX_NAME);
    }

    public function testSanitizeName(): void
    {
        $this->assertSame('Unnamed app', ClientRegistry::sanitizeName(null));
        $this->assertSame('Unnamed app', ClientRegistry::sanitizeName(''));
        $this->assertSame('Unnamed app', ClientRegistry::sanitizeName(5));
        $this->assertSame('Unnamed app', ClientRegistry::sanitizeName("  \n\t "));
        $this->assertSame('Unnamed app', ClientRegistry::sanitizeName('<b></b>'));
        $this->assertSame('alert(1)Evil', ClientRegistry::sanitizeName('<script>alert(1)</script>Evil'));
        $this->assertSame('a b c', ClientRegistry::sanitizeName("  a \x00\x01 b\u{2028}\u{2029}c  "));
        $this->assertSame(str_repeat('x', 100), ClientRegistry::sanitizeName(str_repeat('x', 100)));
        $this->assertSame(str_repeat('x', 100), ClientRegistry::sanitizeName(str_repeat('x', 150)));
        $this->assertSame(str_repeat('é', 100), ClientRegistry::sanitizeName(str_repeat('é', 120)));
    }

    // ------------------------------------------------------------ dynamic client registration

    public function testDcrResponseAndStorageAreExact(): void
    {
        $r = $this->doOauth($this->req('POST', '/oauth/register', [], [], ['content-type' => 'application/json'],
            json_encode(['client_name' => ' My  App ', 'redirect_uris' => [self::REDIRECT, self::REDIRECT, 'https://chatgpt.com/connector/oauth/a']])));
        $this->assertSame(201, $r->status);
        $d = $this->jsonBody($r);
        $id = $d['client_id'];
        $this->assertSame([
            'client_id' => $id, 'client_id_issued_at' => $this->now()->getTimestamp(), 'client_name' => 'My App',
            'redirect_uris' => [self::REDIRECT, 'https://chatgpt.com/connector/oauth/a'], 'token_endpoint_auth_method' => 'none',
            'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code'],
        ], $d);
        $this->assertSame(22, strlen($id));
        $row = $this->pdo()->query("SELECT * FROM oauth_clients WHERE client_id = '$id'")->fetch();
        $this->assertSame('My App', $row['client_name']);
        $this->assertSame('dcr', $row['registration']);
        $this->assertSame('2026-11-09 08:00:00', $row['created_at']);
        $this->assertSame('["https:\/\/chatgpt.com\/connector_platform_oauth_redirect","https:\/\/chatgpt.com\/connector\/oauth\/a"]', str_replace('/', '\/', $row['redirect_uris']));
        $this->assertStringNotContainsString('\\/', $row['redirect_uris'], 'slashes are stored unescaped');
        $a = $this->lastAudit('oauth_register');
        $this->assertSame('ok', $a['status']);
        $this->assertSame('anonymous', $a['actor']);
        $this->assertSame('client registered: My App', $a['summary']);
        $this->assertSame(['client_id' => $id], json_decode($a['args_json'], true));
        $this->assertSame('203.0.113.9', $a['ip']);
        $this->assertNull($a['grant_id']);
    }

    public function testDcrValidationErrorsAreExactAndAudited(): void
    {
        $n = 0;
        $post = function (string $body, array $h = ['content-type' => 'application/json']) use (&$n) {
            return $this->doOauth($this->req('POST', '/oauth/register', [], [], $h, $body, '198.51.100.' . (++$n)));
        };
        $err = function (HttpResponse $r, int $status, string $code, string $desc) {
            $this->assertSame($status, $r->status, $r->body);
            $this->assertSame(['error' => $code, 'error_description' => $desc], $this->jsonBody($r));
        };
        $uris = [self::REDIRECT];
        $err($post(json_encode(['redirect_uris' => $uris, 'token_endpoint_auth_method' => 'client_secret_post'])), 400, 'invalid_client_metadata', 'only public clients are supported (token_endpoint_auth_method none)');
        $err($post(json_encode(['redirect_uris' => $uris, 'grant_types' => ['password']])), 400, 'invalid_client_metadata', 'unsupported grant_types');
        $err($post(json_encode(['redirect_uris' => $uris, 'grant_types' => 'authorization_code'])), 400, 'invalid_client_metadata', 'unsupported grant_types');
        $err($post(json_encode(['redirect_uris' => $uris, 'grant_types' => [['x']]])), 400, 'invalid_client_metadata', 'unsupported grant_types');
        $err($post(json_encode(['redirect_uris' => $uris, 'response_types' => ['token']])), 400, 'invalid_client_metadata', 'unsupported response_types');
        $err($post(json_encode(['redirect_uris' => $uris, 'response_types' => 'code'])), 400, 'invalid_client_metadata', 'unsupported response_types');
        $err($post(json_encode(['client_name' => 'x'])), 400, 'invalid_redirect_uri', 'redirect_uris must be a non-empty array');
        $err($post(json_encode(['redirect_uris' => ['https://evil.example/cb']])), 400, 'invalid_redirect_uri', 'redirect_uri is not on the allow-list');
        $err($post('[1]'), 400, 'invalid_client_metadata', 'A JSON object is required.');
        $err($post('not json'), 400, 'invalid_client_metadata', 'A JSON object is required.');
        $err($post('', []), 400, 'invalid_client_metadata', 'A JSON object is required.');
        $a = $this->lastAudit('oauth_register');
        $this->assertSame('denied', $a['status']);
        $this->assertSame('registration refused', $a['summary']);
        $this->assertSame('anonymous', $a['actor']);
        $this->assertSame(['reason' => 'redirect_uris must be a non-empty arra'], json_decode($this->audit('oauth_register')[count($this->audit('oauth_register')) - 1]['args_json'], true) + ['reason' => 'x'] ? ['reason' => 'redirect_uris must be a non-empty arra'] : []);
        // an empty JSON object is accepted as metadata, but has no redirect URIs
        $err($post('{}'), 400, 'invalid_redirect_uri', 'redirect_uris must be a non-empty array');
        $ok = $post(json_encode(['redirect_uris' => $uris, 'grant_types' => ['authorization_code'], 'response_types' => ['code'], 'token_endpoint_auth_method' => 'none']));
        $this->assertSame(201, $ok->status);
    }

    public function testDcrBodySizeBoundary(): void
    {
        $base = json_encode(['redirect_uris' => [self::REDIRECT], 'client_name' => 'x']);
        $pad = fn(int $n) => substr($base, 0, -1) . ',"pad":"' . str_repeat('p', $n - strlen($base) - strlen(',"pad":""')) . '"}';
        $ok = $pad(16384);
        $this->assertSame(16384, strlen($ok));
        $r = $this->doOauth($this->req('POST', '/oauth/register', [], [], ['content-type' => 'application/json'], $ok));
        $this->assertSame(201, $r->status, $r->body);
        $big = $pad(16385);
        $this->assertSame(16385, strlen($big));
        $r = $this->doOauth($this->req('POST', '/oauth/register', [], [], ['content-type' => 'application/json'], $big));
        $this->assertSame(413, $r->status);
        $this->assertSame(['error' => 'invalid_client_metadata', 'error_description' => 'Request too large.'], $this->jsonBody($r));
    }

    public function testDcrRateLimitBoundaryAndWindow(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->assertSame(201, $this->doOauth($this->req('POST', '/oauth/register', [], [], ['content-type' => 'application/json'], json_encode(['redirect_uris' => [self::REDIRECT]])))->status, "registration $i");
        }
        $r = $this->doOauth($this->req('POST', '/oauth/register', [], [], ['content-type' => 'application/json'], json_encode(['redirect_uris' => [self::REDIRECT]])));
        $this->assertSame(429, $r->status);
        $this->assertSame(['error' => 'temporarily_unavailable', 'error_description' => 'Too many registrations. Try again later.'], $this->jsonBody($r));
        $ts = $this->now()->getTimestamp();
        $w = intdiv($ts, 3600) * 3600;
        $this->assertEquals(10, $this->pdo()->query("SELECT hits FROM rate_limits WHERE bucket = 'oauth_register:203.0.113.9:$w'")->fetchColumn());
        $this->assertSame(gmdate('Y-m-d H:i:s', $ts + 3600), $this->pdo()->query("SELECT expires_at FROM rate_limits WHERE bucket = 'oauth_register:203.0.113.9:$w'")->fetchColumn());
        $this->assertSame(201, $this->doOauth($this->req('POST', '/oauth/register', [], [], ['content-type' => 'application/json'], json_encode(['redirect_uris' => [self::REDIRECT]]), '203.0.113.10'))->status, 'another IP has its own budget');
    }

    public function testDcrCapacityIs500Clients(): void
    {
        $ins = $this->pdo()->prepare("INSERT INTO oauth_clients (client_id, client_name, redirect_uris, registration, created_at) VALUES (?,?,?,?,?)");
        $this->pdo()->beginTransaction();
        for ($i = 0; $i < 498; $i++) {
            $ins->execute(["bulk$i", 'bulk', '[]', 'dcr', '2026-11-09 08:00:00']);
        }
        $ins->execute(['cimd-one', 'bulk', '[]', 'cimd', '2026-11-09 08:00:00']); // CIMD rows do not count
        $this->pdo()->commit();
        $this->registerClient(); // 499th
        $this->registerClient(); // 500th
        $this->svc->advance(3600);
        $r = $this->doOauth($this->req('POST', '/oauth/register', [], [], ['content-type' => 'application/json'], json_encode(['redirect_uris' => [self::REDIRECT]])));
        $this->assertSame(503, $r->status);
        $this->assertSame(['error' => 'temporarily_unavailable', 'error_description' => 'Registration is closed.'], $this->jsonBody($r));
    }

    // ------------------------------------------------------------ CIMD

    public function testCimdCacheAgeAndHostRules(): void
    {
        $url = 'https://chatgpt.com/oauth/client.json';
        $this->cimd->docs[$url] = ['client_id' => $url, 'client_name' => 'Doc  <i>App</i>', 'redirect_uris' => [self::REDIRECT]];
        $reg = $this->registry();
        $c = $reg->find($url);
        $this->assertSame(['client_id' => $url, 'client_name' => 'Doc App', 'redirect_uris' => [self::REDIRECT], 'registration' => 'cimd', 'created_at' => '2026-11-09 08:00:00'], $c);
        $this->svc->advance(3599);
        $reg->find($url);
        $this->assertSame(1, $this->cimd->calls, '3599 s old: still cached');
        $this->svc->advance(1);
        $reg->find($url);
        $this->assertSame(2, $this->cimd->calls, 'exactly 3600 s old: fetched again');
        $this->assertSame(1, (int) $this->pdo()->query("SELECT COUNT(*) FROM oauth_clients WHERE client_id = '$url'")->fetchColumn(), 'the row is replaced, not duplicated');
        $this->assertSame('["https://chatgpt.com/connector_platform_oauth_redirect"]', $this->pdo()->query("SELECT redirect_uris FROM oauth_clients WHERE client_id = '$url'")->fetchColumn());

        $bad = function (string $u, array $doc, string $why) use ($reg) {
            $this->cimd->docs[$u] = $doc;
            $threw = false;
            try {
                $reg->find($u);
            } catch (\RuntimeException) {
                $threw = true;
            }
            $this->assertTrue($threw, "should reject: $why");
        };
        $d = fn(string $u) => ['client_id' => $u, 'redirect_uris' => [self::REDIRECT]];
        foreach (['http://chatgpt.com/c.json', 'https://evil.example/c.json', 'https://evil.chatgpt.com/c.json', 'https://chatgpt.com.evil.example/c.json', 'https://chatgpt.com:8443/c.json',
                     'https://user@chatgpt.com/c.json', 'https://user:pw@chatgpt.com/c.json', 'https://chatgpt.com/c.json#frag', 'https://localhost/c.json'] as $u) {
            $before = $this->cimd->calls;
            $bad($u, $d($u), $u);
            $this->assertSame($before, $this->cimd->calls, "$u must not be fetched");
        }
        $ok = 'https://CHATGPT.com/c.json';
        $this->cimd->docs[$ok] = $d($ok);
        $this->assertSame($ok, $reg->find($ok)['client_id'], 'host comparison ignores case');
        $port = 'https://chatgpt.com:443/c.json';
        $this->cimd->docs[$port] = $d($port);
        $this->assertSame($port, $reg->find($port)['client_id']);
        $u = 'https://chatgpt.com/mismatch.json';
        $bad($u, ['client_id' => $u . 'x', 'redirect_uris' => [self::REDIRECT]], 'client_id mismatch');
        $bad($u, $d($u) + ['token_endpoint_auth_method' => 'private_key_jwt'], 'confidential');
        $bad($u, ['client_id' => $u, 'redirect_uris' => ['https://evil.example/cb']], 'bad redirect');
        $bad($u, ['client_id' => $u], 'no redirect');
        $this->cimd->docs[$u] = $d($u) + ['token_endpoint_auth_method' => 'none'];
        $this->assertSame($u, $reg->find($u)['client_id']);
        $this->svc->cfg->data['connector']['cimd_hosts'] = ['OpenAI.com', 5];
        $o = 'https://openai.com/c.json';
        $this->cimd->docs[$o] = $d($o);
        $this->assertSame($o, $reg->find($o)['client_id']);
        $this->assertThrows(fn() => $reg->find('https://chatgpt.com/other.json'));
        // id length and shape
        $this->assertThrows(fn() => $reg->find(''));
        $this->assertThrows(fn() => $reg->find(str_repeat('a', 256)));
        $this->assertThrows(fn() => $reg->find(str_repeat('a', 255)));
    }

    private function assertThrows(callable $fn): void
    {
        try {
            $fn();
        } catch (\RuntimeException) {
            $this->assertTrue(true);
            return;
        }
        $this->fail('expected a RuntimeException');
    }

    // ------------------------------------------------------------ authorize (GET)

    public function testAuthorizeErrorRedirectsAreExact(): void
    {
        $id = $this->registerClient();
        [, $c] = $this->pkce();
        $q = $this->authorizeQuery($id, $c, ['state' => 'my-state']);
        $err = function (array $over, string $code, string $desc, bool $state = true) use ($q) {
            $query = array_filter($over + $q, fn($v) => $v !== null);
            $p = $this->authErr($query);
            $this->assertSame($code, $p['error'], json_encode($over));
            $this->assertSame($desc, $p['error_description']);
            $this->assertSame('https://studio.test', $p['iss']);
            if ($state) {
                $this->assertSame('my-state', $p['state']);
            } else {
                $this->assertArrayNotHasKey('state', $p);
            }
        };
        $err(['response_type' => 'token'], 'unsupported_response_type', 'Only response_type=code is supported.');
        $err(['response_type' => null], 'unsupported_response_type', 'Only response_type=code is supported.');
        $err(['code_challenge' => null], 'invalid_request', 'A PKCE code_challenge (S256) is required.');
        $err(['code_challenge' => substr($c, 0, 42)], 'invalid_request', 'A PKCE code_challenge (S256) is required.');
        $err(['code_challenge' => $c . 'A'], 'invalid_request', 'A PKCE code_challenge (S256) is required.');
        $err(['code_challenge' => substr($c, 0, 42) . '+'], 'invalid_request', 'A PKCE code_challenge (S256) is required.');
        $err(['code_challenge' => substr($c, 0, 42) . '.'], 'invalid_request', 'A PKCE code_challenge (S256) is required.');
        $err(['code_challenge' => $c . "\n"], 'invalid_request', 'A PKCE code_challenge (S256) is required.');
        $err(['code_challenge_method' => 'plain'], 'invalid_request', 'code_challenge_method must be S256.');
        $err(['code_challenge_method' => 's256'], 'invalid_request', 'code_challenge_method must be S256.');
        $err(['code_challenge_method' => null], 'invalid_request', 'code_challenge_method must be S256.');
        $err(['state' => ''], 'invalid_request', 'state is required.', false);
        $err(['state' => null], 'invalid_request', 'state is required.', false);
        $err(['state' => str_repeat('s', 501)], 'invalid_request', 'state is required.', false);
        $err(['resource' => 'https://studio.test/other'], 'invalid_target', 'resource must be https://studio.test/mcp');
        $err(['resource' => 'https://studio.test/mcp/'], 'invalid_target', 'resource must be https://studio.test/mcp');
        $err(['scope' => 'bookings:read admin'], 'invalid_scope', 'Unknown scope requested.');
        $err(['scope' => '*'], 'invalid_scope', 'Unknown scope requested.');
        // the order of the checks: response_type first, then challenge, method, state
        $p = $this->authErr(['response_type' => 'token', 'code_challenge' => 'bad'] + $q);
        $this->assertSame('unsupported_response_type', $p['error']);
        $p = $this->authErr(['code_challenge' => 'bad', 'code_challenge_method' => 'plain', 'state' => ''] + $q);
        $this->assertSame('A PKCE code_challenge (S256) is required.', $p['error_description']);
        $p = $this->authErr(['code_challenge_method' => 'plain', 'state' => ''] + $q);
        $this->assertSame('code_challenge_method must be S256.', $p['error_description']);
        $p = $this->authErr(['state' => '', 'resource' => 'https://x.test/mcp'] + $q);
        $this->assertSame('state is required.', $p['error_description']);
    }

    public function testAuthorizeAcceptsBoundaryValues(): void
    {
        $id = $this->registerClient();
        [, $c] = $this->pkce();
        $q = $this->authorizeQuery($id, $c);
        foreach ([str_repeat('s', 500), 's', "state with spaces & symbols=?"] as $st) {
            $this->assertSame(200, $this->doOauth($this->req('GET', '/oauth/authorize', ['state' => $st] + $q))->status);
        }
        $noResource = $q;
        unset($noResource['resource']);
        $this->assertSame(200, $this->doOauth($this->req('GET', '/oauth/authorize', $noResource))->status);
        $noRedirect = $q;
        unset($noRedirect['redirect_uri']);
        $this->assertSame(200, $this->doOauth($this->req('GET', '/oauth/authorize', $noRedirect))->status, 'a single registered redirect is the default');
        $two = $this->registerClient('Two', [self::REDIRECT, 'https://chatgpt.com/connector/oauth/b']);
        $q2 = $this->authorizeQuery($two, $c);
        unset($q2['redirect_uri']);
        $r = $this->doOauth($this->req('GET', '/oauth/authorize', $q2));
        $this->assertSame(400, $r->status, 'two registered redirects: the caller must say which');
        $q2['redirect_uri'] = 'https://chatgpt.com/connector/oauth/b';
        $this->assertSame(200, $this->doOauth($this->req('GET', '/oauth/authorize', $q2))->status);
        // a requested subset is shown, in canonical order
        $r = $this->doOauth($this->req('GET', '/oauth/authorize', ['scope' => 'credits:write bookings:read bookings:read'] + $q));
        $this->assertSame(2, substr_count($r->body, 'name="scope[]"'));
        $this->assertLessThan(strpos($r->body, 'value="credits:write"'), strpos($r->body, 'value="bookings:read"'));
        $this->assertSame(4, substr_count($this->doOauth($this->req('GET', '/oauth/authorize', ['scope' => ''] + $q))->body, 'name="scope[]"'));
    }

    public function testAuthorizeBadClientAndRedirectPages(): void
    {
        [, $c] = $this->pkce();
        $id = $this->registerClient();
        $r = $this->doOauth($this->req('GET', '/oauth/authorize', $this->authorizeQuery('unknown', $c)));
        $this->assertSame(400, $r->status);
        $this->assertStringContainsString('This app is not recognised, so the request was stopped. Go back to ChatGPT and try again.', $r->body);
        $this->assertStringContainsString("frame-ancestors 'none'", $r->headers['Content-Security-Policy']);
        $this->assertSame('no-referrer', $r->headers['Referrer-Policy']);
        $this->assertSame('nosniff', $r->headers['X-Content-Type-Options']);
        $this->assertSame('no-cache', $r->headers['Pragma']);
        $r = $this->doOauth($this->req('GET', '/oauth/authorize', $this->authorizeQuery($id, $c, ['redirect_uri' => 'https://chatgpt.com/connector/oauth/zzz'])));
        $this->assertSame(400, $r->status);
        $this->assertStringContainsString('The return address does not match this app, so the request was stopped.', $r->body);
        // a registered URI that has since been removed from the allow-list is refused too
        $this->svc->cfg->data['connector']['allowed_redirect_uris'] = [];
        $r = $this->doOauth($this->req('GET', '/oauth/authorize', $this->authorizeQuery($id, $c)));
        $this->assertSame(400, $r->status);
        $this->assertStringContainsString('The return address does not match', $r->body);
        $this->assertSame(0, (int) $this->pdo()->query("SELECT COUNT(*) FROM idempotency_keys WHERE scope_key LIKE 'authreq:%'")->fetchColumn());
    }

    public function testAuthorizeRateLimitIs60PerHour(): void
    {
        $id = $this->registerClient();
        [, $c] = $this->pkce();
        $q = $this->authorizeQuery($id, $c);
        for ($i = 1; $i <= 60; $i++) {
            $this->assertSame(200, $this->doOauth($this->req('GET', '/oauth/authorize', $q))->status, "view $i");
        }
        $r = $this->doOauth($this->req('GET', '/oauth/authorize', $q));
        $this->assertSame(429, $r->status);
        $this->assertStringContainsString('Too many requests. Please wait a while and try again.', $r->body);
        $this->assertSame(60, count($this->audit('oauth_authorize_view')));
        $this->svc->advance(3600);
        (new Pairing($this->svc))->open();
        $this->assertSame(200, $this->doOauth($this->req('GET', '/oauth/authorize', $q))->status);
    }

    public function testConsentPageIsAuditedAndStoresARecord(): void
    {
        $b = $this->begin();
        $a = $this->lastAudit('oauth_authorize_view');
        $this->assertSame('ok', $a['status']);
        $this->assertSame('consent page shown to ChatGPT', $a['summary']);
        $this->assertSame(['client_id' => $b['client_id']], json_decode($a['args_json'], true));
        $row = $this->pdo()->query("SELECT * FROM idempotency_keys WHERE scope_key LIKE 'authreq:%'")->fetch();
        $this->assertSame('authreq:' . hash('sha256', $b['req']['req']), $row['scope_key']);
        $rec = json_decode($row['result_json'], true);
        $this->assertSame(self::REDIRECT, $rec['redirect_uri']);
        $this->assertSame(['bookings:read', 'bookings:write', 'clients:read', 'credits:write'], $rec['scopes']);
        $this->assertSame('https://studio.test/mcp', $rec['resource']);
        $this->assertSame($b['challenge'], $rec['code_challenge']);
        $this->assertSame(0, $rec['attempts']);
        $this->assertSame(hash('sha256', $b['req']['csrf']), $rec['csrf_hash']);
        $this->assertSame(32, strlen($b['req']['req']));
        $this->assertSame(32, strlen($b['req']['csrf']));
        $this->assertStringContainsString('This is a new connection.', $b['page']->body);
        $this->assertSame(32, strlen($this->hidden($b['page']->body)['req']));
    }

    public function testStaleAuthorizationRecordsArePurgedOnNextView(): void
    {
        $b = $this->begin();
        $this->svc->advance(601);
        $this->begin();
        $this->assertSame(1, (int) $this->pdo()->query("SELECT COUNT(*) FROM idempotency_keys WHERE scope_key LIKE 'authreq:%'")->fetchColumn());
        $this->svc->advance(600);
        $c = $this->begin($b['client_id']);
        // exactly 600 s old is kept (cut is exclusive), 601 s old is purged
        $this->assertSame(2, (int) $this->pdo()->query("SELECT COUNT(*) FROM idempotency_keys WHERE scope_key LIKE 'authreq:%'")->fetchColumn());
        $this->assertNotSame($b['req']['req'], $c['req']['req']);
    }

    // ------------------------------------------------------------ authorize (POST)

    public function testAuthorizationRequestLifetimeIs600Seconds(): void
    {
        $b = $this->begin();
        $this->svc->advance(599);
        $page = $this->doOauth($this->req('POST', '/oauth/authorize', [], ['decision' => 'allow', 'email' => 'x', 'password' => 'y', 'totp' => '000000'] + $b['req']));
        $this->assertSame(200, $page->status, 'still alive at 599 s (consent form shown again with an error)');
        $this->assertStringContainsString('Sign-in failed.', $page->body);
        $b2 = $this->begin();
        $this->svc->advance(600);
        $page = $this->doOauth($this->req('POST', '/oauth/authorize', [], ['decision' => 'allow', 'email' => 'x', 'password' => 'y', 'totp' => '000000'] + $b2['req']));
        $this->assertStringContainsString('Sign-in failed.', $page->body, 'exactly 600 s old is still valid');
        $b3 = $this->begin();
        $this->svc->advance(601);
        $page = $this->doOauth($this->req('POST', '/oauth/authorize', [], ['decision' => 'allow'] + $b3['req']));
        $this->assertSame(400, $page->status);
        $this->assertStringContainsString('This sign-in request has expired or was already used. Go back to ChatGPT and try again.', $page->body);
        // ids that are empty or too long are not even looked up
        foreach (['', str_repeat('a', 101)] as $id) {
            $page = $this->doOauth($this->req('POST', '/oauth/authorize', [], ['decision' => 'allow', 'req' => $id, 'csrf' => 'x']));
            $this->assertSame(400, $page->status);
            $this->assertStringContainsString('expired or was already used', $page->body);
        }
    }

    public function testCsrfAndDecisionHandling(): void
    {
        $b = $this->begin(null, ['state' => 'deny-state-9']);
        $post = fn(array $f) => $this->doOauth($this->req('POST', '/oauth/authorize', [], $f));
        $r = $post(['decision' => 'allow', 'req' => $b['req']['req'], 'csrf' => '']);
        $this->assertSame(403, $r->status);
        $this->assertStringContainsString('The security check failed. Go back to ChatGPT and try again.', $r->body);
        $r = $post(['decision' => 'allow', 'req' => $b['req']['req'], 'csrf' => 'wrong']);
        $this->assertSame(403, $r->status);
        $a = $this->lastAudit('oauth_consent');
        $this->assertSame('denied', $a['status']);
        $this->assertSame('csrf check failed', $a['summary']);
        $this->assertSame(['client_id' => $b['client_id']], json_decode($a['args_json'], true));
        $r = $post(['req' => $b['req']['req'], 'csrf' => $b['req']['csrf']]);
        $this->assertSame(400, $r->status);
        $this->assertStringContainsString('Choose Allow or Deny.', $r->body);
        $r = $post(['decision' => 'maybe', 'req' => $b['req']['req'], 'csrf' => $b['req']['csrf']]);
        $this->assertStringContainsString('Choose Allow or Deny.', $r->body);
        $d = $post(['decision' => 'deny'] + $b['req']);
        $p = $this->locationParams($d);
        $this->assertSame(['error' => 'access_denied', 'error_description' => 'The owner denied the request.', 'state' => 'deny-state-9', 'iss' => 'https://studio.test'], $p);
        $a = $this->lastAudit('oauth_consent');
        $this->assertSame('owner denied access to ChatGPT', $a['summary']);
        $this->assertSame('denied', $a['status']);
        // a denied record is consumed
        $again = $post(['decision' => 'deny'] + $b['req']);
        $this->assertSame(400, $again->status);
        $this->assertStringContainsString('expired or was already used', $again->body);
        $this->assertSame(0, count($this->audit('oauth_token')));
    }

    public function testDenyStateIsReadFromTheStoredRecord(): void
    {
        $b = $this->begin(null, ['state' => 'known-state-1']);
        $p = $this->locationParams($this->doOauth($this->req('POST', '/oauth/authorize', [], ['decision' => 'deny', 'state' => 'forged'] + $b['req'])));
        $this->assertSame('known-state-1', $p['state']);
    }

    public function testSignInChecksAreIdenticalAndOwnerOnly(): void
    {
        $generic = 'Sign-in failed. Check your email, password and authenticator code.';
        $cid = $this->registerClient();
        $try = function (array $over, string $ip = '203.0.113.9') use (&$b, $cid) {
            $b = $this->begin($cid);
            $this->svc->advance(30);
            return $this->doOauth($this->req('POST', '/oauth/authorize', [], $this->consentForm($b['req'], $over), [], '', $ip));
        };
        $cases = [
            'wrong password' => ['password' => 'nope'],
            'wrong email' => ['email' => 'other@example.com'],
            'wrong totp' => ['totp' => '000000'],
            'client email' => ['email' => 'thandi@example.com', 'password' => FakeServices::CLIENT_PASSWORD],
            'empty email' => ['email' => ''],
            'blank email' => ['email' => '   '],
            'empty password' => ['password' => ''],
            'long password' => ['password' => str_repeat('a', 1025)],
            'short totp' => ['totp' => '12345'],
            'long totp' => ['totp' => '1234567'],
            'letters totp' => ['totp' => 'abcdef'],
        ];
        $n = 0;
        foreach ($cases as $why => $over) {
            $r = $try($over, '198.51.100.' . (++$n));
            $this->assertSame(200, $r->status, $why);
            $this->assertStringContainsString($generic, $r->body, $why);
        }
        $a = $this->lastAudit('oauth_login');
        $this->assertSame('denied', $a['status']);
        $this->assertSame('owner sign-in failed on consent page', $a['summary']);
        // the owner row is deliberately a client: even with its valid TOTP it is refused
        $this->pdo()->prepare("UPDATE users SET role = 'client' WHERE id = ?")->execute([$this->svc->ownerId]);
        $r = $try([], '198.51.100.99');
        $this->assertStringContainsString($generic, $r->body);
        $this->pdo()->prepare("UPDATE users SET role = 'owner' WHERE id = ?")->execute([$this->svc->ownerId]);
        // whitespace in the email and spaces in the code are tolerated
        $b = $this->begin($cid);
        $this->svc->advance(30);
        $code = FakeTotp::code(FakeServices::TOTP_SECRET, $this->now()->getTimestamp());
        $r = $this->doOauth($this->req('POST', '/oauth/authorize', [], $this->consentForm($b['req'], ['email' => '  ' . FakeServices::OWNER_EMAIL . ' ', 'totp' => substr($code, 0, 3) . ' ' . substr($code, 3)]), [], '', '198.51.100.120'));
        $this->assertSame(302, $r->status);
    }

    public function testPasswordLengthLimitIs1024(): void
    {
        $b = $this->begin();
        $this->svc->advance(30);
        $r = $this->doOauth($this->req('POST', '/oauth/authorize', [], $this->consentForm($b['req'], ['password' => str_repeat('a', 1024)])));
        $this->assertStringContainsString('Sign-in failed.', $r->body);
        $a = $this->audit('oauth_login');
        $this->assertCount(1, $a, '1024 characters reach the password check (and fail there)');
        $b2 = $this->begin();
        $this->svc->advance(30);
        $this->doOauth($this->req('POST', '/oauth/authorize', [], $this->consentForm($b2['req'], ['password' => str_repeat('a', 1025)]), [], '', '198.51.100.7'));
        $this->assertCount(1, $this->audit('oauth_login'), '1025 characters are refused before any check');
    }

    public function testConsentAttemptLimits(): void
    {
        $b = $this->begin();
        $bad = fn(string $ip) => $this->doOauth($this->req('POST', '/oauth/authorize', [], $this->consentForm($b['req'], ['password' => 'x']), [], '', $ip));
        for ($i = 1; $i <= 5; $i++) {
            $this->assertSame(200, $bad('203.0.113.9')->status, "attempt $i");
        }
        $r = $bad('203.0.113.9');
        $this->assertSame(429, $r->status);
        $this->assertStringContainsString('Too many attempts. Wait 15 minutes and try again.', $r->body);
        $a = $this->lastAudit('oauth_login');
        $this->assertSame('rate_limited', $a['status']);
        $this->assertSame('consent attempts throttled', $a['summary']);
        $ts = $this->now()->getTimestamp();
        $w = intdiv($ts, 900) * 900;
        $this->assertSame(gmdate('Y-m-d H:i:s', $ts + 900), $this->pdo()->query("SELECT expires_at FROM rate_limits WHERE bucket = 'oauth_consent:203.0.113.9:$w'")->fetchColumn());
        // per-record cap: the sixth attempt (from fresh IPs) kills the record
        $c = $this->begin();
        $bad2 = fn(string $ip) => $this->doOauth($this->req('POST', '/oauth/authorize', [], $this->consentForm($c['req'], ['password' => 'x']), [], '', $ip));
        for ($i = 1; $i <= 5; $i++) {
            $this->assertSame(200, $bad2("192.0.2.$i")->status, "record attempt $i");
        }
        $r = $bad2('192.0.2.6');
        $this->assertSame(429, $r->status);
        $this->assertStringContainsString('Too many failed attempts on this request. Go back to ChatGPT and start again.', $r->body);
        $r = $bad2('192.0.2.7');
        $this->assertSame(400, $r->status);
        $this->assertStringContainsString('expired or was already used', $r->body);
        $rec = $this->pdo()->query("SELECT COUNT(*) FROM idempotency_keys WHERE scope_key = 'authreq:" . hash('sha256', $c['req']['req']) . "'")->fetchColumn();
        $this->assertSame(0, (int) $rec);
        // the counter is persisted
        $d = $this->begin();
        $this->doOauth($this->req('POST', '/oauth/authorize', [], $this->consentForm($d['req'], ['password' => 'x']), [], '', '192.0.2.50'));
        $this->assertSame(1, json_decode($this->pdo()->query("SELECT result_json FROM idempotency_keys WHERE scope_key = 'authreq:" . hash('sha256', $d['req']['req']) . "'")->fetchColumn(), true)['attempts']);
    }

    public function testScopeSelectionAtConsent(): void
    {
        $b = $this->begin(null, ['scope' => 'bookings:read credits:write', 'state' => 'sel-state']);
        $this->svc->advance(30);
        // nothing ticked
        $r = $this->doOauth($this->req('POST', '/oauth/authorize', [], $this->consentForm($b['req'], ['scope' => []])));
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('Tick at least one permission, or press Deny.', $r->body);
        // only scopes outside the request
        $this->svc->advance(30);
        $r = $this->doOauth($this->req('POST', '/oauth/authorize', [], $this->consentForm($b['req'], ['scope' => ['bookings:write', 'clients:read']]), [], '', '198.51.100.1'));
        $this->assertStringContainsString('Tick at least one permission', $r->body);
        $this->svc->advance(30);
        $r = $this->doOauth($this->req('POST', '/oauth/authorize', [], $this->consentForm($b['req'], ['scope' => 'bookings:read']), [], '', '198.51.100.2'));
        $this->assertStringContainsString('Tick at least one permission', $r->body, 'a non-array scope field is ignored');
        // granted = posted ∩ requested, canonical order, non-strings dropped
        $this->svc->advance(30);
        $r = $this->doOauth($this->req('POST', '/oauth/authorize', [], $this->consentForm($b['req'], ['scope' => ['credits:write', 5, 'bookings:write', 'bookings:read']]), [], '', '198.51.100.3'));
        $p = $this->locationParams($r);
        $this->assertSame('bookings:read credits:write', $this->pdo()->query('SELECT scopes FROM oauth_codes')->fetchColumn());
        $this->assertSame('sel-state', $p['state']);
        $a = $this->lastAudit('oauth_consent');
        $this->assertSame('ok', $a['status']);
        $this->assertSame('owner:' . $this->svc->ownerId, $a['actor']);
        $this->assertSame('owner approved ChatGPT with 2 scope(s)', $a['summary']);
        $this->assertSame(['client_id' => $b['client_id'], 'scopes' => 'bookings:read credits:write'], json_decode($a['args_json'], true));
    }

    public function testApprovedCodeRecordIsExact(): void
    {
        $b = $this->begin();
        $code = $this->approve($b);
        $row = $this->pdo()->query('SELECT * FROM oauth_codes')->fetch();
        $this->assertSame(hash('sha256', $code), $row['code_hash']);
        $this->assertSame($b['client_id'], $row['client_id']);
        $this->assertSame($this->svc->ownerId, $row['user_id']);
        $this->assertSame(self::REDIRECT, $row['redirect_uri']);
        $this->assertSame('bookings:read bookings:write clients:read credits:write', $row['scopes']);
        $this->assertSame('https://studio.test/mcp', $row['resource']);
        $this->assertSame($b['challenge'], $row['code_challenge']);
        $this->assertSame(gmdate('Y-m-d H:i:s', $this->now()->getTimestamp() + 60), $row['expires_at']);
        $this->assertNull($row['used_at']);
        $this->assertSame(43, strlen($code));
    }

    public function testPairingWindowClosedAtConsentTime(): void
    {
        $b = $this->begin();
        (new Pairing($this->svc))->close();
        $this->svc->advance(30);
        $r = $this->doOauth($this->req('POST', '/oauth/authorize', [], $this->consentForm($b['req'])));
        $this->assertSame(403, $r->status);
        $this->assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM oauth_codes')->fetchColumn());
        $a = $this->lastAudit('oauth_pairing_refused');
        $this->assertSame('denied', $a['status']);
        $this->assertSame('authorize refused: no active grant and pairing window closed (ChatGPT)', $a['summary']);
        $this->assertSame(['client_id' => $b['client_id']], json_decode($a['args_json'], true));
        $again = $this->doOauth($this->req('POST', '/oauth/authorize', [], $this->consentForm($b['req'])));
        $this->assertSame(400, $again->status, 'the request was consumed');
    }

    // ------------------------------------------------------------ pairing window

    public function testPairingWindowIs15MinutesAndStatusFormat(): void
    {
        $p = new Pairing($this->svc);
        $this->assertSame(['open' => false, 'until' => null], $p->status());
        $this->assertFalse($p->isOpen());
        $o = $p->open();
        $this->assertSame(['open' => true, 'until' => '2026-11-09T08:15:00Z'], $o);
        $this->assertSame(15, Pairing::MINUTES);
        $this->assertSame('connector_pairing_until', Pairing::KEY);
        $this->assertSame('{"until":"2026-11-09 08:15:00"}', $this->pdo()->query("SELECT v FROM settings WHERE k = 'connector_pairing_until'")->fetchColumn());
        $this->assertSame('2026-11-09 08:00:00', $this->pdo()->query("SELECT updated_at FROM settings WHERE k = 'connector_pairing_until'")->fetchColumn());
        $this->svc->advance(899);
        $this->assertTrue($p->isOpen());
        $this->svc->advance(1);
        $this->assertFalse($p->isOpen(), 'exactly 15 minutes later it is closed');
        $this->assertSame(['open' => false, 'until' => null], $p->status());
        // re-opening replaces the row
        $p->open();
        $this->svc->advance(10);
        $p->open();
        $this->assertSame(1, (int) $this->pdo()->query("SELECT COUNT(*) FROM settings WHERE k = 'connector_pairing_until'")->fetchColumn());
        $this->assertSame('2026-11-09T08:30:10Z', $p->status()['until']);
        $this->assertSame(['open' => false, 'until' => null], $p->close());
        $this->assertFalse($p->isOpen());
        // corrupt values mean closed
        foreach (['not json', '[]', '{"until":5}', '{"until":null}', '{}', '"x"'] as $v) {
            $this->pdo()->prepare("INSERT OR REPLACE INTO settings (k, v, updated_at) VALUES ('connector_pairing_until', ?, '2026-11-09 08:00:00')")->execute([$v]);
            $this->assertFalse($p->isOpen(), $v);
        }
    }

    public function testPairingIsSingleUseAndPerClient(): void
    {
        $this->autoPair = false;
        $p = new Pairing($this->svc);
        $id = $this->registerClient();
        [$v, $c] = $this->pkce();
        $q = $this->authorizeQuery($id, $c);
        $r = $this->doOauth($this->req('GET', '/oauth/authorize', $q));
        $this->assertSame(403, $r->status, 'a new client with the window closed');
        $a = $this->lastAudit('oauth_pairing_refused');
        $this->assertSame('authorize refused: no active grant and pairing window closed (ChatGPT)', $a['summary']);
        $this->assertSame(['client_id' => $id], json_decode($a['args_json'], true));
        $this->assertSame('denied', $a['status']);
        $this->assertSame('anonymous', $a['actor']);
        $this->assertSame('203.0.113.9', $a['ip']);
        $p->open();
        $b = $this->begin($id);
        $code = $this->approve($b);
        $this->assertTrue($p->isOpen(), 'approval alone does not close the window');
        $tok = $this->exchange($code, $b);
        $this->assertSame(200, $tok->status);
        $this->assertFalse($p->isOpen(), 'the window closes when the new client gets its grant');
        $a = $this->lastAudit('connector_pairing_close');
        $this->assertSame('ok', $a['status']);
        $this->assertSame('pairing window closed: new connection created', $a['summary']);
        $this->assertSame('owner:' . $this->svc->ownerId, $a['actor']);
        $this->assertNotNull($a['grant_id']);
        $this->assertTrue($p->clientHasActiveGrant($id));
        $this->assertFalse($p->clientHasActiveGrant('someone-else'));
        // the same client may re-authorise with the window closed, and that does not touch the window
        $this->assertSame(200, $this->doOauth($this->req('GET', '/oauth/authorize', $this->authorizeQuery($id, $c)))->status);
        $p->open();
        $b2 = $this->begin($id);
        $this->assertStringNotContainsString('This is a new connection.', $b2['page']->body, 'a known client is not flagged as new');
        $code2 = $this->approve($b2);
        $this->assertSame(200, $this->exchange($code2, $b2)->status);
        $this->assertTrue($p->isOpen(), 're-authorising an existing client does not use up the window');
        // another client needs its own window
        $p->close();
        $other = $this->registerClient('Other');
        $this->assertSame(403, $this->doOauth($this->req('GET', '/oauth/authorize', $this->authorizeQuery($other, $c, [])))->status);
        // revoking the only grant makes the client new again
        $this->pdo()->exec("UPDATE oauth_grants SET revoked_at = '2026-11-09 08:00:00'");
        $this->assertFalse($p->clientHasActiveGrant($id));
        $this->assertSame(403, $this->doOauth($this->req('GET', '/oauth/authorize', $this->authorizeQuery($id, $c)))->status);
    }

    // ------------------------------------------------------------ token endpoint: validation

    public function testTokenEndpointRequestValidation(): void
    {
        $post = fn(array $form, array $h = []) => $this->doOauth($this->req('POST', '/oauth/token', [], $form, $h));
        $err = function (HttpResponse $r, int $status, string $code, string $desc) {
            $this->assertSame($status, $r->status, $r->body);
            $this->assertSame(['error' => $code, 'error_description' => $desc], $this->jsonBody($r));
        };
        $err($this->doOauth($this->req('POST', '/oauth/token', [], [], ['content-type' => 'application/json'], '{}')), 400, 'invalid_request', 'Content-Type must be application/x-www-form-urlencoded.');
        $err($this->doOauth($this->req('POST', '/oauth/token', [], ['grant_type' => 'authorization_code'], ['content-type' => 'Application/X-WWW-Form-Urlencoded; charset=UTF-8'])), 400, 'invalid_request', 'code and client_id are required.');
        $err($post(['grant_type' => 'password']), 400, 'unsupported_grant_type', 'grant_type must be authorization_code or refresh_token.');
        $err($post([], ['content-type' => 'application/x-www-form-urlencoded']), 400, 'unsupported_grant_type', 'grant_type must be authorization_code or refresh_token.');
        $err($post(['grant_type' => 'authorization_code', 'code' => 'x']), 400, 'invalid_request', 'code and client_id are required.');
        $err($post(['grant_type' => 'authorization_code', 'client_id' => 'x']), 400, 'invalid_request', 'code and client_id are required.');
        $err($post(['grant_type' => 'authorization_code', 'code' => str_repeat('c', 201), 'client_id' => 'x']), 400, 'invalid_request', 'code and client_id are required.');
        $err($post(['grant_type' => 'authorization_code', 'code' => str_repeat('c', 200), 'client_id' => 'x']), 400, 'invalid_grant', 'The code is invalid or expired.');
        $err($post(['grant_type' => 'refresh_token', 'client_id' => 'x']), 400, 'invalid_request', 'refresh_token and client_id are required.');
        $err($post(['grant_type' => 'refresh_token', 'refresh_token' => 'x']), 400, 'invalid_request', 'refresh_token and client_id are required.');
        $err($post(['grant_type' => 'refresh_token', 'refresh_token' => str_repeat('r', 201), 'client_id' => 'x']), 400, 'invalid_request', 'refresh_token and client_id are required.');
        $err($post(['grant_type' => 'refresh_token', 'refresh_token' => str_repeat('r', 200), 'client_id' => 'x']), 400, 'invalid_grant', 'The refresh token is invalid or expired.');
        $err($post(['grant_type' => ['authorization_code']]), 400, 'unsupported_grant_type', 'grant_type must be authorization_code or refresh_token.');
    }

    public function testTokenRateLimitIs30PerMinute(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->assertSame(400, $this->doOauth($this->req('POST', '/oauth/token', [], ['grant_type' => 'x']))->status, "call $i");
        }
        $r = $this->doOauth($this->req('POST', '/oauth/token', [], ['grant_type' => 'x']));
        $this->assertSame(429, $r->status);
        $this->assertSame(['error' => 'temporarily_unavailable', 'error_description' => 'Too many requests.'], $this->jsonBody($r));
        $ts = $this->now()->getTimestamp();
        $this->assertSame(gmdate('Y-m-d H:i:s', $ts + 60), $this->pdo()->query("SELECT expires_at FROM rate_limits WHERE bucket = 'oauth_token:203.0.113.9:" . intdiv($ts, 60) * 60 . "'")->fetchColumn());
        $this->svc->advance(60);
        $this->assertSame(400, $this->doOauth($this->req('POST', '/oauth/token', [], ['grant_type' => 'x']))->status);
    }

    // ------------------------------------------------------------ token endpoint: authorization code

    public function testCodeLifetimeIs60SecondsExactly(): void
    {
        $b = $this->begin();
        $code = $this->approve($b); // approve() advances 30 s before consenting; the code is minted at the new time
        $this->svc->advance(59);
        $this->assertSame(200, $this->exchange($code, $b)->status, '59 s old');
        $b2 = $this->begin();
        $code2 = $this->approve($b2);
        $this->svc->advance(60);
        $r = $this->exchange($code2, $b2);
        $this->assertSame(400, $r->status, 'exactly 60 s old is expired');
        $this->assertSame(['error' => 'invalid_grant', 'error_description' => 'The code is invalid or expired.'], $this->jsonBody($r));
        $this->assertSame(0, count($this->audit('code_reuse')), 'an expired code is not a replay');
    }

    public function testCodeBindingAndPkce(): void
    {
        $b = $this->begin();
        $code = $this->approve($b);
        $bad = function (array $over, string $code_, string $desc, string $err = 'invalid_grant') use ($b) {
            $r = $this->exchange($code_, $b, $over);
            $this->assertSame(400, $r->status, json_encode($over));
            $this->assertSame(['error' => $err, 'error_description' => $desc], $this->jsonBody($r));
        };
        $expired = 'The code is invalid or expired.';
        $bad([], 'unknown-code', $expired);
        $bad(['client_id' => 'other-client'], $code, $expired);
        $bad(['redirect_uri' => self::REDIRECT . 'x'], $code, $expired);
        $bad(['redirect_uri' => ''], $code, $expired);
        $mal = 'code_verifier is missing or malformed.';
        $bad(['code_verifier' => ''], $code, $mal);
        $bad(['code_verifier' => str_repeat('a', 42)], $code, $mal);
        $bad(['code_verifier' => str_repeat('a', 129)], $code, $mal);
        $bad(['code_verifier' => substr($b['verifier'], 0, 42) . '!'], $code, $mal);
        $bad(['code_verifier' => $b['verifier'] . "\n"], $code, $mal);
        $bad(['code_verifier' => substr($b['verifier'], 0, 42) . '+'], $code, $mal);
        $bad(['code_verifier' => str_repeat('a', 43)], $code, 'PKCE verification failed.');
        $a = $this->lastAudit('oauth_token');
        $this->assertSame('denied', $a['status']);
        $this->assertSame('PKCE verification failed', $a['summary']);
        $this->assertSame(['client_id' => $b['client_id']], json_decode($a['args_json'], true));
        $bad(['resource' => 'https://studio.test/other'], $code, 'resource does not match the authorization request.', 'invalid_target');
        // none of the failed attempts consumed the code
        $this->assertNull($this->pdo()->query('SELECT used_at FROM oauth_codes')->fetchColumn());
        $this->assertSame(200, $this->exchange($code, $b, ['resource' => ''])->status, 'resource may be omitted');
    }

    public function testPkceMethodIsS256NotPlain(): void
    {
        // a client that sends its verifier as the challenge ("plain") must not be able to redeem the code
        $clientId = $this->registerClient();
        [$v] = $this->pkce();
        $v43 = substr($v, 0, 43);
        $page = $this->doOauth($this->req('GET', '/oauth/authorize', $this->authorizeQuery($clientId, $v43)));
        $b = ['client_id' => $clientId, 'verifier' => $v43, 'req' => $this->hidden($page->body)];
        $code = $this->approve($b);
        $r = $this->exchange($code, $b);
        $this->assertSame(400, $r->status);
        $this->assertSame('PKCE verification failed.', $this->jsonBody($r)['error_description']);
        // a hash of the verifier with standard base64 (+, /, =) instead of base64url also fails; the right one passes
        $this->assertSame(rtrim(strtr(base64_encode(hash('sha256', $v43, true)), '+/', '-_'), '='), $this->challengeOf($v43));
    }

    private function challengeOf(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    public function testCodeIsSingleUseAndReplayRevokesGrantAndTokens(): void
    {
        $b = $this->begin();
        $code = $this->approve($b);
        $first = $this->exchange($code, $b);
        $this->assertSame(200, $first->status);
        $tokens = $this->jsonBody($first);
        $replay = $this->exchange($code, $b);
        $this->assertSame(400, $replay->status);
        $this->assertSame(['error' => 'invalid_grant', 'error_description' => 'The code is invalid or expired.'], $this->jsonBody($replay));
        $g = $this->pdo()->query('SELECT * FROM oauth_grants')->fetch();
        $this->assertNotNull($g['revoked_at']);
        $this->assertSame('code_reuse', $g['revoke_reason']);
        $this->assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM oauth_tokens WHERE revoked_at IS NULL')->fetchColumn());
        $a = $this->lastAudit('code_reuse');
        $this->assertSame('denied', $a['status']);
        $this->assertSame('authorization code replayed; grant revoked', $a['summary']);
        $this->assertSame($g['id'], $a['grant_id']);
        $this->assertSame('anonymous', $a['actor']);
        $this->assertNull((new TokenStore($this->svc))->authenticate($tokens['access_token']));
        // used_at is the time of exchange
        $this->assertNotNull($this->pdo()->query('SELECT used_at FROM oauth_codes')->fetchColumn());
        // a replay of a code that never produced a grant has nothing to revoke
        $this->pdo()->exec("DELETE FROM idempotency_keys WHERE scope_key LIKE 'codegrant:%'");
        $this->exchange($code, $b);
        $this->assertSame('authorization code replayed', $this->lastAudit('code_reuse')['summary']);
        $this->assertNull($this->lastAudit('code_reuse')['grant_id']);
    }

    public function testTokenResponseAndRowsAreExact(): void
    {
        $b = $this->begin();
        $code = $this->approve($b);
        $now = $this->now()->getTimestamp();
        $r = $this->exchange($code, $b);
        $d = $this->jsonBody($r);
        $this->assertSame(['access_token', 'token_type', 'expires_in', 'refresh_token', 'scope'], array_keys($d));
        $this->assertSame('Bearer', $d['token_type']);
        $this->assertSame(3600, $d['expires_in']);
        $this->assertSame('bookings:read bookings:write clients:read credits:write', $d['scope']);
        $this->assertSame(43, strlen($d['access_token']));
        $this->assertSame(43, strlen($d['refresh_token']));
        $this->assertNotSame($d['access_token'], $d['refresh_token']);
        $this->assertSame('no-store', $r->headers['Cache-Control']);
        $this->assertSame('no-cache', $r->headers['Pragma']);
        $rows = $this->pdo()->query('SELECT * FROM oauth_tokens ORDER BY kind')->fetchAll();
        $this->assertCount(2, $rows);
        $this->assertSame('access', $rows[0]['kind']);
        $this->assertSame(hash('sha256', $d['access_token']), $rows[0]['token_hash']);
        $this->assertSame(gmdate('Y-m-d H:i:s', $now + 3600), $rows[0]['expires_at']);
        $this->assertSame('refresh', $rows[1]['kind']);
        $this->assertSame(hash('sha256', $d['refresh_token']), $rows[1]['token_hash']);
        $this->assertSame(gmdate('Y-m-d H:i:s', $now + 2592000), $rows[1]['expires_at']);
        $this->assertSame(gmdate('Y-m-d H:i:s', $now), $rows[0]['created_at']);
        $this->assertNull($rows[0]['used_at']);
        $g = $this->pdo()->query('SELECT * FROM oauth_grants')->fetch();
        $this->assertSame($b['client_id'], $g['client_id']);
        $this->assertSame($this->svc->ownerId, $g['user_id']);
        $this->assertSame('bookings:read bookings:write clients:read credits:write', $g['scopes']);
        $this->assertSame('https://studio.test/mcp', $g['resource']);
        $this->assertSame(gmdate('Y-m-d H:i:s', $now), $g['created_at']);
        $a = $this->lastAudit('oauth_token');
        $this->assertSame('ok', $a['status']);
        $this->assertSame('tokens issued', $a['summary']);
        $this->assertSame('owner:' . $this->svc->ownerId, $a['actor']);
        $this->assertSame($g['id'], $a['grant_id']);
        $this->assertSame(['client_id' => $b['client_id'], 'grant' => 'authorization_code'], json_decode($a['args_json'], true));
        $m = $this->pdo()->query("SELECT result_json FROM idempotency_keys WHERE scope_key = 'codegrant:" . hash('sha256', $code) . "'")->fetchColumn();
        $this->assertSame(['grant_id' => $g['id']], json_decode($m, true));
    }

    public function testStoreLifetimesAtTheBoundary(): void
    {
        $t = $this->connect();
        $store = new TokenStore($this->svc);
        $this->assertSame(3600, TokenStore::ACCESS_TTL);
        $this->assertSame(2592000, TokenStore::REFRESH_TTL);
        $this->assertSame(7776000, TokenStore::GRANT_MAX_AGE);
        $this->svc->advance(3599);
        $this->assertNotNull($store->authenticate($t['access']), 'valid 1 s before the hour');
        $this->svc->advance(1);
        $this->assertNull($store->authenticate($t['access']), 'dead at exactly one hour');
    }

    public function testAuthenticateChecksEveryCondition(): void
    {
        $t = $this->connect(['bookings:read', 'clients:read']);
        $store = new TokenStore($this->svc);
        $a = $store->authenticate($t['access']);
        $this->assertSame(['grant_id' => $t['grant_id'], 'client_id' => $t['client_id'], 'user_id' => $this->svc->ownerId, 'scopes' => ['bookings:read', 'clients:read']], $a);
        $this->assertNull($store->authenticate(''));
        $this->assertNull($store->authenticate(str_repeat('a', 201)));
        $this->assertNull($store->authenticate($t['refresh']), 'a refresh token is not an access token');
        $this->assertNull($store->authenticate('x' . $t['access']));
        // owner role
        $this->pdo()->prepare("UPDATE users SET role = 'client' WHERE id = ?")->execute([$this->svc->ownerId]);
        $this->assertNull($store->authenticate($t['access']), 'a grant only acts for an owner');
        $this->pdo()->prepare("UPDATE users SET role = 'owner' WHERE id = ?")->execute([$this->svc->ownerId]);
        $this->assertNotNull($store->authenticate($t['access']));
        $this->pdo()->prepare('UPDATE users SET id = ? WHERE id = ?')->execute(['gone', $this->svc->ownerId]);
        $this->assertNull($store->authenticate($t['access']), 'a deleted owner kills the token');
        $this->pdo()->prepare('UPDATE users SET id = ? WHERE id = ?')->execute([$this->svc->ownerId, 'gone']);
        // token revoked / grant revoked / other resource
        $this->pdo()->exec("UPDATE oauth_tokens SET revoked_at = '2026-11-09 08:00:00' WHERE kind = 'access'");
        $this->assertNull($store->authenticate($t['access']));
        $this->pdo()->exec("UPDATE oauth_tokens SET revoked_at = NULL");
        $this->pdo()->exec("UPDATE oauth_grants SET revoked_at = '2026-11-09 08:00:00'");
        $this->assertNull($store->authenticate($t['access']));
        $this->pdo()->exec("UPDATE oauth_grants SET revoked_at = NULL, resource = 'https://studio.test/mcpx'");
        $this->assertNull($store->authenticate($t['access']));
        $this->pdo()->exec("UPDATE oauth_grants SET resource = 'https://studio.test/mcp'");
        $this->assertNotNull($store->authenticate($t['access']));
        $this->assertSame('https://studio.test/mcp', $store->resource());
    }

    public function testIssuePairHonoursTheNinetyDayCeiling(): void
    {
        $store = new TokenStore($this->svc);
        $gid = $store->createGrant('c1', $this->svc->ownerId, ['bookings:read'], 'https://studio.test/mcp');
        $this->assertSame('2026-11-09 08:00:00', $this->pdo()->query("SELECT created_at FROM oauth_grants WHERE id = '$gid'")->fetchColumn());
        $this->assertSame('bookings:read', $this->pdo()->query("SELECT scopes FROM oauth_grants WHERE id = '$gid'")->fetchColumn());
        $this->svc->advance(86400 * 89);
        $pair = $store->issuePair($gid);
        $this->assertNotNull($pair);
        $refreshEnd = $this->pdo()->query("SELECT expires_at FROM oauth_tokens WHERE grant_id = '$gid' AND kind = 'refresh' ORDER BY created_at DESC LIMIT 1")->fetchColumn();
        $this->assertSame('2027-02-07 08:00:00', $refreshEnd, 'a refresh token never outlives the 90-day grant');
        $this->assertSame(3600, $pair['expires_in']);
        $this->svc->advance(86400 - 1);
        $this->assertNotNull($store->issuePair($gid), 'one second before 90 days');
        $this->svc->advance(1);
        $this->assertNull($store->issuePair($gid), 'exactly 90 days old: no more tokens');
        $this->assertNull($store->issuePair('nope'));
        $this->svc->database->now = new \DateTimeImmutable('2026-11-09 08:00:00', new \DateTimeZone('UTC'));
        $g2 = $store->createGrant('c1', $this->svc->ownerId, ['bookings:read'], 'https://studio.test/mcp');
        $store->revokeGrant($g2, str_repeat('r', 100));
        $this->assertNull($store->issuePair($g2));
        $this->assertSame(str_repeat('r', 80), $this->pdo()->query("SELECT revoke_reason FROM oauth_grants WHERE id = '$g2'")->fetchColumn());
    }

    public function testRevokeGrantMarksTokensOnceWithTheFirstTimestamp(): void
    {
        $t = $this->connect();
        $store = new TokenStore($this->svc);
        $store->revokeGrant($t['grant_id'], 'first');
        $first = $this->pdo()->query('SELECT revoked_at FROM oauth_grants')->fetchColumn();
        $this->svc->advance(500);
        $store->revokeGrant($t['grant_id'], 'second');
        $this->assertSame($first, $this->pdo()->query('SELECT revoked_at FROM oauth_grants')->fetchColumn());
        $this->assertSame('first', $this->pdo()->query('SELECT revoke_reason FROM oauth_grants')->fetchColumn());
        $this->assertSame(2, (int) $this->pdo()->query("SELECT COUNT(*) FROM oauth_tokens WHERE revoked_at = '$first'")->fetchColumn());
        $this->assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM oauth_tokens WHERE revoked_at IS NULL')->fetchColumn());
    }

    // ------------------------------------------------------------ refresh tokens

    public function testRefreshLifetimeIs30DaysExactly(): void
    {
        $t = $this->connect();
        $this->svc->advance(2592000 - 1);
        $r = $this->refresh($t['refresh'], $t['client_id']);
        $this->assertSame(200, $r->status, 'valid one second before 30 days');
        $t2 = $this->connect();
        $this->svc->advance(2592000);
        $r = $this->refresh($t2['refresh'], $t2['client_id']);
        $this->assertSame(400, $r->status, 'dead at exactly 30 days');
        $this->assertSame(['error' => 'invalid_grant', 'error_description' => 'The refresh token is invalid or expired.'], $this->jsonBody($r));
        $this->assertSame(0, count($this->audit('refresh_reuse')));
    }

    public function testRefreshRotationExactResponseAndAudit(): void
    {
        $t = $this->connect(['bookings:read', 'clients:read']);
        $this->svc->advance(10);
        $r = $this->refresh($t['refresh'], $t['client_id'], ['resource' => 'https://studio.test/mcp']);
        $this->assertSame(200, $r->status);
        $d = $this->jsonBody($r);
        $this->assertSame(['access_token', 'token_type', 'expires_in', 'refresh_token', 'scope'], array_keys($d));
        $this->assertSame('Bearer', $d['token_type']);
        $this->assertSame(3600, $d['expires_in']);
        $this->assertSame('bookings:read clients:read', $d['scope']);
        $this->assertNotSame($t['refresh'], $d['refresh_token']);
        $this->assertNotSame($t['access'], $d['access_token']);
        $this->assertSame(gmdate('Y-m-d H:i:s', $this->now()->getTimestamp()), $this->pdo()->query("SELECT used_at FROM oauth_tokens WHERE token_hash = '" . hash('sha256', $t['refresh']) . "'")->fetchColumn());
        $a = $this->lastAudit('oauth_token');
        $this->assertSame('refresh token rotated', $a['summary']);
        $this->assertSame('oauth', $a['actor']);
        $this->assertSame('ok', $a['status']);
        $this->assertSame($t['grant_id'], $a['grant_id']);
        $this->assertSame(['client_id' => $t['client_id'], 'grant' => 'refresh_token'], json_decode($a['args_json'], true));
        $store = new TokenStore($this->svc);
        $this->assertNotNull($store->authenticate($d['access_token']));
        // the old access token still works until it expires (rotation does not revoke it)
        $this->assertNotNull($store->authenticate($t['access']));
    }

    public function testRefreshReuseRevokesTheGrant(): void
    {
        $t = $this->connect();
        $new = $this->jsonBody($this->refresh($t['refresh'], $t['client_id']));
        $r = $this->refresh($t['refresh'], $t['client_id']);
        $this->assertSame(400, $r->status);
        $this->assertSame(['error' => 'invalid_grant', 'error_description' => 'The refresh token is invalid or expired.'], $this->jsonBody($r));
        $g = $this->pdo()->query('SELECT * FROM oauth_grants')->fetch();
        $this->assertSame('refresh_reuse', $g['revoke_reason']);
        $this->assertNotNull($g['revoked_at']);
        $this->assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM oauth_tokens WHERE revoked_at IS NULL')->fetchColumn());
        $this->assertNull((new TokenStore($this->svc))->authenticate($new['access_token']));
        $this->assertSame(400, $this->refresh($new['refresh_token'], $t['client_id'])->status, 'the newest refresh token is dead too');
        $a = $this->audit('refresh_reuse');
        $this->assertSame('denied', $a[0]['status']);
        $this->assertSame('used refresh token presented again; grant revoked', $a[0]['summary']);
        $this->assertSame('oauth', $a[0]['actor']);
        $this->assertSame($t['grant_id'], $a[0]['grant_id']);
    }

    public function testRefreshChecks(): void
    {
        $t = $this->connect();
        $bad = fn(HttpResponse $r) => $this->assertSame(['error' => 'invalid_grant', 'error_description' => 'The refresh token is invalid or expired.'], $this->jsonBody($r));
        $bad($this->refresh($t['refresh'], 'someone-else'));
        $bad($this->refresh('unknown-token', $t['client_id']));
        $bad($this->refresh($t['access'], $t['client_id']));
        $this->assertNull($this->pdo()->query("SELECT revoked_at FROM oauth_grants")->fetchColumn(), 'a wrong client does not revoke');
        $r = $this->refresh($t['refresh'], $t['client_id'], ['resource' => 'https://studio.test/other']);
        $this->assertSame(400, $r->status);
        $this->assertSame(['error' => 'invalid_target', 'error_description' => 'resource does not match the grant.'], $this->jsonBody($r));
        $this->assertNull($this->pdo()->query("SELECT used_at FROM oauth_tokens WHERE kind = 'refresh'")->fetchColumn(), 'a refused resource does not burn the token');
        // revoked grant and revoked token
        $this->pdo()->exec("UPDATE oauth_tokens SET revoked_at = '2026-11-09 08:00:00' WHERE kind = 'refresh'");
        $bad($this->refresh($t['refresh'], $t['client_id']));
        $this->pdo()->exec("UPDATE oauth_tokens SET revoked_at = NULL");
        $this->pdo()->exec("UPDATE oauth_grants SET revoked_at = '2026-11-09 08:00:00'");
        $bad($this->refresh($t['refresh'], $t['client_id']));
        $this->pdo()->exec("UPDATE oauth_grants SET revoked_at = NULL");
        $this->assertSame(200, $this->refresh($t['refresh'], $t['client_id'])->status);
    }

    public function testRefreshAfterNinetyDaysIsRefused(): void
    {
        $t = $this->connect();
        $this->svc->advance(86400 * 30 - 1);
        $t2 = $this->jsonBody($this->refresh($t['refresh'], $t['client_id']));
        $this->svc->advance(86400 * 30 - 1);
        $t3 = $this->jsonBody($this->refresh($t2['refresh_token'], $t['client_id']));
        $this->svc->advance(86400 * 29);
        $r = $this->refresh($t3['refresh_token'], $t['client_id']);
        $this->assertSame(200, $r->status, 'day 89 + a bit');
        $t4 = $this->jsonBody($r);
        $this->svc->advance(86400 * 2);
        $r = $this->refresh($t4['refresh_token'], $t['client_id']);
        $this->assertSame(400, $r->status, 'the grant is more than 90 days old');
    }

    // ------------------------------------------------------------ revocation

    public function testRevocationRules(): void
    {
        $t = $this->connect();
        $rev = fn(array $f) => $this->doOauth($this->req('POST', '/oauth/revoke', [], $f));
        $ok = function (HttpResponse $r) {
            $this->assertSame(200, $r->status);
            $this->assertSame('{}', $r->body);
            $this->assertSame('no-store', $r->headers['Cache-Control']);
        };
        $ok($rev([]));
        $ok($rev(['token' => 'unknown', 'client_id' => $t['client_id']]));
        $ok($rev(['token' => $t['access']]));
        $ok($rev(['token' => $t['access'], 'client_id' => '']));
        $ok($rev(['token' => $t['access'], 'client_id' => 'someone-else']));
        $ok($rev(['token' => str_repeat('a', 201), 'client_id' => $t['client_id']]));
        $this->assertNull($this->pdo()->query('SELECT revoked_at FROM oauth_grants')->fetchColumn());
        $ok($rev(['token' => $t['refresh'], 'client_id' => $t['client_id']]));
        $g = $this->pdo()->query('SELECT * FROM oauth_grants')->fetch();
        $this->assertNotNull($g['revoked_at']);
        $this->assertSame('client_revoked', $g['revoke_reason']);
        $this->assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM oauth_tokens WHERE revoked_at IS NULL')->fetchColumn());
        $a = $this->lastAudit('oauth_revoke');
        $this->assertSame('grant revoked by client', $a['summary']);
        $this->assertSame('oauth', $a['actor']);
        $this->assertSame($t['grant_id'], $a['grant_id']);
        $this->assertSame(['client_id' => $t['client_id']], json_decode($a['args_json'], true));
        // the access token also ends the whole grant
        $this->svc->advance(30);
        $t2 = $this->connect();
        $ok($rev(['token' => $t2['access'], 'client_id' => $t2['client_id']]));
        $this->assertNull((new TokenStore($this->svc))->authenticate($t2['access']));
        $this->assertSame(400, $this->refresh($t2['refresh'], $t2['client_id'])->status);
    }

    public function testRevokeRateLimitIs30PerMinute(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->assertSame(200, $this->doOauth($this->req('POST', '/oauth/revoke', [], ['token' => 'x']))->status, "call $i");
        }
        $r = $this->doOauth($this->req('POST', '/oauth/revoke', [], ['token' => 'x']));
        $this->assertSame(429, $r->status);
        $this->assertSame(['error' => 'temporarily_unavailable', 'error_description' => 'Too many requests.'], $this->jsonBody($r));
        $ts = $this->now()->getTimestamp();
        $this->assertSame(gmdate('Y-m-d H:i:s', $ts + 60), $this->pdo()->query("SELECT expires_at FROM rate_limits WHERE bucket = 'oauth_revoke:203.0.113.9:" . intdiv($ts, 60) * 60 . "'")->fetchColumn());
    }

    // ------------------------------------------------------------ scopes

    public function testScopesHelpers(): void
    {
        $this->assertSame([], Scopes::parse(null));
        $this->assertSame([], Scopes::parse(''));
        $this->assertSame([], Scopes::parse("  \t\n "));
        $this->assertSame(['a', 'b'], Scopes::parse("  a \t b\nb  a "));
        $this->assertSame(['credits:write', 'bookings:read'], Scopes::parse('credits:write bookings:read credits:write'));
        $this->assertTrue(Scopes::allValid([]));
        $this->assertTrue(Scopes::allValid(Scopes::ALL));
        $this->assertFalse(Scopes::allValid(['bookings:read', 'bookings:*']));
        $this->assertFalse(Scopes::allValid(['admin']));
        $this->assertFalse(Scopes::allValid(['BOOKINGS:READ']));
        $this->assertSame(['bookings:read', 'bookings:write', 'clients:read', 'credits:write'], Scopes::normalize(['credits:write', 'clients:read', 'bookings:write', 'bookings:read', 'bogus']));
        $this->assertSame(['clients:read'], Scopes::normalize(['clients:read', 'clients:read']));
        $this->assertSame([], Scopes::normalize(['x']));
        $this->assertEqualsCanonicalizing(Scopes::ALL, array_keys(Scopes::DESCRIPTIONS));
        $this->assertCount(4, Scopes::DESCRIPTIONS);
    }

    // ------------------------------------------------------------ further OAuth edge cases

    public function testLoopbackHttpOnlyOnANonLiveInstance(): void
    {
        $uris = ['http://localhost/cb', 'http://127.0.0.1/cb', 'http://[::1]/cb', 'http://evil.example/cb', 'http://localhost.evil.example/cb', 'https://localhost/cb'];
        $this->svc->cfg->data['connector']['allowed_redirect_uris'] = $uris;
        $this->svc->cfg->live = true;
        foreach ($uris as $u) {
            $this->assertSame(str_starts_with($u, 'https://'), $this->registry()->redirectAllowed($u), "live: $u");
        }
        $this->svc->cfg->live = false;
        $this->assertTrue($this->registry()->redirectAllowed('http://localhost/cb'));
        $this->assertTrue($this->registry()->redirectAllowed('http://127.0.0.1/cb'));
        $this->assertFalse($this->registry()->redirectAllowed('http://evil.example/cb'));
        $this->assertFalse($this->registry()->redirectAllowed('http://localhost.evil.example/cb'));
        $this->assertTrue($this->registry()->redirectAllowed('https://localhost/cb'));
        $this->svc->cfg->data['connector']['allowed_redirect_uris'] = ['HTTP://LOCALHOST/cb', 'HTTPS://CHATGPT.COM/cb', 'ftp://localhost/cb'];
        $this->assertTrue($this->registry()->redirectAllowed('HTTP://LOCALHOST/cb'), 'scheme and host are compared in lower case');
        $this->assertTrue($this->registry()->redirectAllowed('HTTPS://CHATGPT.COM/cb'));
        $this->assertFalse($this->registry()->redirectAllowed('ftp://localhost/cb'));
    }

    public function testRedirectUserinfoAndConfigFiltering(): void
    {
        $this->svc->cfg->data['connector']['allowed_redirect_uris'] = ['https://:pw@chatgpt.com/cb', 'https://user:@chatgpt.com/cb'];
        $this->assertFalse($this->registry()->redirectAllowed('https://:pw@chatgpt.com/cb'), 'a password without a user name');
        $this->assertFalse($this->registry()->redirectAllowed('https://user:@chatgpt.com/cb'));
        $this->svc->cfg->data['connector']['allowed_redirect_uris'] = [];
        $this->svc->cfg->data['connector']['allowed_redirect_prefixes'] = [5, null, ['x'], 'https://chatgpt.com/a/'];
        $this->assertTrue($this->registry()->redirectAllowed('https://chatgpt.com/a/ok'), 'non-string prefixes are dropped instead of crashing');
        $this->svc->cfg->data['connector']['allowed_redirect_uris'] = [5, ['x'], 'https://chatgpt.com/exact'];
        $this->assertTrue($this->registry()->redirectAllowed('https://chatgpt.com/exact'));
    }

    public function testClientLookupEdgeCases(): void
    {
        $reg = $this->registry();
        $url = 'https://chatgpt.com/' . str_repeat('a', 255 - strlen('https://chatgpt.com/'));
        $this->assertSame(255, strlen($url));
        $this->cimd->docs[$url] = ['client_id' => $url, 'redirect_uris' => [self::REDIRECT]];
        $this->assertSame($url, $reg->find($url)['client_id'], 'a 255 character id is accepted');
        $this->assertThrows(fn() => $reg->find($url . 'a'));
        $this->pdo()->prepare("INSERT INTO oauth_clients (client_id, client_name, redirect_uris, registration, created_at) VALUES (?,?,?,?,?)")
            ->execute(['mixed', 'M', json_encode([5, self::REDIRECT, null, 'https://chatgpt.com/connector/oauth/b']), 'dcr', '2026-11-09 08:00:00']);
        $this->assertSame([self::REDIRECT, 'https://chatgpt.com/connector/oauth/b'], $reg->find('mixed')['redirect_uris'], 'non-strings dropped, list re-indexed');
        $this->pdo()->prepare("INSERT INTO oauth_clients (client_id, client_name, redirect_uris, registration, created_at) VALUES (?,?,?,?,?)")
            ->execute(['broken', 'B', 'not json', 'dcr', '2026-11-09 08:00:00']);
        $this->assertSame([], $reg->find('broken')['redirect_uris']);
        $this->assertSame('2026-11-09 08:00:00', $reg->find('broken')['created_at']);
        $this->assertSame('dcr', $reg->find('broken')['registration']);
    }

    public function testErrorRedirectEchoesStateUpTo500Characters(): void
    {
        $id = $this->registerClient();
        [, $c] = $this->pkce();
        $q = $this->authorizeQuery($id, $c, ['response_type' => 'token']);
        $this->assertSame(str_repeat('s', 500), $this->authErr(['state' => str_repeat('s', 500)] + $q)['state']);
        $this->assertArrayNotHasKey('state', $this->authErr(['state' => str_repeat('s', 501)] + $q));
        $this->assertArrayNotHasKey('state', $this->authErr(['state' => ''] + $q));
    }

    public function testScopesParseKeepsAListAfterDuplicates(): void
    {
        $this->assertSame(['a', 'b'], Scopes::parse('a a b'));
        $this->assertSame([0, 1], array_keys(Scopes::parse('a a b')));
        $this->assertSame(['x'], Scopes::parse('  x  '));
    }

    public function testCorruptAuthorizationRecordIsTreatedAsGone(): void
    {
        $b = $this->begin();
        $key = 'authreq:' . hash('sha256', $b['req']['req']);
        foreach (['{"scopes":["bookings:read"]}', '{"csrf_hash":"x"}', '[]', 'not json', '"str"'] as $json) {
            $this->pdo()->prepare('UPDATE idempotency_keys SET result_json = ? WHERE scope_key = ?')->execute([$json, $key]);
            $r = $this->doOauth($this->req('POST', '/oauth/authorize', [], ['decision' => 'allow'] + $b['req']));
            $this->assertSame(400, $r->status, $json);
            $this->assertStringContainsString('expired or was already used', $r->body, $json);
        }
    }

    public function testInternalFailuresAreGenericAndAuditFailuresAreSwallowed(): void
    {
        $prev = ini_set('error_log', '/dev/null'); // keep the expected error_log() lines off stderr
        $this->pdo()->exec('DROP TABLE audit_log');
        $id = $this->registerClient(); // the audit write fails, the registration still succeeds
        $this->assertSame(22, strlen($id));
        $this->pdo()->exec('DROP TABLE oauth_clients');
        $r = $this->doOauth($this->req('POST', '/oauth/register', [], [], ['content-type' => 'application/json'], json_encode(['redirect_uris' => [self::REDIRECT]]), '198.51.100.77'));
        $this->assertSame(500, $r->status);
        $this->assertSame(['error' => 'server_error', 'error_description' => 'Something went wrong.'], $this->jsonBody($r));
        ini_set('error_log', (string) $prev);
    }

    public function testAuditReasonIsCappedAt40Characters(): void
    {
        $r = $this->doOauth($this->req('POST', '/oauth/register', [], [], ['content-type' => 'application/json'], json_encode(['redirect_uris' => [self::REDIRECT], 'token_endpoint_auth_method' => 'client_secret_basic'])));
        $this->assertSame(400, $r->status);
        $reason = json_decode($this->lastAudit('oauth_register')['args_json'], true)['reason'];
        $this->assertSame('only public clients are supported (token', $reason);
        $this->assertSame(40, strlen($reason));
        $ok = $this->doOauth($this->req('POST', '/oauth/register', [], [], ['content-type' => 'application/json'], json_encode(['client_name' => str_repeat('N', 100), 'redirect_uris' => [self::REDIRECT]])));
        $this->assertSame(201, $ok->status);
        $this->assertSame('client registered: ' . str_repeat('N', 100), $this->lastAudit('oauth_register')['summary']);
    }
}
