import { describe, expect, it } from 'vitest';
import { addDays, computeSlots, daysBetween, formatLocalDate, formatLocalDateTime, formatLocalTime, isSlotFree, localDayKey, localDayKeyMs, localToUtc, startOfWeek, weekdayOf } from './slots';
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

describe('regression: impossible local dates', () => {
  it('localToUtc throws instead of rolling 30 Feb over to 2 Mar', () => {
    expect(() => localToUtc('2026-02-30T10:00')).toThrow(/Bad local datetime/);
    expect(() => localToUtc('2026-11-09T25:00')).toThrow();
    expect(() => localToUtc('2026-13-01T10:00')).toThrow();
    expect(localToUtc('2024-02-29T10:00')).toBe('2024-02-29T08:00:00.000Z'); // real leap day still works
    expect(() => localToUtc('2026-02-29T10:00')).toThrow(); // not a leap year
  });
});

describe('computeSlots boundaries (exact)', () => {
  const s15: Settings = { ...settings, slotStepMinutes: 15 };
  const day = '2026-11-03'; // Tuesday
  const times = (over: Partial<Parameters<typeof computeSlots>[0]> = {}) =>
    computeSlots({ ...base, settings: s15, fromDate: day, toDate: day, ...over }).map((x) => formatLocalTime(x.start));
  const at = (t: string) => localToUtc(`${day}T${t}`);

  it('keeps a slot that ends exactly when a busy block starts (including its buffer), drops one minute-step later', () => {
    const google = [{ start: at('10:00'), end: at('11:00') }];
    const t = times({ google });
    expect(t).toContain('08:45'); // 08:45-09:45, buffer to 10:00 = touches only
    expect(t).not.toContain('09:00'); // buffer reaches 10:15
    expect(t).not.toContain('10:45'); // inside the block end? 10:45 starts before 11:00
    expect(t).toContain('11:00'); // starts exactly at block end
    expect(t).not.toContain('10:00');
  });
  it('pads bookings with the buffer after them, no extra padding for google blocks', () => {
    const bookings = [{ start: at('10:00'), end: at('11:00') }];
    const t = times({ bookings });
    expect(t).not.toContain('11:00');
    expect(t).not.toContain('11:00'); // inside the 15 min buffer
    expect(t).toContain('11:15');
    expect(t).toContain('08:45');
    expect(t).not.toContain('09:00');
    const bigBuffer = times({ bookings, settings: { ...s15, bufferMinutes: 30 } });
    expect(bigBuffer).not.toContain('11:15');
    expect(bigBuffer).toContain('11:30');
    expect(bigBuffer).toContain('08:30');
    expect(bigBuffer).not.toContain('08:45');
  });
  it('zero buffer lets sessions sit back to back', () => {
    const t = times({ settings: { ...s15, bufferMinutes: 0 }, bookings: [{ start: at('10:00'), end: at('11:00') }] });
    expect(t).toContain('09:00');
    expect(t).toContain('11:00');
    expect(t).not.toContain('09:15');
    expect(t).not.toContain('10:45');
    expect(computeSlots({ ...base, settings: { ...s15, bufferMinutes: 0 }, fromDate: day, toDate: day }).at(-1)!.start).toBe(at('18:00'));
  });
  it('minimum notice is inclusive: exactly now + notice is allowed', () => {
    const now = new Date(at('06:00')).getTime();
    const got = computeSlots({ ...base, settings: { ...s15, minNoticeHours: 2 }, nowMs: now - 3 * 3600000 + 3600000, fromDate: day, toDate: day });
    // now = 04:00 local; earliest = 06:00 local
    expect(formatLocalTime(got[0].start)).toBe('06:00');
    const later = computeSlots({ ...base, settings: { ...s15, minNoticeHours: 2 }, nowMs: now - 2 * 3600000 + 60000, fromDate: day, toDate: day });
    expect(formatLocalTime(later[0].start)).toBe('06:15');
  });
  it('clamps to today and to the window; the last day of the window is included', () => {
    const now = new Date(localToUtc('2026-11-03T05:00')).getTime();
    const all = computeSlots({ ...base, settings: { ...s15, minNoticeHours: 0, bookingWindowDays: 3 }, nowMs: now, fromDate: '2026-10-25', toDate: '2026-12-31' });
    const days = [...new Set(all.map((x) => localDayKey(x.start)))];
    expect(days).toEqual(['2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06']);
    const none = computeSlots({ ...base, settings: { ...s15, minNoticeHours: 0 }, nowMs: now, fromDate: '2026-12-31', toDate: '2026-12-01' });
    expect(none).toEqual([]);
    const onlyLast = computeSlots({ ...base, settings: { ...s15, minNoticeHours: 0, bookingWindowDays: 3 }, nowMs: now, fromDate: '2026-11-06', toDate: '2026-11-30' });
    expect(new Set(onlyLast.map((x) => localDayKey(x.start)))).toEqual(new Set(['2026-11-06']));
    const pastOnly = computeSlots({ ...base, settings: { ...s15, minNoticeHours: 0 }, nowMs: now, fromDate: '2026-10-20', toDate: '2026-11-02' });
    expect(pastOnly).toEqual([]);
  });
  it('slot objects carry exact start and end', () => {
    const [first] = computeSlots({ ...base, settings: s15, fromDate: day, toDate: day });
    expect(first).toEqual({ start: '2026-11-03T04:00:00.000Z', end: '2026-11-03T05:00:00.000Z' });
    const odd = computeSlots({ ...base, settings: { ...s15, sessionMinutes: 45 }, fromDate: day, toDate: day })[0];
    expect(Date.parse(odd.end) - Date.parse(odd.start)).toBe(45 * 60000);
  });
  it('closed days and days missing from the table give nothing', () => {
    const closedTue = { ...s15, workingHours: s15.workingHours.map((w) => (w.weekday === 2 ? { ...w, open: false } : w)) };
    expect(times({ settings: closedTue })).toEqual([]);
    expect(times({ settings: { ...s15, workingHours: [] } })).toEqual([]);
  });
  it('step sets the grid and the close time includes the buffer', () => {
    const s60 = { ...settings, slotStepMinutes: 60 };
    const t = computeSlots({ ...base, settings: s60, fromDate: day, toDate: day }).map((x) => formatLocalTime(x.start));
    expect(t[0]).toBe('06:00');
    expect(t[1]).toBe('07:00');
    expect(t.at(-1)).toBe('17:00');
    const w = { ...settings, workingHours: settings.workingHours.map((x) => (x.weekday === 2 ? { ...x, start: '06:30', end: '08:45' } : x)), slotStepMinutes: 15 };
    const u = computeSlots({ ...base, settings: w, fromDate: day, toDate: day }).map((x) => formatLocalTime(x.start));
    expect(u).toEqual(['06:30', '06:45', '07:00', '07:15', '07:30']); // 07:30 + 60 + 15 = 08:45 = close
  });
  it('isSlotFree matches computeSlots exactly', () => {
    const inp = { ...base, settings: s15 };
    expect(isSlotFree(inp, at('06:00'))).toBe(true);
    expect(isSlotFree(inp, at('06:05'))).toBe(false); // off-grid
    expect(isSlotFree(inp, at('18:00'))).toBe(false); // too late to fit
    expect(isSlotFree({ ...inp, google: [{ start: at('06:00'), end: at('07:00') }] }, at('06:00'))).toBe(false);
    expect(isSlotFree(inp, '2026-11-01T04:00:00.000Z')).toBe(false); // Sunday
  });
});

describe('date helpers (exact)', () => {
  it('formatLocalDate accepts a day key or an instant and names weekday and month', () => {
    expect(formatLocalDate('2026-11-09')).toBe('Mon 9 Nov');
    expect(formatLocalDate('2026-01-01')).toBe('Thu 1 Jan');
    expect(formatLocalDate('2026-12-31')).toBe('Thu 31 Dec');
    expect(formatLocalDate('2026-12-31T22:00:00.000Z')).toBe('Fri 1 Jan');
    expect(formatLocalDate('2026-02-01')).toBe('Sun 1 Feb');
    expect(formatLocalDate('2026-06-14')).toBe('Sun 14 Jun');
  });
  it('weekdayOf, startOfWeek, daysBetween', () => {
    expect(weekdayOf('2026-11-08')).toBe(0);
    expect(weekdayOf('2026-11-14')).toBe(6);
    expect(startOfWeek('2026-11-09')).toBe('2026-11-09');
    expect(startOfWeek('2026-11-15')).toBe('2026-11-09');
    expect(startOfWeek('2026-11-14')).toBe('2026-11-09');
    expect(startOfWeek('2026-12-31')).toBe('2026-12-28');
    expect(daysBetween('2026-11-09', '2026-11-12')).toBe(3);
    expect(daysBetween('2026-11-12', '2026-11-09')).toBe(-3);
    expect(daysBetween('2024-02-28', '2024-03-01')).toBe(2);
    expect(addDays('2026-03-01', -1)).toBe('2026-02-28');
    expect(addDays('2024-03-01', -1)).toBe('2024-02-29');
    expect(addDays('2026-11-09', 0)).toBe('2026-11-09');
  });
  it('localDayKeyMs and localToUtc handle strings with seconds, a space, and reject garbage', () => {
    expect(localDayKeyMs(Date.parse('2026-11-08T22:00:00.000Z'))).toBe('2026-11-09');
    expect(localDayKeyMs(Date.parse('2026-11-08T21:59:59.999Z'))).toBe('2026-11-08');
    expect(localToUtc('2026-11-09 07:30')).toBe('2026-11-09T05:30:00.000Z');
    expect(localToUtc('2026-11-09T07:30:45')).toBe('2026-11-09T05:30:00.000Z');
    for (const bad of ['', 'x', 'x2026-11-09T07:30', '2026-11-09', '2026-11-09T7:30', '26-11-09T07:30']) expect(() => localToUtc(bad), bad).toThrow('Bad local datetime');
    expect(localToUtc('0026-11-09T07:30')).toBe('0026-11-09T05:30:00.000Z');
    expect(() => localToUtc('2026-11-09T24:00')).toThrow();
    expect(() => localToUtc('2026-11-09T23:60')).toThrow();
    expect(localToUtc('2026-11-09T23:59')).toBe('2026-11-09T21:59:00.000Z');
    expect(() => localToUtc('2026-11-32T10:00')).toThrow();
    expect(() => localToUtc('2026-04-31T10:00')).toThrow();
    expect(localToUtc('2026-04-30T10:00')).toBe('2026-04-30T08:00:00.000Z');
  });
});
