<?php
declare(strict_types=1);

namespace StretchEffect\Services;

use StretchEffect\Contracts\DomainError;
use StretchEffect\Db;
use StretchEffect\Support\Time;

/** Owner view of ChatGPT connector grants (oauth_grants). Revoke kills the grant and all its tokens. */
final class GrantService
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        $rows = $this->db->all(
            'SELECT g.*, c.client_name FROM oauth_grants g LEFT JOIN oauth_clients c ON c.client_id = g.client_id ORDER BY g.created_at DESC LIMIT 200'
        );
        return array_map(static function (array $r): array {
            $g = [
                'id' => (string) $r['id'],
                'clientName' => (string) ($r['client_name'] ?? 'ChatGPT'),
                'scopes' => array_values(array_filter(preg_split('/[\s,]+/', (string) $r['scopes']) ?: [])),
                'createdAt' => Time::iso((string) $r['created_at']),
            ];
            if ($r['last_used_at'] !== null) {
                $g['lastUsedAt'] = Time::iso((string) $r['last_used_at']);
            }
            if ($r['revoked_at'] !== null) {
                $g['revokedAt'] = Time::iso((string) $r['revoked_at']);
            }
            return $g;
        }, $rows);
    }

    public function revoke(string $grantId, string $reason = 'owner'): void
    {
        $now = Time::toDb($this->db->now());
        $this->db->transaction(function () use ($grantId, $now, $reason): void {
            if ($this->db->val('SELECT 1 FROM oauth_grants WHERE id = ?', [$grantId]) === null) {
                throw new DomainError('not_found', 'Grant not found');
            }
            $this->db->run('UPDATE oauth_grants SET revoked_at = ?, revoke_reason = ? WHERE id = ? AND revoked_at IS NULL', [$now, mb_substr($reason, 0, 80), $grantId]);
            $this->db->run('UPDATE oauth_tokens SET revoked_at = ? WHERE grant_id = ? AND revoked_at IS NULL', [$now, $grantId]);
        });
    }

    /**
     * Revoke every connector grant (and its tokens) approved by this user. Call it whenever the user's password or
     * authenticator secret changes, so a stolen connection does not survive a credential reset.
     * @return int grants revoked
     */
    public function revokeAllForUser(string $userId, string $reason = 'credentials_changed'): int
    {
        $now = Time::toDb($this->db->now());
        return $this->db->transaction(function () use ($userId, $now, $reason): int {
            $n = $this->db->run('UPDATE oauth_grants SET revoked_at = ?, revoke_reason = ? WHERE user_id = ? AND revoked_at IS NULL', [$now, mb_substr($reason, 0, 80), $userId]);
            $this->db->run(
                'UPDATE oauth_tokens SET revoked_at = ? WHERE revoked_at IS NULL AND grant_id IN (SELECT id FROM oauth_grants WHERE user_id = ?)',
                [$now, $userId]
            );
            return $n;
        });
    }
}
