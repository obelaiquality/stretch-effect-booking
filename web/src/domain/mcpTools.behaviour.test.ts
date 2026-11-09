// Exact-output tests for runTool against the real demo store with a controlled mini-world.
// These pin down money, booking and security behaviour (they exist to kill mutants, see TESTING.md).
import { afterAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { demoApi, demoStore } from '../data/demoApi';
import { DAILY_CREDIT_CAP, TOOLS, runTool, validateArgs, type JsonSchema } from './mcpTools';
import type { Booking, LedgerEntry } from './types';

const NOW = Date.parse('2026-11-09T08:00:00.000Z'); // Mon 09 Nov, 10:00 local
const st = () => demoStore.getState();
const K = (n: string | number) => `idem-key-${n}-xyz`;
const run = (name: string, args: unknown) => runTool(name, args, demoStore);
const schemaOf = (n: string) => TOOLS.find((t) => t.name === n)!.inputSchema;

function booking(id: string, userId: string, startLocal: string, status: Booking['status'] = 'confirmed', extra: Partial<Booking> = {}): Booking {
  const start = new Date(Date.parse(startLocal + ':00+02:00')).toISOString();
  const name = st().users.find((u) => u.id === userId)!.name;
  return { id, userId, clientName: name, start, end: new Date(Date.parse(start) + 3600000).toISOString(), status, createdBy: 'client', ...extra };
}
function ledger(userId: string, delta: number, reason: LedgerEntry['reason'], actor: string, createdAt = new Date(NOW).toISOString()): LedgerEntry {
  return { id: `lx_${Math.random()}`, userId, delta, reason, actor, createdAt };
}

beforeEach(async () => {
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.setSystemTime(NOW);
  await demoApi.demoReset!();
  // Controlled world: keep the seeded users and settings, replace everything time-dependent.
  demoStore.save((s) => {
    s.bookings = [];
    s.google = [];
    s.ledger = [];
    s.packRequests = [];
    s.idem = {};
  });
});
afterAll(() => vi.useRealTimers());

describe('validateArgs (exact)', () => {
  const S: JsonSchema = {
    type: 'object', additionalProperties: false, required: ['s', 'n'],
    properties: {
      s: { type: 'string', description: '', minLength: 3, maxLength: 5, pattern: '^[a-z]+$' },
      n: { type: 'integer', description: '', minimum: 1, maximum: 20 },
      b: { type: 'boolean', description: '' },
      e: { type: 'string', description: '', enum: ['x', 'y'] },
    },
  };
  it('rejects non-objects', () => {
    for (const bad of [null, undefined, 5, 'x', true, [], [1]]) expect(validateArgs(S, bad)).toEqual(['arguments must be an object']);
  });
  it('accepts a valid object and the boundaries', () => {
    expect(validateArgs(S, { s: 'abc', n: 1 })).toEqual([]);
    expect(validateArgs(S, { s: 'abcde', n: 20, b: false, e: 'y' })).toEqual([]);
  });
  it('reports each problem with an exact message', () => {
    expect(validateArgs(S, { s: 'ab', n: 1 })).toEqual(['"s" is too short (min 3)']);
    expect(validateArgs(S, { s: 'abcdef', n: 1 })).toEqual(['"s" is too long (max 5)']);
    expect(validateArgs(S, { s: 'AB1', n: 1 })).toEqual(['"s" has the wrong format']);
    expect(validateArgs(S, { s: 'abc', n: 0 })).toEqual(['"n" must be at least 1']);
    expect(validateArgs(S, { s: 'abc', n: 21 })).toEqual(['"n" must be at most 20']);
    expect(validateArgs(S, { s: 'abc', n: 1.5 })).toEqual(['"n" must be a whole number']);
    expect(validateArgs(S, { s: 'abc', n: '1' })).toEqual(['"n" must be a whole number']);
    expect(validateArgs(S, { s: 'abc', n: 1, b: 'true' })).toEqual(['"b" must be true or false']);
    expect(validateArgs(S, { s: 'abc', n: 1, b: 1 })).toEqual(['"b" must be true or false']);
    expect(validateArgs(S, { s: 'abc', n: 1, e: 'z' })).toEqual(['"e" must be one of x, y']);
    expect(validateArgs(S, { s: 5, n: 1 })).toEqual(['"s" must be a string']);
    expect(validateArgs(S, { s: 'abc', n: 1, zzz: 1 })).toEqual(['unknown field "zzz"']);
    expect(validateArgs(S, {})).toEqual(['"s" is required', '"n" is required']);
    expect(validateArgs(S, { s: null, n: null })).toEqual(['"s" is required', '"n" is required', '"s" must be a string', '"n" must be a whole number']);
    expect(validateArgs(S, { s: 'abc', n: 1, b: undefined })).toEqual([]);
  });
  it('applies the real schemas: dates, keys, ids, local datetimes', () => {
    const add = schemaOf('add_session_credits');
    const ok = { client_id: 'u_1', sessions: 1, reason: 'abc', idempotency_key: '12345678' };
    expect(validateArgs(add, ok)).toEqual([]);
    expect(validateArgs(add, { ...ok, idempotency_key: '1234567' })).toHaveLength(2); // too short and wrong format? at least rejected
    expect(validateArgs(add, { ...ok, idempotency_key: 'a'.repeat(64) })).toEqual([]);
    expect(validateArgs(add, { ...ok, idempotency_key: 'a'.repeat(65) }).length).toBeGreaterThan(0);
    expect(validateArgs(add, { ...ok, idempotency_key: 'abcd efgh' })).toEqual(['"idempotency_key" has the wrong format']);
    expect(validateArgs(add, { ...ok, reason: 'ab' })).toEqual(['"reason" is too short (min 3)']);
    expect(validateArgs(add, { ...ok, reason: 'a'.repeat(200) })).toEqual([]);
    expect(validateArgs(add, { ...ok, reason: 'a'.repeat(201) })).toEqual(['"reason" is too long (max 200)']);
    expect(validateArgs(add, { ...ok, client_id: 'bad id!' })).toEqual(['"client_id" has the wrong format']);
    expect(validateArgs(add, { ...ok, client_id: '' })).toContain('"client_id" is too short (min 1)');
    expect(validateArgs(add, { ...ok, payment_reference: 'a'.repeat(60) })).toEqual([]);
    expect(validateArgs(add, { ...ok, payment_reference: 'a'.repeat(61) })).toEqual(['"payment_reference" is too long (max 60)']);
    const sched = schemaOf('get_schedule');
    expect(validateArgs(sched, {})).toEqual([]);
    expect(validateArgs(sched, { date: '2026-11-09' })).toEqual([]);
    expect(validateArgs(sched, { date: '2026-11-9' }).length).toBeGreaterThan(0);
    expect(validateArgs(sched, { date: '2026-11-09\n' }).length).toBeGreaterThan(0);
    expect(validateArgs(sched, { date: 'x2026-11-09' }).length).toBeGreaterThan(0);
    const cb = schemaOf('create_booking');
    const cbOk = { client_id: 'u_1', idempotency_key: '12345678' };
    expect(validateArgs(cb, { ...cbOk, start: '2026-11-12T09:00' })).toEqual([]);
    expect(validateArgs(cb, { ...cbOk, start: '2026-11-12T09:00:30' })).toEqual([]);
    expect(validateArgs(cb, { ...cbOk, start: '2026-11-12 09:00' })).toEqual(['"start" has the wrong format']);
    expect(validateArgs(cb, { ...cbOk, start: '2026-11-12T09:00:30Z' }).length).toBeGreaterThan(0);
    expect(validateArgs(schemaOf('list_bookings'), { from: '2026-11-01', to: '2026-11-02', status: 'bogus' })).toEqual(['"status" must be one of confirmed, cancelled, completed, no_show']);
    expect(validateArgs(schemaOf('search_clients'), { query: 'a' })).toEqual(['"query" is too short (min 2)']);
    expect(validateArgs(schemaOf('search_clients'), { query: 'a'.repeat(60) })).toEqual([]);
    expect(validateArgs(schemaOf('search_clients'), { query: 'a'.repeat(61) })).toEqual(['"query" is too long (max 60)']);
    expect(validateArgs(schemaOf('cancel_booking'), { booking_id: 'b_1', refund_credit: 'yes', reason: 'abc', idempotency_key: '12345678' })).toEqual(['"refund_credit" must be true or false']);
    expect(validateArgs(schemaOf('get_business_summary'), { period: 'year' })).toEqual(['"period" must be one of week, month']);
  });
  it('every tool schema is closed and every required field is declared', () => {
    for (const t of TOOLS) {
      expect(t.inputSchema.additionalProperties).toBe(false);
      for (const r of t.inputSchema.required) expect(Object.keys(t.inputSchema.properties)).toContain(r);
      for (const [k, p] of Object.entries(t.inputSchema.properties)) {
        if (p.pattern) expect(() => new RegExp(p.pattern!), `${t.name}.${k}`).not.toThrow();
        if (p.pattern?.startsWith('^')) expect(p.pattern.endsWith('$')).toBe(true);
      }
      expect(t.write).toBe(['add_session_credits', 'remove_session_credits', 'create_booking', 'cancel_booking', 'reschedule_booking'].includes(t.name));
    }
    expect(Object.fromEntries(TOOLS.map((t) => [t.name, t.scope]))).toEqual({
      get_schedule: 'bookings:read', list_bookings: 'bookings:read', find_free_slots: 'bookings:read', search_clients: 'clients:read',
      get_client: 'clients:read', get_business_summary: 'bookings:read', add_session_credits: 'credits:write',
      remove_session_credits: 'credits:write', create_booking: 'bookings:write', cancel_booking: 'bookings:write', reschedule_booking: 'bookings:write',
    });
    expect(schemaOf('add_session_credits').properties.sessions).toMatchObject({ minimum: 1, maximum: 20 });
    expect(DAILY_CREDIT_CAP).toBe(40);
  });
});

describe('runTool entry point', () => {
  it('unknown tool, bad args, missing args', async () => {
    expect(await run('nope', {})).toEqual({ ok: false, summary: 'Unknown tool "nope"', error: 'unknown_tool' });
    const bad = await run('add_session_credits', { client_id: 'u_ruan' });
    expect(bad.error).toBe('invalid_params');
    expect(bad.summary).toBe('Invalid arguments: "sessions" is required; "reason" is required; "idempotency_key" is required');
    const many = await run('add_session_credits', { zz: 1, yy: 2, xx: 3, ww: 4 });
    expect(many.summary.split('; ')).toHaveLength(3); // only the first three problems are reported
    expect((await run('get_schedule', undefined)).ok).toBe(true);
    expect((await run('get_schedule', null)).ok).toBe(true);
    expect((await run('get_schedule', [])).error).toBe('invalid_params');
    expect((await run('get_schedule', 'x')).error).toBe('invalid_params');
  });
  it('checks the grant: revoked, missing, scope', async () => {
    demoStore.save((s) => { s.grants.find((g) => g.id === 'g_chatgpt')!.scopes = ['bookings:read']; });
    expect((await run('get_schedule', {})).ok).toBe(true);
    const r = await run('search_clients', { query: 'th' });
    expect(r).toMatchObject({ ok: false, error: 'insufficient_scope', summary: 'This connection does not have the "clients:read" permission' });
    expect((await run('add_session_credits', { client_id: 'u_ruan', sessions: 1, reason: 'abc', idempotency_key: K(1) })).error).toBe('insufficient_scope');
    expect(demoStore.balance('u_ruan')).toBe(0);
    demoStore.save((s) => { s.grants.find((g) => g.id === 'g_chatgpt')!.revokedAt = '2026-11-01T00:00:00.000Z'; });
    expect(await run('get_schedule', {})).toEqual({ ok: false, summary: 'Connector access was revoked', error: 'forbidden' });
    demoStore.save((s) => { s.grants = []; });
    expect((await run('get_schedule', {})).error).toBe('forbidden');
  });
  it('replays by grant + tool + key, reports it, and stores only successes', async () => {
    const args = { client_id: 'u_ruan', sessions: 2, reason: 'once only', idempotency_key: K('r') };
    const first = await run('add_session_credits', args);
    expect(first.ok).toBe(true);
    expect((first as { replayed?: boolean }).replayed).toBeUndefined();
    const second = (await run('add_session_credits', args)) as typeof first & { replayed: boolean };
    expect(second.replayed).toBe(true);
    expect(second.summary).toBe(`${first.summary} (already done, not repeated)`);
    expect((second.result as { status: string }).status).toBe('replayed');
    expect((second.result as { added: number }).added).toBe(2);
    expect(demoStore.balance('u_ruan')).toBe(2);
    expect(Object.keys(st().idem)).toEqual(['g_chatgpt:add_session_credits:' + K('r')]);
    // a different key is a new action
    await run('add_session_credits', { ...args, idempotency_key: K('r2') });
    expect(demoStore.balance('u_ruan')).toBe(4);
    // failures are not stored, so a retry after fixing the cause works
    const fail = { client_id: 'u_ruan', sessions: 9, reason: 'too many', idempotency_key: K('f') };
    expect((await run('remove_session_credits', fail)).ok).toBe(false);
    expect(Object.keys(st().idem).some((k) => k.endsWith(K('f')))).toBe(false);
    await run('add_session_credits', { client_id: 'u_ruan', sessions: 10, reason: 'top up', idempotency_key: K('g') });
    expect((await run('remove_session_credits', fail)).ok).toBe(true);
    // read tools do not use idempotency
    await run('get_schedule', { date: '2026-11-09' });
    expect(Object.keys(st().idem)).toHaveLength(4);
    // a write replay whose first result had no object result still replays (status only)
    demoStore.save((s) => { s.idem['g_chatgpt:create_booking:' + K('z')] = { ok: true, summary: 'done' }; });
    const odd = await run('create_booking', { client_id: 'u_ruan', start: '2026-11-12T09:00', idempotency_key: K('z') });
    expect(odd).toMatchObject({ ok: true, replayed: true, result: { status: 'replayed' } });
  });
  it('turns ApiErrors and unexpected errors into ok:false', async () => {
    const r = await run('create_booking', { client_id: 'u_ruan', start: '2026-11-12T09:00', idempotency_key: K('n') });
    expect(r).toMatchObject({ ok: false, error: 'no_credits', summary: 'No sessions left. Load a pack first.' });
    const boom = { ...demoStore, createBooking: () => { throw new TypeError('secret detail'); } };
    demoStore.save((s) => void s.ledger.push(ledger('u_ruan', 1, 'owner_adjust', 'u_ivan')));
    const e = await runTool('create_booking', { client_id: 'u_ruan', start: '2026-11-12T09:00', idempotency_key: K('o') }, boom as never);
    expect(e).toEqual({ ok: false, summary: 'The studio system could not do that', error: 'server' });
  });
});

describe('read tools return exact data', () => {
  it('get_schedule lists the day sorted, hides cancelled, shows only google busy blocks', async () => {
    demoStore.save((s) => {
      s.bookings = [
        booking('b_late', 'u_pieter', '2026-11-10T15:00'),
        booking('b_early', 'u_thandi', '2026-11-10T07:30'),
        booking('b_cx', 'u_aisha', '2026-11-10T09:00', 'cancelled'),
        booking('b_done', 'u_liam', '2026-11-10T11:00', 'completed'),
        booking('b_other', 'u_liam', '2026-11-11T09:00'),
      ];
      s.google = [
        { start: '2026-11-10T07:00:00.000Z', end: '2026-11-10T08:00:00.000Z', source: 'google', label: 'Private: dentist' },
        { start: '2026-11-11T07:00:00.000Z', end: '2026-11-11T08:00:00.000Z', source: 'google' },
      ];
    });
    const r = await run('get_schedule', { date: '2026-11-10' });
    const res = r.result as { date: string; bookings: Array<{ booking_id: string; start: string; end: string; status: string; client_name: string; client_id: string }>; busy: unknown[] };
    expect(res.date).toBe('2026-11-10');
    expect(res.bookings.map((b) => b.booking_id)).toEqual(['b_early', 'b_done', 'b_late']);
    expect(res.bookings[0]).toEqual({ booking_id: 'b_early', client_id: 'u_thandi', client_name: 'Thandi Mokoena', start: '2026-11-10T07:30', end: '2026-11-10T08:30', status: 'confirmed' });
    expect(res.busy).toEqual([{ start: '2026-11-10T09:00', end: '2026-11-10T10:00', label: 'Busy (Google Calendar)' }]);
    expect(r.summary).toBe('3 bookings and 1 calendar block on 2026-11-10');
    const one = await run('get_schedule', { date: '2026-11-11' });
    expect(one.summary).toBe('1 booking and 1 calendar block on 2026-11-11');
    const none = await run('get_schedule', { date: '2026-11-20' });
    expect(none.summary).toBe('0 bookings and 0 calendar blocks on 2026-11-20');
    const today = await run('get_schedule', {});
    expect((today.result as { date: string }).date).toBe('2026-11-09');
    // 00:30 local belongs to the local day, not the UTC day
    demoStore.save((s) => { s.bookings = [booking('b_mid', 'u_pieter', '2026-11-12T00:30')]; });
    expect(((await run('get_schedule', { date: '2026-11-12' })).result as { bookings: unknown[] }).bookings).toHaveLength(1);
    expect(((await run('get_schedule', { date: '2026-11-11' })).result as { bookings: unknown[] }).bookings).toHaveLength(0);
  });

  it('list_bookings filters by range and status, sorts, caps at 100 and checks the range', async () => {
    demoStore.save((s) => {
      s.bookings = [
        booking('b3', 'u_pieter', '2026-11-12T10:00'),
        booking('b1', 'u_thandi', '2026-11-10T10:00'),
        booking('b2', 'u_aisha', '2026-11-11T10:00', 'cancelled'),
        booking('b0', 'u_liam', '2026-11-09T23:30'),
        booking('b4', 'u_liam', '2026-11-13T00:10'),
        booking('b5', 'u_liam', '2026-11-20T10:00'),
      ];
    });
    const all = await run('list_bookings', { from: '2026-11-10', to: '2026-11-13' });
    const ids = (r: typeof all) => (r.result as { bookings: Array<{ booking_id: string }> }).bookings.map((b) => b.booking_id);
    expect(ids(all)).toEqual(['b1', 'b2', 'b3', 'b4']); // inclusive on both ends, sorted by start
    expect((all.result as { count: number; from: string; to: string })).toMatchObject({ count: 4, from: '2026-11-10', to: '2026-11-13' });
    expect(all.summary).toBe('4 bookings from 2026-11-10 to 2026-11-13');
    expect(ids(await run('list_bookings', { from: '2026-11-10', to: '2026-11-13', status: 'cancelled' }))).toEqual(['b2']);
    expect((await run('list_bookings', { from: '2026-11-10', to: '2026-11-10', status: 'cancelled' })).summary).toBe('0 bookings from 2026-11-10 to 2026-11-10');
    expect((await run('list_bookings', { from: '2026-11-10', to: '2026-11-10' })).summary).toBe('1 booking from 2026-11-10 to 2026-11-10');
    expect(await run('list_bookings', { from: '2026-11-13', to: '2026-11-12' })).toEqual({ ok: false, summary: '"to" is before "from"', error: 'invalid_params' });
    expect((await run('list_bookings', { from: '2026-11-12', to: '2026-11-12' })).ok).toBe(true); // same day is fine
    expect((await run('list_bookings', { from: '2026-11-01', to: '2026-12-01' })).ok).toBe(true); // 30 days apart
    expect(await run('list_bookings', { from: '2026-11-01', to: '2026-12-02' })).toEqual({ ok: false, summary: 'Range is too long (maximum 31 days)', error: 'invalid_params' });
    demoStore.save((s) => { s.bookings = Array.from({ length: 130 }, (_, i) => booking(`bb${i}`, 'u_pieter', '2026-11-10T10:00')); });
    expect(((await run('list_bookings', { from: '2026-11-10', to: '2026-11-10' })).result as { count: number }).count).toBe(100);
  });

  it('find_free_slots groups start times by day and caps the range at 14 days', async () => {
    const r = await run('find_free_slots', { from: '2026-11-10', to: '2026-11-11' });
    const days = (r.result as { days: Array<{ date: string; starts: string[] }> }).days;
    expect(days.map((d) => d.date)).toEqual(['2026-11-10', '2026-11-11']);
    expect(days[0].starts[0]).toBe('06:00');
    expect(days[0].starts.at(-1)).toBe('17:30');
    const total = days.reduce((n, d) => n + d.starts.length, 0);
    expect(r.summary).toBe(`${total} free start times across 2 days`);
    const sunday = await run('find_free_slots', { from: '2026-11-15', to: '2026-11-15' });
    expect(sunday.summary).toBe('0 free start times across 0 days');
    const one = await run('find_free_slots', { from: '2026-11-10', to: '2026-11-10' });
    expect(one.summary).toMatch(/free start times across 1 day$/);
    expect(await run('find_free_slots', { from: '2026-11-11', to: '2026-11-10' })).toEqual({ ok: false, summary: '"to" is before "from"', error: 'invalid_params' });
    expect((await run('find_free_slots', { from: '2026-11-10', to: '2026-11-10' })).ok).toBe(true);
    expect((await run('find_free_slots', { from: '2026-11-10', to: '2026-11-23' })).ok).toBe(true); // 14 days inclusive
    expect(await run('find_free_slots', { from: '2026-11-10', to: '2026-11-24' })).toEqual({ ok: false, summary: 'Range is too long (maximum 14 days)', error: 'invalid_params' });
  });

  it('search_clients matches name or email, trims, caps at 5, shows balances', async () => {
    demoStore.save((s) => void s.ledger.push(ledger('u_thandi', 3, 'owner_adjust', 'u_ivan')));
    const r = await run('search_clients', { query: '  THANDI ' });
    expect(r.result).toEqual({ clients: [{ client_id: 'u_thandi', name: 'Thandi Mokoena', sessions_left: 3 }] });
    expect(r.summary).toBe('1 client matches');
    expect(((await run('search_clients', { query: 'pieter@example' })).result as { clients: unknown[] }).clients).toHaveLength(1);
    expect((await run('search_clients', { query: 'zzzzzz' })).summary).toBe('0 clients match');
    const many = await run('search_clients', { query: 'e' + 'x' });
    expect(((many.result as { clients: unknown[] }).clients).length).toBeLessThanOrEqual(5);
    expect(((await run('search_clients', { query: 'example' })).result as { clients: unknown[] }).clients).toHaveLength(5);
    expect((await run('search_clients', { query: ' a ' }))).toEqual({ ok: false, summary: 'Query must be at least 2 characters', error: 'invalid_params' });
    expect(((await run('search_clients', { query: 'ivan' })).result as { clients: unknown[] }).clients).toHaveLength(0); // owner is not a client
    expect(JSON.stringify(await run('search_clients', { query: 'th' }))).not.toMatch(/passwordHash|email|@/);
  });

  it('get_client returns balance, upcoming (sorted, confirmed, future) and pending packs', async () => {
    demoStore.save((s) => {
      s.ledger.push(ledger('u_thandi', 5, 'purchase', 'u_ivan'), ledger('u_thandi', -1, 'booking', 'u_thandi'));
      s.bookings = [
        booking('b_b', 'u_thandi', '2026-11-12T10:00'),
        booking('b_a', 'u_thandi', '2026-11-10T10:00'),
        booking('b_past', 'u_thandi', '2026-11-09T09:00'), // 07:00Z < now
        booking('b_now', 'u_thandi', '2026-11-09T10:00'), // start == now is not upcoming
        booking('b_cx', 'u_thandi', '2026-11-11T10:00', 'cancelled'),
        booking('b_other', 'u_pieter', '2026-11-11T10:00'),
        booking('b_visit', 'u_thandi', '2026-11-05T10:30', 'completed'),
      ];
      s.packRequests = [
        { id: 'p1', userId: 'u_thandi', packageId: 'five', sessions: 5, priceZar: 2500, status: 'pending', createdAt: '2026-11-08T00:00:00.000Z' },
        { id: 'p2', userId: 'u_thandi', packageId: 'ten', sessions: 10, priceZar: 4500, status: 'paid', createdAt: '2026-11-01T00:00:00.000Z' },
        { id: 'p3', userId: 'u_pieter', packageId: 'single', sessions: 1, priceZar: 550, status: 'pending', createdAt: '2026-11-01T00:00:00.000Z' },
      ];
    });
    const r = await run('get_client', { client_id: 'u_thandi' });
    expect(r.result).toEqual({
      client_id: 'u_thandi', name: 'Thandi Mokoena', untrusted_email: 'thandi@example.com', sessions_left: 4,
      upcoming_bookings: [
        { booking_id: 'b_a', client_id: 'u_thandi', client_name: 'Thandi Mokoena', start: '2026-11-10T10:00', end: '2026-11-10T11:00', status: 'confirmed' },
        { booking_id: 'b_b', client_id: 'u_thandi', client_name: 'Thandi Mokoena', start: '2026-11-12T10:00', end: '2026-11-12T11:00', status: 'confirmed' },
      ],
      last_visit: '2026-11-05T10:30',
      packs_waiting_for_payment: [{ sessions: 5, price_zar: 2500 }],
    });
    expect(r.summary).toBe('Thandi Mokoena has 4 sessions left and 2 upcoming');
    const none = await run('get_client', { client_id: 'u_ruan' });
    expect((none.result as { last_visit: unknown }).last_visit).toBeNull();
    expect(await run('get_client', { client_id: 'u_ivan' })).toEqual({ ok: false, summary: 'Client not found', error: 'not_found' });
    expect(await run('get_client', { client_id: 'nobody' })).toEqual({ ok: false, summary: 'Client not found', error: 'not_found' });
  });

  it('get_business_summary counts the right window for week and month', async () => {
    const day = (n: number) => new Date(NOW + n * 86400000).toISOString().slice(0, 10);
    demoStore.save((s) => {
      s.bookings = [
        booking('w0', 'u_pieter', `${day(0)}T09:00`), // today
        booking('w6', 'u_pieter', `${day(-6)}T09:00`, 'completed'), // first day of week window
        booking('w7', 'u_pieter', `${day(-7)}T09:00`, 'completed'), // just outside the week, inside month
        booking('w29', 'u_pieter', `${day(-29)}T09:00`, 'cancelled'), // first day of month window
        booking('w30', 'u_pieter', `${day(-30)}T09:00`, 'completed'), // outside
        booking('wf', 'u_pieter', `${day(1)}T09:00`), // tomorrow: outside "to"
        booking('wn', 'u_pieter', `${day(-2)}T09:00`, 'no_show'),
      ];
      s.ledger = [
        ledger('u_pieter', 10, 'purchase', 'u_ivan', new Date(NOW - 2 * 86400000).toISOString()),
        ledger('u_pieter', 5, 'purchase', 'u_ivan', new Date(NOW - 20 * 86400000).toISOString()),
        ledger('u_pieter', 7, 'owner_adjust', 'u_ivan', new Date(NOW - 1 * 86400000).toISOString()),
        ledger('u_pieter', -1, 'booking', 'u_pieter', new Date(NOW - 1 * 86400000).toISOString()),
      ];
      for (const u of s.users) u.createdAt = '2020-01-01T00:00:00.000Z';
      s.users.find((u) => u.id === 'u_ruan')!.createdAt = new Date(NOW - 3 * 86400000).toISOString();
      s.users.find((u) => u.id === 'u_liam')!.createdAt = new Date(NOW - 25 * 86400000).toISOString();
      s.users.find((u) => u.id === 'u_ivan')!.createdAt = new Date(NOW - 1 * 86400000).toISOString(); // owner never counts
    });
    const week = (await run('get_business_summary', { period: 'week' }));
    expect(week.result).toMatchObject({ period: 'week', from: '2026-11-03', to: '2026-11-09', sessions_booked: 3, sessions_completed: 1, sessions_cancelled: 0, sessions_sold: 10, new_clients: 1 });
    expect(week.summary).toBe('3 booked, 1 completed, 0 cancelled over the last 7 days');
    const month = await run('get_business_summary', { period: 'month' });
    expect(month.result).toMatchObject({ period: 'month', from: '2026-10-11', to: '2026-11-09', sessions_booked: 4, sessions_completed: 2, sessions_cancelled: 1, sessions_sold: 15, new_clients: 2 });
    expect(month.summary).toBe('4 booked, 2 completed, 1 cancelled over the last 30 days');
    const ahead = (r: typeof week) => (r.result as { free_hours_ahead: number }).free_hours_ahead;
    const wk = demoStore.freeSlots('2026-11-09', '2026-11-15').length;
    const mo = demoStore.freeSlots('2026-11-09', '2026-12-08').length;
    expect(ahead(week)).toBe(Math.round((wk * 30) / 60));
    expect(ahead(month)).toBe(Math.round((mo * 30) / 60));
    expect(ahead(month)).toBeGreaterThan(ahead(week));
  });
});

describe('credit tools', () => {
  it('add_session_credits writes one ledger row with the right shape', async () => {
    const r = await run('add_session_credits', { client_id: 'u_ruan', sessions: 3, reason: ' EFT in ', idempotency_key: K(1) });
    expect(r.ok).toBe(true);
    expect(r.result).toEqual({ client_id: 'u_ruan', name: 'Ruan Steyn', added: 3, sessions_left: 3, pack_request_settled: false });
    expect(r.summary).toBe('Added 3 sessions to Ruan Steyn. They now have 3.');
    const row = st().ledger.at(-1)!;
    expect(row).toMatchObject({ userId: 'u_ruan', delta: 3, reason: 'chatgpt_adjust', actor: 'chatgpt:g_chatgpt', note: ' EFT in ' });
    const one = await run('add_session_credits', { client_id: 'u_ruan', sessions: 1, reason: 'single', payment_reference: '  REF-9 ', idempotency_key: K(2) });
    expect(one.summary).toBe('Added 1 session to Ruan Steyn. They now have 4.');
    expect(st().ledger.at(-1)!.note).toBe('single (ref REF-9)');
    await run('add_session_credits', { client_id: 'u_ruan', sessions: 1, reason: 'blank ref', payment_reference: '   ', idempotency_key: K(3) });
    expect(st().ledger.at(-1)!.note).toBe('blank ref');
    expect(await run('add_session_credits', { client_id: 'nobody', sessions: 1, reason: 'abc', idempotency_key: K(4) })).toEqual({ ok: false, summary: 'Client not found', error: 'not_found' });
    expect((await run('add_session_credits', { client_id: 'u_ivan', sessions: 1, reason: 'abc', idempotency_key: K(5) })).error).toBe('not_found');
  });

  it('settles a matching pending pack only when a reference is given and the size matches', async () => {
    demoStore.save((s) => {
      s.packRequests = [
        { id: 'p_old_paid', userId: 'u_ruan', packageId: 'five', sessions: 5, priceZar: 2500, status: 'paid', createdAt: '2026-11-01T00:00:00.000Z' },
        { id: 'p_other', userId: 'u_liam', packageId: 'five', sessions: 5, priceZar: 2500, status: 'pending', createdAt: '2026-11-01T00:00:00.000Z' },
        { id: 'p_ten', userId: 'u_ruan', packageId: 'ten', sessions: 10, priceZar: 4500, status: 'pending', createdAt: '2026-11-01T00:00:00.000Z' },
        { id: 'p_five', userId: 'u_ruan', packageId: 'five', sessions: 5, priceZar: 2500, status: 'pending', createdAt: '2026-11-02T00:00:00.000Z' },
      ];
    });
    const noRef = await run('add_session_credits', { client_id: 'u_ruan', sessions: 5, reason: 'no ref given', idempotency_key: K(1) });
    expect((noRef.result as { pack_request_settled: boolean }).pack_request_settled).toBe(false);
    expect(st().packRequests.find((p) => p.id === 'p_five')!.status).toBe('pending');
    expect(st().ledger.at(-1)!.reason).toBe('chatgpt_adjust');
    const wrongSize = await run('add_session_credits', { client_id: 'u_ruan', sessions: 3, reason: 'odd size', payment_reference: 'REF1', idempotency_key: K(2) });
    expect((wrongSize.result as { pack_request_settled: boolean }).pack_request_settled).toBe(false);
    const settled = await run('add_session_credits', { client_id: 'u_ruan', sessions: 5, reason: 'pack paid', payment_reference: 'REF2', idempotency_key: K(3) });
    expect((settled.result as { pack_request_settled: boolean; sessions_left: number }).pack_request_settled).toBe(true);
    expect(st().packRequests.map((p) => [p.id, p.status])).toEqual([['p_old_paid', 'paid'], ['p_other', 'pending'], ['p_ten', 'pending'], ['p_five', 'paid']]);
    expect(st().packRequests.find((p) => p.id === 'p_five')).toMatchObject({ paymentReference: 'REF2' });
    const row = st().ledger.at(-1)!;
    expect(row).toMatchObject({ reason: 'purchase', delta: 5, actor: 'chatgpt:g_chatgpt', userId: 'u_ruan' });
    expect(demoStore.balance('u_ruan')).toBe(5 + 3 + 5);
  });

  it('enforces the per-day cap exactly: 40 ok, 41 refused, counting adds, removes and pack settlements', async () => {
    const add = (client: string, n: number, i: number | string, extra: object = {}) => run('add_session_credits', { client_id: client, sessions: n, reason: 'cap test', idempotency_key: K(i), ...extra });
    expect((await add('u_ruan', 20, 1)).ok).toBe(true);
    expect((await add('u_ruan', 20, 2)).ok).toBe(true); // exactly 40
    const over = await add('u_liam', 1, 3);
    expect(over).toEqual({ ok: false, summary: 'Daily limit reached: ChatGPT can change at most 40 sessions a day. Use the admin screen for more.', error: 'daily_cap' });
    expect((await run('remove_session_credits', { client_id: 'u_ruan', sessions: 1, reason: 'cap test', idempotency_key: K(4) })).error).toBe('daily_cap');
    expect(demoStore.balance('u_ruan')).toBe(40);
    // 39 then +1 is fine, +2 is not
    await demoApi.demoReset!();
    demoStore.save((s) => { s.ledger = []; s.idem = {}; });
    expect((await add('u_ruan', 20, 5)).ok).toBe(true);
    expect((await add('u_ruan', 19, 6)).ok).toBe(true);
    expect((await add('u_ruan', 2, 7)).error).toBe('daily_cap');
    expect((await add('u_ruan', 1, 8)).ok).toBe(true);
  });

  it('daily cap counts pack settlements and removals, ignores owner and other-day rows', async () => {
    demoStore.save((s) => {
      s.ledger.push(
        ledger('u_liam', 30, 'owner_adjust', 'u_ivan'), // owner actions do not count
        ledger('u_liam', 30, 'purchase', 'u_ivan'), // owner marked paid: not ChatGPT
        ledger('u_liam', 30, 'chatgpt_adjust', 'chatgpt:g_chatgpt', '2026-11-08T21:59:59.000Z'), // 23:59:59 local the day before
        ledger('u_liam', -1, 'booking', 'chatgpt:g_chatgpt'), // not a credit change
      );
    });
    expect((await run('add_session_credits', { client_id: 'u_ruan', sessions: 20, reason: 'ok now', idempotency_key: K(1) })).ok).toBe(true);
    // 2026-11-08T22:00:00Z is 00:00 local on 9 Nov: counts
    demoStore.save((s) => void s.ledger.push(ledger('u_liam', 15, 'purchase', 'chatgpt:g_chatgpt', '2026-11-08T22:00:00.000Z')));
    expect((await run('add_session_credits', { client_id: 'u_ruan', sessions: 5, reason: 'edge', idempotency_key: K(2) })).ok).toBe(true); // 20+15+5 = 40
    expect((await run('add_session_credits', { client_id: 'u_ruan', sessions: 1, reason: 'edge', idempotency_key: K(3) })).error).toBe('daily_cap');
    // removals use the same budget (negative deltas count by size)
    demoStore.save((s) => void (s.ledger = s.ledger.filter((l) => l.actor !== 'chatgpt:g_chatgpt' || l.reason === 'booking')));
    demoStore.save((s) => void s.ledger.push(ledger('u_liam', -39, 'chatgpt_adjust', 'chatgpt:g_chatgpt')));
    expect((await run('add_session_credits', { client_id: 'u_ruan', sessions: 2, reason: 'neg counts', idempotency_key: K(4) })).error).toBe('daily_cap');
    expect((await run('add_session_credits', { client_id: 'u_ruan', sessions: 1, reason: 'neg counts', idempotency_key: K(5) })).ok).toBe(true);
  });

  it('remove_session_credits: exact balance allowed, one more refused, balance never negative', async () => {
    demoStore.save((s) => void s.ledger.push(ledger('u_ruan', 3, 'owner_adjust', 'u_ivan')));
    const bad = await run('remove_session_credits', { client_id: 'u_ruan', sessions: 4, reason: 'too much', idempotency_key: K(1) });
    expect(bad).toEqual({ ok: false, summary: 'Ruan Steyn only has 3 sessions; cannot remove 4', error: 'insufficient_balance' });
    expect(demoStore.balance('u_ruan')).toBe(3);
    const ok = await run('remove_session_credits', { client_id: 'u_ruan', sessions: 3, reason: 'correction', idempotency_key: K(2) });
    expect(ok.result).toEqual({ client_id: 'u_ruan', name: 'Ruan Steyn', removed: 3, sessions_left: 0 });
    expect(ok.summary).toBe('Removed 3 sessions from Ruan Steyn. They now have 0.');
    expect(st().ledger.at(-1)).toMatchObject({ delta: -3, reason: 'chatgpt_adjust', note: 'correction', actor: 'chatgpt:g_chatgpt' });
    demoStore.save((s) => void s.ledger.push(ledger('u_ruan', 1, 'owner_adjust', 'u_ivan')));
    expect((await run('remove_session_credits', { client_id: 'u_ruan', sessions: 1, reason: 'one', idempotency_key: K(3) })).summary).toBe('Removed 1 session from Ruan Steyn. They now have 0.');
    expect(await run('remove_session_credits', { client_id: 'nobody', sessions: 1, reason: 'abc', idempotency_key: K(4) })).toEqual({ ok: false, summary: 'Client not found', error: 'not_found' });
  });

  it('removal cap: exactly 40 in a day is allowed, 41 is refused', async () => {
    demoStore.save((s) => void s.ledger.push(ledger('u_ruan', 30, 'owner_adjust', 'u_ivan'), ledger('u_liam', 20, 'chatgpt_adjust', 'chatgpt:g_chatgpt')));
    expect((await run('remove_session_credits', { client_id: 'u_ruan', sessions: 20, reason: 'to the cap', idempotency_key: K(1) })).ok).toBe(true); // 20 + 20 = 40
    const over = await run('remove_session_credits', { client_id: 'u_ruan', sessions: 1, reason: 'one over', idempotency_key: K(2) });
    expect(over.error).toBe('daily_cap');
  });

  it('write idempotency is by key only on write tools; reads with a key field are unaffected', async () => {
    // get_schedule has no idempotency_key field, so the key can not be smuggled in
    expect((await run('get_schedule', { idempotency_key: K(1) })).error).toBe('invalid_params');
    demoStore.save((s) => { s.idem['g_chatgpt:get_schedule:' + K(1)] = { ok: true, summary: 'poisoned', result: {} }; });
    expect((await run('get_schedule', {})).summary).not.toBe('poisoned (already done, not repeated)');
    // a different grant id never sees another grant's stored result
    demoStore.save((s) => { s.idem['g_old:add_session_credits:' + K(2)] = { ok: true, summary: 'other grant', result: {} }; });
    const r = await run('add_session_credits', { client_id: 'u_ruan', sessions: 1, reason: 'fresh', idempotency_key: K(2) });
    expect((r as { replayed?: boolean }).replayed).toBeUndefined();
    expect(demoStore.balance('u_ruan')).toBe(1);
  });

  it('removal checks the balance before the cap', async () => {
    demoStore.save((s) => void s.ledger.push(ledger('u_liam', 40, 'chatgpt_adjust', 'chatgpt:g_chatgpt')));
    const r = await run('remove_session_credits', { client_id: 'u_ruan', sessions: 1, reason: 'both fail', idempotency_key: K(1) });
    expect(r.error).toBe('insufficient_balance');
  });

  it('clean() strips control characters and shortens names in results', async () => {
    demoStore.save((s) => { s.users.find((u) => u.id === 'u_ruan')!.name = 'Ru\u0000an\nSteyn ' + 'x'.repeat(100); });
    const r = await run('add_session_credits', { client_id: 'u_ruan', sessions: 1, reason: 'name test', idempotency_key: K(1) });
    const name = (r.result as { name: string }).name;
    expect(name.startsWith('Ru an Steyn ')).toBe(true);
    expect(name).toHaveLength(60);
    expect(name).not.toMatch(/[\u0000-\u001f]/);
  });
});

describe('booking tools', () => {
  beforeEach(() => {
    demoStore.save((s) => void s.ledger.push(ledger('u_ruan', 2, 'owner_adjust', 'u_ivan')));
  });

  it('create_booking converts local time, takes one session, and writes a chatgpt booking', async () => {
    const r = await run('create_booking', { client_id: 'u_ruan', start: '2026-11-12T09:00', idempotency_key: K(1) });
    expect(r.ok).toBe(true);
    const b = st().bookings.at(-1)!;
    expect(b).toMatchObject({ userId: 'u_ruan', start: '2026-11-12T07:00:00.000Z', end: '2026-11-12T08:00:00.000Z', status: 'confirmed', createdBy: 'chatgpt' });
    expect(r.result).toEqual({ booking_id: b.id, client_id: 'u_ruan', client_name: 'Ruan Steyn', start: '2026-11-12T09:00', end: '2026-11-12T10:00', status: 'confirmed', sessions_left: 1 });
    expect(r.summary).toBe('Booked Ruan Steyn for 2026-11-12 09:00');
    expect(st().ledger.at(-1)).toMatchObject({ delta: -1, reason: 'booking', actor: 'chatgpt:g_chatgpt', userId: 'u_ruan' });
    // same slot again is a conflict, and costs nothing
    const clash = await run('create_booking', { client_id: 'u_ruan', start: '2026-11-12T09:00', idempotency_key: K(2) });
    expect(clash).toMatchObject({ ok: false, error: 'conflict' });
    expect(demoStore.balance('u_ruan')).toBe(1);
    // inside the 15 min buffer is a conflict too, after the buffer is fine
    expect((await run('create_booking', { client_id: 'u_ruan', start: '2026-11-12T10:00', idempotency_key: K(3) })).error).toBe('conflict');
    expect((await run('create_booking', { client_id: 'u_ruan', start: '2026-11-12T10:30', idempotency_key: K(4) })).ok).toBe(true);
    expect((await run('create_booking', { client_id: 'u_ruan', start: '2026-11-13T09:00', idempotency_key: K(5) })).error).toBe('no_credits');
    expect(await run('create_booking', { client_id: 'nobody', start: '2026-11-13T09:00', idempotency_key: K(6) })).toEqual({ ok: false, summary: 'Client not found', error: 'not_found' });
    expect(await run('create_booking', { client_id: 'u_ruan', start: '2026-02-30T09:00', idempotency_key: K(7) })).toMatchObject({ ok: false, error: 'validation' });
    // notice rule applies to ChatGPT bookings: 12 h from 10:00 is 22:00, so tomorrow 07:00 is too soon... but 11 Nov is fine
    demoStore.save((s) => void s.ledger.push(ledger('u_ruan', 5, 'owner_adjust', 'u_ivan')));
    expect((await run('create_booking', { client_id: 'u_ruan', start: '2026-11-09T12:00', idempotency_key: K(8) })).error).toBe('conflict');
    expect((await run('create_booking', { client_id: 'u_ruan', start: '2026-11-10T09:00', idempotency_key: K(9) })).ok).toBe(true);
    expect((await run('create_booking', { client_id: 'u_ruan', start: '2026-11-15T09:00', idempotency_key: K(10) })).error).toBe('conflict'); // Sunday closed
  });

  it('cancel_booking: refund flag controls the credit, only future confirmed bookings', async () => {
    demoStore.save((s) => {
      s.bookings = [
        booking('b_ok', 'u_ruan', '2026-11-12T09:00'),
        booking('b_ok2', 'u_ruan', '2026-11-13T09:00'),
        booking('b_past', 'u_ruan', '2026-11-09T09:00'),
        booking('b_now', 'u_ruan', '2026-11-09T10:00'),
        booking('b_cx', 'u_ruan', '2026-11-14T09:00', 'cancelled'),
        booking('b_done', 'u_ruan', '2026-11-20T09:00', 'completed'),
      ];
    });
    const a = (id: string, refund: boolean, i: string) => run('cancel_booking', { booking_id: id, refund_credit: refund, reason: 'client asked', idempotency_key: K(i) });
    const noRefund = await a('b_ok', false, '1');
    expect(noRefund.result).toEqual({ booking_id: 'b_ok', client_id: 'u_ruan', client_name: 'Ruan Steyn', start: '2026-11-12T09:00', end: '2026-11-12T10:00', status: 'cancelled', refunded: false, sessions_left: 2 });
    expect(noRefund.summary).toBe('Cancelled Ruan Steyn on 2026-11-12 09:00, no refund');
    expect(st().ledger.filter((l) => l.reason === 'cancel_refund')).toHaveLength(0);
    expect(st().bookings.find((b) => b.id === 'b_ok')).toMatchObject({ status: 'cancelled', refunded: false, cancelReason: 'client asked' });
    const withRefund = await a('b_ok2', true, '2');
    expect(withRefund.summary).toBe('Cancelled Ruan Steyn on 2026-11-13 09:00, session refunded');
    expect((withRefund.result as { sessions_left: number; refunded: boolean })).toMatchObject({ sessions_left: 3, refunded: true });
    expect(st().ledger.at(-1)).toMatchObject({ delta: 1, reason: 'cancel_refund', userId: 'u_ruan', actor: 'chatgpt:g_chatgpt', note: 'client asked' });
    expect(await a('nope', true, '3')).toEqual({ ok: false, summary: 'Booking not found', error: 'not_found' });
    expect(await a('b_cx', true, '4')).toEqual({ ok: false, summary: 'Only confirmed bookings can be cancelled', error: 'conflict' });
    expect((await a('b_done', true, '5')).error).toBe('conflict');
    expect(await a('b_past', true, '6')).toEqual({ ok: false, summary: 'That booking is in the past; mark it in the admin screen instead', error: 'conflict' });
    expect((await a('b_now', true, '7')).error).toBe('conflict'); // starting right now counts as past
    expect(demoStore.balance('u_ruan')).toBe(3);
    // a second cancel never refunds twice
    expect((await a('b_ok2', true, '8')).error).toBe('conflict');
    expect(demoStore.balance('u_ruan')).toBe(3);
  });

  it('reschedule_booking: only future confirmed bookings, only to a free time, no credit change', async () => {
    demoStore.save((s) => {
      s.bookings = [
        booking('b_a', 'u_ruan', '2026-11-12T09:00'),
        booking('b_b', 'u_pieter', '2026-11-12T12:00'),
        booking('b_past', 'u_ruan', '2026-11-09T09:00'),
        booking('b_cx', 'u_ruan', '2026-11-14T09:00', 'cancelled'),
      ];
    });
    const mv = (id: string, to: string, i: string) => run('reschedule_booking', { booking_id: id, new_start: to, idempotency_key: K(i) });
    const before = demoStore.balance('u_ruan');
    const ok = await mv('b_a', '2026-11-12T09:30', '1'); // overlaps only itself: allowed
    expect(ok.ok).toBe(true);
    expect(ok.summary).toBe('Moved Ruan Steyn to 2026-11-12 09:30');
    expect(ok.result).toMatchObject({ booking_id: 'b_a', start: '2026-11-12T09:30', end: '2026-11-12T10:30' });
    expect(demoStore.balance('u_ruan')).toBe(before);
    expect((await mv('b_a', '2026-11-12T11:00', '2')).error).toBe('conflict'); // 11:00-12:00 + buffer hits b_b at 12:00
    expect((await mv('b_a', '2026-11-12T10:30', '3')).ok).toBe(true); // 10:30-11:30 + 15 buffer = 11:45 < 12:00
    expect(await mv('nope', '2026-11-12T09:30', '4')).toEqual({ ok: false, summary: 'Booking not found', error: 'not_found' });
    expect(await mv('b_cx', '2026-11-16T09:30', '5')).toEqual({ ok: false, summary: 'Only confirmed bookings can be moved', error: 'conflict' });
    expect(await mv('b_past', '2026-11-16T09:30', '6')).toEqual({ ok: false, summary: 'That booking is in the past', error: 'conflict' });
    demoStore.save((s) => void s.bookings.push(booking('b_now', 'u_ruan', '2026-11-09T10:00')));
    expect(await mv('b_now', '2026-11-16T09:30', '6b')).toMatchObject({ ok: false, error: 'conflict' }); // starts right now: too late to move
    expect(await mv('b_a', '2026-02-30T09:30', '7')).toMatchObject({ ok: false, error: 'validation' });
    expect(st().bookings.find((b) => b.id === 'b_a')!.start).toBe('2026-11-12T08:30:00.000Z');
  });
});
