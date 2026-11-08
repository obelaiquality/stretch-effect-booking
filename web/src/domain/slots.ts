// Pure availability logic. Local time is Africa/Johannesburg = UTC+2 (no DST).
// The offset maths is explicit so results never depend on the machine timezone.
import type { Settings, Slot } from './types';

export const TZ_OFFSET_MIN = 120;
const MIN = 60_000;
const DAY = 86_400_000;

export interface Interval {
  start: string; // UTC ISO
  end: string; // UTC ISO
}

const pad = (n: number) => String(n).padStart(2, '0');

/** Shift a UTC instant so that its getUTC* fields read as local wall-clock fields. */
function toLocalFields(ms: number): Date {
  return new Date(ms + TZ_OFFSET_MIN * MIN);
}

/** "2026-11-09" for the local calendar day of a UTC ISO instant. */
export function localDayKey(utcIso: string): string {
  const d = toLocalFields(new Date(utcIso).getTime());
  return `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}`;
}

/** Local date key for a millisecond timestamp. */
export function localDayKeyMs(ms: number): string {
  return localDayKey(new Date(ms).toISOString());
}

/** "06:30" local time of a UTC ISO instant. */
export function formatLocalTime(utcIso: string): string {
  const d = toLocalFields(new Date(utcIso).getTime());
  return `${pad(d.getUTCHours())}:${pad(d.getUTCMinutes())}`;
}

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/** "Tue 11 Nov" local date of a UTC ISO instant (or of a YYYY-MM-DD key). */
export function formatLocalDate(input: string): string {
  const key = input.length === 10 ? input : localDayKey(input);
  const [y, m, d] = key.split('-').map(Number);
  const wd = new Date(Date.UTC(y, m - 1, d)).getUTCDay();
  return `${WEEKDAYS[wd]} ${d} ${MONTHS[m - 1]}`;
}

/** "Tue 11 Nov · 07:30" */
export function formatLocalDateTime(utcIso: string): string {
  return `${formatLocalDate(utcIso)} · ${formatLocalTime(utcIso)}`;
}

/** Local "YYYY-MM-DDTHH:MM" (or with :SS) to a UTC ISO string. */
export function localToUtc(local: string): string {
  const m = /^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/.exec(local);
  if (!m) throw new Error(`Bad local datetime: ${local}`);
  const [, y, mo, d, h, mi] = m.map(Number) as unknown as number[];
  const ms = Date.UTC(y, mo - 1, d, h, mi) - TZ_OFFSET_MIN * MIN;
  return new Date(ms).toISOString();
}

/** Add days to a YYYY-MM-DD key. */
export function addDays(dateKey: string, days: number): string {
  const [y, m, d] = dateKey.split('-').map(Number);
  const t = new Date(Date.UTC(y, m - 1, d) + days * DAY);
  return `${t.getUTCFullYear()}-${pad(t.getUTCMonth() + 1)}-${pad(t.getUTCDate())}`;
}

/** 0 = Sunday ... 6 = Saturday for a YYYY-MM-DD key. */
export function weekdayOf(dateKey: string): number {
  const [y, m, d] = dateKey.split('-').map(Number);
  return new Date(Date.UTC(y, m - 1, d)).getUTCDay();
}

/** Monday of the week containing the date key. */
export function startOfWeek(dateKey: string): string {
  const wd = weekdayOf(dateKey);
  return addDays(dateKey, -((wd + 6) % 7));
}

export function daysBetween(a: string, b: string): number {
  const [y1, m1, d1] = a.split('-').map(Number);
  const [y2, m2, d2] = b.split('-').map(Number);
  return Math.round((Date.UTC(y2, m2 - 1, d2) - Date.UTC(y1, m1 - 1, d1)) / DAY);
}

const hm = (s: string) => {
  const [h, m] = s.split(':').map(Number);
  return h * 60 + m;
};

export interface ComputeInput {
  settings: Settings;
  fromDate: string; // local YYYY-MM-DD inclusive
  toDate: string; // local YYYY-MM-DD inclusive
  nowMs: number;
  /** Google Calendar busy blocks, used as they are. */
  google: Interval[];
  /** Confirmed bookings; each is padded by bufferMinutes after it. */
  bookings: Interval[];
  /** Owner-side booking: ignore the minimum notice. */
  skipNotice?: boolean;
}

/** Free session start times between two local dates. */
export function computeSlots(inp: ComputeInput): Slot[] {
  const { settings: s, nowMs } = inp;
  const busy: Array<[number, number]> = [];
  for (const g of inp.google) busy.push([new Date(g.start).getTime(), new Date(g.end).getTime()]);
  for (const b of inp.bookings) busy.push([new Date(b.start).getTime(), new Date(b.end).getTime() + s.bufferMinutes * MIN]);

  const today = localDayKeyMs(nowMs);
  const lastDay = addDays(today, s.bookingWindowDays);
  const earliest = inp.skipNotice ? nowMs : nowMs + s.minNoticeHours * 60 * MIN;
  const out: Slot[] = [];

  let day = inp.fromDate < today ? today : inp.fromDate;
  const end = inp.toDate > lastDay ? lastDay : inp.toDate;
  for (; day <= end; day = addDays(day, 1)) {
    const wd = s.workingHours.find((w) => w.weekday === weekdayOf(day));
    if (!wd || !wd.open) continue;
    const openMin = hm(wd.start);
    const closeMin = hm(wd.end);
    const [y, mo, d] = day.split('-').map(Number);
    const dayStartUtc = Date.UTC(y, mo - 1, d) - TZ_OFFSET_MIN * MIN;
    for (let t = openMin; t + s.sessionMinutes + s.bufferMinutes <= closeMin; t += s.slotStepMinutes) {
      const a = dayStartUtc + t * MIN;
      const b = a + s.sessionMinutes * MIN;
      if (a < earliest) continue;
      const blockEnd = b + s.bufferMinutes * MIN; // session plus buffer must be clear
      if (busy.some(([bs, be]) => a < be && blockEnd > bs)) continue;
      out.push({ start: new Date(a).toISOString(), end: new Date(b).toISOString() });
    }
  }
  return out;
}

/** True when `startIso` is one of the free slots for its day. */
export function isSlotFree(inp: Omit<ComputeInput, 'fromDate' | 'toDate'>, startIso: string): boolean {
  const day = localDayKey(startIso);
  const t = new Date(startIso).getTime();
  return computeSlots({ ...inp, fromDate: day, toDate: day }).some((sl) => new Date(sl.start).getTime() === t);
}
