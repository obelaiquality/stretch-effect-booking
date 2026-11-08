<?php
declare(strict_types=1);

/**
 * End-to-end check of the ChatGPT connector against a RUNNING server.
 *
 *   E2E_OWNER_EMAIL=ivan@example.com E2E_OWNER_PASSWORD=... E2E_TOTP_SECRET=BASE32SECRET \
 *     php server/tests/e2e/oauth_mcp_e2e.php http://127.0.0.1:8091
 *
 * The server's app.base_url must equal the URL you pass (the token audience is <base_url>/mcp).
 * The database needs the owner and at least one client whose email contains "example" (bin/seed-demo.php does this).
 * Plays the part of ChatGPT: 401 discovery, metadata, DCR, PKCE S256, consent as the owner (password + TOTP),
 * code exchange, MCP calls, idempotent write replay, refresh rotation, then refresh-token reuse (grant must die).
 * Exit code 0 only when every step passes.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$base = rtrim($argv[1] ?? '', '/');
if ($base === '') {
    fwrite(STDERR, "Usage: php oauth_mcp_e2e.php <server base url>\n");
    exit(2);
}
$email = getenv('E2E_OWNER_EMAIL') ?: '';
$password = getenv('E2E_OWNER_PASSWORD') ?: '';
$totpSecret = getenv('E2E_TOTP_SECRET') ?: '';
if ($email === '' || $password === '' || $totpSecret === '') {
    fwrite(STDERR, "Set E2E_OWNER_EMAIL, E2E_OWNER_PASSWORD and E2E_TOTP_SECRET.\n");
    exit(2);
}
$redirect = 'https://chatgpt.com/connector_platform_oauth_redirect';

$failures = 0;
function step(string $name, bool $ok, string $detail = ''): bool
{
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL') . '  ' . $name . ($ok || $detail === '' ? '' : '  -> ' . $detail) . "\n";
    if (!$ok) {
        $failures++;
    }
    return $ok;
}
function must(bool $ok): void
{
    if (!$ok) {
        echo "\nStopping: later steps depend on this one.\n";
        exit(1);
    }
}

/** @return array{int,array<string,string>,string} */
function http(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $h = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$h): int {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $h[strtolower(trim($k))] = trim($v);
            }
            return strlen($line);
        },
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $out = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    return [$status, $h, $out === false ? '' : (string) $out];
}

function totp(string $base32, int $time): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split(strtoupper(rtrim($base32, '='))) as $c) {
        $bits .= str_pad(decbin((int) strpos($alphabet, $c)), 5, '0', STR_PAD_LEFT);
    }
    $key = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $key .= chr(bindec($byte));
        }
    }
    $hash = hash_hmac('sha1', pack('N*', 0, intdiv($time, 30)), $key, true);
    $o = ord($hash[19]) & 0xf;
    $n = ((ord($hash[$o]) & 0x7f) << 24) | (ord($hash[$o + 1]) << 16) | (ord($hash[$o + 2]) << 8) | ord($hash[$o + 3]);
    return str_pad((string) ($n % 1000000), 6, '0', STR_PAD_LEFT);
}

function b64u(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function mcp(string $base, ?string $token, string $method, array $params = [], int $id = 1): array
{
    $headers = ['Content-Type: application/json', 'Accept: application/json, text/event-stream'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    [$status, $h, $body] = http('POST', $base . '/mcp', $headers, json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => (object) $params]));
    return [$status, $h, json_decode($body, true) ?? []];
}

function tool(string $base, string $token, string $name, array $args): array
{
    [, , $d] = mcp($base, $token, 'tools/call', ['name' => $name, 'arguments' => (object) $args]);
    return $d['result'] ?? ['isError' => true, 'content' => [['text' => json_encode($d)]]];
}

echo "Target: $base\n\n";

// 1. unauthenticated call -> 401 with resource_metadata
[$st, $h] = mcp($base, null, 'tools/list');
$www = $h['www-authenticate'] ?? '';
step('POST /mcp without token -> 401', $st === 401);
step('401 carries WWW-Authenticate Bearer resource_metadata + error="invalid_token"',
    str_starts_with($www, 'Bearer resource_metadata="') && str_contains($www, 'error="invalid_token"'), $www);
preg_match('/resource_metadata="([^"]+)"/', $www, $m);
$prmUrl = $m[1] ?? $base . '/.well-known/oauth-protected-resource';

// 2. metadata
[$st, , $body] = http('GET', $prmUrl);
$prm = json_decode($body, true) ?? [];
step('protected resource metadata', $st === 200 && ($prm['resource'] ?? '') === $base . '/mcp' && ($prm['authorization_servers'][0] ?? '') === $base, $body);
[$st, , $body] = http('GET', ($prm['authorization_servers'][0] ?? $base) . '/.well-known/oauth-authorization-server');
$as = json_decode($body, true) ?? [];
step('authorization server metadata (S256, public clients, iss param)',
    $st === 200 && ($as['code_challenge_methods_supported'] ?? []) === ['S256'] && ($as['authorization_response_iss_parameter_supported'] ?? false) === true
    && ($as['token_endpoint_auth_methods_supported'] ?? []) === ['none'], $body);
must(isset($as['authorization_endpoint'], $as['token_endpoint'], $as['registration_endpoint']));

// 3. dynamic client registration
[$st, , $body] = http('POST', $as['registration_endpoint'], ['Content-Type: application/json'],
    json_encode(['client_name' => 'ChatGPT (e2e)', 'redirect_uris' => [$redirect], 'token_endpoint_auth_method' => 'none', 'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code']]));
$reg = json_decode($body, true) ?? [];
step('DCR register with the ChatGPT redirect URI', $st === 201 && isset($reg['client_id']), $body);
must(isset($reg['client_id']));
$clientId = $reg['client_id'];
[$st] = http('POST', $as['registration_endpoint'], ['Content-Type: application/json'], json_encode(['redirect_uris' => ['https://evil.example/cb']]));
step('DCR refuses a redirect URI that is not allow-listed', $st === 400);

// 4. authorize
$verifier = b64u(random_bytes(48));
$challenge = b64u(hash('sha256', $verifier, true));
$state = b64u(random_bytes(12));
$q = ['response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => $redirect, 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
    'state' => $state, 'resource' => $base . '/mcp', 'scope' => 'bookings:read bookings:write clients:read credits:write'];
[$st, $h] = http('GET', $as['authorization_endpoint'] . '?' . http_build_query(array_diff_key($q, ['code_challenge' => 1]), '', '&', PHP_QUERY_RFC3986));
step('authorize without PKCE is refused (redirect with error)', $st === 302 && str_contains($h['location'] ?? '', 'error=invalid_request'), (string) ($h['location'] ?? ''));
[$st, $h, $page] = http('GET', $as['authorization_endpoint'] . '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986));
step('GET authorize renders the consent page', $st === 200 && str_contains($page, 'name="totp"') && str_contains($page, 'ChatGPT (e2e)'));
step('consent page is not frameable', str_contains($h['content-security-policy'] ?? '', "frame-ancestors 'none'") && ($h['cache-control'] ?? '') !== '', $h['content-security-policy'] ?? '');
preg_match('/name="req" value="([^"]+)"/', $page, $a);
preg_match('/name="csrf" value="([^"]+)"/', $page, $b);
must(isset($a[1], $b[1]));

// 5. consent as the owner (retry once on the next TOTP step if the current one was already used)
$location = '';
for ($try = 0; $try < 2; $try++) {
    $form = http_build_query(['req' => $a[1], 'csrf' => $b[1], 'decision' => 'allow', 'email' => $email, 'password' => $password,
        'totp' => totp($totpSecret, time()), 'scope' => ['bookings:read', 'bookings:write', 'clients:read', 'credits:write']]);
    [$st, $h, $body] = http('POST', $base . '/oauth/authorize', ['Content-Type: application/x-www-form-urlencoded'], $form);
    $location = $h['location'] ?? '';
    if ($st === 302) {
        break;
    }
    if ($try === 0) {
        echo "      (consent refused, waiting for the next authenticator step)\n";
        sleep(31 - (time() % 30));
    }
}
step('POST consent as owner (password + TOTP) -> 302 to the ChatGPT redirect, not followed', $st === 302 && str_starts_with($location, $redirect . '?'), "HTTP $st $location");
must($st === 302);
parse_str((string) parse_url($location, PHP_URL_QUERY), $cb);
step('redirect carries code, the same state and iss', isset($cb['code']) && ($cb['state'] ?? '') === $state && ($cb['iss'] ?? '') === $base, $location);
must(isset($cb['code']));

// 6. token exchange
$tokenForm = fn(array $f): string => http_build_query($f);
[$st, , $body] = http('POST', $as['token_endpoint'], ['Content-Type: application/x-www-form-urlencoded'], $tokenForm([
    'grant_type' => 'authorization_code', 'code' => $cb['code'], 'redirect_uri' => $redirect, 'client_id' => $clientId,
    'code_verifier' => b64u(random_bytes(48)), 'resource' => $base . '/mcp']));
step('token exchange with a wrong PKCE verifier is refused', $st === 400);
[$st, , $body] = http('POST', $as['token_endpoint'], ['Content-Type: application/x-www-form-urlencoded'], $tokenForm([
    'grant_type' => 'authorization_code', 'code' => $cb['code'], 'redirect_uri' => $redirect, 'client_id' => $clientId,
    'code_verifier' => $verifier, 'resource' => $base . '/mcp']));
$tok = json_decode($body, true) ?? [];
step('token exchange with the right verifier', $st === 200 && isset($tok['access_token'], $tok['refresh_token']) && ($tok['token_type'] ?? '') === 'Bearer' && ($tok['expires_in'] ?? 0) === 3600, $body);
must(isset($tok['access_token']));
$access = $tok['access_token'];
$refresh = $tok['refresh_token'];

// 7. MCP
[$st, , $d] = mcp($base, $access, 'initialize', ['protocolVersion' => '2025-11-25', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'e2e', 'version' => '1']]);
step('initialize (version negotiated, server name, instructions)', $st === 200 && ($d['result']['protocolVersion'] ?? '') === '2025-11-25' && ($d['result']['serverInfo']['name'] ?? '') === 'the-stretch-effect'
    && str_contains($d['result']['instructions'] ?? '', 'untrusted'), json_encode($d));
[$st] = http('POST', $base . '/mcp', ['Content-Type: application/json', 'Authorization: Bearer ' . $access], '{"jsonrpc":"2.0","method":"notifications/initialized"}');
step('notifications/initialized -> 202', $st === 202);
[$st, , $d] = mcp($base, $access, 'tools/list');
$names = array_column($d['result']['tools'] ?? [], 'name');
step('tools/list returns the 11 tools', $st === 200 && count($names) === 11 && in_array('add_session_credits', $names, true), implode(',', $names));
$res = tool($base, $access, 'get_schedule', []);
step('get_schedule', ($res['isError'] ?? true) === false && isset($res['structuredContent']['bookings']), json_encode($res));
$res = tool($base, $access, 'search_clients', ['query' => 'example']);
$cid = $res['structuredContent']['clients'][0]['client_id'] ?? null;
step('search_clients finds a client (seed demo data first if this fails)', $cid !== null, json_encode($res));
must($cid !== null);
$before = (int) ($res['structuredContent']['clients'][0]['balance'] ?? 0);
$idem = 'e2e-' . bin2hex(random_bytes(6));
$add = ['client_id' => $cid, 'sessions' => 1, 'reason' => 'e2e test credit', 'payment_reference' => 'E2E-' . bin2hex(random_bytes(4)), 'idempotency_key' => $idem];
$r1 = tool($base, $access, 'add_session_credits', $add);
step('add_session_credits applies once', ($r1['isError'] ?? true) === false && ($r1['structuredContent']['new_balance'] ?? -1) === $before + 1, json_encode($r1));
$r2 = tool($base, $access, 'add_session_credits', $add);
step('replay with the same idempotency_key returns the first result and does not add again',
    ($r2['isError'] ?? true) === false && ($r2['structuredContent']['replayed'] ?? false) === true && ($r2['structuredContent']['new_balance'] ?? -1) === $before + 1, json_encode($r2));
$after = tool($base, $access, 'get_client', ['client_id' => $cid]);
step('balance went up by exactly 1', ($after['structuredContent']['balance'] ?? -1) === $before + 1, json_encode($after));
$bad = tool($base, $access, 'add_session_credits', ['sessions' => 99] + $add);
step('invalid arguments are rejected (isError)', ($bad['isError'] ?? false) === true);
$undo = tool($base, $access, 'remove_session_credits', ['client_id' => $cid, 'sessions' => 1, 'reason' => 'e2e cleanup', 'idempotency_key' => 'e2e-' . bin2hex(random_bytes(6))]);
step('remove_session_credits cleans up', ($undo['isError'] ?? true) === false && ($undo['structuredContent']['new_balance'] ?? -1) === $before, json_encode($undo));
[$st] = http('POST', $base . '/mcp', ['Content-Type: application/json', 'Authorization: Bearer ' . $access, 'Origin: https://evil.example'], '{"jsonrpc":"2.0","id":1,"method":"ping"}');
step('foreign Origin is refused', $st === 403);
[$st] = http('GET', $base . '/mcp', ['Authorization: Bearer ' . $access]);
step('GET /mcp -> 405', $st === 405);

// 8. refresh rotation, then reuse
[$st, , $body] = http('POST', $as['token_endpoint'], ['Content-Type: application/x-www-form-urlencoded'], $tokenForm([
    'grant_type' => 'refresh_token', 'refresh_token' => $refresh, 'client_id' => $clientId, 'resource' => $base . '/mcp']));
$tok2 = json_decode($body, true) ?? [];
step('refresh token rotation issues a new pair', $st === 200 && isset($tok2['access_token']) && $tok2['refresh_token'] !== $refresh && $tok2['access_token'] !== $access, $body);
must(isset($tok2['access_token']));
[$st] = mcp($base, $tok2['access_token'], 'ping');
step('new access token works', $st === 200);
[$st, , $body] = http('POST', $as['token_endpoint'], ['Content-Type: application/x-www-form-urlencoded'], $tokenForm([
    'grant_type' => 'refresh_token', 'refresh_token' => $refresh, 'client_id' => $clientId]));
step('reusing the old refresh token is refused (invalid_grant)', $st === 400 && (json_decode($body, true)['error'] ?? '') === 'invalid_grant', $body);
[$st, $h] = mcp($base, $tok2['access_token'], 'ping');
step('reuse revoked the whole grant: the newest access token now gets 401', $st === 401 && str_contains($h['www-authenticate'] ?? '', 'invalid_token'));
[$st] = http('POST', $as['token_endpoint'], ['Content-Type: application/x-www-form-urlencoded'], $tokenForm([
    'grant_type' => 'refresh_token', 'refresh_token' => $tok2['refresh_token'], 'client_id' => $clientId]));
step('the newest refresh token is dead too', $st === 400);

echo "\n" . ($failures === 0 ? 'ALL PASSED' : "$failures STEP(S) FAILED") . "\n";
exit($failures === 0 ? 0 : 1);
