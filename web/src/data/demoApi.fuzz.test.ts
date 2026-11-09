// Model-based test of demoApi: random sequences of user actions against a small model.
import { afterAll, beforeEach, describe, expect, it, vi } from 'vitest';
import fc from 'fast-check';
import { runs } from '../domain/fuzz.setup';
import { demoApi, demoStore, demoTotpCode, demoTotpSecondsLeft } from './demoApi';
import { ApiError, type Booking } from '../domain/types';
import { addDays, localDayKeyMs } from '../domain/slots';

const NOW = Date.parse('2026-11-09T08:00:00.000Z'); // Mon 10:00 local
const H = 3_600_000;
const PW = 'stretch123';

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.setSystemTime(NOW);
});
afterAll(() => vi.useRealTimers());

type Op =
  | { k: 'signup'; n: number }
  | { k: 'login'; who: number }
  | { k: 'logout' }
  | { k: 'owner' }
  | { k: 'book'; pick: number }
  | { k: 'cancel'; pick: number }
  | { k: 'pack'; pkg: number }
  | { k: 'adjust'; who: number; delta: number }
  | { k: 'markPaid'; pick: number }
  | { k: 'adminCancel'; pick: number; refund: boolean }
  | { k: 'adminBook'; who: number; pick: number }
  | { k: 'time'; hours: number }
  | { k: 'peek'; who: number };

const opArb: fc.Arbitrary<Op> = fc.oneof(
  { weight: 2, arbitrary: fc.nat(999).map((n) => ({ k: 'signup' as const, n })) },
  { weight: 3, arbitrary: fc.nat(99).map((who) => ({ k: 'login' as const, who })) },
  { weight: 1, arbitrary: fc.constant({ k: 'logout' as const }) },
  { weight: 2, arbitrary: fc.constant({ k: 'owner' as const }) },
  { weight: 5, arbitrary: fc.nat(999).map((pick) => ({ k: 'book' as const, pick })) },
  { weight: 3, arbitrary: fc.nat(99).map((pick) => ({ k: 'cancel' as const, pick })) },
  { weight: 2, arbitrary: fc.nat(2).map((pkg) => ({ k: 'pack' as const, pkg })) },
  { weight: 3, arbitrary: fc.record({ k: fc.constant('adjust' as const), who: fc.nat(99), delta: fc.integer({ min: -6, max: 6 }) }) },
  { weight: 2, arbitrary: fc.nat(99).map((pick) => ({ k: 'markPaid' as const, pick })) },
  { weight: 2, arbitrary: fc.record({ k: fc.constant('adminCancel' as const), pick: fc.nat(99), refund: fc.boolean() }) },
  { weight: 2, arbitrary: fc.record({ k: fc.constant('adminBook' as const), who: fc.nat(99), pick: fc.nat(999) }) },
  { weight: 2, arbitrary: fc.oneof(fc.constantFrom(0, 1, 23, 24, 25, 47), fc.integer({ min: 0, max: 80 })).map((hours) => ({ k: 'time' as const, hours })) },
  { weight: 1, arbitrary: fc.nat(99).map((who) => ({ k: 'peek' as const, who })) },
);

async function expectApiError(p: Promise<unknown>, code?: ApiError['code']) {
  try {
    await p;
  } catch (e) {
    expect(e).toBeInstanceOf(ApiError);
    if (code) expect((e as ApiError).code).toBe(code);
    return;
  }
  throw new Error(`expected ApiError${code ? ' ' + code : ''} but call succeeded`);
}

describe('fuzz: demoApi state machine', () => {
  it('keeps balances, bookings, refunds and client isolation consistent', async () => {
    await fc.assert(
      fc.asyncProperty(fc.array(opArb, { minLength: 1, maxLength: 40 }), async (ops) => {
        vi.setSystemTime(NOW);
        await demoApi.demoReset!();
        const st = () => demoStore.getState();
        const settings = st().settings;
        // model
        const pw = new Map<string, string>(); // email -> password
        const balance = new Map<string, number>();
        const emails: string[] = [];
        for (const u of st().users) {
          pw.set(u.email, PW);
          if (u.role === 'client') {
            balance.set(u.id, demoStore.balance(u.id));
            emails.push(u.email);
          }
        }
        let session: { id: string; role: 'client' | 'owner' } | null = null;
        const clientIds = () => [...balance.keys()];
        const pickOf = <T,>(arr: T[], i: number) => arr[i % arr.length];
        let refundRows = 0; // refund ledger rows the model expects to have been written

        const loginAs = async (email: string, password: string) => {
          const r = await demoApi.login(email, password);
          if (r.needsTotp) await demoApi.verifyTotp(demoTotpCode(Date.now()));
        };
        const refreshSession = async () => {
          const me = await demoApi.me();
          session = me ? { id: me.id, role: me.role } : null;
        };
        const confirmed = (): Booking[] => st().bookings.filter((b) => b.status === 'confirmed');
        const slots = async () => demoApi.availability(localDayKeyMs(Date.now()), addDays(localDayKeyMs(Date.now()), 30));
        const refundCount = () => st().ledger.filter((l) => l.reason === 'cancel_refund').length;
        const baseRefunds = refundCount();

        for (const op of ops) {
          switch (op.k) {
            case 'signup': {
              const email = `fuzz${op.n}@example.com`;
              const exists = pw.has(email);
              const p = demoApi.signup({ name: `Fuzz ${op.n}`, email: email.toUpperCase(), password: 'longenough-pw' });
              if (exists) await expectApiError(p, 'conflict');
              else {
                const u = await p;
                pw.set(email, 'longenough-pw');
                balance.set(u.id, 0);
                emails.push(email);
                session = { id: u.id, role: 'client' };
              }
              break;
            }
            case 'login': {
              const email = pickOf(emails, op.who);
              await demoApi.logout();
              await expectApiError(demoApi.login(email, 'wrong-password-123'), 'unauthorized');
              await loginAs(email, pw.get(email)!);
              await refreshSession();
              expect(session?.role).toBe('client');
              break;
            }
            case 'logout':
              await demoApi.logout();
              session = null;
              expect(await demoApi.me()).toBeNull();
              break;
            case 'owner':
              await demoApi.logout();
              await loginAs('ivan@example.com', PW);
              await refreshSession();
              expect(session?.role).toBe('owner');
              break;
            case 'book': {
              if (session?.role !== 'client') {
                await expectApiError(demoApi.book(new Date(Date.now() + 50 * H).toISOString()));
                break;
              }
              const free = await slots();
              if (!free.length) break;
              const s = pickOf(free, op.pick);
              const bal = balance.get(session.id)!;
              const p = demoApi.book(s.start);
              if (bal < 1) await expectApiError(p, 'no_credits');
              else {
                const b = await p;
                expect(b.userId).toBe(session.id);
                expect(b.start).toBe(s.start);
                expect(b.status).toBe('confirmed');
                balance.set(session.id, bal - 1);
              }
              break;
            }
            case 'cancel': {
              if (session?.role !== 'client') break;
              const mine = st().bookings.filter((b) => b.userId === session!.id && b.status === 'confirmed');
              const others = st().bookings.filter((b) => b.userId !== session!.id);
              if (others.length) await expectApiError(demoApi.cancelBooking(others[op.pick % others.length].id), 'not_found');
              if (!mine.length) break;
              const b = pickOf(mine, op.pick);
              const expectRefund = Date.parse(b.start) - Date.now() > settings.cancelFreeHours * H; // strictly more than 24 h
              const r = await demoApi.cancelBooking(b.id);
              expect(r.refunded).toBe(expectRefund);
              expect(r.booking.status).toBe('cancelled');
              if (expectRefund) { balance.set(session.id, balance.get(session.id)! + 1); refundRows++; }
              await expectApiError(demoApi.cancelBooking(b.id), 'conflict'); // never refund twice
              break;
            }
            case 'pack': {
              if (session?.role !== 'client') break;
              const pkg = st().packages[op.pkg];
              const pr = await demoApi.requestPack(pkg.id);
              expect(pr.status).toBe('pending');
              expect(pr.sessions).toBe(pkg.sessions);
              expect(demoStore.balance(session.id)).toBe(balance.get(session.id)); // a request alone adds nothing
              break;
            }
            case 'adjust': {
              const target = pickOf(clientIds(), op.who);
              if (session?.role !== 'owner') {
                if (session) await expectApiError(demoApi.adminAdjustCredits(target, op.delta, 'x'), 'forbidden');
                break;
              }
              const p = demoApi.adminAdjustCredits(target, op.delta, 'fuzz');
              if (op.delta === 0 || balance.get(target)! + op.delta < 0) await expectApiError(p, 'validation');
              else { await p; balance.set(target, balance.get(target)! + op.delta); }
              break;
            }
            case 'markPaid': {
              const prs = st().packRequests;
              if (!prs.length) break;
              const pr = pickOf(prs, op.pick);
              if (session?.role !== 'owner') {
                if (session) await expectApiError(demoApi.adminMarkPackPaid(pr.id), 'forbidden');
                break;
              }
              const wasPending = pr.status === 'pending';
              const sessions = pr.sessions;
              const userId = pr.userId;
              const p = demoApi.adminMarkPackPaid(pr.id, 'EFT-1');
              if (!wasPending) await expectApiError(p, 'conflict');
              else { await p; balance.set(userId, (balance.get(userId) ?? 0) + sessions); }
              break;
            }
            case 'adminCancel': {
              const all = st().bookings;
              if (!all.length) break;
              const b = pickOf(all, op.pick);
              if (session?.role !== 'owner') {
                if (session) await expectApiError(demoApi.adminCancelBooking(b.id, op.refund, 'x'), 'forbidden');
                break;
              }
              const wasConfirmed = b.status === 'confirmed';
              const p = demoApi.adminCancelBooking(b.id, op.refund, 'owner cancel');
              if (!wasConfirmed) await expectApiError(p, 'conflict');
              else {
                await p;
                if (op.refund) { balance.set(b.userId, (balance.get(b.userId) ?? 0) + 1); refundRows++; }
              }
              break;
            }
            case 'adminBook': {
              if (session?.role !== 'owner') break;
              const target = pickOf(clientIds(), op.who);
              const free = demoStore.freeSlots(localDayKeyMs(Date.now()), addDays(localDayKeyMs(Date.now()), 30), { skipNotice: true });
              if (!free.length) break;
              const p = demoApi.adminBookFor(target, pickOf(free, op.pick).start);
              if (balance.get(target)! < 1) await expectApiError(p, 'no_credits');
              else { await p; balance.set(target, balance.get(target)! - 1); }
              break;
            }
            case 'time':
              vi.setSystemTime(Date.now() + op.hours * H);
              break;
            case 'peek': {
              // Client isolation: whatever a client can read is theirs alone.
              if (session?.role === 'client') {
                const b = await demoApi.myBookings();
                const l = await demoApi.myLedger();
                const pr = await demoApi.myPackRequests();
                expect(b.every((x) => x.userId === session!.id)).toBe(true);
                expect(l.every((x) => x.userId === session!.id)).toBe(true);
                expect(pr.every((x) => x.userId === session!.id)).toBe(true);
                expect(await demoApi.myBalance()).toBe(balance.get(session.id));
                await expectApiError(demoApi.adminClients(), 'forbidden');
                await expectApiError(demoApi.adminBookings('2026-01-01', '2027-12-31'), 'forbidden');
                await expectApiError(demoApi.demoCallTool!('get_schedule', {}), 'forbidden');
                expect(JSON.stringify(await demoApi.me())).not.toMatch(/passwordHash/);
              }
              break;
            }
          }
          // ---- global invariants after every step
          for (const [id, bal] of balance) {
            expect(demoStore.balance(id), `balance of ${id} after ${JSON.stringify(op)}`).toBe(bal);
            expect(bal).toBeGreaterThanOrEqual(0);
          }
          const c = confirmed().sort((a, b) => a.start.localeCompare(b.start));
          for (let i = 1; i < c.length; i++) {
            expect(Date.parse(c[i].start), `bookings ${c[i - 1].id}/${c[i].id} overlap`).toBeGreaterThanOrEqual(Date.parse(c[i - 1].end) + settings.bufferMinutes * 60000);
          }
          expect(refundCount() - baseRefunds).toBe(refundRows);
        }
      }),
      runs(60),
    );
  });
});

describe('fuzz: demo authenticator', () => {
  it('codes are always six digits and stable inside a 30 s window', () => {
    fc.assert(
      fc.property(fc.integer({ min: 0, max: Date.UTC(2100, 0, 1) }), (ms) => {
        expect(demoTotpCode(ms)).toMatch(/^\d{6}$/);
        const w = ms - (ms % 30000);
        expect(demoTotpCode(w)).toBe(demoTotpCode(w + 29999));
        expect(demoTotpSecondsLeft(ms)).toBeGreaterThanOrEqual(1);
        expect(demoTotpSecondsLeft(ms)).toBeLessThanOrEqual(30);
      }),
      runs(2000),
    );
  });
});
