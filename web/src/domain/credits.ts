import type { LedgerEntry } from './types';

/** Balance is always the sum of the ledger. Never stored. */
export function balanceOf(ledger: Pick<LedgerEntry, 'delta'>[]): number {
  return ledger.reduce((sum, e) => sum + e.delta, 0);
}

/** Refund the session if the cancellation is more than `cancelFreeHours` before the start. */
export function qualifiesForRefund(startIso: string, nowMs: number, cancelFreeHours: number): boolean {
  const hoursBefore = (new Date(startIso).getTime() - nowMs) / 3_600_000;
  return hoursBefore > cancelFreeHours;
}
