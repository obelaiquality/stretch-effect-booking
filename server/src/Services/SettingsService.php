<?php
declare(strict_types=1);

namespace StretchEffect\Services;

use StretchEffect\Contracts\DomainError;
use StretchEffect\Db;
use StretchEffect\Support\Time;

/** Booking settings (web Settings shape) plus a small JSON key/value store on the settings table. */
final class SettingsService
{
    /** @var array<string,mixed>|null */
    private ?array $cache = null;

    public function __construct(private readonly Db $db)
    {
    }

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        $wh = [];
        for ($d = 0; $d <= 6; $d++) {
            $wh[] = match (true) {
                $d === 0 => ['weekday' => 0, 'open' => false, 'start' => '00:00', 'end' => '00:00'],
                $d === 6 => ['weekday' => 6, 'open' => true, 'start' => '07:00', 'end' => '12:00'],
                default => ['weekday' => $d, 'open' => true, 'start' => '06:00', 'end' => '19:00'],
            };
        }
        return [
            'timezone' => Time::TZ,
            'sessionMinutes' => 60,
            'bufferMinutes' => 15,
            'slotStepMinutes' => 30,
            'bookingWindowDays' => 28,
            'minNoticeHours' => 12,
            'cancelFreeHours' => 24,
            'workingHours' => $wh,
        ];
    }

    /** @return array<string,mixed> */
    public function get(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $stored = $this->getJson('booking') ?? [];
        $s = array_replace(self::defaults(), array_intersect_key($stored, self::defaults()));
        $s['timezone'] = Time::TZ;
        return $this->cache = $s;
    }

    /** @param array<string,mixed> $patch @return array<string,mixed> */
    public function update(array $patch): array
    {
        $cur = $this->get();
        $ranges = [
            'sessionMinutes' => [15, 240], 'bufferMinutes' => [0, 120], 'slotStepMinutes' => [5, 120],
            'bookingWindowDays' => [1, 365], 'minNoticeHours' => [0, 720], 'cancelFreeHours' => [0, 720],
        ];
        foreach ($patch as $k => $v) {
            if ($k === 'timezone') {
                if ($v !== Time::TZ) {
                    throw new DomainError('validation', 'Timezone is fixed to ' . Time::TZ);
                }
                continue;
            }
            if (isset($ranges[$k])) {
                if (!is_int($v) || $v < $ranges[$k][0] || $v > $ranges[$k][1]) {
                    throw new DomainError('validation', "$k must be a whole number between {$ranges[$k][0]} and {$ranges[$k][1]}");
                }
                $cur[$k] = $v;
            } elseif ($k === 'workingHours') {
                $cur[$k] = $this->validateHours($v);
            } else {
                throw new DomainError('validation', "Unknown setting: $k");
            }
        }
        $this->putJson('booking', $cur);
        $this->cache = null;
        return $this->get();
    }

    /** @return list<array<string,mixed>> */
    private function validateHours(mixed $v): array
    {
        if (!is_array($v) || count($v) !== 7) {
            throw new DomainError('validation', 'workingHours needs exactly 7 entries (weekday 0-6)');
        }
        $out = [];
        $seen = [];
        foreach ($v as $row) {
            if (!is_array($row) || !isset($row['weekday']) || !is_int($row['weekday']) || $row['weekday'] < 0 || $row['weekday'] > 6 || isset($seen[$row['weekday']])) {
                throw new DomainError('validation', 'Each workingHours entry needs a unique weekday 0-6');
            }
            $seen[$row['weekday']] = true;
            $open = $row['open'] ?? null;
            $start = $row['start'] ?? null;
            $end = $row['end'] ?? null;
            if (!is_bool($open) || !is_string($start) || !is_string($end)
                || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $end)) {
                throw new DomainError('validation', 'workingHours entries need open (bool) and start/end as HH:MM');
            }
            if ($open && $start >= $end) {
                throw new DomainError('validation', 'Opening time must be before closing time');
            }
            $out[$row['weekday']] = ['weekday' => $row['weekday'], 'open' => $open, 'start' => $start, 'end' => $end];
        }
        ksort($out);
        return array_values($out);
    }

    /** @return array<string,mixed>|null */
    public function getJson(string $key): ?array
    {
        $v = $this->db->val('SELECT v FROM settings WHERE k = ?', [$key]);
        if ($v === null) {
            return null;
        }
        $d = json_decode((string) $v, true);
        return is_array($d) ? $d : null;
    }

    /** @param array<string,mixed> $value */
    public function putJson(string $key, array $value): void
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $now = Time::toDb($this->db->now());
        $exists = $this->db->val('SELECT 1 FROM settings WHERE k = ?', [$key]) !== null;
        if ($exists) {
            $this->db->run('UPDATE settings SET v = ?, updated_at = ? WHERE k = ?', [$json, $now, $key]);
            return;
        }
        try {
            $this->db->run('INSERT INTO settings (k, v, updated_at) VALUES (?,?,?)', [$key, $json, $now]);
        } catch (\PDOException) {
            $this->db->run('UPDATE settings SET v = ?, updated_at = ? WHERE k = ?', [$json, $now, $key]);
        }
    }

    public function deleteKey(string $key): void
    {
        $this->db->run('DELETE FROM settings WHERE k = ?', [$key]);
    }
}
