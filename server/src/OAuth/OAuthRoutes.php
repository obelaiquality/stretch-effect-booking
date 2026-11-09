<?php
declare(strict_types=1);

namespace StretchEffect\OAuth;

use StretchEffect\Contracts\DomainError;
use StretchEffect\Contracts\HttpRequest;
use StretchEffect\Contracts\HttpResponse;
use StretchEffect\Contracts\RouteHandler;
use StretchEffect\Contracts\Services;

/**
 * OAuth 2.1 authorization server for the ChatGPT connector.
 * Authorization code + PKCE (S256) only, public clients, DCR + CIMD, refresh rotation with reuse detection.
 */
final class OAuthRoutes implements RouteHandler
{
    private const AUTHREQ_TTL = 600;     // 10 minutes
    private const CODE_TTL = 60;         // 60 seconds
    private const MAX_REQ_ATTEMPTS = 5;

    private Services $s;
    private TokenStore $tokens;
    private ClientRegistry $clients;
    private Pairing $pairing;

    public function __construct(private ?CimdFetcher $fetcher = null) {}

    public function handle(HttpRequest $request, Services $services): ?HttpResponse
    {
        $path = $request->path;
        $mine = str_starts_with($path, '/oauth/') || str_starts_with($path, '/.well-known/oauth-');
        if (!$mine) {
            return null;
        }
        $this->s = $services;
        if ($services->config()->get('connector.enabled', true) !== true) {
            return $this->json(['error' => 'temporarily_unavailable', 'error_description' => 'The connector is switched off.'], 503, ['Retry-After' => '3600']);
        }
        $hosts = $services->config()->get('connector.cimd_hosts', ['chatgpt.com', 'openai.com']);
        $this->fetcher ??= new HttpCimdFetcher(is_array($hosts) ? array_values(array_filter($hosts, 'is_string')) : []);
        $this->tokens = new TokenStore($services);
        $this->clients = new ClientRegistry($services, $this->fetcher);
        $this->pairing = new Pairing($services);

        try {
            return match ($path) {
                '/.well-known/oauth-protected-resource',
                '/.well-known/oauth-protected-resource/mcp' => $this->guard($request, ['GET'], fn() => $this->protectedResource()),
                '/.well-known/oauth-authorization-server' => $this->guard($request, ['GET'], fn() => $this->serverMetadata()),
                '/oauth/register' => $this->guard($request, ['POST'], fn() => $this->register($request)),
                '/oauth/authorize' => $this->guard($request, ['GET', 'POST'], fn() => $request->method === 'POST' ? $this->authorizePost($request) : $this->authorizeGet($request)),
                '/oauth/token' => $this->guard($request, ['POST'], fn() => $this->token($request)),
                '/oauth/revoke' => $this->guard($request, ['POST'], fn() => $this->revoke($request)),
                default => null,
            };
        } catch (\Throwable $e) {
            error_log('oauth: unhandled ' . get_class($e));
            return $this->json(['error' => 'server_error', 'error_description' => 'Something went wrong.'], 500);
        }
    }

    /** @param list<string> $methods */
    private function guard(HttpRequest $r, array $methods, callable $fn): HttpResponse
    {
        if (!in_array($r->method, $methods, true)) {
            return $this->json(['error' => 'invalid_request', 'error_description' => 'Method not allowed.'], 405, ['Allow' => implode(', ', $methods)]);
        }
        return $fn();
    }

    // ------------------------------------------------------------------ helpers

    /** @param array<string,string> $extra */
    private function json(mixed $data, int $status = 200, array $extra = []): HttpResponse
    {
        return HttpResponse::json($data, $status, $extra + ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function oauthError(string $error, string $desc, int $status = 400): HttpResponse
    {
        return $this->json(['error' => $error, 'error_description' => $desc], $status);
    }

    private function page(string $html, int $status = 200): HttpResponse
    {
        return HttpResponse::html($html, $status, [
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; base-uri 'none'; frame-ancestors 'none'",
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    private function errorPage(string $message, int $status = 400): HttpResponse
    {
        return $this->page(ConsentPage::error($message), $status);
    }

    private function issuer(): string
    {
        return $this->s->config()->baseUrl();
    }

    /** @param array<string,string> $params */
    private function redirectTo(string $uri, array $params): HttpResponse
    {
        $params['iss'] = $this->issuer();
        $sep = str_contains($uri, '?') ? '&' : '?';
        return new HttpResponse(302, [
            'Location' => $uri . $sep . http_build_query($params, '', '&', PHP_QUERY_RFC3986),
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    private function str(mixed $v): string
    {
        return is_string($v) ? $v : '';
    }

    private function audit(?string $grantId, string $actor, string $action, array $args, string $status, string $summary, string $ip): void
    {
        try {
            $this->s->audit()->write($grantId, substr($actor, 0, 60), $action, $args, $status, substr($summary, 0, 250), $ip);
        } catch (\Throwable $e) {
            error_log('oauth: audit write failed');
        }
    }

    private function nowTs(): string
    {
        return TokenStore::ts($this->s->db()->now());
    }

    // ------------------------------------------------------------------ metadata

    private function protectedResource(): HttpResponse
    {
        $base = $this->issuer();
        return $this->json([
            'resource' => $base . '/mcp',
            'authorization_servers' => [$base],
            'scopes_supported' => Scopes::ALL,
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'The Stretch Effect bookings',
        ], 200, ['Cache-Control' => 'public, max-age=300']);
    }

    private function serverMetadata(): HttpResponse
    {
        $base = $this->issuer();
        return $this->json([
            'issuer' => $base,
            'authorization_endpoint' => $base . '/oauth/authorize',
            'token_endpoint' => $base . '/oauth/token',
            'registration_endpoint' => $base . '/oauth/register',
            'revocation_endpoint' => $base . '/oauth/revoke',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'revocation_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => Scopes::ALL,
            'client_id_metadata_document_supported' => true,
            'authorization_response_iss_parameter_supported' => true,
        ], 200, ['Cache-Control' => 'public, max-age=300']);
    }

    // ------------------------------------------------------------------ dynamic client registration

    private function register(HttpRequest $r): HttpResponse
    {
        if (!$this->s->rateLimiter()->hit('oauth_register', $r->ip, 10, 3600)) {
            return $this->oauthError('temporarily_unavailable', 'Too many registrations. Try again later.', 429);
        }
        if (strlen($r->rawBody) > 16384) {
            return $this->oauthError('invalid_client_metadata', 'Request too large.', 413);
        }
        $meta = $r->json();
        if ($meta === null || array_is_list($meta) && $meta !== []) {
            return $this->oauthError('invalid_client_metadata', 'A JSON object is required.');
        }
        try {
            $resp = $this->clients->register($meta);
        } catch (\InvalidArgumentException $e) {
            $isUri = str_contains($e->getMessage(), 'redirect_uri');
            $this->audit(null, 'anonymous', 'oauth_register', ['reason' => substr($e->getMessage(), 0, 40)], 'denied', 'registration refused', $r->ip);
            return $this->oauthError($isUri ? 'invalid_redirect_uri' : 'invalid_client_metadata', $e->getMessage());
        } catch (\OverflowException $e) {
            return $this->oauthError('temporarily_unavailable', 'Registration is closed.', 503);
        }
        $this->audit(null, 'anonymous', 'oauth_register', ['client_id' => $resp['client_id']], 'ok', 'client registered: ' . $resp['client_name'], $r->ip);
        return $this->json($resp, 201);
    }

    // ------------------------------------------------------------------ authorize

    private function authorizeGet(HttpRequest $r): HttpResponse
    {
        if (!$this->s->rateLimiter()->hit('oauth_authorize', $r->ip, 60, 3600)) {
            return $this->errorPage('Too many requests. Please wait a while and try again.', 429);
        }
        $q = $r->query;
        try {
            $client = $this->clients->find($this->str($q['client_id'] ?? ''));
        } catch (\RuntimeException $e) {
            return $this->errorPage('This app is not recognised, so the request was stopped. Go back to ChatGPT and try again.');
        }
        $redirect = $this->str($q['redirect_uri'] ?? '');
        if ($redirect === '' && count($client['redirect_uris']) === 1) {
            $redirect = $client['redirect_uris'][0];
        }
        if (!in_array($redirect, $client['redirect_uris'], true) || !$this->clients->redirectAllowed($redirect)) {
            return $this->errorPage('The return address does not match this app, so the request was stopped.');
        }
        $state = $this->str($q['state'] ?? '');
        $fail = function (string $err, string $desc) use ($redirect, $state): HttpResponse {
            $p = ['error' => $err, 'error_description' => $desc];
            if ($state !== '' && strlen($state) <= 500) {
                $p['state'] = $state;
            }
            return $this->redirectTo($redirect, $p);
        };
        if ($this->str($q['response_type'] ?? '') !== 'code') {
            return $fail('unsupported_response_type', 'Only response_type=code is supported.');
        }
        $challenge = $this->str($q['code_challenge'] ?? '');
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $challenge) !== 1) {
            return $fail('invalid_request', 'A PKCE code_challenge (S256) is required.');
        }
        if ($this->str($q['code_challenge_method'] ?? '') !== 'S256') {
            return $fail('invalid_request', 'code_challenge_method must be S256.');
        }
        if ($state === '' || strlen($state) > 500) {
            return $fail('invalid_request', 'state is required.');
        }
        $resource = $this->str($q['resource'] ?? '');
        if ($resource !== '' && !hash_equals($this->tokens->resource(), $resource)) {
            return $fail('invalid_target', 'resource must be ' . $this->tokens->resource());
        }
        $requested = isset($q['scope']) && $this->str($q['scope']) !== '' ? Scopes::parse($this->str($q['scope'])) : Scopes::ALL;
        if (!Scopes::allValid($requested)) {
            return $fail('invalid_scope', 'Unknown scope requested.');
        }
        $requested = Scopes::normalize($requested);

        // A client with no active grant needs the owner-opened pairing window (see Pairing). Checked after the redirect
        // is validated, so nothing is ever redirected to an unvalidated address.
        $isNew = !$this->pairing->clientHasActiveGrant($client['client_id']);
        if ($isNew && !$this->pairing->isOpen()) {
            return $this->pairingRequired($client['client_id'], (string) $client['client_name'], $r->ip);
        }

        $reqId = $this->s->crypto()->randomToken(24);
        $csrf = $this->s->crypto()->randomToken(24);
        $this->saveAuthRequest($reqId, [
            'client_id' => $client['client_id'], 'client_name' => $client['client_name'],
            'redirect_uri' => $redirect, 'state' => $state, 'code_challenge' => $challenge,
            'resource' => $this->tokens->resource(), 'scopes' => $requested,
            'csrf_hash' => $this->s->crypto()->hash($csrf), 'attempts' => 0, 'registered' => $client['created_at'],
        ]);
        $this->audit(null, 'anonymous', 'oauth_authorize_view', ['client_id' => $client['client_id']], 'ok', 'consent page shown to ' . $client['client_name'], $r->ip);
        return $this->page(ConsentPage::consent(
            ['name' => $client['client_name'], 'client_id' => $client['client_id']],
            $redirect, $requested, $reqId, $csrf, null, '', (string) $client['created_at'], $isNew));
    }

    private function pairingRequired(string $clientId, string $clientName, string $ip): HttpResponse
    {
        $this->audit(null, 'anonymous', 'oauth_pairing_refused', ['client_id' => $clientId], 'denied',
            'authorize refused: no active grant and pairing window closed (' . $clientName . ')', $ip);
        return $this->page(ConsentPage::pairingRequired(), 403);
    }

    private function authorizePost(HttpRequest $r): HttpResponse
    {
        $f = $r->form;
        $reqId = $this->str($f['req'] ?? '');
        $csrf = $this->str($f['csrf'] ?? '');
        $rec = $this->loadAuthRequest($reqId);
        if ($rec === null) {
            return $this->errorPage('This sign-in request has expired or was already used. Go back to ChatGPT and try again.');
        }
        if ($csrf === '' || !hash_equals((string) $rec['csrf_hash'], $this->s->crypto()->hash($csrf))) {
            $this->audit(null, 'anonymous', 'oauth_consent', ['client_id' => $rec['client_id']], 'denied', 'csrf check failed', $r->ip);
            return $this->errorPage('The security check failed. Go back to ChatGPT and try again.', 403);
        }
        $redirect = (string) $rec['redirect_uri'];
        $state = (string) $rec['state'];
        $clientId = (string) $rec['client_id'];

        if ($this->str($f['decision'] ?? '') === 'deny') {
            if (!$this->consumeAuthRequest($reqId)) {
                return $this->errorPage('This sign-in request was already used.');
            }
            $this->audit(null, 'anonymous', 'oauth_consent', ['client_id' => $clientId], 'denied', 'owner denied access to ' . $rec['client_name'], $r->ip);
            return $this->redirectTo($redirect, ['error' => 'access_denied', 'error_description' => 'The owner denied the request.', 'state' => $state]);
        }
        if ($this->str($f['decision'] ?? '') !== 'allow') {
            return $this->errorPage('Choose Allow or Deny.');
        }

        $again = function (string $msg, int $status = 200) use ($rec, $reqId, $csrf, $redirect, $f, $clientId): HttpResponse {
            return $this->page(ConsentPage::consent(
                ['name' => (string) $rec['client_name'], 'client_id' => (string) $rec['client_id']],
                $redirect, $rec['scopes'], $reqId, $csrf, $msg, substr($this->str($f['email'] ?? ''), 0, 190),
                (string) ($rec['registered'] ?? ''), !$this->pairing->clientHasActiveGrant($clientId)), $status);
        };

        // The window may have closed (or the request may predate it) since the consent page was shown.
        if (!$this->pairing->clientHasActiveGrant($clientId) && !$this->pairing->isOpen()) {
            $this->consumeAuthRequest($reqId);
            return $this->pairingRequired($clientId, (string) $rec['client_name'], $r->ip);
        }

        // Brute-force guard: counts every Allow attempt from this IP (5 per 15 minutes).
        if (!$this->s->rateLimiter()->hit('oauth_consent', $r->ip, 5, 900)) {
            $this->audit(null, 'anonymous', 'oauth_login', ['client_id' => $clientId], 'rate_limited', 'consent attempts throttled', $r->ip);
            return $again('Too many attempts. Wait 15 minutes and try again.', 429);
        }
        $attempts = (int) $rec['attempts'] + 1;
        if ($attempts > self::MAX_REQ_ATTEMPTS) {
            $this->consumeAuthRequest($reqId);
            return $this->errorPage('Too many failed attempts on this request. Go back to ChatGPT and start again.', 429);
        }
        $this->updateAuthRequest($reqId, ['attempts' => $attempts] + $rec);

        $generic = 'Sign-in failed. Check your email, password and authenticator code.';
        $email = trim($this->str($f['email'] ?? ''));
        $password = $this->str($f['password'] ?? '');
        $totp = preg_replace('/\s+/', '', $this->str($f['totp'] ?? '')) ?? '';
        if ($email === '' || $password === '' || strlen($password) > 1024 || preg_match('/^\d{6}$/', $totp) !== 1) {
            return $again($generic);
        }
        try {
            $user = $this->s->auth()->verifyPassword($email, $password, $r->ip);
            $ok = $user !== null && ($user['role'] ?? '') === 'owner' && isset($user['id'])
                && $this->s->auth()->verifyTotp((string) $user['id'], $totp, $r->ip);
        } catch (DomainError $e) {
            $this->audit(null, 'anonymous', 'oauth_login', ['client_id' => $clientId], 'rate_limited', 'sign-in throttled on consent page', $r->ip);
            return $again('Too many attempts, try again in 15 minutes.', 429);
        }
        if (!$ok) {
            $this->audit(null, 'anonymous', 'oauth_login', ['client_id' => $clientId], 'denied', 'owner sign-in failed on consent page', $r->ip);
            return $again($generic);
        }

        $posted = $f['scope'] ?? [];
        $posted = is_array($posted) ? array_values(array_filter($posted, 'is_string')) : [];
        $granted = Scopes::normalize(array_values(array_intersect($posted, $rec['scopes'])));
        if ($granted === []) {
            return $again('Tick at least one permission, or press Deny.');
        }
        if (!$this->consumeAuthRequest($reqId)) {
            return $this->errorPage('This sign-in request was already used.');
        }
        $code = $this->s->crypto()->randomToken(32);
        $st = $this->s->db()->pdo()->prepare(
            'INSERT INTO oauth_codes (code_hash, client_id, user_id, redirect_uri, scopes, resource, code_challenge, expires_at) VALUES (?,?,?,?,?,?,?,?)');
        $st->execute([
            $this->s->crypto()->hash($code), $clientId, (string) $user['id'], $redirect, implode(' ', $granted),
            (string) $rec['resource'], (string) $rec['code_challenge'],
            TokenStore::ts($this->s->db()->now()->modify('+' . self::CODE_TTL . ' seconds')),
        ]);
        $this->audit(null, 'owner:' . $user['id'], 'oauth_consent', ['client_id' => $clientId, 'scopes' => implode(' ', $granted)], 'ok',
            'owner approved ' . $rec['client_name'] . ' with ' . count($granted) . ' scope(s)', $r->ip);
        return $this->redirectTo($redirect, ['code' => $code, 'state' => $state]);
    }

    // The authorization request record lives in idempotency_keys (scope_key "authreq:<sha256 of id>").

    /** @param array<string,mixed> $data */
    private function saveAuthRequest(string $id, array $data): void
    {
        $pdo = $this->s->db()->pdo();
        $cut = TokenStore::ts($this->s->db()->now()->modify('-' . self::AUTHREQ_TTL . ' seconds'));
        $d = $pdo->prepare("DELETE FROM idempotency_keys WHERE scope_key LIKE 'authreq:%' AND created_at < ?");
        $d->execute([$cut]);
        $st = $pdo->prepare('INSERT INTO idempotency_keys (scope_key, result_json, created_at) VALUES (?,?,?)');
        $st->execute(['authreq:' . $this->s->crypto()->hash($id), json_encode($data, JSON_UNESCAPED_SLASHES), $this->nowTs()]);
    }

    /** @return array<string,mixed>|null */
    private function loadAuthRequest(string $id): ?array
    {
        if ($id === '' || strlen($id) > 100) {
            return null;
        }
        $st = $this->s->db()->pdo()->prepare('SELECT result_json, created_at FROM idempotency_keys WHERE scope_key = ?');
        $st->execute(['authreq:' . $this->s->crypto()->hash($id)]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $cut = TokenStore::ts($this->s->db()->now()->modify('-' . self::AUTHREQ_TTL . ' seconds'));
        if ($row['created_at'] < $cut) {
            return null;
        }
        $d = json_decode((string) $row['result_json'], true);
        return is_array($d) && isset($d['csrf_hash'], $d['scopes']) ? $d : null;
    }

    /** @param array<string,mixed> $data */
    private function updateAuthRequest(string $id, array $data): void
    {
        $st = $this->s->db()->pdo()->prepare('UPDATE idempotency_keys SET result_json = ? WHERE scope_key = ?');
        $st->execute([json_encode($data, JSON_UNESCAPED_SLASHES), 'authreq:' . $this->s->crypto()->hash($id)]);
    }

    /** Single use: true only for the one caller that actually deleted the record. */
    private function consumeAuthRequest(string $id): bool
    {
        $st = $this->s->db()->pdo()->prepare('DELETE FROM idempotency_keys WHERE scope_key = ?');
        $st->execute(['authreq:' . $this->s->crypto()->hash($id)]);
        return $st->rowCount() === 1;
    }

    // ------------------------------------------------------------------ token endpoint

    private function token(HttpRequest $r): HttpResponse
    {
        if (!$this->s->rateLimiter()->hit('oauth_token', $r->ip, 30, 60)) {
            return $this->oauthError('temporarily_unavailable', 'Too many requests.', 429);
        }
        $ct = strtolower($r->header('content-type') ?? '');
        if (!str_starts_with($ct, 'application/x-www-form-urlencoded')) {
            return $this->oauthError('invalid_request', 'Content-Type must be application/x-www-form-urlencoded.');
        }
        $f = $r->form;
        $grant = $this->str($f['grant_type'] ?? '');
        return match ($grant) {
            'authorization_code' => $this->tokenFromCode($r),
            'refresh_token' => $this->tokenFromRefresh($r),
            default => $this->oauthError('unsupported_grant_type', 'grant_type must be authorization_code or refresh_token.'),
        };
    }

    private function tokenResponse(array $pair, string $scopes): HttpResponse
    {
        return $this->json([
            'access_token' => $pair['access_token'],
            'token_type' => 'Bearer',
            'expires_in' => $pair['expires_in'],
            'refresh_token' => $pair['refresh_token'],
            'scope' => $scopes,
        ]);
    }

    private function tokenFromCode(HttpRequest $r): HttpResponse
    {
        $f = $r->form;
        $code = $this->str($f['code'] ?? '');
        $verifier = $this->str($f['code_verifier'] ?? '');
        $redirect = $this->str($f['redirect_uri'] ?? '');
        $clientId = $this->str($f['client_id'] ?? '');
        $resource = $this->str($f['resource'] ?? '');
        if ($code === '' || strlen($code) > 200 || $clientId === '') {
            return $this->oauthError('invalid_request', 'code and client_id are required.');
        }
        $pdo = $this->s->db()->pdo();
        $hash = $this->s->crypto()->hash($code);
        $st = $pdo->prepare('SELECT * FROM oauth_codes WHERE code_hash = ?');
        $st->execute([$hash]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return $this->oauthError('invalid_grant', 'The code is invalid or expired.');
        }
        if ($row['used_at'] !== null) {
            $this->handleCodeReuse($hash, $r->ip);
            return $this->oauthError('invalid_grant', 'The code is invalid or expired.');
        }
        if ($row['expires_at'] <= $this->nowTs()
            || !hash_equals((string) $row['client_id'], $clientId)
            || !hash_equals((string) $row['redirect_uri'], $redirect)) {
            return $this->oauthError('invalid_grant', 'The code is invalid or expired.');
        }
        if (preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier) !== 1) {
            return $this->oauthError('invalid_grant', 'code_verifier is missing or malformed.');
        }
        $computed = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        if (!hash_equals((string) $row['code_challenge'], $computed)) {
            $this->audit(null, 'anonymous', 'oauth_token', ['client_id' => $clientId], 'denied', 'PKCE verification failed', $r->ip);
            return $this->oauthError('invalid_grant', 'PKCE verification failed.');
        }
        if ($resource !== '' && !hash_equals((string) $row['resource'], $resource)) {
            return $this->oauthError('invalid_target', 'resource does not match the authorization request.');
        }
        // Claim the code: exactly one caller can flip used_at.
        $claim = $pdo->prepare('UPDATE oauth_codes SET used_at = ? WHERE code_hash = ? AND used_at IS NULL');
        $claim->execute([$this->nowTs(), $hash]);
        if ($claim->rowCount() !== 1) {
            $this->handleCodeReuse($hash, $r->ip);
            return $this->oauthError('invalid_grant', 'The code is invalid or expired.');
        }
        $scopes = Scopes::parse((string) $row['scopes']);
        $newClient = !$this->pairing->clientHasActiveGrant((string) $row['client_id']);
        $pair = $this->s->db()->transaction(function () use ($row, $scopes, $hash) {
            $grantId = $this->tokens->createGrant((string) $row['client_id'], (string) $row['user_id'], $scopes, (string) $row['resource']);
            // oauth_codes has no grant column: remember code -> grant so a replayed code can revoke the grant.
            $m = $this->s->db()->pdo()->prepare('INSERT INTO idempotency_keys (scope_key, result_json, created_at) VALUES (?,?,?)');
            $m->execute(['codegrant:' . $hash, json_encode(['grant_id' => $grantId]), $this->nowTs()]);
            return ['grant_id' => $grantId] + ($this->tokens->issuePair($grantId) ?? []);
        });
        if (!isset($pair['access_token'])) {
            return $this->oauthError('invalid_grant', 'The grant is no longer valid.');
        }
        if ($newClient) {
            // Single use: the pairing window closes as soon as a grant exists for a new client.
            $this->pairing->close();
            $this->audit($pair['grant_id'], 'owner:' . $row['user_id'], 'connector_pairing_close', [], 'ok', 'pairing window closed: new connection created', $r->ip);
        }
        $this->audit($pair['grant_id'], 'owner:' . $row['user_id'], 'oauth_token', ['client_id' => $clientId, 'grant' => 'authorization_code'], 'ok', 'tokens issued', $r->ip);
        return $this->tokenResponse($pair, implode(' ', $scopes));
    }

    private function handleCodeReuse(string $codeHash, string $ip): void
    {
        $st = $this->s->db()->pdo()->prepare('SELECT result_json FROM idempotency_keys WHERE scope_key = ?');
        $st->execute(['codegrant:' . $codeHash]);
        $j = $st->fetchColumn();
        $grantId = is_string($j) ? (json_decode($j, true)['grant_id'] ?? null) : null;
        if (is_string($grantId)) {
            $this->tokens->revokeGrant($grantId, 'code_reuse');
            $this->audit($grantId, 'anonymous', 'code_reuse', [], 'denied', 'authorization code replayed; grant revoked', $ip);
        } else {
            $this->audit(null, 'anonymous', 'code_reuse', [], 'denied', 'authorization code replayed', $ip);
        }
    }

    private function tokenFromRefresh(HttpRequest $r): HttpResponse
    {
        $f = $r->form;
        $presented = $this->str($f['refresh_token'] ?? '');
        $clientId = $this->str($f['client_id'] ?? '');
        $resource = $this->str($f['resource'] ?? '');
        if ($presented === '' || strlen($presented) > 200 || $clientId === '') {
            return $this->oauthError('invalid_request', 'refresh_token and client_id are required.');
        }
        $pdo = $this->s->db()->pdo();
        $hash = $this->s->crypto()->hash($presented);
        $st = $pdo->prepare(
            'SELECT t.grant_id, t.expires_at, t.used_at, t.revoked_at AS t_rev, g.client_id, g.scopes, g.resource, g.revoked_at AS g_rev
               FROM oauth_tokens t JOIN oauth_grants g ON g.id = t.grant_id WHERE t.token_hash = ? AND t.kind = ?');
        $st->execute([$hash, 'refresh']);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        $bad = fn() => $this->oauthError('invalid_grant', 'The refresh token is invalid or expired.');
        if (!$row || !hash_equals((string) $row['client_id'], $clientId)) {
            return $bad();
        }
        $grantId = (string) $row['grant_id'];
        if ($row['used_at'] !== null) {
            return $this->refreshReuse($grantId, $r->ip, $bad);
        }
        if ($row['t_rev'] !== null || $row['g_rev'] !== null || $row['expires_at'] <= $this->nowTs()) {
            return $bad();
        }
        if ($resource !== '' && !hash_equals((string) $row['resource'], $resource)) {
            return $this->oauthError('invalid_target', 'resource does not match the grant.');
        }
        $claim = $pdo->prepare('UPDATE oauth_tokens SET used_at = ? WHERE token_hash = ? AND used_at IS NULL');
        $claim->execute([$this->nowTs(), $hash]);
        if ($claim->rowCount() !== 1) {
            return $this->refreshReuse($grantId, $r->ip, $bad);
        }
        $pair = $this->tokens->issuePair($grantId);
        if ($pair === null) {
            return $bad();
        }
        $this->audit($grantId, 'oauth', 'oauth_token', ['client_id' => $clientId, 'grant' => 'refresh_token'], 'ok', 'refresh token rotated', $r->ip);
        return $this->tokenResponse($pair, (string) $row['scopes']);
    }

    private function refreshReuse(string $grantId, string $ip, callable $bad): HttpResponse
    {
        $this->tokens->revokeGrant($grantId, 'refresh_reuse');
        $this->audit($grantId, 'oauth', 'refresh_reuse', [], 'denied', 'used refresh token presented again; grant revoked', $ip);
        return $bad();
    }

    // ------------------------------------------------------------------ revocation (RFC 7009)

    private function revoke(HttpRequest $r): HttpResponse
    {
        if (!$this->s->rateLimiter()->hit('oauth_revoke', $r->ip, 30, 60)) {
            return $this->oauthError('temporarily_unavailable', 'Too many requests.', 429);
        }
        $token = $this->str($r->form['token'] ?? '');
        $clientId = $this->str($r->form['client_id'] ?? '');
        if ($token !== '' && strlen($token) <= 200) {
            $st = $this->s->db()->pdo()->prepare(
                'SELECT t.grant_id, g.client_id FROM oauth_tokens t JOIN oauth_grants g ON g.id = t.grant_id WHERE t.token_hash = ?');
            $st->execute([$this->s->crypto()->hash($token)]);
            $row = $st->fetch(\PDO::FETCH_ASSOC);
            // Revoking either token kind ends the whole grant. A client may only revoke its own tokens.
            if ($row && $clientId !== '' && hash_equals((string) $row['client_id'], $clientId)) {
                $this->tokens->revokeGrant((string) $row['grant_id'], 'client_revoked');
                $this->audit((string) $row['grant_id'], 'oauth', 'oauth_revoke', ['client_id' => $clientId], 'ok', 'grant revoked by client', $r->ip);
            }
        }
        return $this->json(new \stdClass(), 200);
    }
}
