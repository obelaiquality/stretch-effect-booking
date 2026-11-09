<?php
declare(strict_types=1);

namespace StretchEffect\Services;

use StretchEffect\Contracts\AuditLog as AuditLogContract;
use StretchEffect\Db;
use StretchEffect\Support\Time;

final class AuditLog implements AuditLogContract
{
    /** Rows of these kinds are kept 365 days instead of 180 (sign-in and connector events; anonymous failures are not, they are the flood). */
    public const LONG_KEPT_SQL = "(action LIKE 'oauth%' OR action LIKE 'connector%' OR action LIKE 'auth.%' OR action LIKE 'mcp%' OR action IN ('code_reuse','refresh_reuse'))"
        . " AND NOT (grant_id IS NULL AND actor = 'anonymous' AND status IN ('denied','rate_limited'))";

    /** Anonymous denied / throttled rows written per IP per hour; beyond this nothing more is stored (the limiters still act). */
    public const ANON_FAILURES_PER_IP_HOUR = 20;

    public function __construct(private readonly Db $db)
    {
    }

    public function write(?string $grantId, string $actor, string $action, array $args, string $status, string $summary, ?string $ip): void
    {
        if ($grantId === null && $actor === 'anonymous' && in_array($status, ['denied', 'rate_limited'], true) && $ip !== null
            && (int) $this->db->val(
                "SELECT COUNT(*) FROM audit_log WHERE ip = ? AND grant_id IS NULL AND actor = 'anonymous' AND status IN ('denied','rate_limited') AND at >= ?",
                [mb_substr($ip, 0, 45), Time::toDb($this->db->now()->modify('-1 hour'))]
            ) >= self::ANON_FAILURES_PER_IP_HOUR) {
            return;
        }
        $this->db->run(
            'INSERT INTO audit_log (id, at, grant_id, actor, action, args_json, status, summary, ip) VALUES (?,?,?,?,?,?,?,?,?)',
            [
                $this->db->newId(), Time::toDb($this->db->now()), $grantId,
                mb_substr($actor, 0, 60), mb_substr($action, 0, 60),
                json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
                mb_substr($status, 0, 14), mb_substr($summary, 0, 255), $ip === null ? null : mb_substr($ip, 0, 45),
            ]
        );
    }

    public function recent(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $rows = $this->db->all('SELECT * FROM audit_log ORDER BY at DESC, id DESC LIMIT ' . $limit);
        return array_map(static function (array $r): array {
            $args = json_decode((string) ($r['args_json'] ?? ''), true);
            return [
                'id' => (string) $r['id'],
                'at' => Time::iso((string) $r['at']),
                'grantId' => (string) ($r['grant_id'] ?? ''),
                'tool' => (string) $r['action'],
                'args' => is_array($args) && $args !== [] ? $args : new \stdClass(),
                'status' => (string) $r['status'],
                'summary' => (string) $r['summary'],
                'actor' => (string) $r['actor'],
            ];
        }, $rows);
    }
}
