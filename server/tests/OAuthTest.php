<?php
declare(strict_types=1);

namespace StretchEffect\Tests;

require_once __DIR__ . '/Support/FakeTestCase.php';

use StretchEffect\OAuth\HttpCimdFetcher;
use StretchEffect\Tests\Support\{FakeServices, FakeTestCase, FakeTotp};

final class OAuthTest extends FakeTestCase
{
    // ------------------------------------------------------------ metadata

    public function testProtectedResourceMetadata(): void
    {
        foreach (['/.well-known/oauth-protected-resource', '/.well-known/oauth-protected-resource/mcp'] as $p) {
            $d = $this->jsonBody($this->doOauth($this->req('GET', $p)));
            $this->assertSame('https://studio.test/mcp', $d['resource']);
            $this->assertSame(['https://studio.test'], $d['authorization_servers']);
            $this->assertSame(['bookings:read', 'bookings:write', 'clients:read', 'credits:write'], $d['scopes_supported']);
            $this->assertSame(['header'], $d['bearer_methods_supported']);
        }
    }

    public function testAuthorizationServerMetadata(): void
    {
        $d = $this->jsonBody($this->doOauth($this->req('GET', '/.well-known/oauth-authorization-server')));
        $this->assertSame('https://studio.test', $d['issuer']);
        $this->assertSame('https://studio.test/oauth/authorize', $d['authorization_endpoint']);
        $this->assertSame('https://studio.test/oauth/token', $d['token_endpoint']);
        $this->assertSame('https://studio.test/oauth/register', $d['registration_endpoint']);
        $this->assertSame('https://studio.test/oauth/revoke', $d['revocation_endpoint']);
        $this->assertSame(['S256'], $d['code_challenge_methods_supported']);
        $this->assertSame(['none'], $d['token_endpoint_auth_methods_supported']);
        $this->assertSame(['authorization_code', 'refresh_token'], $d['grant_types_supported']);
        $this->assertTrue($d['client_id_metadata_document_supported']);
        $this->assertTrue($d['authorization_response_iss_parameter_supported']);
    }

    public function testKillSwitchReturns503(): void
    {
        $this->svc->cfg->data['connector']['enabled'] = false;
        $this->assertSame(503, $this->doOauth($this->req('GET', '/.well-known/oauth-authorization-server'))->status);
        $this->assertSame(503, $this->doOauth($this->req('POST', '/oauth/token'))->status);
    }

    public function testUnrelatedPathIsNotHandled(): void
    {
        $this->assertNull($this->oauth()->handle($this->req('GET', '/api/x'), $this->svc));
    }

    // ------------------------------------------------------------ dynamic client registration

    public function testDcrRejectsNonAllowListedRedirect(): void
    {
        foreach (['https://evil.example/cb', 'http://chatgpt.com/connector_platform_oauth_redirect', 'https://chatgpt.com/connector_platform_oauth_redirect#x',
                     'https://chatgpt.com/connector/oauth/*', 'https://chatgpt.com/connector/oauth/../x', 'https://chatgpt.com/connector/oauthx/abc',
                     'https://chatgpt.com/connector/oauth/abc?x=1', 'https://chatgpt.com@evil.example/connector_platform_oauth_redirect'] as $bad) {
            $r = $this->doOauth($this->req('POST', '/oauth/register', [], [], ['content-type' => 'application/json'],
                json_encode(['client_name' => 'x', 'redirect_uris' => [$bad]])));
            $this->assertSame(400, $r->status, $bad);
            $this->assertSame('invalid_redirect_uri', $this->jsonBody($r)['error'], $bad);
        }
    }

    public function testDcrAcceptsAllowListedAndPrefixRedirects(): void
    {
        $id = $this->registerClient('ChatGPT', [self::REDIRECT, 'https://chatgpt.com/connector/oauth/AbC_123-x']);
        $row = $this->svc->database->pdo->query("SELECT * FROM oauth_clients WHERE client_id = '$id'")->fetch();
        $this->assertSame('dcr', $row['registration']);
    }

    public function testDcrRejectsConfidentialClientsAndTooManyUris(): void
    {
        $r = $this->doOauth($this->req('POST', '/oauth/register', [], [], ['content-type' => 'application/json'],
            json_encode(['redirect_uris' => [self::REDIRECT], 'token_endpoint_auth_method' => 'client_secret_basic'])));
        $this->assertSame('invalid_client_metadata', $this->jsonBody($r)['error']);
        $this->svc->cfg->data['connector']['allowed_redirect_prefixes'] = ['https://chatgpt.com/connector/oauth/'];
        $many = array_map(fn($i) => "https://chatgpt.com/connector/oauth/a$i", range(1, 6));
        $r = $this->doOauth($this->req('POST', '/oauth/register', [], [], ['content-type' => 'application/json'], json_encode(['redirect_uris' => $many])));
        $this->assertSame(400, $r->status);
    }

    public function testDcrSanitizesClientName(): void
    {
        $id = $this->registerClient("<script>alert(1)</script>Evil\n\x00" . str_repeat('x', 300));
        $name = $this->svc->database->pdo->query("SELECT client_name FROM oauth_clients WHERE client_id = '$id'")->fetchColumn();
        $this->assertStringNotContainsString('<', $name);
        $this->assertLessThanOrEqual(100, mb_strlen($name));
        $this->assertStringNotContainsString("\n", $name);
    }

    public function testDcrIsRateLimited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->registerClient();
        }
        $r = $this->doOauth($this->req('POST', '/oauth/register', [], [], ['content-type' => 'application/json'], json_encode(['redirect_uris' => [self::REDIRECT]])));
        $this->assertSame(429, $r->status);
    }

    // ------------------------------------------------------------ CIMD

    public function testCimdClientIsFetchedValidatedAndCached(): void
    {
        $url = 'https://chatgpt.com/oauth/client.json';
        $this->cimd->docs[$url] = ['client_id' => $url, 'client_name' => 'ChatGPT', 'redirect_uris' => [self::REDIRECT], 'token_endpoint_auth_method' => 'none'];
        [, $challenge] = $this->pkce();
        $r = $this->doOauth($this->req('GET', '/oauth/authorize', $this->authorizeQuery($url, $challenge)));
        $this->assertSame(200, $r->status);
        $this->doOauth($this->req('GET', '/oauth/authorize', $this->authorizeQuery($url, $challenge)));
        $this->assertSame(1, $this->cimd->calls, 'second request must use the cache');
        $this->assertSame('cimd', $this->svc->database->pdo->query("SELECT registration FROM oauth_clients WHERE client_id = '$url'")->fetchColumn());
    }

    public function testCimdRejectsIdMismatchBadHostAndBadRedirect(): void
    {
        [, $challenge] = $this->pkce();
        $url = 'https://chatgpt.com/oauth/client.json';
        $this->cimd->docs[$url] = ['client_id' => 'https://chatgpt.com/other.json', 'redirect_uris' => [self::REDIRECT]];
        $this->assertSame(400, $this->doOauth($this->req('GET', '/oauth/authorize', $this->authorizeQuery($url, $challenge)))->status);

        $evil = 'https://evil.example/client.json';
        $this->cimd->docs[$evil] = ['client_id' => $evil, 'redirect_uris' => [self::REDIRECT]];
        $this->assertSame(400, $this->doOauth($this->req('GET', '/oauth/authorize', $this->authorizeQuery($evil, $challenge)))->status);
        $this->assertSame(0, $this->cimd->calls === 1 ? 0 : 0); // fetcher is only called for allow-listed hosts
        $this->assertSame(1, $this->cimd->calls);

        $u2 = 'https://openai.com/c.json';
        $this->cimd->docs[$u2] = ['client_id' => $u2, 'redirect_uris' => ['https://evil.example/cb']];
        $this->assertSame(400, $this->doOauth($this->req('GET', '/oauth/authorize', $this->authorizeQuery($u2, $challenge)))->status);
    }

    public function testSsrfGuardRejectsPrivateAddresses(): void
    {
        foreach (['127.0.0.1', '10.0.0.5', '192.168.1.1', '172.16.0.9', '169.254.169.254', '0.0.0.0', '::1', 'fe80::1', 'fc00::1', '::ffff:127.0.0.1', 'garbage'] as $ip) {
            $this->assertFalse(HttpCimdFetcher::isPublicIp($ip), $ip);
        }
        $this->assertTrue(HttpCimdFetcher::isPublicIp('8.8.8.8'));
        $this->assertTrue(HttpCimdFetcher::isPublicIp('2606:4700:4700::1111'));
        $f = new HttpCimdFetcher(['chatgpt.com']);
        foreach (['http://chatgpt.com/x', 'https://evil.example/x', 'https://chatgpt.com:8443/x', 'https://u:p@chatgpt.com/x', 'https://localhost/x'] as $u) {
            try {
                $f->fetch($u);
                $this->fail("should refuse $u");
            } catch (\RuntimeException $e) {
                $this->assertStringStartsWith('cimd:', $e->getMessage());
            }
        }
    }

    // ------------------------------------------------------------ authorize

    public function testAuthorizeShowsBrandedConsentPageWithSecurityHeaders(): void
    {
        $id = $this->registerClient('Pretend <b>ChatGPT</b>');
        [, $challenge] = $this->pkce();
        $r = $this->doOauth($this->req('GET', '/oauth/authorize', $this->authorizeQuery($id, $challenge)));
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString("frame-ancestors 'none'", $r->headers['Content-Security-Policy']);
        $this->assertSame('no-store', $r->headers['Cache-Control']);
        $this->assertSame('DENY', $r->headers['X-Frame-Options']);
        $this->assertStringContainsString('chatgpt.com', $r->body);
        $this->assertStringContainsString('#c8102e', $r->body);
        $this->assertStringContainsString('name="totp"', $r->body);
        $this->assertStringNotContainsString('<b>ChatGPT</b>', $r->body);
        $this->assertSame(4, substr_count($r->body, 'name="scope[]"'));
    }

    public function testAuthorizeRejectsBadClientAndRedirectWithHtmlNotRedirect(): void
    {
        [, $challenge] = $this->pkce();
        $id = $this->registerClient();
        $r = $this->doOauth($this->req('GET', '/oauth/authorize', $this->authorizeQuery('nope', $challenge)));
        $this->assertSame(400, $r->status);
        $this->assertArrayNotHasKey('Location', $r->headers);
        $r = $this->doOauth($this->req('GET', '/oauth/authorize', $this->authorizeQuery($id, $challenge, ['redirect_uri' => 'https://evil.example/cb'])));
        $this->assertSame(400, $r->status);
        $this->assertArrayNotHasKey('Location', $r->headers);
        // an allow-listed URI that this client did not register is also refused
        $r = $this->doOauth($this->req('GET', '/oauth/authorize', $this->authorizeQuery($id, $challenge, ['redirect_uri' => 'https://chatgpt.com/connector/oauth/other'])));
        $this->assertSame(400, $r->status);
    }

    public function testAuthorizeRejectsMissingPkcePlainMethodAndMissingState(): void
    {
        $id = $this->registerClient();
        [, $challenge] = $this->pkce();
        $q = $this->authorizeQuery($id, $challenge);

        $noPkce = $q;
        unset($noPkce['code_challenge']);
        $p = $this->locationParams($this->doOauth($this->req('GET', '/oauth/authorize', $noPkce)));
        $this->assertSame('invalid_request', $p['error']);
        $this->assertSame($q['state'], $p['state']);
        $this->assertSame('https://studio.test', $p['iss']);

        $p = $this->locationParams($this->doOauth($this->req('GET', '/oauth/authorize', ['code_challenge_method' => 'plain'] + $q)));
        $this->assertSame('invalid_request', $p['error']);

        $noMethod = $q;
        unset($noMethod['code_challenge_method']);
        $this->assertSame('invalid_request', $this->locationParams($this->doOauth($this->req('GET', '/oauth/authorize', $noMethod)))['error']);

        $noState = $q;
        unset($noState['state']);
        $this->assertSame('invalid_request', $this->locationParams($this->doOauth($this->req('GET', '/oauth/authorize', $noState)))['error']);

        $p = $this->locationParams($this->doOauth($this->req('GET', '/oauth/authorize', ['resource' => 'https://other.example/mcp'] + $q)));
        $this->assertSame('invalid_target', $p['error']);
        $p = $this->locationParams($this->doOauth($this->req('GET', '/oauth/authorize', ['scope' => 'bookings:read openid'] + $q)));
        $this->assertSame('invalid_scope', $p['error']);
        $p = $this->locationParams($this->doOauth($this->req('GET', '/oauth/authorize', ['response_type' => 'token'] + $q)));
        $this->assertSame('unsupported_response_type', $p['error']);
    }

    // ------------------------------------------------------------ consent

    private function page(?string $clientId = null): array
    {
        $clientId ??= $this->registerClient();
        [$verifier, $challenge] = $this->pkce();
        $q = $this->authorizeQuery($clientId, $challenge);
        $r = $this->doOauth($this->req('GET', '/oauth/authorize', $q));
        return [$this->hidden($r->body), $clientId, $verifier, $q];
    }

    public function testConsentRefusesNonOwnerEvenWithValidCode(): void
    {
        [$h] = $this->page();
        $form = $this->consentForm($h, ['email' => 'thandi@example.com', 'password' => FakeServices::CLIENT_PASSWORD]);
        $r = $this->doOauth($this->req('POST', '/oauth/authorize', [], $form));
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('Sign-in failed', $r->body);
        $this->assertSame(0, (int) $this->svc->database->pdo->query('SELECT COUNT(*) FROM oauth_codes')->fetchColumn());
    }

    public function testConsentRefusesWrongPasswordAndWrongTotpWithSameMessage(): void
    {
        [$h] = $this->page();
        $r1 = $this->doOauth($this->req('POST', '/oauth/authorize', [], $this->consentForm($h, ['password' => 'wrong'])));
        $r2 = $this->doOauth($this->req('POST', '/oauth/authorize', [], $this->consentForm($h, ['totp' => '000000'])));
        $this->assertStringContainsString('Sign-in failed', $r1->body);
        $this->assertStringContainsString('Sign-in failed', $r2->body);
        $this->assertSame(0, (int) $this->svc->database->pdo->query('SELECT COUNT(*) FROM oauth_codes')->fetchColumn());
        $this->assertGreaterThanOrEqual(2, (int) $this->svc->database->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'oauth_login' AND status = 'denied'")->fetchColumn());
    }

    public function testConsentRejectsBadCsrfAndUnknownRequest(): void
    {
        [$h] = $this->page();
        $r = $this->doOauth($this->req('POST', '/oauth/authorize', [], $this->consentForm($h, ['csrf' => 'forged'])));
        $this->assertSame(403, $r->status);
        $r = $this->doOauth($this->req('POST', '/oauth/authorize', [], $this->consentForm($h, ['req' => 'nonexistent'])));
        $this->assertSame(400, $r->status);
        $this->assertArrayNotHasKey('Location', $r->headers);
    }

    public function testAuthorizationRequestIsSingleUseAndExpires(): void
    {
        [$h] = $this->page();
        $ok = $this->doOauth($this->req('POST', '/oauth/authorize', [], $this->consentForm($h)));
        $this->assertArrayHasKey('code', $this->locationParams($ok));
        $this->svc->advance(31); // fresh TOTP step
        $again = $this->doOauth($this->req('POST', '/oauth/authorize', [], $this->consentForm($h)));
        $this->assertSame(400, $again->status, 'a consumed request cannot be replayed');

        [$h2] = $this->page();
        $this->svc->advance(601);
        $late = $this->doOauth($this->req('POST', '/oauth/authorize', [], $this->consentForm($h2)));
        $this->assertSame(400, $late->status);
    }

    public function testDenyRedirectsWithAccessDeniedStateAndIss(): void
    {
        [$h, , , $q] = $this->page();
        $r = $this->doOauth($this->req('POST', '/oauth/authorize', [], ['decision' => 'deny'] + $h));
        $p = $this->locationParams($r);
        $this->assertSame('access_denied', $p['error']);
        $this->assertSame($q['state'], $p['state']);
        $this->assertSame('https://studio.test', $p['iss']);
        $this->assertStringStartsWith(self::REDIRECT . '?', $r->headers['Location']);
    }

    public function testApprovalRedirectsWithCodeStateIssAndAudits(): void
    {
        [$h, , , $q] = $this->page();
        $r = $this->doOauth($this->req('POST', '/oauth/authorize', [], $this->consentForm($h)));
        $p = $this->locationParams($r);
        $this->assertSame($q['state'], $p['state']);
        $this->assertSame('https://studio.test', $p['iss']);
        $this->assertGreaterThan(30, strlen($p['code']));
        $stored = $this->svc->database->pdo->query('SELECT code_hash FROM oauth_codes')->fetchColumn();
        $this->assertSame(hash('sha256', $p['code']), $stored, 'only the hash of the code is stored');
        $this->assertSame(1, (int) $this->svc->database->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'oauth_consent' AND status = 'ok'")->fetchColumn());
    }

    public function testOwnerCanUntickScopes(): void
    {
        $t = $this->connect(['bookings:read']);
        $scopes = $this->svc->database->pdo->query('SELECT scopes FROM oauth_grants')->fetchColumn();
        $this->assertSame('bookings:read', $scopes);
        $this->assertNotEmpty($t['access']);
    }

    public function testConsentWithNoScopesTickedIsRefused(): void
    {
        [$h] = $this->page();
        $r = $this->doOauth($this->req('POST', '/oauth/authorize', [], $this->consentForm($h, ['scope' => []])));
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('at least one', $r->body);
    }

    public function testConsentAttemptsAreRateLimited(): void
    {
        [$h] = $this->page();
        for ($i = 0; $i < 5; $i++) {
            $this->doOauth($this->req('POST', '/oauth/authorize', [], $this->consentForm($h, ['password' => 'wrong'])));
        }
        // sixth attempt is refused even with correct credentials
        $r = $this->doOauth($this->req('POST', '/oauth/authorize', [], $this->consentForm($h)));
        $this->assertSame(429, $r->status);
        $this->assertArrayNotHasKey('Location', $r->headers);
    }

    public function testRequestIsDroppedAfterTooManyFailedAttemptsOnOneRecord(): void
    {
        [$h] = $this->page();
        // different IPs so the IP limiter does not interfere
        $last = null;
        for ($i = 0; $i < 6; $i++) {
            $last = $this->doOauth($this->req('POST', '/oauth/authorize', [], $this->consentForm($h, ['password' => 'wrong']), [], '', "198.51.100.$i"));
        }
        $this->assertSame(429, $last->status);
    }

    // ------------------------------------------------------------ token endpoint

    /** @return array{code:string,client_id:string,verifier:string} */
    private function code(): array
    {
        [$h, $cid, $verifier] = $this->page();
        $p = $this->locationParams($this->doOauth($this->req('POST', '/oauth/authorize', [], $this->consentForm($h))));
        return ['code' => $p['code'], 'client_id' => $cid, 'verifier' => $verifier];
    }

    /** @param array<string,string> $over */
    private function exchange(array $c, array $over = []): \StretchEffect\Contracts\HttpResponse
    {
        return $this->doOauth($this->req('POST', '/oauth/token', [], $over + [
            'grant_type' => 'authorization_code', 'code' => $c['code'], 'redirect_uri' => self::REDIRECT,
            'client_id' => $c['client_id'], 'code_verifier' => $c['verifier'], 'resource' => 'https://studio.test/mcp',
        ]));
    }

    public function testTokenExchangeIssuesOpaqueHashedTokens(): void
    {
        $c = $this->code();
        $r = $this->exchange($c);
        $this->assertSame(200, $r->status);
        $this->assertSame('no-store', $r->headers['Cache-Control']);
        $j = $this->jsonBody($r);
        $this->assertSame('Bearer', $j['token_type']);
        $this->assertSame(3600, $j['expires_in']);
        $this->assertSame('bookings:read bookings:write clients:read credits:write', $j['scope']);
        $pdo = $this->svc->database->pdo;
        $hashes = $pdo->query('SELECT token_hash, kind, expires_at FROM oauth_tokens ORDER BY kind')->fetchAll();
        $this->assertCount(2, $hashes);
        $this->assertSame(hash('sha256', $j['access_token']), $hashes[0]['token_hash']);
        $this->assertSame(hash('sha256', $j['refresh_token']), $hashes[1]['token_hash']);
        $this->assertSame('2026-11-09 09:00:00', $hashes[0]['expires_at']);
        $this->assertSame('2026-12-09 08:00:00', $hashes[1]['expires_at']);
        $this->assertSame('https://studio.test/mcp', $pdo->query('SELECT resource FROM oauth_grants')->fetchColumn());
    }

    public function testCodeIsSingleUseAndReplayRevokesTheGrant(): void
    {
        $c = $this->code();
        $first = $this->jsonBody($this->exchange($c));
        $second = $this->exchange($c);
        $this->assertSame(400, $second->status);
        $this->assertSame('invalid_grant', $this->jsonBody($second)['error']);
        $this->assertNotNull($this->svc->database->pdo->query('SELECT revoked_at FROM oauth_grants')->fetchColumn());
        $this->assertSame('code_reuse', $this->svc->database->pdo->query('SELECT revoke_reason FROM oauth_grants')->fetchColumn());
        // the access token issued from the replayed code no longer works
        $mcp = $this->mcpRaw($first['access_token'], '{"jsonrpc":"2.0","id":1,"method":"ping"}');
        $this->assertSame(401, $mcp->status);
    }

    public function testPkceVerifierMismatchIsRejected(): void
    {
        $c = $this->code();
        [$otherVerifier] = $this->pkce();
        $r = $this->exchange($c, ['code_verifier' => $otherVerifier]);
        $this->assertSame(400, $r->status);
        $this->assertSame('invalid_grant', $this->jsonBody($r)['error']);
        // the right verifier still works afterwards (a wrong guess does not burn the code)
        $this->assertSame(200, $this->exchange($c)->status);
        $this->assertSame(400, $this->exchange($c, ['code_verifier' => 'short'])->status);
    }

    public function testRedirectUriClientIdAndResourceMustMatchAtTokenEndpoint(): void
    {
        $c = $this->code();
        $this->assertSame(400, $this->exchange($c, ['redirect_uri' => 'https://chatgpt.com/connector/oauth/x'])->status);
        $this->assertSame(400, $this->exchange($c, ['client_id' => 'someone-else'])->status);
        $r = $this->exchange($c, ['resource' => 'https://studio.test/other']);
        $this->assertSame('invalid_target', $this->jsonBody($r)['error']);
        $this->assertSame(200, $this->exchange($c)->status);
    }

    public function testCodeExpiresAfter60Seconds(): void
    {
        $c = $this->code();
        $this->svc->advance(61);
        $this->assertSame(400, $this->exchange($c)->status);
    }

    public function testTokenEndpointRejectsOtherGrantTypesAndContentTypes(): void
    {
        $r = $this->doOauth($this->req('POST', '/oauth/token', [], ['grant_type' => 'client_credentials']));
        $this->assertSame('unsupported_grant_type', $this->jsonBody($r)['error']);
        $r = $this->doOauth($this->req('POST', '/oauth/token', [], [], ['content-type' => 'application/json'], '{}'));
        $this->assertSame('invalid_request', $this->jsonBody($r)['error']);
        $this->assertSame(405, $this->doOauth($this->req('GET', '/oauth/token'))->status);
    }

    public function testRefreshRotatesAndReuseRevokesTheGrant(): void
    {
        $t = $this->connect();
        $refresh = fn(string $rt) => $this->doOauth($this->req('POST', '/oauth/token', [], [
            'grant_type' => 'refresh_token', 'refresh_token' => $rt, 'client_id' => $t['client_id'], 'resource' => 'https://studio.test/mcp']));
        $first = $refresh($t['refresh']);
        $this->assertSame(200, $first->status, $first->body);
        $new = $this->jsonBody($first);
        $this->assertNotSame($t['refresh'], $new['refresh_token']);
        $this->assertNotSame($t['access'], $new['access_token']);
        $this->assertSame(200, $this->mcpRaw($new['access_token'], '{"jsonrpc":"2.0","id":1,"method":"ping"}')->status);

        // replay the OLD refresh token: refused, grant revoked, new tokens die too
        $replay = $refresh($t['refresh']);
        $this->assertSame(400, $replay->status);
        $this->assertSame('invalid_grant', $this->jsonBody($replay)['error']);
        $this->assertSame('refresh_reuse', $this->svc->database->pdo->query('SELECT revoke_reason FROM oauth_grants')->fetchColumn());
        $this->assertSame(401, $this->mcpRaw($new['access_token'], '{"jsonrpc":"2.0","id":1,"method":"ping"}')->status);
        $this->assertSame(400, $refresh($new['refresh_token'])->status);
        $this->assertSame(1, (int) $this->svc->database->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'refresh_reuse'")->fetchColumn());
    }

    public function testRefreshRequiresSameClientAndUnexpired(): void
    {
        $t = $this->connect();
        $r = $this->doOauth($this->req('POST', '/oauth/token', [], ['grant_type' => 'refresh_token', 'refresh_token' => $t['refresh'], 'client_id' => 'other']));
        $this->assertSame(400, $r->status);
        $this->svc->advance(31 * 86400);
        $r = $this->doOauth($this->req('POST', '/oauth/token', [], ['grant_type' => 'refresh_token', 'refresh_token' => $t['refresh'], 'client_id' => $t['client_id']]));
        $this->assertSame(400, $r->status);
    }

    public function testRevocationEndsTheGrant(): void
    {
        $t = $this->connect();
        $r = $this->doOauth($this->req('POST', '/oauth/revoke', [], ['token' => $t['refresh'], 'client_id' => $t['client_id']]));
        $this->assertSame(200, $r->status);
        $this->assertSame(401, $this->mcpRaw($t['access'], '{"jsonrpc":"2.0","id":1,"method":"ping"}')->status);
        // unknown tokens and foreign clients get the same 200 and change nothing
        $this->assertSame(200, $this->doOauth($this->req('POST', '/oauth/revoke', [], ['token' => 'nope']))->status);
        $this->svc->advance(31); // TOTP codes are single use per time step
        $t2 = $this->connect();
        $this->doOauth($this->req('POST', '/oauth/revoke', [], ['token' => $t2['access'], 'client_id' => 'someone-else']));
        $this->assertSame(200, $this->mcpRaw($t2['access'], '{"jsonrpc":"2.0","id":1,"method":"ping"}')->status);
    }

    public function testTotpCannotBeReusedForASecondApproval(): void
    {
        [$h1] = $this->page();
        [$h2] = $this->page();
        $this->assertArrayHasKey('code', $this->locationParams($this->doOauth($this->req('POST', '/oauth/authorize', [], $this->consentForm($h1)))));
        $r = $this->doOauth($this->req('POST', '/oauth/authorize', [], $this->consentForm($h2)));
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('Sign-in failed', $r->body);
    }
}
