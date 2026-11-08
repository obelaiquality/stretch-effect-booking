import { describe, expect, it } from 'vitest';
import { balanceOf } from '../domain/credits';
import { buildSeed } from './seed';

describe('seed', () => {
  const s = buildSeed(Date.UTC(2026, 10, 9, 8, 0));
  it('has balances 0..9 with exactly one at zero and one pending pack', () => {
    const bal = s.users.filter((u) => u.role === 'client').map((u) => balanceOf(s.ledger.filter((l) => l.userId === u.id)));
    expect(bal.length).toBe(12);
    expect(bal.filter((b) => b === 0)).toHaveLength(1);
    expect(Math.max(...bal)).toBeLessThanOrEqual(9);
    expect(Math.min(...bal)).toBe(0);
    expect(s.packRequests.filter((p) => p.status === 'pending')).toHaveLength(1);
  });
  it('has no overlapping confirmed bookings and the demo client has one upcoming', () => {
    const c = s.bookings.filter((b) => b.status === 'confirmed').sort((a, b) => a.start.localeCompare(b.start));
    for (let i = 1; i < c.length; i++) expect(new Date(c[i].start).getTime()).toBeGreaterThanOrEqual(new Date(c[i - 1].end).getTime() + 15 * 60000);
    expect(c.some((b) => b.userId === 'u_thandi')).toBe(true);
    expect(s.bookings.some((b) => b.status === 'no_show')).toBe(true);
    expect(s.bookings.some((b) => b.status === 'cancelled')).toBe(true);
    expect(s.grants).toHaveLength(2);
    expect(s.audit.length).toBeGreaterThanOrEqual(8);
  });
});
