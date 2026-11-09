import { afterAll, beforeEach, describe, expect, it, vi } from 'vitest';
import fc from 'fast-check';
import { runs } from './fuzz.setup';
import { DAILY_CREDIT_CAP, MAX_CREDITS_PER_CALL, TOOLS, runTool, validateArgs } from './mcpTools';
import { demoApi, demoStore } from '../data/demoApi';
import { balanceOf } from './credits';
import { localDayKey } from './slots';

const NOW = Date.parse('2026-11-09T08:00:00.000Z'); // Mon 10:00 local
const PROTO_KEYS = Object.getOwnPropertyNames(Object.prototype).sort().join();
const CLIENTS = ['u_thandi', 'u_pieter', 'u_aisha', 'u_liam', 'u_naledi', 'u_ruan', 'u_megan'];
const snapshot = () => JSON.stringify(demoStore.getState());
const polluted = () =>
  PROTO_KEYS !== Object.getOwnPropertyNames(Object.prototype).sort().join() ||
  ({} as Record<string, unknown>).polluted !== undefined ||
  ({} as Record<string, unknown>).isAdmin !== undefined ||
  ({} as Record<string, unknown>).sessions !== undefined;

beforeEach(async () => {
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.setSystemTime(NOW);
  await demoApi.demoReset!();
});
afterAll(() => vi.useRealTimers());

// ---- argument generators
const evil = [
  '__proto__', 'constructor', 'prototype', 'toString', 'hasOwnProperty', 'valueOf', '__defineGetter__', '',
  'NaN', 'Infinity', '-Infinity', '-0', '1e999', '9'.repeat(400), '\u0000', '‮', '😀', 'Ω'.repeat(300),
  '<script>alert(1)</script>', "'; DROP TABLE users;--", '2026-02-30', '2026-11-09T99:99', '0000-00-00',
];
const evilNumber = fc.constantFrom(NaN, Infinity, -Infinity, -0, 0, -1, 21, 1e21, 1.5, 2 ** 53, Number.MAX_VALUE, -(2 ** 31));
const hostileValue: fc.Arbitrary<unknown> = fc.oneof(
  fc.constantFrom(...evil), evilNumber, fc.string(), fc.string({ minLength: 200, maxLength: 5000 }), fc.integer(), fc.double(),
  fc.boolean(), fc.constant(null), fc.constant(undefined), fc.array(fc.anything(), { maxLength: 3 }), fc.anything(),
  fc.constant(JSON.parse('{"__proto__":{"polluted":true,"isAdmin":true}}')),
  fc.constant(() => 1), fc.constant(Symbol('x')) as fc.Arbitrary<unknown>,
);
const dangerousObj: fc.Arbitrary<Record<string, unknown>> = fc
  .array(fc.tuple(fc.constantFrom('__proto__', 'constructor', 'prototype', 'sessions', 'client_id', 'isAdmin', 'x', 'idempotency_key'), hostileValue), { maxLength: 6 })
  .map((pairs) => {
    // JSON.parse creates a real own "__proto__" key, unlike an object literal assignment.
    const o: Record<string, unknown> = {};
    for (const [k, v] of pairs) Object.defineProperty(o, k, { value: v, enumerable: true, writable: true, configurable: true });
    return o;
  });
const key = fc.stringMatching(/^[A-Za-z0-9_-]{8,64}$/);
const localDt = fc.oneof(
  fc.integer({ min: 0, max: 20 }).chain((d) => fc.tuple(fc.constant(d), fc.integer({ min: 5, max: 19 }), fc.constantFrom('00', '15', '30', '45'))).map(([d, h, m]) => {
    const day = new Date(NOW + (d + 1) * 86400_000);
    return `${day.toISOString().slice(0, 10)}T${String(h).padStart(2, '0')}:${m}`;
  }),
  fc.constantFrom('2026-02-30T10:00', '2026-11-12T25:00', '2026-13-01T10:00', '2026-11-12T10:00', '2026-11-12T07:00:59'),
);
const date = fc.oneof(fc.integer({ min: -3, max: 30 }).map((d) => new Date(NOW + d * 86400_000).toISOString().slice(0, 10)), fc.constantFrom('2026-99-99', '9999-12-31', '0000-01-01', '2026-11-09'));
const validArgs: fc.Arbitrary<[string, Record<string, unknown>]> = fc.oneof(
  fc.tuple(fc.constant('get_schedule'), fc.record({ date }, { requiredKeys: [] })),
  fc.tuple(fc.constant('list_bookings'), fc.record({ from: date, to: date, status: fc.constantFrom('confirmed', 'cancelled', 'completed', 'no_show') }, { requiredKeys: ['from', 'to'] })),
  fc.tuple(fc.constant('find_free_slots'), fc.record({ from: date, to: date })),
  fc.tuple(fc.constant('search_clients'), fc.record({ query: fc.oneof(fc.string({ minLength: 2, maxLength: 60 }), fc.constantFrom('th', 'ai', '  ', '%%', '.*')) })),
  fc.tuple(fc.constant('get_client'), fc.record({ client_id: fc.constantFrom(...CLIENTS, 'u_ivan', 'nope') })),
  fc.tuple(fc.constant('get_business_summary'), fc.record({ period: fc.constantFrom('week', 'month') })),
  fc.tuple(fc.constant('add_session_credits'), fc.record({ client_id: fc.constantFrom(...CLIENTS), sessions: fc.integer({ min: 1, max: 20 }), reason: fc.string({ minLength: 3, maxLength: 40 }), idempotency_key: key, payment_reference: fc.string({ maxLength: 20 }) }, { requiredKeys: ['client_id', 'sessions', 'reason', 'idempotency_key'] })),
  fc.tuple(fc.constant('remove_session_credits'), fc.record({ client_id: fc.constantFrom(...CLIENTS), sessions: fc.integer({ min: 1, max: 20 }), reason: fc.string({ minLength: 3, maxLength: 40 }), idempotency_key: key })),
  fc.tuple(fc.constant('create_booking'), fc.record({ client_id: fc.constantFrom(...CLIENTS), start: localDt, idempotency_key: key })),
  fc.tuple(fc.constant('cancel_booking'), fc.record({ booking_id: fc.string({ minLength: 1, maxLength: 12 }), refund_credit: fc.boolean(), reason: fc.string({ minLength: 3, maxLength: 40 }), idempotency_key: key })),
  fc.tuple(fc.constant('reschedule_booking'), fc.record({ booking_id: fc.string({ minLength: 1, maxLength: 12 }), new_start: localDt, idempotency_key: key })),
);
/** Valid arguments with one field swapped for a hostile value, or an extra field added. */
const mutated = validArgs.chain(([name, args]) =>
  fc.oneof(
    fc.tuple(fc.constant(name), fc.constantFrom(...Object.keys(args), 'extra'), hostileValue).map(([n, k, v]): [string, unknown] => [n, { ...args, [k]: v }]),
    fc.tuple(fc.constant(name), fc.constantFrom('__proto__', 'constructor', 'toString', 'hasOwnProperty', 'x'), hostileValue).map(([n, k, v]): [string, unknown] => {
      const o = { ...args };
      Object.defineProperty(o, k, { value: v, enumerable: true, writable: true, configurable: true });
      return [n, o];
    }),
  ),
);
const toolName = fc.oneof(fc.constantFrom(...TOOLS.map((t) => t.name)), fc.constantFrom(...evil), fc.string({ maxLength: 40 }));
const anyCall: fc.Arbitrary<[string, unknown]> = fc.oneof(
  { weight: 3, arbitrary: validArgs as fc.Arbitrary<[string, unknown]> },
  { weight: 4, arbitrary: mutated },
  { weight: 3, arbitrary: fc.tuple(toolName, fc.oneof(hostileValue, dangerousObj)) },
);

describe('fuzz: runTool against a real DemoStoreAccess', () => {
  it('never throws, changes nothing when ok:false, never pollutes Object.prototype', async () => {
    await fc.assert(
      fc.asyncProperty(fc.array(anyCall, { minLength: 1, maxLength: 12 }), async (calls) => {
        await demoApi.demoReset!();
        for (const [name, args] of calls) {
          const before = snapshot();
          let res;
          try {
            res = await runTool(name, args, demoStore);
          } catch (e) {
            throw new Error(`runTool(${name}) threw: ${String(e)}\nargs=${safe(args)}`);
          }
          expect(typeof res.ok, 'ok is boolean').toBe('boolean');
          expect(typeof res.summary).toBe('string');
          if (!res.ok) {
            expect(typeof res.error).toBe('string');
            expect(snapshot(), `state changed on failed ${name} ${safe(args)}`).toBe(before);
          }
          expect(polluted()).toBe(false);
          // results never carry secrets
          expect(JSON.stringify(res)).not.toMatch(/passwordHash|945d6da3bee7f/);
        }
        // ledger and bookings stay sane
        for (const u of demoStore.getState().users) expect(demoStore.balance(u.id)).toBeGreaterThanOrEqual(0);
        const conf = demoStore.getState().bookings.filter((b) => b.status === 'confirmed').sort((a, b) => a.start.localeCompare(b.start));
        const buf = demoStore.settings().bufferMinutes * 60000;
        for (let i = 1; i < conf.length; i++) expect(Date.parse(conf[i].start)).toBeGreaterThanOrEqual(Date.parse(conf[i - 1].end) + buf);
      }),
      runs(300),
    );
  });

  it('rejects every unknown field, including inherited names like __proto__ and constructor', async () => {
    await fc.assert(
      fc.asyncProperty(
        fc.constantFrom(...TOOLS.map((t) => t.name)),
        fc.constantFrom('__proto__', 'constructor', 'toString', 'hasOwnProperty', 'valueOf', 'isPrototypeOf', 'zzz', 'Date', 'idempotency_keyx'),
        hostileValue,
        async (toolName, field, value) => {
          const tool = TOOLS.find((t) => t.name === toolName)!;
          // Start from all-valid args for this tool.
          const base: Record<string, unknown> = {};
          for (const r of tool.inputSchema.required) base[r] = SAMPLE[r];
          if (field in tool.inputSchema.properties && Object.hasOwn(tool.inputSchema.properties, field)) return;
          const args = { ...base };
          Object.defineProperty(args, field, { value, enumerable: true, writable: true, configurable: true });
          const errs = validateArgs(tool.inputSchema, args);
          expect(errs.join(), `${tool.name}.${field}`).toMatch(/unknown field/);
          const before = snapshot();
          const res = await runTool(tool.name, args, demoStore);
          expect(res.ok).toBe(false);
          expect(res.error).toBe('invalid_params');
          expect(snapshot()).toBe(before);
          expect(polluted()).toBe(false);
        },
      ),
      runs(300),
    );
  });

  it('credit caps: 1-20 per call and 40 a day via ChatGPT, whatever the call order', async () => {
    const creditCall = fc.record({
      add: fc.boolean(),
      client: fc.constantFrom(...CLIENTS),
      sessions: fc.oneof(fc.integer({ min: 1, max: 20 }), fc.integer({ min: -5, max: 60 })),
      key,
      ref: fc.option(fc.stringMatching(/^[A-Z0-9-]{4,12}$/), { nil: undefined }),
    });
    await fc.assert(
      fc.asyncProperty(fc.array(creditCall, { minLength: 1, maxLength: 25 }), async (calls) => {
        await demoApi.demoReset!();
        const day = localDayKey(new Date(NOW).toISOString());
        let moved = 0;
        for (const c of calls) {
          const args: Record<string, unknown> = { client_id: c.client, sessions: c.sessions, reason: 'fuzz run', idempotency_key: c.key };
          if (c.add && c.ref) args.payment_reference = c.ref;
          const before = demoStore.getState().ledger.length;
          const res = await runTool(c.add ? 'add_session_credits' : 'remove_session_credits', args, demoStore);
          const rows = demoStore.getState().ledger.slice(before);
          if (c.sessions < 1 || c.sessions > MAX_CREDITS_PER_CALL) {
            expect(res.ok).toBe(false);
            expect(rows).toHaveLength(0);
          }
          for (const r of rows) {
            expect(Math.abs(r.delta)).toBeGreaterThanOrEqual(1);
            expect(Math.abs(r.delta)).toBeLessThanOrEqual(MAX_CREDITS_PER_CALL);
            expect(r.actor).toBe(demoStore.actor);
          }
          if (!res.ok) expect(rows).toHaveLength(0);
          moved += rows.reduce((n, r) => n + Math.abs(r.delta), 0);
          const today = demoStore.getState().ledger.filter((l) => l.actor.startsWith('chatgpt') && localDayKey(l.createdAt) === day);
          expect(today.reduce((n, l) => n + Math.abs(l.delta), 0)).toBeLessThanOrEqual(DAILY_CREDIT_CAP);
        }
        expect(moved).toBeLessThanOrEqual(DAILY_CREDIT_CAP);
      }),
      runs(300),
    );
  });

  it('idempotency: replaying a successful write never applies it twice', async () => {
    const writes = fc.oneof(
      fc.record({ tool: fc.constant('add_session_credits'), client_id: fc.constantFrom(...CLIENTS), sessions: fc.integer({ min: 1, max: 5 }), reason: fc.constant('replay test'), idempotency_key: key }),
      fc.record({ tool: fc.constant('remove_session_credits'), client_id: fc.constantFrom('u_pieter', 'u_liam'), sessions: fc.integer({ min: 1, max: 2 }), reason: fc.constant('replay test'), idempotency_key: key }),
      fc.record({ tool: fc.constant('create_booking'), client_id: fc.constantFrom('u_pieter', 'u_liam'), start: fc.constantFrom('2026-11-12T08:00', '2026-11-13T10:30', '2026-11-16T15:00'), idempotency_key: key }),
    );
    await fc.assert(
      fc.asyncProperty(writes, fc.integer({ min: 2, max: 5 }), async ({ tool, ...args }, times) => {
        await demoApi.demoReset!();
        const first = await runTool(tool, args, demoStore);
        const afterFirst = snapshot();
        const ledgerLen = demoStore.getState().ledger.length;
        const bookingsLen = demoStore.getState().bookings.length;
        for (let i = 0; i < times; i++) {
          const again = await runTool(tool, args, demoStore);
          if (first.ok) {
            expect(again.ok).toBe(true);
            expect((again as { replayed?: boolean }).replayed).toBe(true);
            expect((again.result as { status?: string }).status).toBe('replayed');
            expect(demoStore.getState().ledger).toHaveLength(ledgerLen);
            expect(demoStore.getState().bookings).toHaveLength(bookingsLen);
            expect(snapshot()).toBe(afterFirst);
          }
        }
      }),
      runs(150),
    );
  });

  it('idempotency keys are scoped to the tool: the same key on another tool is not a replay', async () => {
    const k = 'shared-key-0001';
    const a = await runTool('add_session_credits', { client_id: 'u_ruan', sessions: 1, reason: 'one', idempotency_key: k }, demoStore);
    const r = await runTool('remove_session_credits', { client_id: 'u_ruan', sessions: 1, reason: 'two', idempotency_key: k }, demoStore);
    expect(a.ok && r.ok).toBe(true);
    expect((r as { replayed?: boolean }).replayed).toBeUndefined();
    expect(demoStore.balance('u_ruan')).toBe(0);
  });
});

describe('fuzz: demoCallTool rate limits', () => {
  it('never lets more than 20 write calls through in an hour or 60 calls in a minute', async () => {
    await demoApi.login('ivan@example.com', 'stretch123');
    const { demoTotpCode } = await import('../data/demoApi');
    await demoApi.verifyTotp(demoTotpCode(Date.now()));
    await fc.assert(
      fc.asyncProperty(fc.array(fc.boolean(), { minLength: 1, maxLength: 90 }), async (isWrite) => {
        await demoApi.demoReset!();
        await demoApi.login('ivan@example.com', 'stretch123');
        await demoApi.verifyTotp(demoTotpCode(Date.now()));
        let okWrites = 0;
        let okCalls = 0;
        let n = 0;
        for (const w of isWrite) {
          const res = w
            ? await demoApi.demoCallTool!('add_session_credits', { client_id: 'u_ruan', sessions: 1, reason: 'rate test', idempotency_key: `rate-key-${n++}-${Math.random().toString(36).slice(2)}` })
            : await demoApi.demoCallTool!('get_schedule', {});
          if (res.ok) { okCalls++; if (w) okWrites++; }
          if (res.error === 'rate_limited') expect(okCalls >= 60 || okWrites >= 0).toBe(true);
        }
        expect(okWrites).toBeLessThanOrEqual(20);
        expect(okCalls).toBeLessThanOrEqual(60);
        expect(balanceOf(demoStore.getState().ledger.filter((l) => l.userId === 'u_ruan'))).toBeLessThanOrEqual(20);
      }),
      runs(25),
    );
  });
});

const SAMPLE: Record<string, unknown> = {
  date: '2026-11-09', from: '2026-11-09', to: '2026-11-10', query: 'thandi', client_id: 'u_thandi', period: 'week',
  sessions: 1, reason: 'sample reason', idempotency_key: 'sample-key-0001', booking_id: 'b_1', refund_credit: true,
  start: '2026-11-12T08:00', new_start: '2026-11-12T08:00', status: 'confirmed',
};
function safe(v: unknown) {
  try { return JSON.stringify(v)?.slice(0, 300); } catch { return String(v); }
}
