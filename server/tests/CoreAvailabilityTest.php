<?php
declare(strict_types=1);

namespace StretchEffect\Tests;

use PHPUnit\Framework\TestCase;
use StretchEffect\Support\Time;

final class CoreAvailabilityTest extends TestCase
{
    public function testWeekdayHoursAreSouthAfricanTime(): void
    {
        $app = CoreTestKit::app();
        $slots = $app->bookings()->availability('2026-11-09', '2026-11-09'); // Monday
        $this->assertCount(25, $slots); // 06:00 .. 18:00 every 30 min
        $this->assertSame('2026-11-09T04:00:00Z', $slots[0]['start']); // 06:00 SAST = 04:00 UTC
        $this->assertSame('2026-11-09T05:00:00Z', $slots[0]['end']);
        $this->assertSame('2026-11-09T16:00:00Z', end($slots)['start']); // 18:00 SAST, ends 19:00
    }

    public function testSaturdayShortDayAndSundayClosed(): void
    {
        $app = CoreTestKit::app();
        $sat = $app->bookings()->availability('2026-11-14', '2026-11-14');
        $this->assertCount(9, $sat); // 07:00 .. 11:00
        $this->assertSame('2026-11-14T05:00:00Z', $sat[0]['start']);
        $this->assertSame('2026-11-14T09:00:00Z', end($sat)['start']);
        $this->assertSame([], $app->bookings()->availability('2026-11-15', '2026-11-15'));
    }

    public function testMinimumNoticeRemovesNearSlots(): void
    {
        // Monday 06:00 SAST: 12 h notice means only Monday 18:00 SAST is left.
        $app = CoreTestKit::app([], ['now' => '2026-11-09T04:00:00Z']);
        $slots = $app->bookings()->availability('2026-11-09', '2026-11-09');
        $this->assertCount(1, $slots);
        $this->assertSame('2026-11-09T16:00:00Z', $slots[0]['start']);
    }

    public function testBufferPadsExistingBookings(): void
    {
        $app = CoreTestKit::app();
        $c = CoreTestKit::client($app, credits: 1);
        $app->bookings()->create($c['id'], '2026-11-09T08:00:00Z', 'client', $c['id']); // 10:00-11:00 SAST
        $starts = array_column($app->bookings()->availability('2026-11-09', '2026-11-09'), 'start');
        $this->assertContains('2026-11-09T06:30:00Z', $starts, '08:30 SAST ends 09:30, buffer to 09:45, fits before 10:00');
        $this->assertNotContains('2026-11-09T07:00:00Z', $starts, '09:00 SAST has no 15 min gap before 10:00');
        $this->assertNotContains('2026-11-09T08:00:00Z', $starts);
        $this->assertNotContains('2026-11-09T09:00:00Z', $starts, '11:00 SAST is inside the 15 min buffer? no: 11:00 < 11:15');
        $this->assertContains('2026-11-09T09:30:00Z', $starts, '11:30 SAST is after the 11:15 buffer end');
        $this->assertCount(20, $starts);
    }

    public function testGoogleBusyBlocksSlotsButEndIsExclusive(): void
    {
        $app = CoreTestKit::app();
        $app->db()->run('INSERT INTO calendar_busy (start_at, end_at, synced_at) VALUES (?,?,?)', ['2026-11-09 07:00:00', '2026-11-09 08:00:00', '2026-11-08 08:00:00']); // 09:00-10:00 SAST
        $starts = array_column($app->bookings()->availability('2026-11-09', '2026-11-09'), 'start');
        foreach (['06:00', '06:30', '07:00', '07:30'] as $t) { // 08:00..09:30 SAST are blocked
            $this->assertNotContains("2026-11-09T$t:00Z", $starts, $t);
        }
        $this->assertContains('2026-11-09T05:30:00Z', $starts, '07:30 SAST finishes with buffer at 08:45');
        $this->assertContains('2026-11-09T08:00:00Z', $starts, '10:00 SAST starts exactly when busy ends');
    }

    public function testBookingWindowLimit(): void
    {
        $app = CoreTestKit::app();
        $this->assertSame([], $app->bookings()->availability('2026-12-21', '2026-12-21')); // 43 days out
        $this->assertNotEmpty($app->bookings()->availability('2026-12-01', '2026-12-01')); // 23 days out
    }

    public function testSettingsCanChangeSessionLength(): void
    {
        $app = CoreTestKit::app();
        $app->settings()->update(['sessionMinutes' => 90, 'bufferMinutes' => 0]);
        $slots = $app->bookings()->availability('2026-11-14', '2026-11-14'); // Sat 07:00-12:00
        $this->assertSame('2026-11-14T05:00:00Z', $slots[0]['start']);
        $this->assertSame('2026-11-14T06:30:00Z', $slots[0]['end']);
        $this->assertSame('2026-11-14T08:30:00Z', end($slots)['start']); // 10:30 SAST ends 12:00
    }

    public function testLocalToUtc(): void
    {
        $app = CoreTestKit::app();
        $this->assertSame('2026-11-09T07:30:00Z', $app->bookings()->localToUtc('2026-11-09T09:30'));
        $this->assertSame('2026-11-09T07:30:00Z', Time::iso(Time::parseIso('2026-11-09T09:30:00+02:00')));
    }
}
