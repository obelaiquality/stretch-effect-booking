// Day-key and range helpers for the admin pages. Display formatting lives in lib/format.ts.
// All keys are local days (Africa/Johannesburg, UTC+2, no DST).
import type { Booking, BusyBlock } from '../../domain/types';
import { formatDay } from '../../lib/format';

const OFFSET_MS = 2 * 3600 * 1000;

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
  return formatDay(new Date(Date.UTC(y, m - 1, d, 10)).toISOString());
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
