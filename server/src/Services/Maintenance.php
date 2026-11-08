<?php
declare(strict_types=1);

namespace StretchEffect\Services;

use StretchEffect\Db;
use StretchEffect\Google\GoogleCalendar;
use StretchEffect\Support\Log;
use StretchEffect\Support\Time;

/** Cron work: calendar sync + housekeeping. */
final class Maintenance
{
    public function __construct(
        private readonly Db $db,
        private readonly SettingsService $settings,
        private readonly GoogleCalendar $google,
    ) {
    }

    /** @return array<string,mixed> */
    public function run(): array
    {
        $out = ['calendar' => 'not_connected', 'synced' => 0];
        if ($this->google->isConnected()) {
            try {
                $now = $this->db->now();
                $days = (int) $this->settings->get()['bookingWindowDays'];
                $out['synced'] = $this->google->syncBusy($now->modify('-1 day'), $now->modify('+' . ($days + 2) . ' days'));
                $out['calendar'] = 'ok';
            } catch (\Throwable $e) {
                Log::error('Cron calendar sync failed: ' . $e->getMessage());
                $out['calendar'] = 'error';
            }
        }
        $out['cleaned'] = $this->cleanup();
        return $out;
    }

    /** @return array<string,int> rows deleted per table */
    public function cleanup(): array
    {
        $now = $this->db->now();
        $day = Time::toDb($now->modify('-1 day'));
        $week = Time::toDb($now->modify('-7 days'));
        return [
            'oauth_codes' => $this->db->run('DELETE FROM oauth_codes WHERE expires_at < ?', [$day]),
            'oauth_tokens' => $this->db->run('DELETE FROM oauth_tokens WHERE expires_at < ?', [$day]),
            'rate_limits' => $this->db->run('DELETE FROM rate_limits WHERE expires_at < ?', [Time::toDb($now)]),
            'idempotency_keys' => $this->db->run('DELETE FROM idempotency_keys WHERE created_at < ?', [$week]),
            'calendar_busy' => $this->db->run('DELETE FROM calendar_busy WHERE end_at < ?', [$day]),
        ];
    }
}
