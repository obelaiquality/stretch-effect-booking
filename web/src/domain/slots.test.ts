import { describe, expect, it } from 'vitest';
import { addDays, computeSlots, formatLocalDate, formatLocalDateTime, formatLocalTime, localDayKey, localToUtc, startOfWeek } from './slots';
import type { Settings } from './types';

const settings: Settings = {
  timezone: 'Africa/Johannesburg',
  sessionMinutes: 60,
  bufferMinutes: 15,
  slotStepMinutes: 30,
  bookingWindowDays: 28,
  minNoticeHours: 12,
  cancelFreeHours: 24,
  workingHours: [
    { weekday: 0, open: false, start: '00:00', end: '00:00' },
    ...[1, 2, 3, 4, 5].map((weekday) => ({ weekday, open: true, start: '06:00', end: '19:00' })),
    { weekday: 6, open: true, start: '07:00', end: '12:00' },
  ],
};
// Sunday 2026-11-01 12:00 local, so Monday 2 Nov is the next open day (outside 12 h notice? 18 h away)
const NOW = new Date(localToUtc('2026-11-01T12:00')).getTime();
const base = { settings, nowMs: NOW, google: [], bookings: [] };

describe('time helpers', () => {
  it('converts local to UTC (UTC+2)', () => {
    expect(localToUtc('2026-11-09T07:30')).toBe('2026-11-09T05:30:00.000Z');
    expect(localToUtc('2026-11-09T01:00')).toBe('2026-11-08T23:00:00.000Z');
  });
  it('formats local date and time', () => {
    expect(formatLocalTime('2026-11-09T05:30:00.000Z')).toBe('07:30');
    expect(localDayKey('2026-11-08T23:00:00.000Z')).toBe('2026-11-09');
    expect(formatLocalDate('2026-11-11T05:30:00.000Z')).toBe('Wed 11 Nov');
    expect(formatLocalDateTime('2026-11-10T05:30:00.000Z')).toBe('Tue 10 Nov · 07:30');
  });
  it('adds days and finds the week start', () => {
    expect(addDays('2026-11-30', 1)).toBe('2026-12-01');
    expect(startOfWeek('2026-11-08')).toBe('2026-11-02');
  });
});

describe('computeSlots', () => {
  it('skips Sunday and respects weekday hours', () => {
    expect(computeSlots({ ...base, fromDate: '2026-11-01', toDate: '2026-11-01' })).toHaveLength(0);
    const mon = computeSlots({ ...base, fromDate: '2026-11-02', toDate: '2026-11-02' });
    expect(formatLocalTime(mon[0].start)).toBe('06:00');
    // last start: 17:30 (ends 18:30, buffer to 18:45); 18:00 would need until 19:15
    expect(formatLocalTime(mon[mon.length - 1].start)).toBe('17:30');
  });
  it('Saturday closes at 12:00 including buffer', () => {
    const sat = computeSlots({ ...base, fromDate: '2026-11-07', toDate: '2026-11-07' });
    expect(formatLocalTime(sat[0].start)).toBe('07:00');
    expect(formatLocalTime(sat[sat.length - 1].start)).toBe('10:30');
  });
  it('removes Google busy blocks', () => {
    const google = [{ start: localToUtc('2026-11-03T09:00'), end: localToUtc('2026-11-03T10:00') }];
    const tue = computeSlots({ ...base, google, fromDate: '2026-11-03', toDate: '2026-11-03' }).map((s) => formatLocalTime(s.start));
    expect(tue).not.toContain('09:00');
    expect(tue).not.toContain('08:30'); // 08:30-09:30 overlaps; 08:00 ends 09:00 + 15 buffer overlaps too
    expect(tue).not.toContain('08:00');
    expect(tue).toContain('07:30'); // 07:30-08:30 + buffer 08:45 clear
    expect(tue).toContain('10:00');
  });
  it('pads a booking with the buffer after it', () => {
    const bookings = [{ start: localToUtc('2026-11-03T09:00'), end: localToUtc('2026-11-03T10:00') }];
    const times = computeSlots({ ...base, bookings, fromDate: '2026-11-03', toDate: '2026-11-03' }).map((s) => formatLocalTime(s.start));
    expect(times).not.toContain('10:00'); // inside the buffer
    expect(times).toContain('10:30');
  });
  it('applies minimum notice', () => {
    const now = new Date(localToUtc('2026-11-03T08:00')).getTime();
    const times = computeSlots({ ...base, nowMs: now, fromDate: '2026-11-03', toDate: '2026-11-03' });
    expect(times).toHaveLength(0); // 12 h notice pushes past 17:30 close on the same day
    const skip = computeSlots({ ...base, nowMs: now, skipNotice: true, fromDate: '2026-11-03', toDate: '2026-11-03' });
    expect(formatLocalTime(skip[0].start)).toBe('08:00');
  });
  it('limits to the booking window', () => {
    const far = computeSlots({ ...base, fromDate: '2026-11-01', toDate: '2027-01-31' });
    const last = far[far.length - 1];
    expect(localDayKey(last.start) <= '2026-11-29').toBe(true);
  });
});
