// Time-range helpers for the owner calendar and the "next 7 days" bars.
import type { Slot } from '../../domain/types';

export interface Range {
  s: number; // ms
  e: number;
}

/** Merge overlapping or touching ranges into sorted, disjoint ones. */
export function mergeRanges(rs: Range[]): Range[] {
  const sorted = [...rs].sort((a, b) => a.s - b.s);
  const out: Range[] = [];
  for (const r of sorted) {
    const last = out[out.length - 1];
    if (last && r.s <= last.e) last.e = Math.max(last.e, r.e);
    else out.push({ ...r });
  }
  return out;
}

/** Remove every blocker from every range, so the result never overlaps a blocker. */
export function subtractRanges(rs: Range[], blockers: Range[]): Range[] {
  let out = rs;
  for (const b of mergeRanges(blockers)) {
    const next: Range[] = [];
    for (const r of out) {
      if (b.e <= r.s || b.s >= r.e) next.push(r);
      else {
        if (b.s > r.s) next.push({ s: r.s, e: b.s });
        if (b.e < r.e) next.push({ s: b.e, e: r.e });
      }
    }
    out = next;
  }
  return out;
}

export const slotRanges = (slots: Slot[]): Range[] =>
  mergeRanges(slots.map((s) => ({ s: new Date(s.start).getTime(), e: new Date(s.end).getTime() })));

export const hoursOf = (rs: Range[]) => rs.reduce((n, r) => n + (r.e - r.s), 0) / 3600000;

/** "3h", "2.5h", "0h". */
export const fmtHours = (h: number) => `${Math.round(h * 10) / 10}h`;
