<?php
declare(strict_types=1);

namespace StretchEffect\Tests;

use PHPUnit\Framework\TestCase;
use StretchEffect\App;
use StretchEffect\Contracts\DomainError;
use StretchEffect\Mail\NullMailer;
use StretchEffect\Services\AuthService;
use StretchEffect\Support\Time;

/** Second half of the mutation-driven service tests: Google calendar side effects, mail, summary figures, auth buckets. */
final class MutationServicesExtraTest extends TestCase
{
    private const START = '2026-11-10T08:00:00Z'; // Tuesday 10:00 SAST

    private function code(callable $fn): string
    {
        try {
            $fn();
        } catch (DomainError $e) {
            return $e->errorCode . ': ' . $e->getMessage();
        }
        return 'none';
    }

    /** @return array{0:App,1:FakeGoogleTransport,2:NullMailer} */
    private function connectedApp(array $options = []): array
    {
        $g = new FakeGoogleTransport();
        $mailer = new NullMailer();
        $app = CoreTestKit::app([], $options + ['transport' => $g, 'mailer' => $mailer]);
        $app->google()->handleCallback('auth-code');
        return [$app, $g, $mailer];
    }

    // ------------------------------------------------------------ Google calendar

    public function testOwnGoogleEventDoesNotBlockMovingButOthersDo(): void
    {
        [$app, $g] = $this->connectedApp();
        $c = CoreTestKit::client($app, credits: 2);
        $bk = $app->bookings()->create($c['id'], self::START, 'client', $c['id']);
        // Google echoes the booking's own event back as busy, and one more real appointment later that day
        $g->busy = [['start' => '2026-11-10T08:00:00Z', 'end' => '2026-11-10T09:00:00Z'], ['start' => '2026-11-10T11:00:00Z', 'end' => '2026-11-10T12:00:00Z']];
        $this->assertSame('none', $this->code(fn() => $app->bookings()->reschedule($bk['id'], '2026-11-10T08:30:00Z', 'x')), 'the own event is ignored');
        $g->busy = [['start' => '2026-11-10T08:30:00Z', 'end' => '2026-11-10T09:30:00Z'], ['start' => '2026-11-10T11:00:00Z', 'end' => '2026-11-10T12:00:00Z']];
        $this->assertSame('conflict: That time is not available.', $this->code(fn() => $app->bookings()->reschedule($bk['id'], '2026-11-10T11:30:00Z', 'x')), 'a later busy block (after the own event) still blocks');
        $g->busy = [['start' => '2026-11-10T08:30:00Z', 'end' => '2026-11-10T09:30:00Z'], ['start' => '2026-11-10T11:00:00Z', 'end' => '2026-11-10T12:00:00Z']];
        $this->assertSame('none', $this->code(fn() => $app->bookings()->reschedule($bk['id'], '2026-11-10T13:00:00Z', 'x')));
        // an event with the same start but a different end is not the booking's own
        $g->busy = [['start' => '2026-11-10T13:00:00Z', 'end' => '2026-11-10T13:30:00Z']];
        $this->assertSame('conflict: That time is not available.', $this->code(fn() => $app->bookings()->reschedule($bk['id'], '2026-11-10T13:00:00Z', 'x')));
        $g->busy = [['start' => '2026-11-10T12:30:00Z', 'end' => '2026-11-10T14:00:00Z']];
        $this->assertSame('conflict: That time is not available.', $this->code(fn() => $app->bookings()->reschedule($bk['id'], '2026-11-10T13:30:00Z', 'x')), 'same end as the own event, different start');
    }

    public function testRescheduleUpdatesTheGoogleEventAndMails(): void
    {
        [$app, $g, $mailer] = $this->connectedApp();
        $c = CoreTestKit::client($app, credits: 2);
        $bk = $app->bookings()->create($c['id'], self::START, 'client', $c['id']);
        $this->assertCount(1, $mailer->sent);
        $app->bookings()->reschedule($bk['id'], '2026-11-10T10:00:00Z', 'owner');
        $patch = $g->callsTo('/events/' . $bk['googleEventId'], 'PATCH');
        $this->assertCount(1, $patch);
        $body = json_decode((string) $patch[0]['body'], true);
        $this->assertSame('2026-11-10T12:00:00+02:00', $body['start']['dateTime']);
        $this->assertCount(2, $mailer->sent);
        $this->assertSame('client@example.com', $mailer->sent[1]['to']);
        $this->assertStringContainsString('Your session has been moved', $mailer->sent[1]['subject']);
        // a failing calendar rolls the move back
        $g->failEvents = true;
        $this->assertSame('server: The calendar event could not be updated. Please try again.', $this->code(fn() => $app->bookings()->reschedule($bk['id'], '2026-11-10T12:00:00Z', 'owner')));
        $this->assertSame('2026-11-10 10:00:00', $app->db()->val('SELECT start_at FROM bookings WHERE id = ?', [$bk['id']]));
        $this->assertCount(2, $mailer->sent, 'no mail for a move that did not happen');
    }

    public function testCancelTouchesGoogleOnlyWhenThereIsAnEventAndAConnection(): void
    {
        // connected, but the booking predates the connection (no event id): nothing is deleted
        $g = new FakeGoogleTransport();
        $mailer = new NullMailer();
        $app = CoreTestKit::app([], ['transport' => $g, 'mailer' => $mailer]);
        $c = CoreTestKit::client($app, credits: 3);
        $early = $app->bookings()->create($c['id'], self::START, 'client', $c['id']);
        $app->google()->handleCallback('auth-code');
        $app->bookings()->cancel($early['id'], null, 'x', $c['id']);
        $this->assertCount(0, $g->callsTo('/events', 'DELETE'));
        $this->assertCount(2, $mailer->sent, 'a confirmation and a cancellation mail');
        $this->assertSame('client@example.com', $mailer->sent[1]['to']);
        $this->assertStringContainsString('Session cancelled', $mailer->sent[1]['subject']);
        $this->assertStringContainsString('credit has been returned', $mailer->sent[1]['text']);
        // an event id exists but Google was disconnected since: nothing to call
        $late = $app->bookings()->create($c['id'], '2026-11-10T10:00:00Z', 'client', $c['id']);
        $this->assertNotEmpty($late['googleEventId']);
        $app->db()->run("DELETE FROM settings WHERE k = 'google'");
        $calls = count($g->calls);
        $app->bookings()->cancel($late['id'], false, 'x', $c['id']);
        $this->assertCount($calls, $g->calls, 'no Google call when disconnected');
        $this->assertStringContainsString('not returned', end($mailer->sent)['text']);
        // a failing delete does not fail the cancellation
        $app2 = CoreTestKit::app([], ['transport' => $g2 = new FakeGoogleTransport()]);
        $app2->google()->handleCallback('auth-code');
        $c2 = CoreTestKit::client($app2, credits: 1);
        $bk = $app2->bookings()->create($c2['id'], self::START, 'client', $c2['id']);
        $g2->failEvents = true;
        $r = $app2->bookings()->cancel($bk['id'], null, 'x', $c2['id']);
        $this->assertSame('cancelled', $app2->db()->val('SELECT status FROM bookings WHERE id = ?', [$bk['id']]));
        $this->assertTrue($r['refunded']);
    }

    public function testCancelAndRescheduleClearTheirCachedBusyRows(): void
    {
        $app = CoreTestKit::app();
        $c = CoreTestKit::client($app, credits: 3);
        $b = $app->bookings();
        $one = $b->create($c['id'], self::START, 'client', $c['id']);
        $add = fn(string $s, string $e) => $app->db()->run("INSERT INTO calendar_busy (start_at, end_at, synced_at) VALUES (?,?,'2026-11-08 00:00:00')", [$s, $e]);
        $add('2026-11-10 08:00:00', '2026-11-10 09:00:00'); // echo of the booking
        $add('2026-11-10 14:00:00', '2026-11-10 15:00:00'); // somebody else
        $b->cancel($one['id'], true, 'x', 'x');
        $this->assertSame(['2026-11-10 14:00:00'], array_column($app->db()->all('SELECT start_at FROM calendar_busy'), 'start_at'), 'only the echo of the cancelled booking goes');
        $two = $b->create($c['id'], '2026-11-10T10:00:00Z', 'client', $c['id']);
        $add('2026-11-10 10:00:00', '2026-11-10 11:00:00');
        $b->reschedule($two['id'], '2026-11-10T11:30:00Z', 'x');
        $rows = array_column($app->db()->all('SELECT start_at FROM calendar_busy ORDER BY start_at'), 'start_at');
        $this->assertSame(['2026-11-10 14:00:00'], $rows, 'the old echo is removed when a booking moves');
    }

    public function testCachedBusyBlocksRescheduleExceptTheOwnEcho(): void
    {
        $app = CoreTestKit::app();
        $c = CoreTestKit::client($app, credits: 2);
        $b = $app->bookings();
        $one = $b->create($c['id'], self::START, 'client', $c['id']);
        $app->db()->run("INSERT INTO calendar_busy (start_at, end_at, synced_at) VALUES ('2026-11-10 08:00:00', '2026-11-10 09:00:00', '2026-11-08 00:00:00')");
        $app->db()->run("INSERT INTO calendar_busy (start_at, end_at, synced_at) VALUES ('2026-11-10 12:00:00', '2026-11-10 13:00:00', '2026-11-08 00:00:00')");
        $this->assertSame('conflict: That time is not available.', $this->code(fn() => $b->reschedule($one['id'], '2026-11-10T12:00:00Z', 'x')), 'a cached busy block is respected');
        $this->assertSame('none', $this->code(fn() => $b->reschedule($one['id'], '2026-11-10T08:30:00Z', 'x')), 'the booking\'s own cached echo is not');
    }

    public function testBusyBlocksSkipEveryEchoNotJustTheFirst(): void
    {
        $app = CoreTestKit::app();
        $c = CoreTestKit::client($app, credits: 3);
        $b = $app->bookings();
        $b->create($c['id'], self::START, 'client', $c['id']);
        $add = fn(string $s, string $e) => $app->db()->run("INSERT INTO calendar_busy (start_at, end_at, synced_at) VALUES (?,?,'2026-11-08 00:00:00')", [$s, $e]);
        $add('2026-11-10 08:00:00', '2026-11-10 09:00:00'); // echo first
        $add('2026-11-10 13:00:00', '2026-11-10 14:00:00'); // then a real block
        $blocks = $b->busyBlocks('2026-11-10', '2026-11-10');
        $this->assertSame(['booking', 'google'], array_column($blocks, 'source'));
        $this->assertSame('Busy (Google Calendar)', $blocks[1]['label']);
        $this->assertSame('Test Client', $blocks[0]['label']);
        $this->assertSame('2026-11-10T13:00:00Z', $blocks[1]['start']);
    }

    public function testListAndForUserShapeAndLimit(): void
    {
        $app = CoreTestKit::app();
        $c = CoreTestKit::client($app, credits: 1);
        $in = $app->db()->pdo()->prepare("INSERT INTO bookings (id,user_id,start_at,end_at,status,created_by,created_at) VALUES (?,?,?,?, 'cancelled','client','2026-11-01 00:00:00')");
        $app->db()->pdo()->beginTransaction();
        for ($i = 0; $i < 1003; $i++) {
            $t = gmdate('Y-m-d H:i:s', 1_794_000_000 + $i * 60);
            $in->execute([sprintf('b%04d', $i), $c['id'], $t, $t]);
        }
        $app->db()->pdo()->commit();
        $all = $app->bookings()->list(gmdate('Y-m-d', 1_794_000_000), gmdate('Y-m-d', 1_794_000_000 + 1003 * 60 + 86400));
        $this->assertCount(1000, $all, 'list is capped at 1000 rows');
        $this->assertSame('b0000', $all[0]['id']);
        $this->assertSame(['id', 'userId', 'clientName', 'start', 'end', 'status', 'createdBy'], array_slice(array_keys($all[0]), 0, 7));
        $mine = $app->bookings()->forUser($c['id']);
        $this->assertCount(500, $mine, 'forUser is capped at 500 rows');
        $this->assertSame('b1002', $mine[0]['id']);
        $this->assertArrayHasKey('start', $mine[0]);
        $this->assertStringEndsWith('Z', $mine[0]['start']);
    }

    public function testCancelStoresTheRefundFlag(): void
    {
        $app = CoreTestKit::app();
        $c = CoreTestKit::client($app, credits: 3);
        $b = $app->bookings();
        $one = $b->create($c['id'], self::START, 'client', $c['id']);
        $two = $b->create($c['id'], '2026-11-10T10:00:00Z', 'client', $c['id']);
        $app->db()->freeze('2026-11-10T07:00:00Z'); // 1 h before: inside the window
        $b->cancel($one['id'], null, 'late', 'x');
        $this->assertSame(0, (int) $app->db()->val('SELECT refunded FROM bookings WHERE id = ?', [$one['id']]));
        $b->cancel($two['id'], true, 'goodwill', 'x');
        $this->assertSame(1, (int) $app->db()->val('SELECT refunded FROM bookings WHERE id = ?', [$two['id']]));
    }

    // ------------------------------------------------------------ slot grid with unusual settings

    public function testBufferAndStepSettingsDriveTheGrid(): void
    {
        $app = CoreTestKit::app([], ['now' => '2026-11-01T00:00:00Z']);
        $app->settings()->update(['slotStepMinutes' => 5]);
        $c = CoreTestKit::client($app, credits: 2);
        $app->bookings()->create($c['id'], self::START, 'client', $c['id']); // 08:00-09:00Z, buffer 15
        $free = array_column($app->bookings()->availability('2026-11-10', '2026-11-10'), 'start');
        $this->assertContains('2026-11-10T06:45:00Z', $free, 'ends at 07:45 + 15 min buffer = 08:00, touching but not overlapping');
        $this->assertNotContains('2026-11-10T06:50:00Z', $free);
        $this->assertNotContains('2026-11-10T09:10:00Z', $free);
        $this->assertContains('2026-11-10T09:15:00Z', $free, 'starts exactly when the booking buffer ends');
        $this->assertContains('2026-11-10T04:05:00Z', $free, 'the 5 minute step is honoured');
        // a larger buffer moves both edges
        $app->settings()->update(['bufferMinutes' => 30]);
        $free = array_column($app->bookings()->availability('2026-11-10', '2026-11-10'), 'start');
        $this->assertContains('2026-11-10T06:30:00Z', $free);
        $this->assertNotContains('2026-11-10T06:35:00Z', $free);
        $this->assertContains('2026-11-10T09:30:00Z', $free);
        $this->assertNotContains('2026-11-10T09:25:00Z', $free);
        // no buffer at all
        $app->settings()->update(['bufferMinutes' => 0]);
        $free = array_column($app->bookings()->availability('2026-11-10', '2026-11-10'), 'start');
        $this->assertContains('2026-11-10T07:00:00Z', $free);
        $this->assertContains('2026-11-10T09:00:00Z', $free);
        $this->assertNotContains('2026-11-10T07:05:00Z', $free);
        $this->assertNotContains('2026-11-10T08:55:00Z', $free);
    }

    public function testASettingsRowWithAZeroStepStillMakesProgress(): void
    {
        $app = CoreTestKit::app([], ['now' => '2026-11-01T00:00:00Z']);
        $app->db()->run("INSERT INTO settings (k, v, updated_at) VALUES ('booking', ?, '2026-11-01 00:00:00')", [json_encode(['slotStepMinutes' => 0, 'workingHours' => [
            ['weekday' => 0, 'open' => false, 'start' => '00:00', 'end' => '00:00'], ['weekday' => 1, 'open' => false, 'start' => '00:00', 'end' => '00:00'],
            ['weekday' => 2, 'open' => true, 'start' => '09:00', 'end' => '10:30'], ['weekday' => 3, 'open' => false, 'start' => '00:00', 'end' => '00:00'],
            ['weekday' => 4, 'open' => false, 'start' => '00:00', 'end' => '00:00'], ['weekday' => 5, 'open' => false, 'start' => '00:00', 'end' => '00:00'],
            ['weekday' => 6, 'open' => false, 'start' => '00:00', 'end' => '00:00']]])]);
        $slots = $app->bookings()->availability('2026-11-10', '2026-11-10');
        $this->assertCount(31, $slots, 'a step of 0 is treated as 1 minute: 09:00 to 09:30 inclusive');
        $this->assertSame('2026-11-10T07:01:00Z', $slots[1]['start']);
    }

    public function testClosedDayConfiguration(): void
    {
        $app = CoreTestKit::app([], ['now' => '2026-11-01T00:00:00Z']);
        $wh = [];
        for ($d = 0; $d <= 6; $d++) {
            $wh[] = ['weekday' => $d, 'open' => $d === 2, 'start' => '10:00', 'end' => '12:00'];
        }
        $app->settings()->update(['workingHours' => $wh]);
        $this->assertCount(3, $app->bookings()->availability('2026-11-10', '2026-11-10'), '10:00, 10:30, 11:00');
        $this->assertSame([], $app->bookings()->availability('2026-11-11', '2026-11-11'));
        $this->assertSame([], $app->bookings()->availability('2026-11-09', '2026-11-09'));
    }

    // ------------------------------------------------------------ summary figures

    public function testSummaryWeekBoundariesAndFreeHours(): void
    {
        $app = CoreTestKit::app([], ['now' => '2026-11-10T08:00:00Z']); // Tuesday 10:00 local
        $c = CoreTestKit::client($app, credits: 0);
        $in = $app->db()->pdo()->prepare("INSERT INTO bookings (id,user_id,start_at,end_at,status,created_by,created_at) VALUES (?,?,?,?,?,'client','2026-11-01 00:00:00')");
        $rows = [['out1', '2026-11-08 21:59:00', 'confirmed'], ['monAm', '2026-11-08 22:00:00', 'confirmed'], ['monPm', '2026-11-09 12:00:00', 'confirmed'],
            ['thu', '2026-11-12 08:00:00', 'completed'], ['fri', '2026-11-13 08:00:00', 'no_show'], ['sat', '2026-11-14 08:00:00', 'cancelled'],
            ['sunEnd', '2026-11-15 21:59:00', 'confirmed'], ['out2', '2026-11-15 22:00:00', 'confirmed']];
        foreach ($rows as [$id, $st, $status]) {
            $in->execute([$id, $c['id'], $st, gmdate('Y-m-d H:i:s', strtotime($st . ' UTC') + 3600), $status]);
        }
        $w = $app->bookings()->summary('week');
        $this->assertSame(5, $w['bookedSessions']);
        $this->assertSame(1, $w['completedSessions']);
        $this->assertSame(1, $w['cancelledSessions']);
        $this->assertSame(['2026-11-09', '2026-11-15'], [$w['from'], $w['to']]);
        // Wed 13 h, Thu 13 h - 2 h taken, Fri 13 h - 2 h taken, Sat 5 h (earliest start is Tue 22:00 local)
        $this->assertSame(40.0, $w['freeSlotHours']);
    }

    public function testSummaryMonthBoundariesFreeHoursAndLastDay(): void
    {
        $app = CoreTestKit::app([], ['now' => '2026-11-14T04:00:00Z']); // Saturday 06:00 local
        $c = CoreTestKit::client($app, credits: 0);
        $in = $app->db()->pdo()->prepare("INSERT INTO bookings (id,user_id,start_at,end_at,status,created_by,created_at) VALUES (?,?,?,?,?,'client','2026-11-01 00:00:00')");
        foreach ([['o1', '2026-10-31 21:59:00'], ['i1', '2026-10-31 22:00:00'], ['i2', '2026-11-30 21:59:00'], ['o2', '2026-11-30 22:00:00']] as [$id, $st]) {
            $in->execute([$id, $c['id'], $st, gmdate('Y-m-d H:i:s', strtotime($st . ' UTC') + 3600), 'confirmed']);
        }
        $m = $app->bookings()->summary('month');
        $this->assertSame(2, $m['bookedSessions']);
        $this->assertSame(['2026-11-01', '2026-11-30'], [$m['from'], $m['to']]);
        // earliest start is Sat 18:00 local; Mon 16 - Fri 20 (5 x 13) + Sat 21 (5) + Mon 23 - Fri 27 (5 x 13) + Sat 28 (5) + Mon 30 (13, minus 2 h around the booking at 23:59 local: none)
        $this->assertSame(153.0, $m['freeSlotHours']);
        $this->assertSame(0, $m['creditsSold']);
        // on the last day of the month there can still be slots left that evening
        $app->db()->freeze('2026-11-30T03:00:00Z'); // Monday 05:00 local: earliest start 17:00 local
        $this->assertSame(2.0, $app->bookings()->summary('month')['freeSlotHours'], '17:00-19:00 is free on the last day');
        $app->db()->freeze('2026-11-30T21:00:00Z');
        $this->assertSame(0.0, $app->bookings()->summary('month')['freeSlotHours']);
    }

    public function testSummaryFreeHoursWithoutOverlapDoubleCounting(): void
    {
        $app = CoreTestKit::app([], ['now' => '2026-11-14T04:00:00Z']);
        $this->assertSame(0.0, fmod($app->bookings()->summary('month')['freeSlotHours'], 0.5));
        $this->assertSame(0.0, $app->bookings()->summary('week')['freeSlotHours'], 'Saturday after 18:00 is closed and the week ends on Sunday');
    }

    // ------------------------------------------------------------ AuthService details

    public function testPasswordsAreHashedWithArgon2idParameters(): void
    {
        if (!defined('PASSWORD_ARGON2ID')) {
            $this->markTestSkipped('argon2id is not available in this PHP build');
        }
        $info = password_get_info(AuthService::hashPassword('x'));
        $this->assertSame('argon2id', $info['algoName']);
        $this->assertSame(['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1], $info['options']);
        $this->assertFalse(password_needs_rehash(AuthService::hashPassword('x'), PASSWORD_ARGON2ID, ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1]));
    }

    public function testLoginBucketsAndWindows(): void
    {
        $app = CoreTestKit::app();
        CoreTestKit::client($app, 'pw@example.com', 0, 'Pw', 'right-password-1');
        $auth = $app->auth();
        $auth->verifyPassword(' PW@example.com', 'right-password-1', '10.1.1.1');
        $w = intdiv($app->db()->now()->getTimestamp(), 900) * 900;
        $exp = gmdate('Y-m-d H:i:s', $w + 900);
        $this->assertSame($exp, $app->db()->val("SELECT expires_at FROM rate_limits WHERE bucket = ?", ["login_ip:10.1.1.1:$w"]));
        $pair = hash('sha256', 'pw@example.com|10.1.1.1');
        $auth->verifyPassword('pw@example.com', 'wrong', '10.1.1.1');
        $this->assertSame(1, (int) $app->db()->val('SELECT hits FROM rate_limits WHERE bucket = ?', ["login_email_ip:$pair:$w"]), 'the pair bucket is keyed on "email|ip" and was reset by the success');
        $this->assertSame($exp, $app->db()->val('SELECT expires_at FROM rate_limits WHERE bucket = ?', ["login_email_ip:$pair:$w"]));
        $this->assertSame($exp, $app->db()->val('SELECT expires_at FROM rate_limits WHERE bucket = ?', ['login_email:' . hash('sha256', 'pw@example.com') . ":$w"]));
    }

    public function testSuccessfulLoginResetsThePerEmailBudget(): void
    {
        $app = CoreTestKit::app();
        CoreTestKit::client($app, 'pw@example.com', 0, 'Pw', 'right-password-1');
        $auth = $app->auth();
        for ($i = 1; $i <= 29; $i++) {
            $this->assertNull($auth->verifyPassword('pw@example.com', 'wrong', "10.20.0.$i"));
        }
        $this->assertNotNull($auth->verifyPassword('pw@example.com', 'right-password-1', '10.20.1.1'), 'the 30th hit is allowed and succeeds');
        for ($i = 1; $i <= 29; $i++) {
            $this->assertNull($auth->verifyPassword('pw@example.com', 'wrong', "10.20.2.$i"), "after reset attempt $i");
        }
    }

    public function testStrongHashIsNotRewrittenOnLogin(): void
    {
        $app = CoreTestKit::app();
        $c = CoreTestKit::client($app, 'pw@example.com', 0, 'Pw', 'right-password-1');
        $before = (string) $app->db()->val('SELECT password_hash FROM users WHERE id = ?', [$c['id']]);
        $this->assertNotNull($app->auth()->verifyPassword('pw@example.com', 'right-password-1', '10.1.1.1'));
        $this->assertSame($before, (string) $app->db()->val('SELECT password_hash FROM users WHERE id = ?', [$c['id']]));
    }

    public function testTotpBucketNames(): void
    {
        $app = CoreTestKit::app();
        $o = CoreTestKit::owner($app);
        $w = intdiv($app->db()->now()->getTimestamp(), 900) * 900;
        $exp = gmdate('Y-m-d H:i:s', $w + 900);
        $app->auth()->verifyTotp($o['id'], '000000', '10.1.1.1');
        $app->auth()->verifyTotp($o['id'], '000000');
        $this->assertSame($exp, $app->db()->val('SELECT expires_at FROM rate_limits WHERE bucket = ?', ['totp_ip:' . $o['id'] . "|10.1.1.1:$w"]));
        $this->assertSame($exp, $app->db()->val('SELECT expires_at FROM rate_limits WHERE bucket = ?', ['totp_ip:' . $o['id'] . "|-:$w"]));
        $this->assertSame($exp, $app->db()->val('SELECT expires_at FROM rate_limits WHERE bucket = ?', ['totp:' . $o['id'] . ":$w"]));
        $this->assertSame(2, (int) $app->db()->val('SELECT hits FROM rate_limits WHERE bucket = ?', ['totp:' . $o['id'] . ":$w"]));
    }

    public function testStartingTheSecondFactorEndsAnyFullSession(): void
    {
        $app = CoreTestKit::app();
        $o = CoreTestKit::owner($app);
        $other = CoreTestKit::client($app);
        $auth = $app->auth();
        $auth->startSession($other['id']);
        $this->assertSame($other['id'], $auth->currentUser()['id']);
        $auth->beginTotp($o['id']);
        $this->assertNull($auth->currentUser(), 'a half-finished owner login is not a session, and the old one is gone');
        $this->assertSame($o['id'], $auth->session()->get('pending_totp')['uid']);
        $this->assertSame($app->db()->now()->getTimestamp(), $auth->session()->get('pending_totp')['at']);
    }

    // ------------------------------------------------------------ GrantService details

    public function testGrantListScopesAreReindexedAndReasonsCutInCharacters(): void
    {
        $app = CoreTestKit::app();
        $o = CoreTestKit::owner($app);
        $ins = fn(string $id, string $scopes) => $app->db()->run("INSERT INTO oauth_grants (id, client_id, user_id, scopes, resource, created_at) VALUES (?,?,?,?,'r','2026-11-01 08:00:00')", [$id, 'c', $o['id'], $scopes]);
        $ins('g1', ' bookings:read  credits:write');
        $list = $app->grants()->list();
        $this->assertSame([0 => 'bookings:read', 1 => 'credits:write'], $list[0]['scopes']);
        $this->assertSame('g1', $list[0]['id']);
        $this->assertIsString($list[0]['id']);
        $ins('g2', 'x');
        $app->grants()->revoke('g2', str_repeat('é', 100));
        $this->assertSame(str_repeat('é', 80), $app->db()->val("SELECT revoke_reason FROM oauth_grants WHERE id = 'g2'"));
        $ins('g3', 'x');
        $app->grants()->revokeAllForUser($o['id'], str_repeat('é', 100));
        $this->assertSame(str_repeat('é', 80), $app->db()->val("SELECT revoke_reason FROM oauth_grants WHERE id = 'g3'"));
    }

    // ------------------------------------------------------------ Time helpers

    public function testTimeHelpers(): void
    {
        $this->assertSame('UTC', Time::utc()->getName());
        $this->assertSame('Africa/Johannesburg', Time::tz()->getName());
        $this->assertNull(Time::isoOrNull(null));
        $this->assertSame('2026-11-09T08:00:00Z', Time::isoOrNull('2026-11-09 08:00:00'));
        $this->assertSame('2026-11-09T08:00:00Z', Time::iso('2026-11-09 08:00:00'));
        $this->assertSame('2026-11-09T08:00:00Z', Time::iso(new \DateTimeImmutable('2026-11-09 10:00:00', Time::tz())));
        $this->assertSame('2026-11-09 08:00:00', Time::toDb(new \DateTimeImmutable('2026-11-09 10:00:00', Time::tz())));
        // parseIso: anchors on both ends
        foreach (['2026-11-09T08:00:00Z', '2026-11-09T08:00Z', '2026-11-09T08:00:00.123Z', '2026-11-09T10:00:00+02:00', '2026-11-09T10:00:00+0200'] as $ok) {
            $this->assertSame('2026-11-09T08:00:00Z', Time::iso(Time::parseIso($ok)), $ok);
        }
        foreach (['', 'x2026-11-09T08:00:00Z', '2026-11-09T08:00:00Zx', "2026-11-09T08:00:00Z\n", '2026-11-09 08:00:00Z', '2026-11-09T08:00:00', '2026-13-45T08:00:00Z', 'x'] as $bad) {
            $this->assertNull(Time::parseIso($bad), json_encode($bad));
        }
        $this->assertSame('2026-11-09', Time::localDate('2026-11-09')->format('Y-m-d'));
        $this->assertSame('Africa/Johannesburg', Time::localDate('2026-11-09')->getTimezone()->getName());
        $this->assertSame('00:00', Time::localDate('2026-11-09')->format('H:i'));
        foreach (['', '2026-11-09 ', 'x2026-11-09', '2026-11-09x', "2026-11-09\n", "2026-11-09\0", '2026-02-30', '2026-13-01', '26-11-09'] as $bad) {
            $this->assertNull(Time::localDate($bad), json_encode($bad));
        }
    }
}
