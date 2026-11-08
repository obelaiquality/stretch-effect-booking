<?php
declare(strict_types=1);

namespace StretchEffect\Services;

use StretchEffect\Contracts\AuditLog as AuditLogContract;
use StretchEffect\Db;
use StretchEffect\Support\Time;

final class AuditLog implements AuditLogContract
{
    public function __construct(private readonly Db $db)
    {
    }

    public function write(?string $grantId, string $actor, string $action, array $args, string $status, string $summary, ?string $ip): void
    {
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
