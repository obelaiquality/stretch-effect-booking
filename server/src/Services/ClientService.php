<?php
declare(strict_types=1);

namespace StretchEffect\Services;

use StretchEffect\Contracts\ClientService as ClientServiceContract;
use StretchEffect\Db;
use StretchEffect\Support\Presenter;
use StretchEffect\Support\Time;

final class ClientService implements ClientServiceContract
{
    public function __construct(private readonly Db $db, private readonly CreditService $credits)
    {
    }

    public function search(string $query, int $limit = 20): array
    {
        $limit = max(1, min(200, $limit));
        $q = trim($query);
        if ($q === '') {
            $rows = $this->db->all("SELECT * FROM users WHERE role = 'client' ORDER BY name LIMIT " . $limit);
        } else {
            $like = '%' . strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $rows = $this->db->all(
                "SELECT * FROM users WHERE role = 'client' AND (name LIKE ? ESCAPE '!' OR email LIKE ? ESCAPE '!' OR phone LIKE ? ESCAPE '!') ORDER BY name LIMIT " . $limit,
                [$like, $like, $like]
            );
        }
        return array_map(fn (array $r): array => $this->summary($r), $rows);
    }

    public function get(string $userId): ?array
    {
        $row = $this->db->one("SELECT * FROM users WHERE id = ? AND role = 'client'", [$userId]);
        if ($row === null) {
            return null;
        }
        $bookings = $this->db->all(
            'SELECT b.*, u.name AS client_name FROM bookings b JOIN users u ON u.id = b.user_id WHERE b.user_id = ? ORDER BY b.start_at DESC LIMIT 500',
            [$userId]
        );
        return [
            'summary' => $this->summary($row),
            'ledger' => $this->credits->ledger($userId),
            'bookings' => array_map([Presenter::class, 'booking'], $bookings),
            'packs' => $this->credits->packs($userId),
        ];
    }

    /** @param array<string,mixed> $u @return array<string,mixed> */
    private function summary(array $u): array
    {
        $now = Time::toDb($this->db->now());
        $id = (string) $u['id'];
        $upcoming = (int) $this->db->val("SELECT COUNT(*) FROM bookings WHERE user_id = ? AND status = 'confirmed' AND start_at >= ?", [$id, $now]);
        $last = $this->db->val(
            "SELECT MAX(start_at) FROM bookings WHERE user_id = ? AND (status = 'completed' OR (status = 'confirmed' AND start_at < ?))",
            [$id, $now]
        );
        $s = [
            'user' => Presenter::user($u),
            'balance' => $this->credits->balance($id),
            'upcoming' => $upcoming,
        ];
        if ($last !== null) {
            $s['lastVisit'] = Time::iso((string) $last);
        }
        $s['pendingPacks'] = (int) $this->db->val("SELECT COUNT(*) FROM pack_requests WHERE user_id = ? AND status = 'pending'", [$id]);
        return $s;
    }
}
