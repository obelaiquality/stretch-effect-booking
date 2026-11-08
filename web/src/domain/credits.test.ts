import { describe, expect, it } from 'vitest';
import { balanceOf, qualifiesForRefund } from './credits';

describe('credits', () => {
  it('sums the ledger', () => {
    expect(balanceOf([{ delta: 5 }, { delta: -1 }, { delta: 1 }, { delta: -2 }])).toBe(3);
    expect(balanceOf([])).toBe(0);
  });
  it('refunds only when more than 24 h before start', () => {
    const now = Date.UTC(2026, 10, 9, 8, 0);
    expect(qualifiesForRefund(new Date(now + 25 * 3_600_000).toISOString(), now, 24)).toBe(true);
    expect(qualifiesForRefund(new Date(now + 24 * 3_600_000).toISOString(), now, 24)).toBe(false);
    expect(qualifiesForRefund(new Date(now + 3_600_000).toISOString(), now, 24)).toBe(false);
  });
});
