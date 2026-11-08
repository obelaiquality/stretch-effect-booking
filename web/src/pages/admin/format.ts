// Local formatting helpers for the admin pages. All display in Africa/Johannesburg (UTC+2, no DST).
import type { Booking, BusyBlock } from '../../domain/types';

const TZ = 'Africa/Johannesburg';
const OFFSET_MS = 2 * 3600 * 1000;

export const rand = (n: number) => 'R' + Math.round(n).toLocaleString('en-ZA').replace(/\s/g, ',');

const dtf = (o: Intl.DateTimeFormatOptions) => new Intl.DateTimeFormat('en-ZA', { timeZone: TZ, ...o });
const parts = (iso: string, o: Intl.DateTimeFormatOptions) => {
  const m: Record<string, string> = {};
  for (const p of dtf(o).formatToParts(new Date(iso))) m[p.type] = p.value;
  return m;
};

export function fmtTime(iso: string): string {
  const p = parts(iso, { hour: '2-digit', minute: '2-digit', hour12: false });
  return `${p.hour === '24' ? '00' : p.hour}:${p.minute}`;
}
export function fmtDay(iso: string): string {
  const p = parts(iso, { weekday: 'short', day: 'numeric', month: 'short' });
  return `${p.weekday} ${p.day} ${p.month}`;
}
/** "Tue 11 Nov · 07:30" */
export function fmtWhen(iso: string): string {
  return `${fmtDay(iso)} · ${fmtTime(iso)}`;
}
export function fmtDate(iso: string): string {
  const p = parts(iso, { day: 'numeric', month: 'short', year: 'numeric' });
  return `${p.day} ${p.month} ${p.year}`;
}

/** Local day key YYYY-MM-DD for an instant. */
export function dayKey(d: Date | string): string {
  const t = typeof d === 'string' ? new Date(d) : d;
  return new Date(t.getTime() + OFFSET_MS).toISOString().slice(0, 10);
}
export const todayKey = () => dayKey(new Date());
export function addDaysKey(key: string, n: number): string {
  const [y, m, d] = key.split('-').map(Number);
  return new Date(Date.UTC(y, m - 1, d + n)).toISOString().slice(0, 10);
}
/** UTC ISO of local YYYY-MM-DD + HH:MM. */
export function localToUtcIso(key: string, hhmm: string): string {
  const [y, m, d] = key.split('-').map(Number);
  const [h, mi] = hhmm.split(':').map(Number);
  return new Date(Date.UTC(y, m - 1, d, h, mi) - OFFSET_MS).toISOString();
}
/** Minutes since local midnight for an instant. */
export function minutesOfDay(iso: string | Date): number {
  const t = typeof iso === 'string' ? new Date(iso) : iso;
  const l = new Date(t.getTime() + OFFSET_MS);
  return l.getUTCHours() * 60 + l.getUTCMinutes();
}
/** Monday of the local week containing key. */
export function mondayOf(key: string): string {
  const [y, m, d] = key.split('-').map(Number);
  const wd = new Date(Date.UTC(y, m - 1, d)).getUTCDay();
  return addDaysKey(key, -((wd + 6) % 7));
}
export function weekdayOf(key: string): number {
  const [y, m, d] = key.split('-').map(Number);
  return new Date(Date.UTC(y, m - 1, d)).getUTCDay();
}
export const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
export const WEEKDAYS_SHORT = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
export const dayLabel = (key: string) => {
  const [y, m, d] = key.split('-').map(Number);
  return fmtDay(new Date(Date.UTC(y, m - 1, d, 10)).toISOString());
};

export function actorLabel(actor: string, clientId: string): string {
  if (actor.startsWith('chatgpt')) return 'ChatGPT';
  if (actor === clientId) return 'Client';
  if (actor === 'system') return 'System';
  return 'You';
}

export const isLive = (b: Booking) => b.status === 'confirmed' || b.status === 'completed';
export const busyOverlaps = (b: BusyBlock, dayStartIso: string, dayEndIso: string) => b.start < dayEndIso && b.end > dayStartIso;

export const statusLabel: Record<Booking['status'], string> = {
  confirmed: 'Confirmed',
  completed: 'Completed',
  cancelled: 'Cancelled',
  no_show: 'No-show',
};
