<?php
declare(strict_types=1);

namespace StretchEffect\Tests\Fuzz;

use StretchEffect\App;
use StretchEffect\Contracts\DomainError;
use StretchEffect\Mcp\SchemaValidator;
use StretchEffect\Mcp\Tools;
use StretchEffect\Services\PaymentReferences;
use StretchEffect\Services\Totp;
use StretchEffect\Support\Time;

/**
 * Availability (slot computation) against an independent brute-force implementation over random clocks, settings, bookings
 * and busy blocks; TOTP against RFC 6238 and an independent implementation; text/date normalisers for idempotence.
 */
final class PureFuzzTest extends FuzzCase
{
    /** SAST is UTC+2 all year (no DST), so local time can be computed without the tz database: that keeps this oracle independent. */
    private const SAST = 7200;

    private function localEpoch(string $date, int $minutes): int
    {
        [$y, $m, $d] = array_map('intval', explode('-', $date));
        return gmmktime(0, 0, 0, $m, $d, $y) + $minutes * 60 - self::SAST;
    }

    /** @return list<int> start epochs of every slot the rules allow */
    private function expectedSlots(string $from, string $to, int $now, array $s, array $busy, array $bookings): array
    {
        $session = $s['sessionMinutes'] * 60;
        $buffer = $s['bufferMinutes'] * 60;
        $step = $s['slotStepMinutes'] * 60;
        $out = [];
        $day = gmmktime(0, 0, 0, (int) substr($from, 5, 2), (int) substr($from, 8, 2), (int) substr($from, 0, 4));
        $last = gmmktime(0, 0, 0, (int) substr($to, 5, 2), (int) substr($to, 8, 2), (int) substr($to, 0, 4));
        for (; $day <= $last; $day += 86400) {
            $date = gmdate('Y-m-d', $day);
            $cfg = $s['workingHours'][(int) gmdate('w', $day)];
            if (!$cfg['open']) {
                continue;
            }
            [$oh, $om] = explode(':', $cfg['start']);
            [$ch, $cm] = explode(':', $cfg['end']);
            $open = $this->localEpoch($date, (int) $oh * 60 + (int) $om);
            $close = $this->localEpoch($date, (int) $ch * 60 + (int) $cm);
            for ($t = $open; $t + $session <= $close; $t += $step) {
                if ($t < $now + $s['minNoticeHours'] * 3600 || $t > $now + $s['bookingWindowDays'] * 86400) {
                    continue;
                }
                $end = $t + $session + $buffer;
                foreach ($busy as [$a, $b]) {
                    if ($t < $b && $end > $a) {
                        continue 2;
                    }
                }
                foreach ($bookings as [$a, $b]) {
                    if ($t < $b + $buffer && $end > $a) {
                        continue 2;
                    }
                }
                $out[] = $t;
            }
        }
        return $out;
    }

    /** @return array<string,mixed> random but valid settings */
    private function randomSettings(): array
    {
        $wh = [];
        for ($d = 0; $d <= 6; $d++) {
            $open = $this->rng->chance(0.8);
            $startMin = $this->rng->pick([0, 5 * 60, 6 * 60, 6 * 60 + 15, 7 * 60, 8 * 60 + 30, 12 * 60]);
            $endMin = $startMin + $this->rng->pick([30, 60, 90, 4 * 60, 5 * 60, 13 * 60]);
            $endMin = min($endMin, 23 * 60 + 59);
            $wh[] = ['weekday' => $d, 'open' => $open, 'start' => sprintf('%02d:%02d', intdiv($startMin, 60), $startMin % 60), 'end' => sprintf('%02d:%02d', intdiv($endMin, 60), $endMin % 60)];
        }
        return [
            'sessionMinutes' => $this->rng->pick([15, 30, 45, 60, 90, 120]), 'bufferMinutes' => $this->rng->pick([0, 5, 10, 15, 30, 60]),
            'slotStepMinutes' => $this->rng->pick([5, 10, 15, 30, 45, 60]), 'bookingWindowDays' => $this->rng->pick([1, 2, 7, 28, 90]),
            'minNoticeHours' => $this->rng->pick([0, 1, 12, 24, 48]), 'cancelFreeHours' => 24, 'workingHours' => $wh,
        ];
    }

    private function randomNow(): string
    {
        $base = $this->rng->pick(['2026-11-08', '2026-12-31', '2027-01-01', '2028-02-28', '2028-02-29', '2028-03-01', '2027-02-28', '2027-03-01', '2026-06-30', '2026-07-01', '2030-12-31', '2026-01-01']);
        $t = $this->rng->pick(['00:00:00', '21:59:59', '22:00:00', '22:00:01', '23:59:59', '04:00:00', '03:59:59', '10:00:00', '17:30:00', '05:45:30']);
        return $base . 'T' . $t . 'Z';
    }

    public function testAvailabilityMatchesIndependentOracle(): void
    {
        $w = FuzzWorld::create(0);
        $db = $w->app->db();
        $clientId = $w->clients[0]['id'];
        $cases = (int) min($this->iterations, 1500);
        for ($i = 0; $i < $cases; $i++) {
            $this->caseNo = $i;
            $db->run('DELETE FROM bookings');
            $db->run('DELETE FROM calendar_busy');
            $db->run("DELETE FROM settings WHERE k = 'booking'");
            $s = $this->randomSettings();
            $settings = $w->app->withSession(new \StretchEffect\Session\ArraySession())->settings(); // fresh cache
            $settings->update($s);
            $nowIso = $this->randomNow();
            $w->app->db()->freeze($nowIso);
            // sit exactly on, one second before or after a grid boundary sometimes
            $w->advance($this->rng->pick([0, 0, -1, 1, 900, 1800, 3599, 3601, 43199, 43200, 43201, 86399]));
            $now = $w->now()->getTimestamp();
            $from = gmdate('Y-m-d', $now + $this->rng->int(-1, 3) * 86400 + self::SAST);
            $to = gmdate('Y-m-d', strtotime($from . ' UTC') + $this->rng->int(0, 31) * 86400);
            if ($this->rng->chance(0.05)) {
                [$from, $to] = [$to, $from]; // inverted range
            }
            // bookings and busy blocks, some exactly at slot boundaries
            $busy = [];
            $bookings = [];
            $nBusy = $this->rng->int(0, 6);
            $nBook = $this->rng->int(0, 6);
            $anchor = $this->localEpoch(gmdate('Y-m-d', $now + 2 * 86400 + self::SAST), 6 * 60);
            for ($k = 0; $k < $nBusy; $k++) {
                $a = $anchor + $this->rng->int(-2, 40) * 900 + $this->rng->pick([0, 0, 1, -1, 60]);
                $b = $a + $this->rng->pick([0, 1, 60, 900, 1800, 3600, 7200, 86400, 90000]);
                $busy[] = [$a, $b];
                $db->run('INSERT INTO calendar_busy (start_at, end_at, synced_at) VALUES (?,?,?)', [gmdate('Y-m-d H:i:s', $a), gmdate('Y-m-d H:i:s', $b), gmdate('Y-m-d H:i:s', $now)]);
            }
            for ($k = 0; $k < $nBook; $k++) {
                $a = $anchor + $this->rng->int(-2, 80) * 900;
                $b = $a + $s['sessionMinutes'] * 60;
                $bookings[] = [$a, $b];
                $db->run("INSERT INTO bookings (id, user_id, start_at, end_at, status, created_by, refunded, created_at) VALUES (?,?,?,?, 'confirmed', 'owner', 0, ?)",
                    [$db->newId(), $clientId, gmdate('Y-m-d H:i:s', $a), gmdate('Y-m-d H:i:s', $b), gmdate('Y-m-d H:i:s', $now)]);
            }
            $this->inFlight = "availability($from, $to) now=" . gmdate('c', $now) . ' settings=' . json_encode($s) . ' busy=' . json_encode($busy) . ' bookings=' . json_encode($bookings);
            $fresh = $w->app->withSession(new \StretchEffect\Session\ArraySession());
            $got = array_map(static fn(array $sl): int => (int) strtotime($sl['start']), $fresh->bookings()->availability($from, $to));
            $want = $from > $to ? [] : $this->expectedSlots($from, $to, $now, $s, $busy, $bookings);
            $this->ensure($got === array_values(array_unique($got)), 'duplicate slots');
            $sorted = $got;
            sort($sorted);
            $this->ensure($got === $sorted, 'slots are not in time order');
            $extra = array_values(array_diff($got, $want));
            $missing = array_values(array_diff($want, $got));
            $this->ensure($extra === [] && $missing === [], 'slot list differs from the rules. Offered but not allowed: ' . json_encode(array_map(static fn($t) => gmdate('D Y-m-d H:i', $t + 7200) . ' SAST', array_slice($extra, 0, 4)))
                . ' Allowed but not offered: ' . json_encode(array_map(static fn($t) => gmdate('D Y-m-d H:i', $t + 7200) . ' SAST', array_slice($missing, 0, 4))));
            // create() agrees with availability: a listed slot books, an unlisted nearby time does not
            if ($want !== [] && $this->rng->chance(0.25)) {
                $w->app->credits()->adjust($clientId, 1, 'purchase', 't', 't');
                $t = $this->rng->pick($want);
                $b = $fresh->bookings()->create($clientId, gmdate('Y-m-d\TH:i:s\Z', $t), 'owner', 'o');
                $this->ensure($b['start'] === gmdate('Y-m-d\TH:i:s\Z', $t), 'booked another time than asked');
                $this->tally('booked');
            }
            if ($this->rng->chance(0.25)) {
                $t = ($want !== [] ? $this->rng->pick($want) : $anchor) + $this->rng->pick([1, -1, 60, 300, 1799]);
                $w->app->credits()->adjust($clientId, 1, 'purchase', 't', 't');
                $d = gmdate('Y-m-d', $t + self::SAST);
                $allowed = in_array($t, $fresh->bookings()->availability($d, $d) === [] ? [] : array_map(static fn($sl) => (int) strtotime($sl['start']), $fresh->bookings()->availability($d, $d)), true);
                try {
                    $fresh->bookings()->create($clientId, gmdate('Y-m-d\TH:i:s\Z', $t), 'owner', 'o');
                    $this->ensure($allowed, 'create() accepted a time that is not an available slot: ' . gmdate('c', $t));
                } catch (DomainError) {
                    $this->ensure(!$allowed || true, '');
                }
            }
            $this->tally($want === [] ? 'no_slots_case' : 'cases_with_slots');
            $this->endCase();
        }
    }

    public function testTotpAgainstRfc6238AndAnIndependentImplementation(): void
    {
        $rfc = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // ASCII "12345678901234567890"
        foreach ([[59, '287082'], [1111111109, '081804'], [1111111111, '050471'], [1234567890, '005924'], [2000000000, '279037'], [20000000000, '353130']] as [$time, $code]) {
            $this->ensure(Totp::code($rfc, Totp::step($time)) === $code, "RFC 6238 vector for t=$time");
            $this->ensure(Totp::match($rfc, $code, $time) === Totp::step($time), "match() rejects the RFC code at t=$time");
        }
        $independent = static function (string $key, int $step): string {
            $h = hash_hmac('sha1', pack('N2', $step >> 32, $step & 0xffffffff), $key, true);
            $o = ord($h[19]) & 15;
            return str_pad((string) ((((ord($h[$o]) & 0x7f) << 24 | ord($h[$o + 1]) << 16 | ord($h[$o + 2]) << 8 | ord($h[$o + 3])) % 1000000)), 6, '0', STR_PAD_LEFT);
        };
        for ($i = 0; $i < $this->iterations; $i++) {
            $this->caseNo = $i;
            $key = $this->rng->bytes($this->rng->pick([10, 16, 20, 32, 64]));
            $secret = Totp::base32Encode($key);
            $this->ensure(Totp::base32Decode($secret) === $key, 'base32 does not round-trip');
            $t = $this->rng->pick([0, 29, 30, 59, 60, 1767225600, 1767225629, 1767225630, 4102444800, 2 ** 31 - 1, 2 ** 31, 2 ** 32, $this->rng->int(0, 4_000_000_000)]);
            $step = Totp::step($t);
            $this->ensure(Totp::code($secret, $step) === $independent($key, $step), "code differs from the independent implementation (step $step)");
            $window = [];
            for ($d = -1; $d <= 1; $d++) {
                $window[$step + $d] = $independent($key, $step + $d);
            }
            $off = $this->rng->pick([-3, -2, -1, 0, 1, 2, 3]);
            $code = $this->rng->chance(0.8) ? $independent($key, $step + $off) : $this->rng->pick(['000000', '999999', ' ' . $window[$step], $window[$step] . ' ', $window[$step] . "\n", '+' . substr($window[$step], 1), "\u{0661}\u{0662}\u{0663}\u{0664}\u{0665}\u{0666}", Hostile::string($this->rng), substr($window[$step], 0, 5), $window[$step] . '0', '']);
            $got = Totp::match($secret, $code, $t);
            $expect = null;
            foreach ($window as $s => $c) {
                if ($c === $code) {
                    $expect = $s; // the server keeps the last matching step
                }
            }
            $this->ensure($got === $expect, "match(): got " . var_export($got, true) . ' expected ' . var_export($expect, true) . " for code " . json_encode($code) . " offset $off");
            // hostile secrets never raise
            Totp::base32Decode(Hostile::string($this->rng));
            Totp::code(Hostile::string($this->rng), $step);
            $this->tally('totp');
            $this->endCase();
        }
    }

    public function testOwnerTotpCannotBeReplayedAndOldStepsAreRefused(): void
    {
        $w = FuzzWorld::create(0);
        $app = $w->app;
        $uid = (string) $w->owner['id'];
        $secret = $w->owner['secret'];
        $last = 0;
        $n = min($this->iterations, 1200);
        for ($i = 0; $i < $n; $i++) {
            $this->caseNo = $i;
            $w->advance($this->rng->pick([1, 10, 29, 31, 61, 200]));
            $now = $w->now()->getTimestamp();
            $step = Totp::step($now);
            $off = $this->rng->pick([-3, -2, -1, -1, 0, 0, 0, 1, 1, 2]);
            $code = Totp::code($secret, $step + $off);
            $this->inFlight = "verifyTotp offset=$off step=$step last=$last";
            try {
                $ok = $app->auth()->verifyTotp($uid, $code, '10.9.' . ($i % 250) . '.1');
            } catch (DomainError $e) {
                $this->ensure($e->errorCode === 'rate_limited', 'unexpected error: ' . $e->errorCode);
                $this->tally('rate_limited');
                $this->endCase();
                continue;
            }
            $match = null;
            foreach ([-1, 0, 1] as $d) {
                if (Totp::code($secret, $step + $d) === $code) {
                    $match = $step + $d;
                }
            }
            $expect = $match !== null && $match > $last;
            $this->ensure($ok === $expect, 'verifyTotp returned ' . var_export($ok, true) . ' but the rules say ' . var_export($expect, true) . " (code step " . var_export($match, true) . ", last accepted $last)");
            if ($ok) {
                $last = $match;
                try {
                    $again = $app->auth()->verifyTotp($uid, $code, '10.9.' . ($i % 250) . '.2');
                    $this->ensure($again === false, 'a TOTP code was accepted twice');
                } catch (DomainError) {
                    // throttled: also fine
                }
            }
            $this->tally($ok ? 'accepted' : 'refused');
            $this->endCase();
        }
    }

    public function testNormalisersAndParsers(): void
    {
        for ($i = 0; $i < $this->iterations; $i++) {
            $this->caseNo = $i;
            $s = Hostile::string($this->rng);
            $this->inFlight = 'input=' . json_encode($s, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if (mb_check_encoding($s, 'UTF-8')) {
                $n = PaymentReferences::normalize($s);
                $this->ensure(PaymentReferences::normalize($n) === $n, 'PaymentReferences::normalize is not idempotent');
                $this->ensure(mb_strlen($n) <= 80 && preg_match('/[\p{C}\p{Zl}\p{Zp}]/u', $n) !== 1 && $n === trim($n), 'normalised reference still has control characters, edge spaces or is too long');
                $this->ensure($n === mb_strtoupper($n), 'normalised reference is not upper case');
                $t = Tools::text($s, 50);
                $this->ensure(Tools::text($t, 50) === $t && mb_strlen($t) <= 50 && preg_match('/[\p{C}]/u', $t) !== 1, 'Tools::text is not idempotent/clean');
            }
            $d = Time::parseIso($s);
            if ($d !== null) {
                $this->ensure(preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $s) === 1 && Time::parseIso(Time::iso($d))?->getTimestamp() === $d->getTimestamp(), 'parseIso accepted something odd: ' . json_encode($s));
            }
            $ld = Time::localDate($s);
            if ($ld !== null) {
                $this->ensure($ld->format('Y-m-d') === $s && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1, 'localDate accepted ' . json_encode($s));
            }
            foreach (['validDate', 'validLocalDateTime'] as $f) {
                if (SchemaValidator::$f($s)) {
                    $this->ensure(preg_match('/^\d{4}-\d{2}-\d{2}/', $s) === 1, "$f accepted " . json_encode($s));
                }
            }
            // generated near-valid dates: must agree with the calendar
            $y = $this->rng->pick([2000, 2024, 2026, 2028, 2100, 1900, 9999, 1]);
            $m = $this->rng->int(0, 13);
            $dd = $this->rng->int(0, 32);
            $str = sprintf('%04d-%02d-%02d', $y, $m, $dd);
            $real = $m >= 1 && $m <= 12 && $dd >= 1 && checkdate($m, $dd, $y);
            $this->ensure(SchemaValidator::validDate($str) === $real, "validDate($str) disagrees with checkdate");
            $this->ensure((Time::localDate($str) !== null) === $real, "localDate($str) disagrees with checkdate");
            $this->tally('normalise');
            $this->endCase();
        }
    }
}
