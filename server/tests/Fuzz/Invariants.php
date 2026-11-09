<?php
declare(strict_types=1);

namespace StretchEffect\Tests\Fuzz;

use StretchEffect\App;

/** Database-level invariants of the booking system, checked after every fuzz case. Each returns a list of violations (empty = fine). */
final class Invariants
{
    /** @return array<string,int> user id => SUM(delta) */
    public static function balances(App $app): array
    {
        $out = [];
        foreach ($app->db()->all('SELECT u.id, COALESCE(SUM(l.delta),0) AS b FROM users u LEFT JOIN credit_ledger l ON l.user_id = u.id GROUP BY u.id ORDER BY u.id') as $r) {
            $out[(string) $r['id']] = (int) $r['b'];
        }
        return $out;
    }

    public static function ledgerSig(App $app): string
    {
        return md5(json_encode($app->db()->all('SELECT id, user_id, delta, reason, booking_id FROM credit_ledger ORDER BY id')));
    }

    public static function bookingSig(App $app): string
    {
        return md5(json_encode($app->db()->all('SELECT id, user_id, start_at, end_at, status, refunded FROM bookings ORDER BY id')));
    }

    /** Everything that matters for "a rejected request changed nothing" (rate limits, audit and idempotency rows are allowed to change). */
    public static function stateSig(App $app): string
    {
        $t = [];
        foreach (['users', 'credit_ledger', 'bookings', 'pack_requests', 'payment_references', 'packages', 'oauth_clients'] as $table) {
            $t[$table] = $app->db()->all("SELECT * FROM $table ORDER BY 1");
        }
        $t['oauth_grants'] = $app->db()->all('SELECT id, client_id, user_id, scopes, resource, revoked_at FROM oauth_grants ORDER BY id'); // last_used_at is touched by every call
        $t['settings'] = $app->db()->all("SELECT k, v FROM settings WHERE k NOT LIKE 'connector_%' ORDER BY k");
        return md5(json_encode($t, JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /** @return list<string> */
    public static function balances_ok(App $app): array
    {
        $v = [];
        foreach (self::balances($app) as $id => $b) {
            $id = (string) $id; // an all-digit id becomes an int array key
            if ($b < 0) {
                $v[] = "client $id has a negative balance ($b)";
            }
            if ($b !== $app->credits()->balance($id)) {
                $v[] = "balance() disagrees with SUM(ledger) for $id";
            }
        }
        return $v;
    }

    /** ChatGPT credit rules: each change 1..20, at most 40 added per Africa/Johannesburg day. @return list<string> */
    public static function chatgptCaps(App $app): array
    {
        $v = [];
        $perDay = [];
        foreach ($app->db()->all("SELECT delta, created_at FROM credit_ledger WHERE reason = 'chatgpt_adjust'") as $r) {
            $d = (int) $r['delta'];
            if ($d === 0 || abs($d) > 20) {
                $v[] = "a chatgpt_adjust row changed credits by $d (allowed 1..20)";
            }
            if ($d > 0) {
                $local = (new \DateTimeImmutable((string) $r['created_at'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Africa/Johannesburg'))->format('Y-m-d');
                $perDay[$local] = ($perDay[$local] ?? 0) + $d;
            }
        }
        foreach ($perDay as $day => $sum) {
            if ($sum > 40) {
                $v[] = "ChatGPT added $sum sessions on $day (cap 40)";
            }
        }
        return $v;
    }

    /**
     * Confirmed bookings: no overlap, buffer gap, on the 30-minute grid, inside default working hours, one -1 ledger row each,
     * refund flag matching ledger. Assumes default settings (the stateful fuzz never changes them).
     * @return list<string>
     */
    public static function bookings(App $app): array
    {
        $v = [];
        $rows = $app->db()->all("SELECT * FROM bookings WHERE status IN ('confirmed','completed','no_show') ORDER BY start_at, id");
        $dump = static fn() => json_encode(array_map(static fn($r) => [$r['id'], $r['start_at'], $r['status'], $r['created_by']], $rows));
        $tz = new \DateTimeZone('Africa/Johannesburg');
        $prevEnd = null;
        $prevId = null;
        foreach ($rows as $r) {
            $s = (new \DateTimeImmutable((string) $r['start_at'], new \DateTimeZone('UTC')));
            $e = (new \DateTimeImmutable((string) $r['end_at'], new \DateTimeZone('UTC')));
            if ($e->getTimestamp() - $s->getTimestamp() !== 3600) {
                $v[] = "booking {$r['id']} is not 60 minutes long";
            }
            if ($prevEnd !== null && $s->getTimestamp() < $prevEnd + 900) {
                $v[] = "booking {$r['id']} starts less than 15 minutes after booking $prevId ends (overlap or buffer broken). Bookings: " . $dump();
            }
            $prevEnd = $e->getTimestamp();
            $prevId = $r['id'];
            $ls = $s->setTimezone($tz);
            $le = $e->setTimezone($tz);
            $min = (int) $ls->format('i');
            if ($min % 30 !== 0) {
                $v[] = "booking {$r['id']} is off the 30-minute grid ({$ls->format('H:i')})";
            }
            $w = (int) $ls->format('w');
            $open = $w === 0 ? null : ($w === 6 ? [7 * 60, 12 * 60] : [6 * 60, 19 * 60]);
            $sm = (int) $ls->format('H') * 60 + $min;
            $em = (int) $le->format('H') * 60 + (int) $le->format('i');
            if ($open === null || $sm < $open[0] || $em > $open[1] || $ls->format('Y-m-d') !== $le->format('Y-m-d')) {
                $v[] = "booking {$r['id']} is outside working hours ({$ls->format('D H:i')}-{$le->format('H:i')})";
            }
        }
        foreach ($app->db()->all('SELECT b.id, b.status, b.refunded, (SELECT COUNT(*) FROM credit_ledger l WHERE l.booking_id = b.id AND l.reason = \'booking\') AS debits, (SELECT COUNT(*) FROM credit_ledger l WHERE l.booking_id = b.id AND l.reason = \'cancel_refund\') AS refunds FROM bookings b') as $r) {
            if ((int) $r['debits'] !== 1) {
                $v[] = "booking {$r['id']} has {$r['debits']} booking debit rows (expected 1)";
            }
            if ((int) $r['refunds'] !== (int) $r['refunded'] || ((int) $r['refunds'] > 0 && $r['status'] !== 'cancelled')) {
                $v[] = "booking {$r['id']}: refunded flag {$r['refunded']} vs {$r['refunds']} refund rows (status {$r['status']})";
            }
        }
        return $v;
    }

    /** @return list<string> */
    public static function all(App $app): array
    {
        return array_merge(self::balances_ok($app), self::chatgptCaps($app), self::bookings($app));
    }
}
