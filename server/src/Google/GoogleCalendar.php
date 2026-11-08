<?php
declare(strict_types=1);

namespace StretchEffect\Google;

use DateTimeImmutable;
use DateTimeInterface;
use StretchEffect\Config;
use StretchEffect\Contracts\DomainError;
use StretchEffect\Crypto;
use StretchEffect\Db;
use StretchEffect\Services\SettingsService;
use StretchEffect\Support\Log;
use StretchEffect\Support\Time;

/**
 * Google Calendar over plain cURL (OAuth web flow for the owner).
 * State lives in settings key 'google': refresh_token_enc, access_token_enc, access_expires, last_sync_at, ...
 */
final class GoogleCalendar
{
    public const SCOPES = [
        'https://www.googleapis.com/auth/calendar.events.owned',
        'https://www.googleapis.com/auth/calendar.freebusy',
    ];
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
    private const API = 'https://www.googleapis.com/calendar/v3';

    public function __construct(
        private readonly Db $db,
        private readonly Config $config,
        private readonly Crypto $crypto,
        private readonly SettingsService $settings,
        private readonly HttpTransport $http,
    ) {
    }

    public function configured(): bool
    {
        return (string) $this->config->get('google.client_id', '') !== '' && (string) $this->config->get('google.client_secret', '') !== '';
    }

    public function calendarId(): string
    {
        return (string) ($this->config->get('google.calendar_id') ?: 'primary');
    }

    public function isConnected(): bool
    {
        $s = $this->settings->getJson('google');
        return $s !== null && !empty($s['refresh_token_enc']);
    }

    /** @return array<string,mixed> CalendarConnection shape */
    public function connection(): array
    {
        $s = $this->settings->getJson('google') ?? [];
        $c = ['connected' => !empty($s['refresh_token_enc'])];
        if ($c['connected']) {
            $cal = $this->calendarId();
            if (str_contains($cal, '@')) {
                $c['accountEmail'] = $cal;
            }
            $c['calendarId'] = $cal;
            if (!empty($s['last_sync_at'])) {
                $c['lastSyncAt'] = Time::iso((string) $s['last_sync_at']);
            }
        }
        $c['mode'] = 'live';
        return $c;
    }

    public function redirectUri(): string
    {
        return (string) ($this->config->get('google.redirect_uri') ?: $this->config->baseUrl() . '/api/admin/calendar/callback');
    }

    public function authorizationUrl(string $state): string
    {
        if (!$this->configured()) {
            throw new DomainError('validation', 'Google is not configured on this server (google.client_id / client_secret).');
        }
        return self::AUTH_URL . '?' . http_build_query([
            'client_id' => $this->config->get('google.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'false',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function handleCallback(string $code): void
    {
        $res = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
        ]);
        if (empty($res['refresh_token'])) {
            throw new DomainError('validation', 'Google did not return a refresh token. Remove the app from your Google account permissions and connect again.');
        }
        $state = [
            'refresh_token_enc' => $this->crypto->encrypt((string) $res['refresh_token']),
            'connected_at' => Time::toDb($this->db->now()),
            'scope' => (string) ($res['scope'] ?? ''),
        ];
        $this->cacheAccessToken($state, $res);
        $this->settings->putJson('google', $state);
    }

    public function disconnect(): void
    {
        $s = $this->settings->getJson('google');
        if ($s !== null && !empty($s['refresh_token_enc'])) {
            try {
                $this->http->request('POST', self::REVOKE_URL, ['Content-Type' => 'application/x-www-form-urlencoded'],
                    http_build_query(['token' => $this->crypto->decrypt((string) $s['refresh_token_enc'])]));
            } catch (\Throwable $e) {
                Log::error('Google revoke failed: ' . $e->getMessage());
            }
        }
        $this->settings->deleteKey('google');
        $this->db->run('DELETE FROM calendar_busy');
    }

    // ---------- API calls ----------

    /** @return list<array{start:string,end:string}> busy ranges, UTC ISO */
    public function freeBusy(DateTimeInterface $from, DateTimeInterface $to): array
    {
        $out = [];
        $cur = DateTimeImmutable::createFromInterface($from);
        $end = DateTimeImmutable::createFromInterface($to);
        while ($cur < $end) {
            $chunkEnd = min($cur->modify('+31 days'), $end);
            try {
                $res = $this->api('POST', '/freeBusy', [
                    'timeMin' => Time::iso($cur), 'timeMax' => Time::iso($chunkEnd), 'timeZone' => 'UTC',
                    'items' => [['id' => $this->calendarId()]],
                ]);
            } catch (GoogleApiException $e) {
                throw new DomainError('server', $e->getMessage());
            }
            $cals = $res['calendars'] ?? [];
            $cal = reset($cals);
            if (is_array($cal) && !empty($cal['errors'])) {
                throw new DomainError('server', 'Google Calendar could not be read.');
            }
            foreach (($cal['busy'] ?? []) as $b) {
                $s = Time::parseIso((string) ($b['start'] ?? ''));
                $e = Time::parseIso((string) ($b['end'] ?? ''));
                if ($s !== null && $e !== null && $e > $s) {
                    $out[] = ['start' => Time::iso($s), 'end' => Time::iso($e)];
                }
            }
            $cur = $chunkEnd;
        }
        return $out;
    }

    /** Deterministic Google event id for a booking (base32hex characters only, 5-1024 long). */
    public static function eventIdFor(string $bookingId): string
    {
        return 'se' . strtolower(preg_replace('/[^0-9a-f]/i', '', $bookingId));
    }

    /**
     * @param array<string,mixed> $booking row: id, user_id, start_at, end_at
     * @return string the Google event id
     */
    public function insertEvent(array $booking, string $clientName): string
    {
        $id = self::eventIdFor((string) $booking['id']);
        $body = $this->eventBody($booking, $clientName) + ['id' => $id];
        try {
            $this->api('POST', '/calendars/' . rawurlencode($this->calendarId()) . '/events', $body);
        } catch (GoogleApiException $e) {
            if ($e->status !== 409) {
                throw new DomainError('server', $e->getMessage());
            }
            // duplicate id: a previous attempt already created it
        }
        return $id;
    }

    /** @param array<string,mixed> $booking */
    public function patchEvent(string $eventId, array $booking, string $clientName): void
    {
        $body = $this->eventBody($booking, $clientName);
        try {
            $this->api('PATCH', '/calendars/' . rawurlencode($this->calendarId()) . '/events/' . rawurlencode($eventId), $body);
        } catch (GoogleApiException $e) {
            throw new DomainError('server', $e->getMessage());
        }
    }

    public function deleteEvent(string $eventId): void
    {
        try {
            $this->api('DELETE', '/calendars/' . rawurlencode($this->calendarId()) . '/events/' . rawurlencode($eventId));
        } catch (GoogleApiException $e) {
            if ($e->status !== 404 && $e->status !== 410) {
                throw new DomainError('server', $e->getMessage());
            }
        }
    }

    /** Refresh the calendar_busy cache for [from, to). @return int blocks stored */
    public function syncBusy(DateTimeInterface $from, DateTimeInterface $to): int
    {
        $busy = $this->freeBusy($from, $to);
        $now = Time::toDb($this->db->now());
        $this->db->transaction(function () use ($busy, $from, $to, $now): void {
            $this->db->run('DELETE FROM calendar_busy WHERE end_at > ? AND start_at < ?', [Time::toDb($from), Time::toDb($to)]);
            foreach ($busy as $b) {
                $this->db->run('INSERT INTO calendar_busy (start_at, end_at, synced_at) VALUES (?,?,?)', [
                    Time::toDb(Time::parseIso($b['start'])), Time::toDb(Time::parseIso($b['end'])), $now,
                ]);
            }
        });
        $s = $this->settings->getJson('google') ?? [];
        $s['last_sync_at'] = $now;
        $this->settings->putJson('google', $s);
        return count($busy);
    }

    /** Lazy refresh used by read endpoints when cron has not run recently. Never throws. */
    public function syncIfStale(int $maxAgeSeconds, int $windowDays): void
    {
        if (!$this->isConnected()) {
            return;
        }
        $s = $this->settings->getJson('google') ?? [];
        $last = isset($s['last_sync_at']) ? Time::fromDb((string) $s['last_sync_at'])->getTimestamp() : 0;
        if ($this->db->now()->getTimestamp() - $last < $maxAgeSeconds) {
            return;
        }
        try {
            $now = $this->db->now();
            $this->syncBusy($now->modify('-1 day'), $now->modify('+' . ($windowDays + 2) . ' days'));
        } catch (\Throwable $e) {
            Log::error('Lazy calendar sync failed: ' . $e->getMessage());
            $s['last_sync_at'] = Time::toDb($this->db->now()); // back off for $maxAgeSeconds
            $this->settings->putJson('google', $s);
        }
    }

    // ---------- internals ----------

    /** @param array<string,mixed> $booking @return array<string,mixed> */
    private function eventBody(array $booking, string $clientName): array
    {
        $tz = Time::TZ;
        $fmt = static fn (string $db): array => ['dateTime' => Time::fromDb($db)->setTimezone(Time::tz())->format('Y-m-d\TH:i:sP'), 'timeZone' => $tz];
        return [
            'summary' => 'Stretch: ' . $clientName,
            'description' => 'Booked through the Stretch Effect booking system.',
            'start' => $fmt((string) $booking['start_at']),
            'end' => $fmt((string) $booking['end_at']),
            'transparency' => 'opaque',
            'reminders' => ['useDefault' => false],
            'extendedProperties' => ['private' => ['bookingId' => (string) $booking['id'], 'clientId' => (string) $booking['user_id']]],
        ];
    }

    /** @param array<string,mixed> $res @param array<string,mixed> $state */
    private function cacheAccessToken(array &$state, array $res): void
    {
        if (!empty($res['access_token'])) {
            $state['access_token_enc'] = $this->crypto->encrypt((string) $res['access_token']);
            $state['access_expires'] = $this->db->now()->getTimestamp() + max(60, (int) ($res['expires_in'] ?? 3600)) - 60;
        }
    }

    /** @param array<string,string> $form @return array<string,mixed> */
    private function tokenRequest(array $form): array
    {
        $form += ['client_id' => (string) $this->config->get('google.client_id'), 'client_secret' => (string) $this->config->get('google.client_secret')];
        try {
            $r = $this->http->request('POST', self::TOKEN_URL, ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query($form));
        } catch (\RuntimeException $e) {
            Log::error('Google token request: ' . $e->getMessage());
            throw new DomainError('server', 'Could not reach Google. Please try again.');
        }
        $json = json_decode($r['body'], true);
        if ($r['status'] !== 200 || !is_array($json)) {
            $err = is_array($json) ? (string) ($json['error'] ?? '') : '';
            Log::error('Google token error ' . $r['status'] . ' ' . $err);
            if ($err === 'invalid_grant') {
                throw new DomainError('server', 'The Google connection has expired. Reconnect Google Calendar in the admin area.');
            }
            throw new DomainError('server', 'Google rejected the request (' . ($err ?: 'HTTP ' . $r['status']) . ').');
        }
        return $json;
    }

    private function accessToken(): string
    {
        $s = $this->settings->getJson('google');
        if ($s === null || empty($s['refresh_token_enc'])) {
            throw new DomainError('server', 'Google Calendar is not connected.');
        }
        if (!empty($s['access_token_enc']) && (int) ($s['access_expires'] ?? 0) > $this->db->now()->getTimestamp()) {
            return $this->crypto->decrypt((string) $s['access_token_enc']);
        }
        $res = $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $this->crypto->decrypt((string) $s['refresh_token_enc'])]);
        $this->cacheAccessToken($s, $res);
        $this->settings->putJson('google', $s);
        return (string) ($res['access_token'] ?? '');
    }

    /** @param array<string,mixed>|null $body @return array<string,mixed> */
    private function api(string $method, string $path, ?array $body = null): array
    {
        $headers = ['Authorization' => 'Bearer ' . $this->accessToken(), 'Accept' => 'application/json'];
        $payload = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        try {
            $r = $this->http->request($method, self::API . $path, $headers, $payload);
        } catch (\RuntimeException $e) {
            Log::error('Google API ' . $method . ' ' . $path . ': ' . $e->getMessage());
            throw new DomainError('server', 'Could not reach Google Calendar. Please try again.');
        }
        if ($r['status'] === 401) {
            // access token revoked/expired early: drop the cache and retry once
            $s = $this->settings->getJson('google') ?? [];
            unset($s['access_token_enc'], $s['access_expires']);
            $this->settings->putJson('google', $s);
            $headers['Authorization'] = 'Bearer ' . $this->accessToken();
            try {
                $r = $this->http->request($method, self::API . $path, $headers, $payload);
            } catch (\RuntimeException $e) {
                throw new DomainError('server', 'Could not reach Google Calendar. Please try again.');
            }
        }
        if ($r['status'] >= 200 && $r['status'] < 300) {
            $json = json_decode($r['body'], true);
            return is_array($json) ? $json : [];
        }
        Log::error('Google API ' . $method . ' ' . $path . ' -> ' . $r['status'] . ' ' . substr($r['body'], 0, 300));
        // Code carries the HTTP status so callers can tolerate 404/409/410.
        throw new GoogleApiException($r['status'], 'Google Calendar request failed (HTTP ' . $r['status'] . ').');
    }
}
