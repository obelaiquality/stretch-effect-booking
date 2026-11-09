import { describe, expect, it } from 'vitest';
import fc from 'fast-check';
import { runs } from './fuzz.setup';
import { balanceOf, qualifiesForRefund } from './credits';

const H = 3_600_000;

describe('fuzz: credits', () => {
  it('balance equals the sum of deltas, in any order, and for any split', () => {
    fc.assert(
      fc.property(fc.array(fc.integer({ min: -50, max: 50 })), fc.nat(), (deltas, cut) => {
        const l = deltas.map((delta) => ({ delta }));
        const sum = deltas.reduce((a, b) => a + b, 0);
        expect(balanceOf(l)).toBe(sum);
        expect(balanceOf([...l].reverse())).toBe(sum);
        const i = cut % (l.length + 1);
        expect(balanceOf(l.slice(0, i)) + balanceOf(l.slice(i))).toBe(sum);
      }),
      runs(500),
    );
  });

  it('refund rule is exact: strictly more than the free hours before the start', () => {
    fc.assert(
      fc.property(
        fc.integer({ min: Date.UTC(2024, 0, 1), max: Date.UTC(2030, 0, 1) }),
        fc.integer({ min: 0, max: 72 }),
        fc.integer({ min: -3, max: 3 }),
        (now, free, offMs) => {
          const start = new Date(now + free * H + offMs).toISOString();
          expect(qualifiesForRefund(start, now, free)).toBe(offMs > 0);
        },
      ),
      runs(1000),
    );
    const now = Date.UTC(2026, 10, 9, 8, 0);
    expect(qualifiesForRefund(new Date(now + 24 * H + 1).toISOString(), now, 24)).toBe(true);
    expect(qualifiesForRefund(new Date(now + 24 * H).toISOString(), now, 24)).toBe(false);
    expect(qualifiesForRefund(new Date(now + 24 * H - 1).toISOString(), now, 24)).toBe(false);
    expect(qualifiesForRefund(new Date(now - H).toISOString(), now, 0)).toBe(false); // already started
    expect(qualifiesForRefund('not a date', now, 24)).toBe(false);
  });

  it('refund decision is monotonic in time: later cancel never refunds when an earlier one did not', () => {
    fc.assert(
      fc.property(fc.integer({ min: 0, max: 10 * 86400_000 }), fc.integer({ min: 0, max: 10 * 86400_000 }), (t1, t2) => {
        const start = new Date(Date.UTC(2026, 10, 20)).toISOString();
        const base = Date.UTC(2026, 10, 20) - 10 * 86400_000;
        const [early, late] = t1 <= t2 ? [t1, t2] : [t2, t1];
        if (qualifiesForRefund(start, base + late, 24)) expect(qualifiesForRefund(start, base + early, 24)).toBe(true);
      }),
      runs(500),
    );
  });

  it('guarded ledger sequences never go negative', () => {
    type Op = { k: 'buy'; n: number } | { k: 'book' } | { k: 'cancel'; refund: boolean } | { k: 'adjust'; n: number };
    const op: fc.Arbitrary<Op> = fc.oneof(
      fc.integer({ min: 1, max: 20 }).map((n) => ({ k: 'buy' as const, n })),
      fc.constant({ k: 'book' as const }),
      fc.boolean().map((refund) => ({ k: 'cancel' as const, refund })),
      fc.integer({ min: -20, max: 20 }).map((n) => ({ k: 'adjust' as const, n })),
    );
    fc.assert(
      fc.property(fc.array(op, { maxLength: 80 }), (ops) => {
        const ledger: { delta: number }[] = [];
        let open = 0;
        for (const o of ops) {
          const bal = balanceOf(ledger);
          if (o.k === 'buy') ledger.push({ delta: o.n });
          else if (o.k === 'book' && bal >= 1) { ledger.push({ delta: -1 }); open++; }
          else if (o.k === 'cancel' && open > 0) { open--; if (o.refund) ledger.push({ delta: 1 }); }
          else if (o.k === 'adjust' && o.n !== 0 && bal + o.n >= 0) ledger.push({ delta: o.n });
          expect(balanceOf(ledger)).toBeGreaterThanOrEqual(0);
        }
      }),
      runs(300),
    );
  });
});
