import { describe, expect, it } from 'vitest';
import fc from 'fast-check';
import { runs } from './fuzz.setup';
import {
  addDays, computeSlots, daysBetween, formatLocalDate, formatLocalDateTime, formatLocalTime, isSlotFree,
  localDayKey, localDayKeyMs, localToUtc, startOfWeek, weekdayOf, TZ_OFFSET_MIN,
  type Interval,
} from './slots';
import type { Settings, WorkingDay } from './types';

const MIN = 60_000;
const hhmm = (m: number) => `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`;
const hm = (s: string) => Number(s.slice(0, 2)) * 60 + Number(s.slice(3));

const workingDay = (weekday: number): fc.Arbitrary<WorkingDay> =>
  fc.record({
    open: fc.boolean(),
    a: fc.integer({ min: 0, max: 22 * 60 }),
    len: fc.integer({ min: 30, max: 14 * 60 }),
  }).map(({ open, a, len }) => ({ weekday, open, start: hhmm(a), end: hhmm(Math.min(a + len, 24 * 60 - 1)) }));

const settingsArb: fc.Arbitrary<Settings> = fc.record({
  sessionMinutes: fc.integer({ min: 30, max: 120 }),
  bufferMinutes: fc.integer({ min: 0, max: 30 }),
  slotStepMinutes: fc.constantFrom(15, 30, 60),
  minNoticeHours: fc.integer({ min: 0, max: 48 }),
  bookingWindowDays: fc.integer({ min: 1, max: 60 }),
  workingHours: fc.tuple(...[0, 1, 2, 3, 4, 5, 6].map(workingDay)),
}).map((r) => ({ timezone: 'Africa/Johannesburg' as const, cancelFreeHours: 24, ...r, workingHours: r.workingHours as WorkingDay[] }));

const T0 = Date.UTC(2024, 0, 1);
const T1 = Date.UTC(2029, 0, 1);
// Biased towards month/year ends and leap days
const nowArb = fc.oneof(
  fc.integer({ min: T0, max: T1 }),
  fc.tuple(fc.constantFrom(2024, 2025, 2026, 2027, 2028), fc.constantFrom(0, 1, 2, 11), fc.constantFrom(1, 28, 29, 30, 31), fc.integer({ min: 0, max: 47 })).map(
    ([y, m, d, h]) => Date.UTC(y, m, d, 0, 0) + h * 30 * MIN,
  ),
);

const intervalsNear = (now: number): fc.Arbitrary<Interval[]> =>
  fc.array(
    fc.record({ off: fc.integer({ min: -2 * 86400, max: 70 * 86400 }), len: fc.integer({ min: 1, max: 6 * 3600 }) }).map(({ off, len }) => {
      const s = now + off * 1000;
      return { start: new Date(s).toISOString(), end: new Date(s + len * 1000).toISOString() };
    }),
    { maxLength: 25 },
  );

const caseArb = fc
  .tuple(settingsArb, nowArb, fc.boolean())
  .chain(([settings, now, skipNotice]) =>
    fc.record({
      settings: fc.constant(settings),
      now: fc.constant(now),
      skipNotice: fc.constant(skipNotice),
      google: intervalsNear(now),
      bookings: intervalsNear(now),
      fromOff: fc.integer({ min: -3, max: 70 }),
      span: fc.integer({ min: 0, max: 70 }),
    }),
  );

describe('fuzz: computeSlots invariants', () => {
  it('holds for random settings, busy blocks, bookings and now', () => {
    fc.assert(
      fc.property(caseArb, (c) => {
        const s = c.settings;
        const today = localDayKeyMs(c.now);
        const fromDate = addDays(today, c.fromOff);
        const toDate = addDays(fromDate, c.span);
        const inp = { settings: s, nowMs: c.now, google: c.google, bookings: c.bookings, skipNotice: c.skipNotice };
        const slots = computeSlots({ ...inp, fromDate, toDate });
        const earliest = c.skipNotice ? c.now : c.now + s.minNoticeHours * 60 * MIN;
        const lastDay = addDays(today, s.bookingWindowDays);
        let prev = -Infinity;
        for (const sl of slots) {
          const a = Date.parse(sl.start);
          const b = Date.parse(sl.end);
          // sorted, unique
          expect(a).toBeGreaterThan(prev);
          prev = a;
          // exact length
          expect(b - a).toBe(s.sessionMinutes * MIN);
          // iso format canonical
          expect(new Date(a).toISOString()).toBe(sl.start);
          // inside the requested range, today and the window
          const day = localDayKey(sl.start);
          expect(day >= fromDate && day <= toDate).toBe(true);
          expect(day >= today && day <= lastDay).toBe(true);
          // working hours
          const wd = s.workingHours.find((w) => w.weekday === weekdayOf(day))!;
          expect(wd.open).toBe(true);
          const startMin = hm(formatLocalTime(sl.start));
          expect(startMin).toBeGreaterThanOrEqual(hm(wd.start));
          expect(startMin + s.sessionMinutes + s.bufferMinutes).toBeLessThanOrEqual(hm(wd.end));
          expect((startMin - hm(wd.start)) % s.slotStepMinutes).toBe(0);
          // notice
          expect(a).toBeGreaterThanOrEqual(earliest);
          // busy blocks: session plus buffer must be clear
          const blockEnd = b + s.bufferMinutes * MIN;
          for (const g of c.google) expect(a < Date.parse(g.end) && blockEnd > Date.parse(g.start)).toBe(false);
          for (const bk of c.bookings) expect(a < Date.parse(bk.end) + s.bufferMinutes * MIN && blockEnd > Date.parse(bk.start)).toBe(false);
        }
        // isSlotFree agrees with membership for every returned slot
        for (const sl of slots.slice(0, 5)) expect(isSlotFree(inp, sl.start)).toBe(true);
      }),
      runs(400),
    );
  });

  it('is complete: every on-grid candidate that satisfies the rules is returned', () => {
    fc.assert(
      fc.property(caseArb, (c) => {
        const s = c.settings;
        const today = localDayKeyMs(c.now);
        const fromDate = addDays(today, Math.max(0, c.fromOff % 10));
        const toDate = addDays(fromDate, Math.min(c.span, 6));
        const got = new Set(computeSlots({ settings: s, nowMs: c.now, google: c.google, bookings: c.bookings, skipNotice: c.skipNotice, fromDate, toDate }).map((x) => x.start));
        const earliest = c.skipNotice ? c.now : c.now + s.minNoticeHours * 60 * MIN;
        const lastDay = addDays(today, s.bookingWindowDays);
        for (let day = fromDate; day <= toDate && day <= lastDay; day = addDays(day, 1)) {
          const wd = s.workingHours.find((w) => w.weekday === weekdayOf(day))!;
          if (!wd.open) continue;
          for (let t = hm(wd.start); t + s.sessionMinutes + s.bufferMinutes <= hm(wd.end); t += s.slotStepMinutes) {
            const a = Date.parse(localToUtc(`${day}T${hhmm(t)}`));
            const blockEnd = a + (s.sessionMinutes + s.bufferMinutes) * MIN;
            const blocked =
              a < earliest ||
              c.google.some((g) => a < Date.parse(g.end) && blockEnd > Date.parse(g.start)) ||
              c.bookings.some((g) => a < Date.parse(g.end) + s.bufferMinutes * MIN && blockEnd > Date.parse(g.start));
            expect(got.has(new Date(a).toISOString())).toBe(!blocked);
          }
        }
      }),
      runs(250),
    );
  });
});

describe('fuzz: local/UTC conversion and date helpers', () => {
  const localArb = fc
    .tuple(
      fc.oneof(fc.integer({ min: 1971, max: 2099 }), fc.constantFrom(2024, 2028, 2100, 1999, 2000)),
      fc.integer({ min: 1, max: 12 }),
      fc.integer({ min: 0, max: 3 }),
      fc.integer({ min: 0, max: 23 }),
      fc.integer({ min: 0, max: 59 }),
    )
    .map(([y, m, dOff, h, mi]) => {
      const dim = new Date(Date.UTC(y, m, 0)).getUTCDate();
      const d = dOff === 0 ? 1 : dOff === 1 ? dim : dOff === 2 ? Math.max(1, dim - 1) : 15;
      const p = (n: number, w = 2) => String(n).padStart(w, '0');
      return { key: `${p(y, 4)}-${p(m)}-${p(d)}`, time: `${p(h)}:${p(mi)}`, y, m, d, h, mi };
    });

  it('localToUtc / localDayKey / formatLocalTime round-trip', () => {
    fc.assert(
      fc.property(localArb, (l) => {
        const utc = localToUtc(`${l.key}T${l.time}`);
        expect(Date.parse(utc)).toBe(Date.UTC(l.y, l.m - 1, l.d, l.h, l.mi) - TZ_OFFSET_MIN * MIN);
        expect(localDayKey(utc)).toBe(l.key);
        expect(formatLocalTime(utc)).toBe(l.time);
        expect(localToUtc(`${l.key} ${l.time}:59`)).toBe(utc);
        expect(localDayKeyMs(Date.parse(utc))).toBe(l.key);
        expect(formatLocalDateTime(utc)).toBe(`${formatLocalDate(utc)} · ${l.time}`);
        expect(formatLocalDate(utc)).toBe(formatLocalDate(l.key));
      }),
      runs(1000),
    );
  });

  it('UTC instants round-trip through local fields', () => {
    fc.assert(
      fc.property(fc.integer({ min: Date.UTC(1971, 0, 1), max: Date.UTC(2100, 0, 1) }), (ms) => {
        const minuteAligned = Math.floor(ms / MIN) * MIN;
        const iso = new Date(minuteAligned).toISOString();
        expect(Date.parse(localToUtc(`${localDayKey(iso)}T${formatLocalTime(iso)}`))).toBe(minuteAligned);
      }),
      runs(1000),
    );
  });

  it('addDays, daysBetween, weekdayOf and startOfWeek are consistent', () => {
    fc.assert(
      fc.property(localArb, fc.integer({ min: -800, max: 800 }), (l, n) => {
        const k2 = addDays(l.key, n);
        expect(daysBetween(l.key, k2)).toBe(n);
        expect(addDays(k2, -n)).toBe(l.key);
        expect(/^\d{4}-\d{2}-\d{2}$/.test(k2)).toBe(true);
        expect(weekdayOf(addDays(l.key, 7))).toBe(weekdayOf(l.key));
        expect(weekdayOf(addDays(l.key, 1))).toBe((weekdayOf(l.key) + 1) % 7);
        const mon = startOfWeek(l.key);
        expect(weekdayOf(mon)).toBe(1);
        const back = daysBetween(mon, l.key);
        expect(back).toBeGreaterThanOrEqual(0);
        expect(back).toBeLessThanOrEqual(6);
        expect(localDayKey(localToUtc(`${k2}T12:00`))).toBe(k2);
      }),
      runs(1000),
    );
  });

  it('knows leap days and month/year ends', () => {
    expect(addDays('2024-02-28', 1)).toBe('2024-02-29');
    expect(addDays('2024-02-29', 1)).toBe('2024-03-01');
    expect(addDays('2026-02-28', 1)).toBe('2026-03-01');
    expect(addDays('2100-02-28', 1)).toBe('2100-03-01');
    expect(addDays('2026-12-31', 1)).toBe('2027-01-01');
    expect(localDayKey('2026-12-31T22:00:00.000Z')).toBe('2027-01-01');
    expect(localDayKey('2024-02-28T22:30:00.000Z')).toBe('2024-02-29');
    expect(formatLocalTime('2026-12-31T22:00:00.000Z')).toBe('00:00');
    expect(localToUtc('2027-01-01T00:30')).toBe('2026-12-31T22:30:00.000Z');
  });

  it('localToUtc rejects dates that do not exist instead of rolling them over', () => {
    for (const bad of ['2026-02-30T10:00', '2026-13-01T10:00', '2026-11-09T25:00', '2026-11-09T10:60', '2026-00-10T10:00', '2026-04-31T07:00'])
      expect(() => localToUtc(bad), bad).toThrow();
  });
});
