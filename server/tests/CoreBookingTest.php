<?php
declare(strict_types=1);

namespace StretchEffect\Tests;

use PHPUnit\Framework\TestCase;
use StretchEffect\Contracts\DomainError;
use StretchEffect\Mail\NullMailer;

final class CoreBookingTest extends TestCase
{
    private const START = '2026-11-10T08:00:00Z'; // Tuesday 10:00 SAST

    private function code(callable $fn): string
    {
        try {
            $fn();
        } catch (DomainError $e) {
            return $e->errorCode;
        }
        return 'none';
    }

    public function testBookingCostsOneCredit(): void
    {
        $mailer = new NullMailer();
        $app = CoreTestKit::app([], ['mailer' => $mailer]);
        $c = CoreTestKit::client($app, credits: 2);
        $b = $app->bookings()->create($c['id'], self::START, 'client', $c['id']);
        $this->assertSame('confirmed', $b['status']);
        $this->assertSame(self::START, $b['start']);
        $this->assertSame('2026-11-10T09:00:00Z', $b['end']);
        $this->assertSame('Test Client', $b['clientName']);
        $this->assertSame(1, $app->credits()->balance($c['id']));
        $ledger = $app->credits()->ledger($c['id']);
        $reasons = array_column($ledger, 'reason');
        sort($reasons);
        $this->assertSame(['booking', 'purchase'], $reasons);
        $this->assertEquals(['booking' => -1, 'purchase' => 2], array_column($ledger, 'delta', 'reason'));
        $this->assertCount(1, $mailer->sent);
        $this->assertSame('client@example.com', $mailer->sent[0]['to']);
    }

    public function testNoCredits(): void
    {
        $app = CoreTestKit::app();
        $c = CoreTestKit::client($app, credits: 0);
        $this->assertSame('no_credits', $this->code(fn () => $app->bookings()->create($c['id'], self::START, 'client', $c['id'])));
        $this->assertSame([], $app->bookings()->forUser($c['id']));
    }

    public function testDoubleBookingConflict(): void
    {
        $app = CoreTestKit::app();
        $a = CoreTestKit::client($app, 'a@example.com', 1);
        $b = CoreTestKit::client($app, 'b@example.com', 1);
        $app->bookings()->create($a['id'], self::START, 'client', $a['id']);
        $this->assertSame('conflict', $this->code(fn () => $app->bookings()->create($b['id'], self::START, 'client', $b['id'])));
        $this->assertSame('conflict', $this->code(fn () => $app->bookings()->create($b['id'], '2026-11-10T08:30:00Z', 'client', $b['id'])), 'overlap');
        $this->assertSame('conflict', $this->code(fn () => $app->bookings()->create($b['id'], '2026-11-10T09:00:00Z', 'client', $b['id'])), 'inside buffer');
        $this->assertSame(1, $app->credits()->balance($b['id']), 'failed bookings cost nothing');
        $app->bookings()->create($b['id'], '2026-11-10T09:30:00Z', 'client', $b['id']);
    }

    public function testOffGridOrClosedTimeRejected(): void
    {
        $app = CoreTestKit::app();
        $c = CoreTestKit::client($app, credits: 3);
        $this->assertSame('conflict', $this->code(fn () => $app->bookings()->create($c['id'], '2026-11-10T08:10:00Z', 'client', $c['id'])), 'off the 30 min grid');
        $this->assertSame('conflict', $this->code(fn () => $app->bookings()->create($c['id'], '2026-11-10T20:00:00Z', 'client', $c['id'])), 'after hours');
        $this->assertSame('conflict', $this->code(fn () => $app->bookings()->create($c['id'], '2026-11-15T08:00:00Z', 'client', $c['id'])), 'Sunday');
        $this->assertSame('validation', $this->code(fn () => $app->bookings()->create($c['id'], 'tomorrow', 'client', $c['id'])));
    }

    public function testCancelRefundRuleOutsideWindow(): void
    {
        $app = CoreTestKit::app();
        $c = CoreTestKit::client($app, credits: 1);
        $b = $app->bookings()->create($c['id'], self::START, 'client', $c['id']);
        $app->db()->freeze('2026-11-09T07:00:00Z'); // 25 h before
        $r = $app->bookings()->cancel($b['id'], null, 'Cannot make it', $c['id']);
        $this->assertTrue($r['refunded']);
        $this->assertSame('cancelled', $r['booking']['status']);
        $this->assertSame(1, $app->credits()->balance($c['id']));
        $this->assertContains(self::START, array_column($app->bookings()->availability('2026-11-10', '2026-11-10'), 'start'), 'slot is free again');
    }

    public function testCancelRefundRuleInsideWindow(): void
    {
        $app = CoreTestKit::app();
        $c = CoreTestKit::client($app, credits: 2);
        $b1 = $app->bookings()->create($c['id'], self::START, 'client', $c['id']);
        $b2 = $app->bookings()->create($c['id'], '2026-11-10T10:00:00Z', 'client', $c['id']);
        $app->db()->freeze('2026-11-09T09:00:00Z'); // 23 h before b1
        $r1 = $app->bookings()->cancel($b1['id'], null, 'late', $c['id']);
        $this->assertFalse($r1['refunded']);
        $this->assertSame(0, $app->credits()->balance($c['id']));
        // owner can choose to refund inside the window
        $r2 = $app->bookings()->cancel($b2['id'], true, 'owner goodwill', 'owner-1');
        $this->assertTrue($r2['refunded']);
        $this->assertSame(1, $app->credits()->balance($c['id']));
        // cancelled twice is a conflict
        $this->assertSame('conflict', $this->code(fn () => $app->bookings()->cancel($b2['id'], true, 'again', 'owner-1')));
    }

    public function testOwnerCanDenyRefundOutsideWindow(): void
    {
        $app = CoreTestKit::app();
        $c = CoreTestKit::client($app, credits: 1);
        $b = $app->bookings()->create($c['id'], self::START, 'client', $c['id']);
        $app->db()->freeze('2026-11-08T08:00:00Z');
        $r = $app->bookings()->cancel($b['id'], false, 'no refund', 'owner-1');
        $this->assertFalse($r['refunded']);
        $this->assertSame(0, $app->credits()->balance($c['id']));
    }

    public function testRescheduleKeepsCreditAndChecksConflicts(): void
    {
        $app = CoreTestKit::app();
        $c = CoreTestKit::client($app, credits: 2);
        $b = $app->bookings()->create($c['id'], self::START, 'client', $c['id']);
        $other = $app->bookings()->create($c['id'], '2026-11-10T11:00:00Z', 'client', $c['id']);
        $moved = $app->bookings()->reschedule($b['id'], '2026-11-10T08:30:00Z', 'owner');
        $this->assertSame('2026-11-10T08:30:00Z', $moved['start']);
        $this->assertSame(0, $app->credits()->balance($c['id']));
        $this->assertSame('conflict', $this->code(fn () => $app->bookings()->reschedule($b['id'], $other['start'], 'owner')));
    }

    public function testGoogleEventWrittenAndDeleted(): void
    {
        $g = new FakeGoogleTransport();
        $app = CoreTestKit::app([], ['transport' => $g]);
        $app->google()->handleCallback('auth-code');
        $this->assertTrue($app->google()->isConnected());
        $stored = (string) $app->db()->val("SELECT v FROM settings WHERE k = 'google'");
        $this->assertStringNotContainsString('rt-secret', $stored, 'refresh token is encrypted at rest');
        $c = CoreTestKit::client($app, credits: 1);
        $b = $app->bookings()->create($c['id'], self::START, 'client', $c['id']);
        $this->assertSame('se' . $app->db()->val('SELECT id FROM bookings'), $b['googleEventId']);
        $post = $g->callsTo('/events', 'POST');
        $this->assertCount(1, $post);
        $body = json_decode((string) $post[0]['body'], true);
        $this->assertSame($b['id'], $body['extendedProperties']['private']['bookingId']);
        $this->assertSame($b['googleEventId'], $body['id']);
        $this->assertSame('2026-11-10T10:00:00+02:00', $body['start']['dateTime']);
        $app->bookings()->cancel($b['id'], null, 'x', $c['id']);
        $this->assertCount(1, $g->callsTo('/events/' . $b['googleEventId'], 'DELETE'));
    }

    public function testLiveGoogleBusyBlocksBooking(): void
    {
        $g = new FakeGoogleTransport();
        $g->busy = [['start' => '2026-11-10T08:00:00Z', 'end' => '2026-11-10T08:30:00Z']];
        $app = CoreTestKit::app([], ['transport' => $g]);
        $app->google()->handleCallback('auth-code');
        $c = CoreTestKit::client($app, credits: 1);
        $this->assertSame('conflict', $this->code(fn () => $app->bookings()->create($c['id'], self::START, 'client', $c['id'])));
        $this->assertSame(1, $app->credits()->balance($c['id']));
    }

    public function testGoogleFailureRollsBackBooking(): void
    {
        $g = new FakeGoogleTransport();
        $app = CoreTestKit::app([], ['transport' => $g]);
        $app->google()->handleCallback('auth-code');
        $g->failEvents = true;
        $c = CoreTestKit::client($app, credits: 1);
        $this->assertSame('server', $this->code(fn () => $app->bookings()->create($c['id'], self::START, 'client', $c['id'])));
        $this->assertSame(1, $app->credits()->balance($c['id']));
        $this->assertSame(0, (int) $app->db()->val('SELECT COUNT(*) FROM bookings'));
    }

    public function testSyncBusyFillsCacheAndAvailability(): void
    {
        $g = new FakeGoogleTransport();
        $g->busy = [['start' => '2026-11-09T06:00:00Z', 'end' => '2026-11-09T07:00:00Z']];
        $app = CoreTestKit::app([], ['transport' => $g]);
        $app->google()->handleCallback('auth-code');
        $res = $app->maintenance()->run();
        $this->assertSame('ok', $res['calendar']);
        $this->assertSame(1, $res['synced']);
        $starts = array_column($app->bookings()->availability('2026-11-09', '2026-11-09'), 'start');
        $this->assertNotContains('2026-11-09T06:00:00Z', $starts);
        $busy = $app->bookings()->busyBlocks('2026-11-09', '2026-11-09');
        $this->assertSame('google', $busy[0]['source']);
    }

    public function testSummary(): void
    {
        $app = CoreTestKit::app();
        $c = CoreTestKit::client($app, credits: 2);
        $app->bookings()->create($c['id'], self::START, 'client', $c['id']);
        $s = $app->bookings()->summary('week');
        $this->assertSame('2026-11-02', $s['from']);
        $this->assertSame('2026-11-08', $s['to']);
        $s2 = $app->bookings()->summary('month');
        $this->assertSame(1, $s2['bookedSessions']);
        $this->assertSame(2, $s2['creditsSold']);
        $this->assertSame(1, $s2['newClients']);
        $this->assertGreaterThan(0, $s2['freeSlotHours']);
    }
}
