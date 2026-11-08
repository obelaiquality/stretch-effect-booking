<?php
declare(strict_types=1);

namespace StretchEffect\Services;

use StretchEffect\Contracts\RateLimiter as RateLimiterContract;
use StretchEffect\Db;
use StretchEffect\Support\Time;

final class RateLimiter implements RateLimiterContract
{
    public function __construct(private readonly Db $db)
    {
    }

    public function hit(string $name, string $subject, int $limit, int $windowSeconds): bool
    {
        if ($limit <= 0 || $windowSeconds <= 0) {
            return false;
        }
        $now = $this->db->now()->getTimestamp();
        $windowStart = intdiv($now, $windowSeconds) * $windowSeconds;
        $bucket = $name . ':' . $subject . ':' . $windowStart;
        if (strlen($bucket) > 190) {
            $bucket = $name . ':' . hash('sha256', $subject) . ':' . $windowStart;
        }
        $expires = Time::toDb((new \DateTimeImmutable('@' . ($windowStart + $windowSeconds))));

        if (random_int(1, 100) === 1) {
            $this->db->run('DELETE FROM rate_limits WHERE expires_at < ?', [Time::toDb($this->db->now())]);
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            if ($this->db->run('UPDATE rate_limits SET hits = hits + 1 WHERE bucket = ? AND hits < ?', [$bucket, $limit]) === 1) {
                return true;
            }
            if ($this->db->val('SELECT hits FROM rate_limits WHERE bucket = ?', [$bucket]) !== null) {
                return false; // exists and at the limit
            }
            try {
                $this->db->run('INSERT INTO rate_limits (bucket, hits, expires_at) VALUES (?, 1, ?)', [$bucket, $expires]);
                return true;
            } catch (\PDOException) {
                // lost a race with a parallel request: retry the UPDATE
            }
        }
        return false;
    }

    /** Clear a subject's counters (e.g. after a successful login). */
    public function reset(string $name, string $subject): void
    {
        $this->db->run('DELETE FROM rate_limits WHERE bucket LIKE ?', [$name . ':' . $subject . ':%']);
    }
}
