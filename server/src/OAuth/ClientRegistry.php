<?php
declare(strict_types=1);

namespace StretchEffect\OAuth;

use StretchEffect\Contracts\Services;

/** OAuth client registration (RFC 7591 DCR) and Client ID Metadata Documents (CIMD). Public clients only. */
final class ClientRegistry
{
    public const MAX_REDIRECTS = 5;
    public const MAX_NAME = 100;
    private const CIMD_CACHE_SECONDS = 3600;
    private const MAX_CLIENTS = 500;

    public function __construct(private Services $s, private CimdFetcher $fetcher) {}

    // ---------------------------------------------------------------- redirect allow-list

    /** @return list<string> */
    private function cfgList(string $key, array $default): array
    {
        $v = $this->s->config()->get($key, $default);
        return is_array($v) ? array_values(array_filter($v, 'is_string')) : $default;
    }

    public function redirectAllowed(mixed $uri): bool
    {
        if (!is_string($uri) || $uri === '' || strlen($uri) > 500) {
            return false;
        }
        // no whitespace/control characters, wildcards, backslashes, fragments or dot segments
        if (preg_match('/[\x00-\x20\x7f*\\\\#]/', $uri) || str_contains($uri, '/../') || str_ends_with($uri, '/..')) {
            return false;
        }
        $p = parse_url($uri);
        if ($p === false || !isset($p['scheme'], $p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['fragment'])) {
            return false;
        }
        $scheme = strtolower($p['scheme']);
        $host = strtolower($p['host']);
        $loopback = in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
        // https only; plain http is tolerated for loopback hosts on a non-live install (MCP Inspector, local dev).
        if ($scheme !== 'https' && !($scheme === 'http' && $loopback && !$this->s->config()->isLive())) {
            return false;
        }
        $exact = $this->cfgList('connector.allowed_redirect_uris', ['https://chatgpt.com/connector_platform_oauth_redirect']);
        if (in_array($uri, $exact, true)) {
            return true;
        }
        $prefixes = $this->cfgList('connector.allowed_redirect_prefixes', ['https://chatgpt.com/connector/oauth/']);
        foreach ($prefixes as $prefix) {
            if (!str_ends_with($prefix, '/') || !str_starts_with($prefix, 'https://')) {
                continue; // misconfigured prefix, ignore (a prefix without a trailing slash would match other paths)
            }
            if (str_starts_with($uri, $prefix)) {
                $rest = substr($uri, strlen($prefix));
                if (preg_match('/^[A-Za-z0-9._~-]{1,128}$/', $rest) === 1) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return list<string> @throws \InvalidArgumentException */
    public function validateRedirects(mixed $uris): array
    {
        if (!is_array($uris) || $uris === [] || !array_is_list($uris)) {
            throw new \InvalidArgumentException('redirect_uris must be a non-empty array');
        }
        if (count($uris) > self::MAX_REDIRECTS) {
            throw new \InvalidArgumentException('at most ' . self::MAX_REDIRECTS . ' redirect_uris are allowed');
        }
        foreach ($uris as $u) {
            if (!$this->redirectAllowed($u)) {
                throw new \InvalidArgumentException('redirect_uri is not on the allow-list');
            }
        }
        return array_values(array_unique($uris));
    }

    public static function sanitizeName(mixed $name): string
    {
        $n = is_string($name) ? $name : '';
        $n = strip_tags($n);
        $n = preg_replace('/[\p{C}\p{Zl}\p{Zp}]+/u', ' ', $n) ?? '';
        $n = trim(preg_replace('/\s+/u', ' ', $n) ?? '');
        $n = mb_substr($n, 0, self::MAX_NAME);
        return $n === '' ? 'Unnamed app' : $n;
    }

    // ---------------------------------------------------------------- DCR

    /**
     * @param array<string,mixed> $meta decoded registration request
     * @return array<string,mixed> registration response
     * @throws \InvalidArgumentException invalid_client_metadata / invalid_redirect_uri (message = description)
     */
    public function register(array $meta): array
    {
        $auth = $meta['token_endpoint_auth_method'] ?? 'none';
        if ($auth !== 'none') {
            throw new \InvalidArgumentException('only public clients are supported (token_endpoint_auth_method none)');
        }
        $grants = $meta['grant_types'] ?? ['authorization_code', 'refresh_token'];
        if (!is_array($grants) || array_diff($grants, ['authorization_code', 'refresh_token']) !== []) {
            throw new \InvalidArgumentException('unsupported grant_types');
        }
        $resp = $meta['response_types'] ?? ['code'];
        if (!is_array($resp) || array_diff($resp, ['code']) !== []) {
            throw new \InvalidArgumentException('unsupported response_types');
        }
        $uris = $this->validateRedirects($meta['redirect_uris'] ?? null);
        $name = self::sanitizeName($meta['client_name'] ?? '');

        $pdo = $this->s->db()->pdo();
        $count = (int) $pdo->query("SELECT COUNT(*) FROM oauth_clients WHERE registration = 'dcr'")->fetchColumn();
        if ($count >= self::MAX_CLIENTS) {
            throw new \OverflowException('registration capacity reached');
        }
        $clientId = $this->s->crypto()->randomToken(16);
        $now = $this->s->db()->now();
        $st = $pdo->prepare('INSERT INTO oauth_clients (client_id, client_name, redirect_uris, registration, created_at) VALUES (?,?,?,?,?)');
        $st->execute([$clientId, $name, json_encode($uris, JSON_UNESCAPED_SLASHES), 'dcr', $now->format('Y-m-d H:i:s')]);

        return [
            'client_id' => $clientId,
            'client_id_issued_at' => $now->getTimestamp(),
            'client_name' => $name,
            'redirect_uris' => $uris,
            'token_endpoint_auth_method' => 'none',
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
        ];
    }

    // ---------------------------------------------------------------- lookup (DCR row or CIMD document)

    /**
     * @return array{client_id:string,client_name:string,redirect_uris:list<string>,registration:string}
     * @throws \RuntimeException when the client is unknown or its metadata is unacceptable
     */
    public function find(string $clientId): array
    {
        if ($clientId === '' || strlen($clientId) > 255) {
            throw new \RuntimeException('unknown client');
        }
        $row = $this->row($clientId);
        if (str_starts_with($clientId, 'https://')) {
            $fresh = $row !== null
                && (new \DateTimeImmutable($row['created_at'], new \DateTimeZone('UTC')))->getTimestamp()
                    > $this->s->db()->now()->getTimestamp() - self::CIMD_CACHE_SECONDS;
            if (!$fresh) {
                $row = $this->fetchCimd($clientId);
            }
        }
        if ($row === null) {
            throw new \RuntimeException('unknown client');
        }
        $uris = json_decode((string) $row['redirect_uris'], true);
        return [
            'client_id' => $row['client_id'],
            'client_name' => $row['client_name'],
            'redirect_uris' => is_array($uris) ? array_values(array_filter($uris, 'is_string')) : [],
            'registration' => $row['registration'],
        ];
    }

    /** @return array<string,mixed>|null */
    private function row(string $clientId): ?array
    {
        $st = $this->s->db()->pdo()->prepare('SELECT * FROM oauth_clients WHERE client_id = ?');
        $st->execute([$clientId]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        return is_array($r) ? $r : null;
    }

    /** @return array<string,mixed> the stored oauth_clients row */
    private function fetchCimd(string $url): array
    {
        $p = parse_url($url);
        $hosts = array_map('strtolower', $this->cfgList('connector.cimd_hosts', ['chatgpt.com', 'openai.com']));
        if ($p === false || ($p['scheme'] ?? '') !== 'https' || !isset($p['host']) || isset($p['user']) || isset($p['pass'])
            || isset($p['fragment']) || (isset($p['port']) && $p['port'] !== 443)
            || !in_array(strtolower($p['host']), $hosts, true)) {
            throw new \RuntimeException('cimd host not allowed');
        }
        $doc = $this->fetcher->fetch($url);
        if (($doc['client_id'] ?? null) !== $url) {
            throw new \RuntimeException('cimd client_id mismatch');
        }
        if (($doc['token_endpoint_auth_method'] ?? 'none') !== 'none') {
            throw new \RuntimeException('cimd client must be public');
        }
        try {
            $uris = $this->validateRedirects($doc['redirect_uris'] ?? null);
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException('cimd redirect_uris rejected');
        }
        $name = self::sanitizeName($doc['client_name'] ?? '');
        $now = $this->s->db()->now()->format('Y-m-d H:i:s');
        $pdo = $this->s->db()->pdo();
        $del = $pdo->prepare('DELETE FROM oauth_clients WHERE client_id = ?');
        $del->execute([$url]);
        $ins = $pdo->prepare('INSERT INTO oauth_clients (client_id, client_name, redirect_uris, registration, created_at) VALUES (?,?,?,?,?)');
        $ins->execute([$url, $name, json_encode($uris, JSON_UNESCAPED_SLASHES), 'cimd', $now]);
        return ['client_id' => $url, 'client_name' => $name, 'redirect_uris' => json_encode($uris, JSON_UNESCAPED_SLASHES),
            'registration' => 'cimd', 'created_at' => $now];
    }
}
