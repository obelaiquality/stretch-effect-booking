<?php
declare(strict_types=1);

namespace StretchEffect\Tests\Fuzz;

use StretchEffect\Contracts\HttpResponse;
use StretchEffect\OAuth\OAuthRoutes;
use StretchEffect\OAuth\Pairing;
use StretchEffect\Services\Totp;

/**
 * Fuzzes the OAuth authorization server (register, authorize GET/POST, token, revoke, metadata) with hostile bodies and
 * redirect_uri tricks. The test keeps its own record of what it legitimately obtained, so the checks do not trust the server:
 * a redirect only ever goes to a registered, allow-listed URI; a token is only ever issued for a code, the matching PKCE
 * verifier, the exact client and redirect URI, once.
 */
final class OAuthFuzzTest extends FuzzCase
{
    private const RESOURCE = 'http://localhost:8090/mcp';
    private const ISS = 'http://localhost:8090';
    private FuzzWorld $w;
    private FuzzCimd $cimd;
    private int $ipSeq = 0;
    /** @var array<string,list<string>> client_id => registered redirect URIs (what the test knows the server stored) */
    private array $clients = [];
    /** @var array<string,array<string,mixed>> code => bookkeeping */
    private array $codes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->w = FuzzWorld::create();
        $this->cimd = new FuzzCimd();
        foreach ([[RedirectOracle::OFFICIAL], [RedirectOracle::PREFIX . 'abc123_x', RedirectOracle::OFFICIAL]] as $uris) {
            $r = $this->call('POST', '/oauth/register', [], [], ['content-type' => 'application/json'], json_encode(['client_name' => 'Legit', 'redirect_uris' => $uris]));
            $j = json_decode($r->body, true);
            $this->clients[$j['client_id']] = $j['redirect_uris'];
        }
    }

    private function ip(): string
    {
        $n = ++$this->ipSeq;
        return '10.' . (($n >> 16) & 255) . '.' . (($n >> 8) & 255) . '.' . ($n & 255);
    }

    /** @param array<string,string> $query @param array<string,mixed> $form @param array<string,string> $headers */
    private function call(string $method, string $path, array $query = [], array $form = [], array $headers = [], string $body = '', ?string $ip = null): HttpResponse
    {
        if ($form !== [] && !isset($headers['content-type'])) {
            $headers['content-type'] = 'application/x-www-form-urlencoded';
        }
        $form = $this->normForm($form);
        // Keep the failure description small: some generated forms are huge or deeply nested.
        $desc = $body !== '' ? substr($body, 0, 2000) : (strlen(serialize($form)) > 20000 ? '[form with ' . count($form) . ' top-level keys, too large to print]' : http_build_query($form));
        $this->describe($method, $path, $desc, $headers, $query);
        $req = FuzzWorld::request($method, $path, $headers, $query, $body, $form, $ip ?? $this->ip());
        $res = (new OAuthRoutes($this->cimd))->handle($req, $this->w->app);
        if ($res === null) {
            return new HttpResponse(404, [], '{"error":"not_found"}');
        }
        $this->checkCommon($res);
        return $res;
    }

    /** What PHP hands a form handler: strings and arrays of strings. */
    private function normForm(array $form): array
    {
        $norm = static function (mixed $v) use (&$norm): mixed {
            return is_array($v) ? array_map($norm, $v) : (is_bool($v) ? ($v ? '1' : '') : (is_scalar($v) ? (string) $v : ($v === null ? '' : 'x')));
        };
        return array_map($norm, $form);
    }

    private function checkCommon(HttpResponse $res): void
    {
        $this->ensure($res->status < 500, "HTTP {$res->status}: " . substr($res->body, 0, 300));
        $this->assertNoLeak($res->body, 'OAuth response');
        foreach ($res->headers as $k => $v) {
            $this->ensure(preg_match('/[\r\n]/', $k . $v) !== 1, "header $k contains a line break: " . json_encode($v));
        }
        $this->ensure(mb_check_encoding($res->body, 'UTF-8'), 'body is not valid UTF-8');
        if (str_contains(strtolower($res->headers['Content-Type'] ?? ''), 'json')) {
            $this->ensure(json_decode($res->body) !== null || $res->body === 'null', 'JSON response does not parse: ' . substr($res->body, 0, 200));
        }
        if (str_contains($res->headers['Content-Type'] ?? '', 'html')) {
            // A raw tag, or an event handler right after a raw quote, means the input escaped its context.
            // Escaped text such as "&quot;&gt;&lt;svg/onload=" is harmless and must not fail.
            $this->ensure(!preg_match('/<(script|img|svg|iframe)\b|["\']\s*\/?\s*on\w+\s*=/i', $res->body), 'unescaped markup in an HTML page');
            $this->ensure(str_contains($res->headers['Content-Security-Policy'] ?? '', "frame-ancestors 'none'") && ($res->headers['X-Frame-Options'] ?? '') === 'DENY', 'HTML page without anti-framing headers');
            $this->ensure(str_contains($res->headers['Cache-Control'] ?? '', 'no-store'), 'HTML page without no-store');
        }
    }

    /** A redirect may only go to one registered URI of the client, which must itself be allow-listed, and must say who we are. */
    private function checkRedirect(HttpResponse $res, ?string $clientId, ?string $expectState): void
    {
        if (!isset($res->headers['Location'])) {
            return;
        }
        $loc = $res->headers['Location'];
        $base = explode('?', $loc, 2)[0];
        $this->ensure($res->status === 302, "Location on a {$res->status} response");
        $this->ensure(!str_contains($loc, '#'), 'redirect carries a fragment');
        $this->ensure(RedirectOracle::allowed($base), "redirect to a URI outside the allow-list: $base");
        $this->ensure($clientId !== null && in_array($base, $this->clients[$clientId] ?? [], true), "redirect to $base which is not registered for the client");
        parse_str((string) parse_url($loc, PHP_URL_QUERY), $q);
        $this->ensure(($q['iss'] ?? null) === self::ISS, 'redirect without the right iss');
        $this->ensure(isset($q['code']) xor isset($q['error']), 'redirect must carry exactly one of code/error');
        if ($expectState !== null && $expectState !== '' && strlen($expectState) <= 500) {
            $this->ensure(($q['state'] ?? null) === $expectState, 'state was not returned unchanged');
        }
    }

    // --------------------------------------------------------------------------------------------- registration

    public function testRegistrationAndRedirectUriTricks(): void
    {
        for ($i = 0; $i < $this->iterations; $i++) {
            $this->caseNo = $i;
            $this->w->advance($this->rng->pick([0, 5, 400]));
            $kind = $this->rng->weighted(['attack' => 45, 'mutate_body' => 35, 'raw' => 12, 'valid' => 8]);
            $base = $this->rng->pick([RedirectOracle::OFFICIAL, RedirectOracle::PREFIX . 'k' . $this->rng->int(1, 99)]);
            $uris = [$base];
            $body = '';
            if ($kind === 'attack') {
                $n = $this->rng->int(1, 3);
                $pool = RedirectOracle::attacks($this->rng, $base);
                $uris = [];
                for ($k = 0; $k < $n; $k++) {
                    $uris[] = $this->rng->pick($pool);
                }
            }
            $meta = ['client_name' => $this->rng->pick(['Legit', 'My App', '<b>bold</b>', Hostile::string($this->rng)]), 'redirect_uris' => $uris, 'token_endpoint_auth_method' => 'none',
                'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code']];
            if ($kind === 'mutate_body') {
                $m = Hostile::mutate($this->rng, $meta);
                $body = Json::encode(Hostile::finish($m));
            } elseif ($kind === 'raw') {
                $body = Hostile::brokenBody($this->rng);
            } else {
                $body = Json::encode($meta);
            }
            $res = $this->call('POST', '/oauth/register', [], [], ['content-type' => $this->rng->pick(['application/json', 'application/json', 'text/plain', ''])], $body);
            $j = json_decode($res->body, true);
            if ($res->status === 201) {
                $this->ensure(is_array($j) && isset($j['client_id'], $j['redirect_uris']) && is_array($j['redirect_uris']) && $j['redirect_uris'] !== [], '201 without client_id/redirect_uris');
                foreach ($j['redirect_uris'] as $u) {
                    $this->ensure(is_string($u) && RedirectOracle::allowed($u), 'registered a redirect URI outside the allow-list: ' . json_encode($u));
                }
                $this->ensure(!isset($j['client_secret']), 'public client got a secret');
                $this->ensure(strlen((string) $j['client_name']) <= 400, 'client name is not capped');
                $this->clients[$j['client_id']] = $j['redirect_uris'];
                $row = $this->w->app->db()->one('SELECT * FROM oauth_clients WHERE client_id = ?', [$j['client_id']]);
                foreach (json_decode((string) $row['redirect_uris'], true) as $u) {
                    $this->ensure(RedirectOracle::allowed($u), 'stored a redirect URI outside the allow-list');
                }
            } else {
                $this->ensure(in_array($res->status, [400, 413, 415, 429, 503], true), "unexpected status {$res->status}");
                $this->ensure(is_array($j) && is_string($j['error'] ?? null), 'error response without error code');
            }
            $this->tally("$kind:{$res->status}");
            $this->endCase();
        }
    }

    // --------------------------------------------------------------------------------------------- authorize (GET + POST)

    /** @return array{0:string,1:string} verifier, challenge */
    private function pkce(): array
    {
        $v = rtrim(strtr(base64_encode($this->rng->bytes(32)), '+/', '-_'), '=');
        return [$v, rtrim(strtr(base64_encode(hash('sha256', $v, true)), '+/', '-_'), '=')];
    }

    private function hasGrant(string $clientId): bool
    {
        return $this->w->app->db()->val('SELECT 1 FROM oauth_grants WHERE client_id = ? AND revoked_at IS NULL LIMIT 1', [$clientId]) !== null;
    }

    /** What the spec says the answer to this authorize request must be, written without reading the server code. */
    private function authorizeShouldShowConsent(array $q, ?array $registeredUris, bool $pairingOpen, bool $hasGrant): bool
    {
        if ($registeredUris === null) {
            return false;
        }
        $redirect = $q['redirect_uri'] ?? '';
        if ($redirect === '' && count($registeredUris) === 1) {
            $redirect = $registeredUris[0];
        }
        if (!in_array($redirect, $registeredUris, true) || !RedirectOracle::allowed($redirect)) {
            return false;
        }
        if (($q['response_type'] ?? '') !== 'code' || ($q['code_challenge_method'] ?? '') !== 'S256') {
            return false;
        }
        if (preg_match('/\A[A-Za-z0-9_-]{43}\z/', $q['code_challenge'] ?? '') !== 1) {
            return false;
        }
        $state = $q['state'] ?? '';
        if ($state === '' || strlen($state) > 500) {
            return false;
        }
        if (($q['resource'] ?? '') !== '' && $q['resource'] !== self::RESOURCE) {
            return false;
        }
        $scope = trim($q['scope'] ?? '');
        if ($scope !== '') {
            foreach (preg_split('/\s+/', $scope) as $s) {
                if (!in_array($s, ['bookings:read', 'bookings:write', 'clients:read', 'credits:write'], true)) {
                    return false;
                }
            }
        }
        return $pairingOpen || $hasGrant;
    }

    /** @return array<string,string> */
    private function authorizeQuery(string $clientId, string $challenge, string $redirect): array
    {
        return ['response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => $redirect, 'state' => 'st-' . $this->rng->hex(4), 'code_challenge' => $challenge,
            'code_challenge_method' => 'S256', 'resource' => self::RESOURCE, 'scope' => 'bookings:read credits:write'];
    }

    public function testAuthorizeNeverRedirectsOutsideTheRegisteredAllowList(): void
    {
        $ids = array_keys($this->clients);
        for ($i = 0; $i < $this->iterations; $i++) {
            $this->caseNo = $i;
            $this->w->advance($this->rng->pick([0, 1, 30]));
            [, $challenge] = $this->pkce();
            $clientId = $this->rng->pick($ids);
            $registered = $this->clients[$clientId];
            $kind = $this->rng->weighted(['valid' => 10, 'redirect_attack' => 35, 'param_mutation' => 30, 'client_attack' => 10, 'cimd' => 10, 'method' => 5]);
            $q = $this->authorizeQuery($clientId, $challenge, $this->rng->pick($registered));
            $cid = $clientId;
            switch ($kind) {
                case 'redirect_attack':
                    $q['redirect_uri'] = $this->rng->pick(RedirectOracle::attacks($this->rng, $this->rng->pick($registered)));
                    break;
                case 'param_mutation':
                    $q = array_map(static fn($v) => is_string($v) ? $v : (string) $v, array_filter(Hostile::mutate($this->rng, $q), static fn($v) => is_scalar($v) || $v === null));
                    $q = array_map(static fn($v) => (string) $v, $q);
                    $cid = $q['client_id'] ?? '';
                    break;
                case 'client_attack':
                    $q['client_id'] = $cid = $this->rng->pick([Hostile::string($this->rng), $clientId . 'x', strtoupper($clientId), ' ' . $clientId, $clientId . "\0", '', 'https://evil.test/c.json', 'https://chatgpt.com.evil.test/c.json', 'https://chatgpt.com:8443/c.json', 'https://user@chatgpt.com/c.json', 'http://chatgpt.com/c.json', 'https://chatgpt.com/c.json#f', str_repeat('a', 300)]);
                    break;
                case 'cimd':
                    $cid = $q['client_id'] = 'https://chatgpt.com/cimd/' . $this->rng->int(1, 5);
                    $doc = ['client_id' => $cid, 'client_name' => 'CIMD', 'redirect_uris' => [RedirectOracle::OFFICIAL], 'token_endpoint_auth_method' => 'none'];
                    if ($this->rng->chance(0.6)) {
                        $doc = Hostile::mutate($this->rng, $doc);
                        $doc = is_array($doc) ? array_map(static fn($v) => $v, $doc) : [];
                        unset($doc['__pairs']);
                    }
                    if ($this->rng->chance(0.5) && isset($doc['redirect_uris'])) {
                        $doc['redirect_uris'] = [$this->rng->pick(RedirectOracle::attacks($this->rng, RedirectOracle::OFFICIAL))];
                    }
                    $this->cimd->docs[$cid] = $doc;
                    $this->w->app->db()->run('DELETE FROM oauth_clients WHERE client_id = ?', [$cid]);
                    unset($this->clients[$cid]);
                    break;
                default:
            }
            $pairingOpen = $this->rng->chance(0.85);
            $pairingOpen ? (new Pairing($this->w->app))->open() : (new Pairing($this->w->app))->close();
            $method = $kind === 'method' ? $this->rng->pick(['PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS', 'GET']) : 'GET';
            $path = $this->rng->chance(0.1) ? $this->rng->pick(['/oauth/authorize/', '/oauth/Authorize', '/oauth/authorize%2f', '/oauth//authorize', '/oauth/authorize.php', '/oauth/']) : '/oauth/authorize';
            $res = $this->call($method, $path, $q);
            $stored = $this->w->app->db()->one('SELECT redirect_uris FROM oauth_clients WHERE client_id = ?', [$cid]);
            if ($stored !== null && str_starts_with($cid, 'https://')) {
                $this->clients[$cid] = json_decode((string) $stored['redirect_uris'], true);
            }
            $known = $this->clients[$cid] ?? null;
            $this->checkRedirect($res, $known !== null ? $cid : null, $q['state'] ?? null);
            if ($res->status === 200 && $method === 'GET' && $path === '/oauth/authorize') {
                $this->ensure($this->authorizeShouldShowConsent($q, $known, $pairingOpen, $known !== null && $this->hasGrant($cid)), 'consent page shown for a request that must be refused: ' . json_encode($q));
            }
            if ($path === '/oauth/authorize' && $method === 'GET' && $res->status !== 429) {
                $should = $this->authorizeShouldShowConsent($q, $known, $pairingOpen, $known !== null && $this->hasGrant($cid));
                $this->ensure(!$should || $res->status === 200, "a valid authorize request was refused ({$res->status}): " . substr($res->body, 0, 200));
            }
            $this->tally("$kind:" . $res->status);
            $this->endCase();
        }
    }

    // --------------------------------------------------------------------------------------------- consent, token, refresh, revoke

    /** Run authorize GET and return [req, csrf, verifier, challenge, state, redirect] for a fresh valid request. */
    private function startFlow(string $clientId, string $redirect): ?array
    {
        [$verifier, $challenge] = $this->pkce();
        (new Pairing($this->w->app))->open();
        $q = $this->authorizeQuery($clientId, $challenge, $redirect);
        $res = $this->call('GET', '/oauth/authorize', $q);
        if ($res->status !== 200) {
            return null;
        }
        preg_match('/name="req" value="([^"]+)"/', $res->body, $a);
        preg_match('/name="csrf" value="([^"]+)"/', $res->body, $b);
        return ['req' => html_entity_decode($a[1] ?? ''), 'csrf' => html_entity_decode($b[1] ?? ''), 'verifier' => $verifier, 'challenge' => $challenge, 'state' => $q['state'], 'redirect' => $redirect, 'client' => $clientId];
    }

    /** @return array<string,mixed> */
    private function ownerForm(array $flow, bool $correct = true): array
    {
        $this->w->advance(31);
        return ['req' => $flow['req'], 'csrf' => $flow['csrf'], 'decision' => 'allow', 'email' => $this->w->owner['email'], 'password' => $this->w->owner['password'],
            'totp' => Totp::code($this->w->owner['secret'], Totp::step($this->w->now()->getTimestamp())), 'scope' => ['bookings:read', 'bookings:write']];
    }

    public function testConsentTokenRefreshAndRevokeInvariants(): void
    {
        $ids = array_keys($this->clients);
        $mcpPing = function (string $token): int {
            $r = $this->w->send(FuzzWorld::request('POST', '/mcp', ['content-type' => 'application/json', 'authorization' => 'Bearer ' . $token], [], '{"jsonrpc":"2.0","id":1,"method":"ping"}'));
            return $r->status;
        };
        $issued = 0;
        for ($i = 0; $i < min($this->iterations, 700); $i++) { // each case verifies an Argon2 password hash, so this target is the slow one
            $this->caseNo = $i;
            $this->w->advance($this->rng->pick([1, 10]));
            $clientId = $this->rng->pick($ids);
            $redirect = $this->rng->pick($this->clients[$clientId]);
            $flow = $this->startFlow($clientId, $redirect);
            if ($flow === null) {
                $this->tally('flow_refused');
                $this->endCase();
                continue;
            }
            $attack = $this->rng->weighted(['wrong_password' => 10, 'wrong_totp' => 10, 'client_account' => 6, 'bad_csrf' => 8, 'form_fuzz' => 22, 'deny' => 5, 'happy' => 39]);
            $form = $this->ownerForm($flow);
            switch ($attack) {
                case 'wrong_password':
                    $form['password'] = $this->rng->pick(['x', '', $form['password'] . ' ', strtoupper($form['password']), Hostile::string($this->rng), str_repeat('a', 2000)]);
                    break;
                case 'wrong_totp':
                    $form['totp'] = $this->rng->pick(['000000', '12345', '1234567', '', 'abcdef', Hostile::string($this->rng), Totp::code($this->w->owner['secret'], Totp::step($this->w->now()->getTimestamp()) + 5), Totp::code($this->w->owner['secret'], Totp::step($this->w->now()->getTimestamp()) - 3)]);
                    break;
                case 'client_account':
                    $form['email'] = $this->w->clients[0]['email'];
                    $form['password'] = $this->w->clients[0]['password'];
                    break;
                case 'bad_csrf':
                    $form['csrf'] = $this->rng->pick(['', 'x', $flow['csrf'] . 'x', substr($flow['csrf'], 1), strtoupper($flow['csrf']), Hostile::string($this->rng)]);
                    break;
                case 'form_fuzz':
                    $form = array_map(static fn($v) => $v, Hostile::mutate($this->rng, $form));
                    unset($form['__pairs']);
                    break;
                case 'deny':
                    $form['decision'] = 'deny';
                    break;
            }
            $ip = $this->ip();
            $form = $this->normForm($form);
            $res = $this->call('POST', '/oauth/authorize', [], $form, [], '', $ip);
            $this->checkRedirect($res, $clientId, $flow['state']);
            $loc = $res->headers['Location'] ?? null;
            parse_str($loc === null ? '' : (string) parse_url($loc, PHP_URL_QUERY), $rq);
            $gotCode = isset($rq['code']);
            $credsOk = $attack === 'happy' || ($attack === 'form_fuzz' && ($form['req'] ?? null) === $flow['req'] && ($form['csrf'] ?? null) === $flow['csrf'] && ($form['decision'] ?? null) === 'allow'
                && is_string($form['email'] ?? null) && trim($form['email']) === $this->w->owner['email'] && ($form['password'] ?? null) === $this->w->owner['password'] && is_string($form['totp'] ?? null) && preg_replace('/\s+/', '', $form['totp']) === $this->expectedTotp());
            if ($gotCode) {
                $this->ensure($credsOk, "an authorization code was issued although the attack was '$attack'");
                $code = $rq['code'];
                $this->ensure(!isset($this->codes[$code]), 'the same authorization code was issued twice');
                $this->codes[$code] = ['client' => $clientId, 'redirect' => $redirect, 'verifier' => $flow['verifier'], 'challenge' => $flow['challenge'], 'at' => $this->w->now()->getTimestamp(), 'used' => false];
                // a consumed request id can not be replayed into a second code
                $again = $this->call('POST', '/oauth/authorize', [], $form, [], '', $this->ip());
                $this->ensure(!isset($again->headers['Location']) || !str_contains($again->headers['Location'], 'code='), 'the consent request produced a second code');
                $this->exchange($code, $mcpPing);
                $issued++;
            } else {
                if ($attack === 'happy' && $res->status !== 429) {
                    $this->ensure(false, "the correct owner credentials were refused ({$res->status}): " . substr($res->body, 0, 200));
                }
                if ($attack === 'deny') {
                    $this->ensure(($rq['error'] ?? '') === 'access_denied', 'deny did not redirect with access_denied');
                }
            }
            $this->tally($attack . ($gotCode ? ':code' : ':none'));
            $this->endCase();
        }
        $this->ensure($issued > 0, 'no authorization code was ever issued; the fuzz did not reach the token endpoint');
    }

    private function expectedTotp(): string
    {
        return Totp::code($this->w->owner['secret'], Totp::step($this->w->now()->getTimestamp()));
    }

    /** Attack the token endpoint with one fresh code; then exercise refresh rotation, reuse and revocation. */
    private function exchange(string $code, callable $mcpPing): void
    {
        $c = $this->codes[$code];
        $good = ['grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $c['client'], 'redirect_uri' => $c['redirect'], 'code_verifier' => $c['verifier'], 'resource' => self::RESOURCE];
        $attacks = $this->rng->int(1, 4);
        for ($k = 0; $k < $attacks; $k++) {
            $f = $good;
            $kind = $this->rng->pick(['wrong_verifier', 'verifier_mutated', 'verifier_missing', 'verifier_array', 'plain_pkce', 'redirect_variant', 'wrong_client', 'wrong_resource', 'no_content_type', 'fuzz', 'short_verifier']);
            $headers = ['content-type' => 'application/x-www-form-urlencoded'];
            switch ($kind) {
                case 'wrong_verifier':
                    $f['code_verifier'] = $this->pkce()[0];
                    break;
                case 'verifier_mutated':
                    $v = $c['verifier'];
                    $p = $this->rng->int(0, strlen($v) - 1);
                    $f['code_verifier'] = substr($v, 0, $p) . ($v[$p] === 'A' ? 'B' : 'A') . substr($v, $p + 1);
                    break;
                case 'verifier_missing':
                    unset($f['code_verifier']);
                    break;
                case 'verifier_array':
                    $f['code_verifier'] = [$c['verifier']];
                    break;
                case 'plain_pkce':
                    $f['code_verifier'] = $c['challenge']; // what a downgrade to method=plain would send
                    break;
                case 'short_verifier':
                    $f['code_verifier'] = substr($c['verifier'], 0, 42);
                    break;
                case 'redirect_variant':
                    $f['redirect_uri'] = $this->rng->pick(array_values(array_filter(RedirectOracle::attacks($this->rng, $c['redirect']), static fn($u) => $u !== $c['redirect'])));
                    break;
                case 'wrong_client':
                    $f['client_id'] = $this->rng->pick(array_values(array_filter(array_keys($this->clients), fn($x) => $x !== $c['client']) ?: ['nope']));
                    break;
                case 'wrong_resource':
                    $f['resource'] = $this->rng->pick(['http://localhost:8090/mcp/', 'http://localhost:8090', 'https://localhost:8090/mcp', 'http://localhost:8090/other']);
                    break;
                case 'no_content_type':
                    $headers = ['content-type' => $this->rng->pick(['application/json', 'text/plain', ''])];
                    break;
                default:
                    $f = array_map(static fn($v) => $v, Hostile::mutate($this->rng, $good));
                    unset($f['__pairs']);
            }
            $isGood = ($f['grant_type'] ?? null) === 'authorization_code' && ($f['code'] ?? null) === $code && ($f['client_id'] ?? null) === $c['client'] && ($f['redirect_uri'] ?? null) === $c['redirect']
                && ($f['code_verifier'] ?? null) === $c['verifier'] && (!is_string($f['resource'] ?? '') || in_array($f['resource'] ?? '', ['', self::RESOURCE], true)) && ($headers['content-type'] ?? '') === 'application/x-www-form-urlencoded';
            $res = $this->call('POST', '/oauth/token', [], $f, $headers, '', $this->ip());
            $j = json_decode($res->body, true);
            if (isset($j['access_token'])) {
                $this->ensure($isGood, "a token was issued for a '$kind' request (PKCE/client/redirect not exact)");
                $this->tokens($j, $c['client'], $code, $mcpPing);
                return;
            }
            $this->ensure(is_array($j) && is_string($j['error'] ?? null), 'token endpoint error without error code');
            $this->ensure(!isset($j['refresh_token']), 'refresh token in an error response');
            $this->tally("token:$kind");
        }
        // the genuine request (sometimes after the code has expired)
        $expired = $this->rng->chance(0.15);
        if ($expired) {
            $this->w->advance(61);
        }
        $res = $this->call('POST', '/oauth/token', [], $good, ['content-type' => 'application/x-www-form-urlencoded'], '', $this->ip());
        $j = json_decode($res->body, true);
        if ($expired) {
            $this->ensure(!isset($j['access_token']), 'a code older than 60 seconds was accepted');
            return;
        }
        if ($res->status === 429) {
            return;
        }
        $this->ensure(isset($j['access_token']), 'the genuine code exchange failed: ' . $res->body);
        $this->tokens($j, $c['client'], $code, $mcpPing);
    }

    /** @param array<string,mixed> $j */
    private function tokens(array $j, string $clientId, string $code, callable $mcpPing): void
    {
        $this->codes[$code]['used'] = true;
        $this->ensure(($j['token_type'] ?? '') === 'Bearer' && ($j['expires_in'] ?? 0) === 3600, 'token response has wrong type/lifetime');
        $this->ensure(is_string($j['access_token']) && strlen($j['access_token']) >= 43 && $j['access_token'] !== $j['refresh_token'], 'weak or duplicated tokens');
        $this->ensure($mcpPing($j['access_token']) === 200, 'a fresh access token does not work');
        // the code can never be used again, and replaying it kills the grant that came from it
        $replay = $this->call('POST', '/oauth/token', [], ['grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $clientId, 'redirect_uri' => $this->codes[$code]['redirect'], 'code_verifier' => $this->codes[$code]['verifier'], 'resource' => self::RESOURCE], ['content-type' => 'application/x-www-form-urlencoded'], '', $this->ip());
        $this->ensure(!str_contains($replay->body, 'access_token'), 'a used authorization code produced tokens again');
        if ($replay->status !== 429) {
            $this->ensure($mcpPing($j['access_token']) === 401, 'code replay did not revoke the grant created from it');
            $this->tally('code_reuse_revoked');
            return;
        }
    }

    /** Refresh rotation: each refresh token works once; presenting a used one kills the grant. */
    public function testRefreshRotationAndRevocation(): void
    {
        $mcpPing = fn(string $t): int => $this->w->send(FuzzWorld::request('POST', '/mcp', ['content-type' => 'application/json', 'authorization' => 'Bearer ' . $t], [], '{"jsonrpc":"2.0","id":1,"method":"ping"}'))->status;
        $clientId = array_key_first($this->clients);
        $form = static fn(array $x): array => $x + ['grant_type' => 'refresh_token'];
        for ($i = 0; $i < min($this->iterations, 600); $i++) {
            $this->caseNo = $i;
            $this->w->advance(2);
            $grantId = (new \StretchEffect\OAuth\TokenStore($this->w->app))->createGrant($clientId, (string) $this->w->owner['id'], ['bookings:read'], self::RESOURCE);
            $pair = (new \StretchEffect\OAuth\TokenStore($this->w->app))->issuePair($grantId);
            $r1 = $pair['refresh_token'];
            $mode = $this->rng->pick(['rotate', 'reuse', 'wrong_client', 'variant', 'revoke_other_client', 'revoke_right', 'expired']);
            $send = fn(array $f) => $this->call('POST', '/oauth/token', [], $form($f), ['content-type' => 'application/x-www-form-urlencoded'], '', $this->ip());
            switch ($mode) {
                case 'rotate':
                    $a = json_decode($send(['refresh_token' => $r1, 'client_id' => $clientId])->body, true);
                    $this->ensure(isset($a['access_token'], $a['refresh_token']) && $a['refresh_token'] !== $r1 && $a['access_token'] !== $pair['access_token'], 'refresh did not rotate');
                    $b = json_decode($send(['refresh_token' => $a['refresh_token'], 'client_id' => $clientId])->body, true);
                    $this->ensure(isset($b['access_token']), 'second rotation failed');
                    break;
                case 'reuse':
                    $a = json_decode($send(['refresh_token' => $r1, 'client_id' => $clientId])->body, true);
                    $second = $send(['refresh_token' => $r1, 'client_id' => $clientId]);
                    $this->ensure(!str_contains($second->body, 'access_token'), 'a used refresh token worked twice');
                    $this->ensure($mcpPing($a['access_token']) === 401 && $mcpPing($pair['access_token']) === 401, 'refresh reuse did not revoke the whole grant');
                    $this->ensure(!str_contains($send(['refresh_token' => $a['refresh_token'], 'client_id' => $clientId])->body, 'access_token'), 'rotated refresh token still works after reuse detection');
                    break;
                case 'wrong_client':
                    $other = array_values(array_diff(array_keys($this->clients), [$clientId]))[0] ?? 'nope';
                    $res = $send(['refresh_token' => $r1, 'client_id' => $other]);
                    $this->ensure(!str_contains($res->body, 'access_token'), 'refresh token accepted for another client');
                    $this->ensure($mcpPing($pair['access_token']) === 200, 'a refresh attempt by another client killed the grant');
                    break;
                case 'variant':
                    $f = Hostile::mutate($this->rng, ['refresh_token' => $r1, 'client_id' => $clientId, 'resource' => self::RESOURCE]);
                    unset($f['__pairs']);
                    $res = $send($f);
                    if (str_contains($res->body, 'access_token')) {
                        $this->ensure(($f['refresh_token'] ?? null) === $r1 && ($f['client_id'] ?? null) === $clientId && (!is_string($f['resource'] ?? '') || ($f['resource'] ?? '') === '' || $f['resource'] === self::RESOURCE), 'refresh succeeded with altered parameters');
                    }
                    break;
                case 'revoke_other_client':
                    $other = array_values(array_diff(array_keys($this->clients), [$clientId]))[0] ?? 'nope';
                    $rv = $this->call('POST', '/oauth/revoke', [], ['token' => $this->rng->pick([$r1, $pair['access_token']]), 'client_id' => $other], [], '', $this->ip());
                    $this->ensure($rv->status === 200, 'revoke must answer 200 even when it does nothing');
                    $this->ensure($mcpPing($pair['access_token']) === 200, 'another client revoked this grant');
                    break;
                case 'revoke_right':
                    $rv = $this->call('POST', '/oauth/revoke', [], ['token' => $this->rng->pick([$r1, $pair['access_token']]), 'client_id' => $clientId], [], '', $this->ip());
                    $this->ensure($rv->status === 200, 'revoke failed');
                    $this->ensure($mcpPing($pair['access_token']) === 401 && !str_contains($send(['refresh_token' => $r1, 'client_id' => $clientId])->body, 'access_token'), 'revoked grant still works');
                    break;
                default: // expired refresh token (30 days) / grant older than 90 days
                    $this->w->advance($this->rng->pick([2592000 - 5, 2592000, 2592001]));
                    $res = $send(['refresh_token' => $r1, 'client_id' => $clientId]);
                    $okAge = $this->w->now()->getTimestamp() - $this->w->app->db()->now()->getTimestamp();
                    $secs = (int) $this->w->app->db()->val("SELECT CAST(strftime('%s', ?) AS INTEGER) - CAST(strftime('%s', expires_at) AS INTEGER) FROM oauth_tokens WHERE token_hash = ?", [$this->w->now()->format('Y-m-d H:i:s'), hash('sha256', $r1)]);
                    $this->ensure((str_contains($res->body, 'access_token')) === ($secs < 0), "refresh token expiry boundary wrong (now is {$secs}s past expiry): " . substr($res->body, 0, 100));
            }
            $this->tally($mode);
            $this->endCase();
        }
        // random junk at the token and revoke endpoints
        for ($i = 0; $i < $this->iterations; $i++) {
            $this->caseNo = 10000 + $i;
            $path = $this->rng->pick(['/oauth/token', '/oauth/revoke']);
            $f = ['grant_type' => $this->rng->pick(['authorization_code', 'refresh_token', 'password', 'client_credentials', '', Hostile::string($this->rng)]), 'code' => Hostile::value($this->rng), 'refresh_token' => Hostile::value($this->rng),
                'client_id' => Hostile::value($this->rng), 'code_verifier' => Hostile::value($this->rng), 'redirect_uri' => Hostile::value($this->rng), 'token' => Hostile::value($this->rng)];
            $f = array_filter($f, fn() => $this->rng->chance(0.6));
            $f = array_map(static fn($v) => $v instanceof Pairs || $v instanceof RawJson ? 'x' : $v, $f);
            $res = $this->call('POST', $path, [], $f, $this->rng->chance(0.9) ? [] : ['content-type' => 'application/json'], $this->rng->chance(0.1) ? Hostile::brokenBody($this->rng) : '', $this->ip());
            $this->ensure(!str_contains($res->body, 'access_token'), 'junk request got a token');
            $this->tally('junk');
            $this->endCase();
        }
    }

    public function testMetadataAndMethods(): void
    {
        $paths = ['/.well-known/oauth-protected-resource', '/.well-known/oauth-protected-resource/mcp', '/.well-known/oauth-authorization-server', '/.well-known/oauth-', '/.well-known/oauth-authorization-server/', '/oauth/register', '/oauth/token', '/oauth/revoke', '/oauth/authorize', '/oauth/', '/oauth/unknown', '/oauth/../mcp', '/oauth/%2e%2e/api'];
        for ($i = 0; $i < min($this->iterations, 800); $i++) {
            $this->caseNo = $i;
            $method = $this->rng->pick(['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS']);
            $path = $this->rng->chance(0.85) ? $this->rng->pick($paths) : '/oauth/' . Hostile::string($this->rng);
            $res = $this->call($method, $path, $this->rng->chance(0.3) ? ['x' => Hostile::string($this->rng)] : [], [], $this->rng->chance(0.5) ? ['content-type' => 'application/json'] : [], $this->rng->chance(0.3) ? Hostile::brokenBody($this->rng) : '', $this->ip());
            if ($method === 'GET' && str_starts_with($path, '/.well-known/oauth-') && $res->status === 200) {
                $j = json_decode($res->body, true);
                $this->ensure(is_array($j) && (($j['issuer'] ?? self::ISS) === self::ISS), 'metadata has the wrong issuer');
                foreach (['code_challenge_methods_supported'] as $k) {
                    if (isset($j[$k])) {
                        $this->ensure($j[$k] === ['S256'], 'metadata offers a PKCE method other than S256');
                    }
                }
                $this->ensure(!isset($j['client_secret']) && !isset($j['registration_access_token']), 'metadata leaks a secret');
            }
            $this->tally((string) $res->status);
            $this->endCase();
        }
    }
}
