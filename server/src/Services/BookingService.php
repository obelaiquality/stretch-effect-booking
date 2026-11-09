<?php
declare(strict_types=1);

namespace StretchEffect\Services;

use DateTimeImmutable;
use StretchEffect\Contracts\BookingService as BookingServiceContract;
use StretchEffect\Contracts\DomainError;
use StretchEffect\Db;
use StretchEffect\Google\GoogleCalendar;
use StretchEffect\Mail\Notifier;
use StretchEffect\Support\Log;
use StretchEffect\Support\Presenter;
use StretchEffect\Support\Time;

final class BookingService implements BookingServiceContract
{
    private const BOOKING_SELECT = 'SELECT b.*, u.name AS client_name FROM bookings b JOIN users u ON u.id = b.user_id';

    public function __construct(
        private readonly Db $db,
        private readonly SettingsService $settings,
        private readonly CreditService $credits,
        private readonly GoogleCalendar $google,
        private readonly Notifier $notifier,
        private readonly AuditLog $audit,
    ) {
    }

    // ---------- availability ----------

    public function availability(string $fromDate, string $toDate): array
    {
        return $this->computeSlots($fromDate, $toDate);
    }

    /**
     * Free slots = working hours - Google busy - confirmed bookings (buffer-padded) - min notice - window.
     * A candidate occupies [start, start + session + buffer); an existing booking occupies [start, end + buffer);
     * a Google busy block occupies [start, end). So gaps of at least the buffer sit on both sides of a booking.
     *
     * @param array{excludeBookingId?:string, skipNotice?:bool, busy?:list<array{0:int,1:int}>} $opts
     * @return list<array{start:string,end:string}>
     */
    private function computeSlots(string $fromDate, string $toDate, array $opts = []): array
    {
        $from = Time::localDate($fromDate);
        $to = Time::localDate($toDate);
        if ($from === null || $to === null) {
            throw new DomainError('validation', 'Dates must be YYYY-MM-DD');
        }
        if ($to < $from) {
            return [];
        }
        $s = $this->settings->get();
        $session = (int) $s['sessionMinutes'] * 60;
        $buffer = (int) $s['bufferMinutes'] * 60;
        $step = max(1, (int) $s['slotStepMinutes']) * 60;
        $now = $this->db->now()->getTimestamp();
        $earliest = !empty($opts['skipNotice']) ? $now : $now + (int) $s['minNoticeHours'] * 3600;
        $latest = $now + (int) $s['bookingWindowDays'] * 86400;
        $rangeStart = $from->getTimestamp();
        $rangeEnd = $to->modify('+1 day')->getTimestamp();

        // occupied intervals [a, b)
        $occupied = [];
        if (isset($opts['busy'])) {
            $occupied = $opts['busy'];
        } else {
            $rows = $this->db->all('SELECT start_at, end_at FROM calendar_busy WHERE start_at < ? AND end_at > ?',
                [Time::toDb(new DateTimeImmutable('@' . ($rangeEnd + $session)), ), Time::toDb(new DateTimeImmutable('@' . ($rangeStart - $session)))]);
            foreach ($rows as $r) {
                $occupied[] = [Time::fromDb((string) $r['start_at'])->getTimestamp(), Time::fromDb((string) $r['end_at'])->getTimestamp()];
            }
        }
        // completed and no_show still occupy their time: the owner may mark a future session before it happens, and the slot must not reopen
        $sql = "SELECT id, start_at, end_at FROM bookings WHERE status IN ('confirmed','completed','no_show') AND start_at < ? AND end_at > ?";
        $params = [Time::toDb(new DateTimeImmutable('@' . ($rangeEnd + $session + $buffer))), Time::toDb(new DateTimeImmutable('@' . ($rangeStart - $session - $buffer)))];
        foreach ($this->db->all($sql, $params) as $r) {
            if (($opts['excludeBookingId'] ?? null) === $r['id']) {
                continue;
            }
            $occupied[] = [Time::fromDb((string) $r['start_at'])->getTimestamp(), Time::fromDb((string) $r['end_at'])->getTimestamp() + $buffer];
        }

        $hours = [];
        foreach ($s['workingHours'] as $wh) {
            $hours[(int) $wh['weekday']] = $wh;
        }

        $slots = [];
        for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
            $wh = $hours[(int) $d->format('w')] ?? null;
            if ($wh === null || !$wh['open']) {
                continue;
            }
            [$oh, $om] = array_map('intval', explode(':', (string) $wh['start']));
            [$ch, $cm] = array_map('intval', explode(':', (string) $wh['end']));
            $open = $d->setTime($oh, $om)->getTimestamp();
            $close = $d->setTime($ch, $cm)->getTimestamp();
            for ($t = $open; $t + $session <= $close; $t += $step) {
                if ($t < $earliest || $t > $latest) {
                    continue;
                }
                $candEnd = $t + $session + $buffer;
                foreach ($occupied as [$a, $b]) {
                    if ($t < $b && $candEnd > $a) {
                        continue 2;
                    }
                }
                $slots[] = ['start' => gmdate(Time::ISO, $t), 'end' => gmdate(Time::ISO, $t + $session)];
            }
        }
        return $slots;
    }

    public function localToUtc(string $local): string
    {
        if (str_contains($local, "\0")) { // createFromFormat() throws a ValueError on a NUL byte
            throw new DomainError('validation', 'Time must look like 2026-11-09T09:30');
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $local, Time::tz())
            ?: DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $local, Time::tz());
        // createFromFormat() rolls impossible dates over (2026-13-10 becomes 2027-01-10): only accept what round-trips
        if ($d === false || $d->format(strlen($local) === 16 ? 'Y-m-d\TH:i' : 'Y-m-d\TH:i:s') !== $local) {
            throw new DomainError('validation', 'Time must look like 2026-11-09T09:30');
        }
        return Time::iso($d);
    }

    // ---------- reading ----------

    public function list(string $fromDate, string $toDate, ?string $status = null): array
    {
        $from = Time::localDate($fromDate);
        $to = Time::localDate($toDate);
        if ($from === null || $to === null) {
            throw new DomainError('validation', 'Dates must be YYYY-MM-DD');
        }
        $sql = self::BOOKING_SELECT . ' WHERE b.start_at >= ? AND b.start_at < ?';
        $params = [Time::toDb($from), Time::toDb($to->modify('+1 day'))];
        if ($status !== null) {
            $sql .= ' AND b.status = ?';
            $params[] = $status;
        }
        $rows = $this->db->all($sql . ' ORDER BY b.start_at LIMIT 1000', $params);
        return array_map([Presenter::class, 'booking'], $rows);
    }

    public function get(string $bookingId): ?array
    {
        $row = $this->db->one(self::BOOKING_SELECT . ' WHERE b.id = ?', [$bookingId]);
        return $row === null ? null : Presenter::booking($row);
    }

    /** @return list<array<string,mixed>> */
    public function forUser(string $userId): array
    {
        $rows = $this->db->all(self::BOOKING_SELECT . ' WHERE b.user_id = ? ORDER BY b.start_at DESC LIMIT 500', [$userId]);
        return array_map([Presenter::class, 'booking'], $rows);
    }

    /** @return list<array{start:string,end:string,source:string,label?:string}> BusyBlock shapes (owner view) */
    public function busyBlocks(string $fromDate, string $toDate): array
    {
        $from = Time::localDate($fromDate);
        $to = Time::localDate($toDate);
        if ($from === null || $to === null) {
            throw new DomainError('validation', 'Dates must be YYYY-MM-DD');
        }
        $a = Time::toDb($from);
        $b = Time::toDb($to->modify('+1 day'));
        $bookings = $this->db->all(
            "SELECT b.start_at, b.end_at, u.name FROM bookings b JOIN users u ON u.id = b.user_id WHERE b.status = 'confirmed' AND b.start_at < ? AND b.end_at > ? ORDER BY b.start_at",
            [$b, $a]
        );
        $own = [];
        $out = [];
        foreach ($bookings as $r) {
            $own[$r['start_at'] . '|' . $r['end_at']] = true;
            $out[] = ['start' => Time::iso((string) $r['start_at']), 'end' => Time::iso((string) $r['end_at']), 'source' => 'booking', 'label' => (string) $r['name']];
        }
        foreach ($this->db->all('SELECT start_at, end_at FROM calendar_busy WHERE start_at < ? AND end_at > ? ORDER BY start_at', [$b, $a]) as $r) {
            if (isset($own[$r['start_at'] . '|' . $r['end_at']])) {
                continue; // our own booking events echo back from Google
            }
            $out[] = ['start' => Time::iso((string) $r['start_at']), 'end' => Time::iso((string) $r['end_at']), 'source' => 'google', 'label' => 'Busy (Google Calendar)'];
        }
        usort($out, static fn (array $x, array $y): int => strcmp($x['start'], $y['start']));
        return $out;
    }

    // ---------- writing ----------

    private function lock(): void
    {
        if ($this->db->driver() === 'mysql') {
            // One global lock row serialises booking writes (single practitioner).
            $this->db->run("INSERT IGNORE INTO settings (k, v, updated_at) VALUES ('lock', '{}', ?)", [Time::toDb($this->db->now())]);
            $this->db->val("SELECT k FROM settings WHERE k = 'lock' FOR UPDATE");
        }
        // SQLite: Db::transaction() already used BEGIN IMMEDIATE.
    }

    /** @return array{0:string,1:string} [startUtcIso, localDate] */
    private function normaliseStart(string $startUtc): array
    {
        $d = Time::parseIso($startUtc);
        if ($d === null) {
            throw new DomainError('validation', 'start must be an ISO 8601 time such as 2026-11-09T07:00:00Z');
        }
        return [Time::iso($d), $d->setTimezone(Time::tz())->format('Y-m-d')];
    }

    /**
     * Live Google busy for the day around a slot, or null when Google is not connected.
     * @return list<array{0:int,1:int}>|null
     */
    private function liveBusy(string $localDate, ?array $ignoreBooking = null): ?array
    {
        if (!$this->google->isConnected()) {
            return null;
        }
        $day = Time::localDate($localDate);
        $busy = $this->google->freeBusy($day->modify('-1 day'), $day->modify('+2 days'));
        $out = [];
        foreach ($busy as $b) {
            $a = Time::parseIso($b['start'])->getTimestamp();
            $e = Time::parseIso($b['end'])->getTimestamp();
            if ($ignoreBooking !== null && $a === $ignoreBooking[0] && $e === $ignoreBooking[1]) {
                continue; // the booking's own Google event
            }
            $out[] = [$a, $e];
        }
        return $out;
    }

    public function create(string $userId, string $startUtc, string $createdBy, string $actor): array
    {
        [$startIso, $localDate] = $this->normaliseStart($startUtc);
        if (!in_array($createdBy, ['client', 'owner', 'chatgpt'], true)) {
            throw new DomainError('validation', 'Invalid createdBy');
        }
        $session = (int) $this->settings->get()['sessionMinutes'];

        $booking = $this->db->transaction(function () use ($userId, $startIso, $localDate, $createdBy, $actor, $session): array {
            $this->lock();
            $user = $this->db->one("SELECT id, name, email, role FROM users WHERE id = ?" . $this->db->forUpdate(), [$userId]);
            if ($user === null || $user['role'] !== 'client') {
                throw new DomainError('not_found', 'Client not found');
            }
            try {
                $busy = $this->liveBusy($localDate);
            } catch (DomainError) {
                throw new DomainError('server', 'The calendar could not be checked right now. Please try again in a moment.');
            }
            $slots = $this->computeSlots($localDate, $localDate, ['skipNotice' => $createdBy === 'owner'] + ($busy !== null ? ['busy' => $busy] : []));
            if (!in_array($startIso, array_column($slots, 'start'), true)) {
                throw new DomainError('conflict', 'That time is no longer available. Please pick another slot.');
            }
            if ($this->credits->balance($userId) < 1) {
                throw new DomainError('no_credits', 'No session credits left. Please buy a pack first.');
            }
            $id = $this->db->newId();
            $start = Time::parseIso($startIso);
            $end = $start->modify("+$session minutes");
            $this->db->run(
                "INSERT INTO bookings (id, user_id, start_at, end_at, status, created_by, refunded, created_at) VALUES (?,?,?,?, 'confirmed', ?, 0, ?)",
                [$id, $userId, Time::toDb($start), Time::toDb($end), $createdBy, Time::toDb($this->db->now())]
            );
            $this->credits->adjust($userId, -1, 'booking', 'Session booked for ' . $start->setTimezone(Time::tz())->format('D j M H:i'), $actor, $id);
            $row = $this->db->one('SELECT * FROM bookings WHERE id = ?', [$id]);
            if ($this->google->isConnected()) {
                try {
                    $eventId = $this->google->insertEvent($row, (string) $user['name']);
                } catch (DomainError) {
                    throw new DomainError('server', 'The booking could not be added to the calendar. Please try again.');
                }
                $this->db->run('UPDATE bookings SET google_event_id = ? WHERE id = ?', [$eventId, $id]);
            }
            return $this->db->one(self::BOOKING_SELECT . ' WHERE b.id = ?', [$id]) ?? [];
        });

        $user = $this->db->one('SELECT name, email FROM users WHERE id = ?', [$userId]) ?? [];
        $this->notifier->confirmation($booking, $user);
        $this->audit->write(null, $actor, 'booking.create', ['bookingId' => $booking['id'], 'start' => $startIso, 'createdBy' => $createdBy], 'ok', 'Booking created', null);
        return Presenter::booking($booking);
    }

    public function cancel(string $bookingId, ?bool $refund, string $reason, string $actor): array
    {
        $reason = mb_substr(trim($reason), 0, 255);
        [$row, $refunded] = $this->db->transaction(function () use ($bookingId, $refund, $reason, $actor): array {
            $this->lock();
            $row = $this->db->one(self::BOOKING_SELECT . ' WHERE b.id = ?' . $this->db->forUpdate(), [$bookingId]);
            if ($row === null) {
                throw new DomainError('not_found', 'Booking not found');
            }
            if ($row['status'] !== 'confirmed') {
                throw new DomainError('conflict', 'Only confirmed bookings can be cancelled');
            }
            $free = (int) $this->settings->get()['cancelFreeHours'];
            $secondsToStart = Time::fromDb((string) $row['start_at'])->getTimestamp() - $this->db->now()->getTimestamp();
            $outsideWindow = $secondsToStart > $free * 3600;
            $doRefund = $outsideWindow ? $refund !== false : $refund === true;
            $now = Time::toDb($this->db->now());
            $this->db->run("UPDATE bookings SET status = 'cancelled', cancelled_at = ?, cancel_reason = ?, refunded = ? WHERE id = ?",
                [$now, $reason === '' ? null : $reason, $doRefund ? 1 : 0, $bookingId]);
            if ($doRefund) {
                $this->credits->adjust((string) $row['user_id'], 1, 'cancel_refund', 'Refund for cancelled session', $actor, $bookingId);
            }
            $this->db->run('DELETE FROM calendar_busy WHERE start_at = ? AND end_at = ?', [$row['start_at'], $row['end_at']]);
            return [$this->db->one(self::BOOKING_SELECT . ' WHERE b.id = ?', [$bookingId]) ?? [], $doRefund];
        });

        if (!empty($row['google_event_id']) && $this->google->isConnected()) {
            try {
                $this->google->deleteEvent((string) $row['google_event_id']);
            } catch (\Throwable $e) {
                Log::error('Could not delete Google event for booking ' . $bookingId . ': ' . $e->getMessage());
            }
        }
        $user = $this->db->one('SELECT name, email FROM users WHERE id = ?', [$row['user_id']]) ?? [];
        $this->notifier->cancellation($row, $user, $refunded);
        $this->audit->write(null, $actor, 'booking.cancel', ['bookingId' => $bookingId, 'refunded' => $refunded], 'ok', 'Booking cancelled', null);
        return ['booking' => Presenter::booking($row), 'refunded' => $refunded];
    }

    public function reschedule(string $bookingId, string $newStartUtc, string $actor): array
    {
        [$startIso, $localDate] = $this->normaliseStart($newStartUtc);
        $session = (int) $this->settings->get()['sessionMinutes'];
        $row = $this->db->transaction(function () use ($bookingId, $startIso, $localDate, $session): array {
            $this->lock();
            $cur = $this->db->one(self::BOOKING_SELECT . ' WHERE b.id = ?' . $this->db->forUpdate(), [$bookingId]);
            if ($cur === null) {
                throw new DomainError('not_found', 'Booking not found');
            }
            if ($cur['status'] !== 'confirmed') {
                throw new DomainError('conflict', 'Only confirmed bookings can be moved');
            }
            $own = [Time::fromDb((string) $cur['start_at'])->getTimestamp(), Time::fromDb((string) $cur['end_at'])->getTimestamp()];
            try {
                $busy = $this->liveBusy($localDate, $own);
            } catch (DomainError) {
                throw new DomainError('server', 'The calendar could not be checked right now. Please try again in a moment.');
            }
            $opts = ['skipNotice' => true, 'excludeBookingId' => $bookingId];
            if ($busy === null) {
                // cached busy blocks may include this booking's own Google event
                $cached = [];
                foreach ($this->db->all('SELECT start_at, end_at FROM calendar_busy') as $b) {
                    $pair = [Time::fromDb((string) $b['start_at'])->getTimestamp(), Time::fromDb((string) $b['end_at'])->getTimestamp()];
                    if ($pair !== $own) {
                        $cached[] = $pair;
                    }
                }
                $opts['busy'] = $cached;
            } else {
                $opts['busy'] = $busy;
            }
            $slots = $this->computeSlots($localDate, $localDate, $opts);
            if (!in_array($startIso, array_column($slots, 'start'), true)) {
                throw new DomainError('conflict', 'That time is not available.');
            }
            $start = Time::parseIso($startIso);
            $this->db->run('UPDATE bookings SET start_at = ?, end_at = ? WHERE id = ?',
                [Time::toDb($start), Time::toDb($start->modify("+$session minutes")), $bookingId]);
            $this->db->run('DELETE FROM calendar_busy WHERE start_at = ? AND end_at = ?', [$cur['start_at'], $cur['end_at']]);
            $new = $this->db->one(self::BOOKING_SELECT . ' WHERE b.id = ?', [$bookingId]) ?? [];
            if (!empty($new['google_event_id']) && $this->google->isConnected()) {
                try {
                    $this->google->patchEvent((string) $new['google_event_id'], $new, (string) $new['client_name']);
                } catch (DomainError) {
                    throw new DomainError('server', 'The calendar event could not be updated. Please try again.');
                }
            }
            return $new;
        });
        $user = $this->db->one('SELECT name, email FROM users WHERE id = ?', [$row['user_id']]) ?? [];
        $this->notifier->confirmation($row, $user, 'Your session has been moved');
        $this->audit->write(null, $actor, 'booking.reschedule', ['bookingId' => $bookingId, 'start' => $startIso], 'ok', 'Booking rescheduled', null);
        return Presenter::booking($row);
    }

    /** Owner marks a booking completed or no_show. */
    public function setStatus(string $bookingId, string $status, string $actor): array
    {
        if (!in_array($status, ['completed', 'no_show'], true)) {
            throw new DomainError('validation', 'status must be completed or no_show');
        }
        $row = $this->db->one(self::BOOKING_SELECT . ' WHERE b.id = ?', [$bookingId]);
        if ($row === null) {
            throw new DomainError('not_found', 'Booking not found');
        }
        if ($row['status'] === 'cancelled') {
            throw new DomainError('conflict', 'A cancelled booking cannot be marked ' . $status);
        }
        $this->db->run('UPDATE bookings SET status = ? WHERE id = ?', [$status, $bookingId]);
        $this->audit->write(null, $actor, 'booking.status', ['bookingId' => $bookingId, 'status' => $status], 'ok', 'Booking marked ' . $status, null);
        $row['status'] = $status;
        return Presenter::booking($row);
    }

    // ---------- summary ----------

    public function summary(string $period): array
    {
        if (!in_array($period, ['week', 'month'], true)) {
            throw new DomainError('validation', 'period must be week or month');
        }
        $today = $this->db->now()->setTimezone(Time::tz())->setTime(0, 0);
        if ($period === 'week') {
            $from = $today->modify('-' . ((int) $today->format('N') - 1) . ' days'); // Monday
            $toExclusive = $from->modify('+7 days');
        } else {
            $from = $today->modify('first day of this month');
            $toExclusive = $from->modify('+1 month');
        }
        $a = Time::toDb($from);
        $b = Time::toDb($toExclusive);
        $count = fn (string $statusSql): int => (int) $this->db->val("SELECT COUNT(*) FROM bookings WHERE start_at >= ? AND start_at < ? AND $statusSql", [$a, $b]);
        $lastDay = $toExclusive->modify('-1 day')->format('Y-m-d');

        // free hours = union of free session windows in the future part of the period
        $now = $this->db->now()->setTimezone(Time::tz());
        $startDay = max($from, $now->setTime(0, 0))->format('Y-m-d');
        $free = $startDay > $lastDay ? [] : $this->computeSlots($startDay, $lastDay);
        $session = (int) $this->settings->get()['sessionMinutes'] * 60;
        $minutes = 0;
        $coveredUntil = 0;
        foreach ($free as $slot) {
            $s = Time::parseIso($slot['start'])->getTimestamp();
            $e = $s + $session;
            $s = max($s, $coveredUntil);
            if ($e > $s) {
                $minutes += intdiv($e - $s, 60);
                $coveredUntil = $e;
            }
        }
        return [
            'period' => $period,
            'from' => $from->format('Y-m-d'),
            'to' => $lastDay,
            'bookedSessions' => $count("status IN ('confirmed','completed','no_show')"),
            'completedSessions' => $count("status = 'completed'"),
            'cancelledSessions' => $count("status = 'cancelled'"),
            'freeSlotHours' => round($minutes / 60, 1),
            'creditsSold' => (int) $this->db->val("SELECT COALESCE(SUM(delta), 0) FROM credit_ledger WHERE reason = 'purchase' AND created_at >= ? AND created_at < ?", [$a, $b]),
            'newClients' => (int) $this->db->val("SELECT COUNT(*) FROM users WHERE role = 'client' AND created_at >= ? AND created_at < ?", [$a, $b]),
        ];
    }
}
