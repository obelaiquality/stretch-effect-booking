<?php
declare(strict_types=1);

namespace StretchEffect\OAuth;

use StretchEffect\Contracts\Services;

/** Grants and opaque tokens. Only SHA-256 hashes are stored. Shared by the OAuth routes and the MCP endpoint. */
final class TokenStore
{
    public const ACCESS_TTL = 3600;          // 1 hour
    public const REFRESH_TTL = 2592000;      // 30 days
    public const GRANT_MAX_AGE = 7776000;    // 90 days: refresh tokens cannot extend a grant past this

    public function __construct(private Services $s) {}

    public static function ts(\DateTimeInterface $d): string
    {
        return $d->format('Y-m-d H:i:s');
    }

    public function resource(): string
    {
        return $this->s->config()->baseUrl() . '/mcp';
    }

    /** @param list<string> $scopes */
    public function createGrant(string $clientId, string $userId, array $scopes, string $resource): string
    {
        $id = $this->s->db()->newId();
        $st = $this->s->db()->pdo()->prepare(
            'INSERT INTO oauth_grants (id, client_id, user_id, scopes, resource, created_at) VALUES (?,?,?,?,?,?)');
        $st->execute([$id, $clientId, $userId, implode(' ', $scopes), $resource, self::ts($this->s->db()->now())]);
        return $id;
    }

    /**
     * Issue an access + refresh token pair for a grant.
     * @return array{access_token:string,refresh_token:string,expires_in:int}|null null when the grant is too old or revoked
     */
    public function issuePair(string $grantId): ?array
    {
        $pdo = $this->s->db()->pdo();
        $g = $pdo->prepare('SELECT created_at, revoked_at FROM oauth_grants WHERE id = ?');
        $g->execute([$grantId]);
        $grant = $g->fetch(\PDO::FETCH_ASSOC);
        if (!$grant || $grant['revoked_at'] !== null) {
            return null;
        }
        $now = $this->s->db()->now();
        $hardEnd = (new \DateTimeImmutable($grant['created_at'], new \DateTimeZone('UTC')))->modify('+' . self::GRANT_MAX_AGE . ' seconds');
        if ($hardEnd <= $now) {
            return null;
        }
        $refreshEnd = min($now->modify('+' . self::REFRESH_TTL . ' seconds'), $hardEnd);
        $access = $this->s->crypto()->randomToken(32);
        $refresh = $this->s->crypto()->randomToken(32);
        $ins = $pdo->prepare('INSERT INTO oauth_tokens (token_hash, grant_id, kind, expires_at, created_at) VALUES (?,?,?,?,?)');
        $ins->execute([$this->s->crypto()->hash($access), $grantId, 'access', self::ts($now->modify('+' . self::ACCESS_TTL . ' seconds')), self::ts($now)]);
        $ins->execute([$this->s->crypto()->hash($refresh), $grantId, 'refresh', self::ts($refreshEnd), self::ts($now)]);
        return ['access_token' => $access, 'refresh_token' => $refresh, 'expires_in' => self::ACCESS_TTL];
    }

    public function revokeGrant(string $grantId, string $reason): void
    {
        $now = self::ts($this->s->db()->now());
        $pdo = $this->s->db()->pdo();
        $a = $pdo->prepare('UPDATE oauth_grants SET revoked_at = ?, revoke_reason = ? WHERE id = ? AND revoked_at IS NULL');
        $a->execute([$now, substr($reason, 0, 80), $grantId]);
        $b = $pdo->prepare('UPDATE oauth_tokens SET revoked_at = ? WHERE grant_id = ? AND revoked_at IS NULL');
        $b->execute([$now, $grantId]);
    }

    /**
     * Validate a bearer access token for the MCP resource.
     * @return array{grant_id:string,client_id:string,user_id:string,scopes:list<string>}|null
     */
    public function authenticate(string $bearer): ?array
    {
        if ($bearer === '' || strlen($bearer) > 200) {
            return null;
        }
        $st = $this->s->db()->pdo()->prepare(
            'SELECT t.grant_id, t.expires_at, t.revoked_at AS t_rev, g.client_id, g.user_id, g.scopes, g.resource, g.revoked_at AS g_rev
               FROM oauth_tokens t JOIN oauth_grants g ON g.id = t.grant_id
              WHERE t.token_hash = ? AND t.kind = ?');
        $st->execute([$this->s->crypto()->hash($bearer), 'access']);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$r || $r['t_rev'] !== null || $r['g_rev'] !== null) {
            return null;
        }
        if ($r['expires_at'] <= self::ts($this->s->db()->now())) {
            return null;
        }
        if (!hash_equals($this->resource(), (string) $r['resource'])) {
            return null;
        }
        return [
            'grant_id' => (string) $r['grant_id'],
            'client_id' => (string) $r['client_id'],
            'user_id' => (string) $r['user_id'],
            'scopes' => Scopes::parse((string) $r['scopes']),
        ];
    }

    public function touch(string $grantId): void
    {
        $st = $this->s->db()->pdo()->prepare('UPDATE oauth_grants SET last_used_at = ? WHERE id = ?');
        $st->execute([self::ts($this->s->db()->now()), $grantId]);
    }
}
