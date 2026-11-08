import { describe, expect, it } from 'vitest';
import { ApiError, type Booking, type LedgerEntry, type LedgerReason } from './types';
import { DAILY_CREDIT_CAP, TOOLS, runTool, validateArgs, type ToolContext } from './mcpTools';

const NOW = Date.parse('2026-11-09T08:00:00.000Z'); // Mon 09 Nov 2026, 10:00 local

function makeCtx(opts: { scopes?: string[]; revoked?: boolean } = {}) {
  let seq = 0;
  const st = {
    users: [
      { id: 'u_thandi', role: 'client', name: 'Thandi Example', email: 'thandi@example.com', createdAt: '2026-10-01T08:00:00.000Z', passwordHash: 'SECRET_HASH' },
      { id: 'u_pieter', role: 'client', name: 'Pieter Example', email: 'pieter@example.com', createdAt: '2026-10-02T08:00:00.000Z', passwordHash: 'SECRET_HASH' },
    ],
    ledger: [] as LedgerEntry[],
    bookings: [
      { id: 'b_1', userId: 'u_pieter', clientName: 'Pieter Example', start: '2026-11-10T05:00:00.000Z', end: '2026-11-10T06:00:00.000Z', status: 'confirmed', createdBy: 'client' },
    ] as Booking[],
    packRequests: [{ id: 'p_1', userId: 'u_thandi', packageId: 'five', sessions: 5, priceZar: 2500, status: 'pending', createdAt: '2026-11-08T08:00:00.000Z' }] as any[],
    grants: [{ id: 'g_chatgpt', clientName: 'ChatGPT', scopes: opts.scopes ?? ['bookings:read', 'bookings:write', 'clients:read', 'credits:write'], createdAt: '2026-10-01T08:00:00.000Z', revokedAt: opts.revoked ? '2026-11-01T08:00:00.000Z' : undefined }],
    idem: {} as Record<string, unknown>,
  };
  const balance = (id: string) => st.ledger.filter((l) => l.userId === id).reduce((n, l) => n + l.delta, 0);
  const adjust = (userId: string, delta: number, reason: LedgerReason, note: string, actor: string) => {
    const e: LedgerEntry = { id: 'l_' + ++seq, userId, delta, reason, note, actor, createdAt: new Date(NOW).toISOString() };
    st.ledger.push(e);
    return e;
  };
  st.ledger.push({ id: 'l_0', userId: 'u_pieter', delta: 3, reason: 'purchase', actor: 'u_owner', createdAt: '2026-10-05T08:00:00.000Z' });
  const ctx = {
    grantId: 'g_chatgpt',
    actor: 'chatgpt:g_chatgpt',
    getState: () => st,
    save: (m: (s: typeof st) => void) => m(st),
    now: () => NOW,
    newId: (p: string) => `${p}_${++seq}`,
    settings: () => ({ slotStepMinutes: 30 }),
    balance,
    freeSlots: () => [{ start: '2026-11-12T05:00:00.000Z', end: '2026-11-12T06:00:00.000Z' }],
    busyBlocks: () => [{ start: '2026-11-09T09:00:00.000Z', end: '2026-11-09T10:00:00.000Z', source: 'google', label: 'Dentist' }],
    createBooking: (userId: string, start: string, createdBy: string, actor: string) => {
      if (balance(userId) < 1) throw new ApiError('no_credits', 'No sessions left. Load a pack first.');
      const b = { id: 'b_' + ++seq, userId, clientName: st.users.find((u) => u.id === userId)!.name, start, end: new Date(Date.parse(start) + 3600000).toISOString(), status: 'confirmed', createdBy } as Booking;
      st.bookings.push(b);
      adjust(userId, -1, 'booking', 'Session booked', actor);
      return b;
    },
    cancelBooking: (id: string, refund: boolean, reason: string, actor: string) => {
      const b = st.bookings.find((x) => x.id === id)!;
      b.status = 'cancelled';
      if (refund) adjust(b.userId, 1, 'cancel_refund', reason, actor);
      return { ...b };
    },
    rescheduleBooking: (id: string, start: string) => {
      const b = st.bookings.find((x) => x.id === id)!;
      b.start = start;
      return { ...b };
    },
    adjustCredits: adjust,
    markPackPaid: (id: string, ref: string | undefined, actor: string) => {
      const pr = st.packRequests.find((p) => p.id === id);
      pr.status = 'paid';
      adjust(pr.userId, pr.sessions, 'purchase', `pack (${ref})`, actor);
      return pr;
    },
    clientSummary: (id: string) => ({ user: st.users.find((u) => u.id === id), balance: balance(id), upcoming: 0, pendingPacks: 0 }),
  };
  return { ctx: ctx as unknown as ToolContext, st };
}

const key = (n: string) => `key-${n}-0000000`;

describe('tool definitions', () => {
  it('matches the build spec table', () => {
    expect(TOOLS.map((t) => t.name)).toEqual([
      'get_schedule', 'list_bookings', 'find_free_slots', 'search_clients', 'get_client', 'get_business_summary',
      'add_session_credits', 'remove_session_credits', 'create_booking', 'cancel_booking', 'reschedule_booking',
    ]);
    for (const t of TOOLS) {
      expect(t.annotations.openWorldHint).toBe(false);
      expect(t.annotations.readOnlyHint).toBe(!t.write);
      expect(t.inputSchema.additionalProperties).toBe(false);
      expect(t.annotations.destructiveHint).toBe(t.name === 'remove_session_credits' || t.name === 'cancel_booking');
    }
    const add = TOOLS.find((t) => t.name === 'add_session_credits')!;
    expect(add.inputSchema.required).toEqual(['client_id', 'sessions', 'reason', 'idempotency_key']);
    expect(add.scope).toBe('credits:write');
    expect(TOOLS.find((t) => t.name === 'cancel_booking')!.inputSchema.required).toEqual(['booking_id', 'refund_credit', 'reason', 'idempotency_key']);
    expect(TOOLS.find((t) => t.name === 'reschedule_booking')!.inputSchema.required).toEqual(['booking_id', 'new_start', 'idempotency_key']);
  });
  it('validator rejects bad input', () => {
    const s = TOOLS.find((t) => t.name === 'add_session_credits')!.inputSchema;
    expect(validateArgs(s, { client_id: 'u_1', sessions: 21, reason: 'ok!', idempotency_key: key('a') }).join()).toMatch(/at most 20/);
    expect(validateArgs(s, { client_id: 'u_1', sessions: 0, reason: 'ok!', idempotency_key: key('a') }).join()).toMatch(/at least 1/);
    expect(validateArgs(s, { client_id: 'u_1', sessions: 1.5, reason: 'ok!', idempotency_key: key('a') }).join()).toMatch(/whole number/);
    expect(validateArgs(s, { client_id: 'u_1', sessions: 1 }).length).toBe(2);
    expect(validateArgs(s, { client_id: 'u_1', sessions: 1, reason: 'ok!', idempotency_key: key('a'), extra: 1 }).join()).toMatch(/unknown field/);
  });
});

describe('runTool reads', () => {
  it('rejects unknown tools and unknown fields', async () => {
    const { ctx } = makeCtx();
    expect((await runTool('drop_database', {}, ctx)).error).toBe('unknown_tool');
    const r = await runTool('get_schedule', { date: '2026-11-09', hax: 1 }, ctx);
    expect(r.ok).toBe(false);
    expect(r.error).toBe('invalid_params');
  });
  it('get_schedule returns bookings and busy blocks without extras', async () => {
    const { ctx } = makeCtx();
    const r = await runTool('get_schedule', { date: '2026-11-10' }, ctx);
    expect(r.ok).toBe(true);
    const res = r.result as { bookings: Array<Record<string, unknown>> };
    expect(res.bookings[0]).toMatchObject({ client_name: 'Pieter Example', start: '2026-11-10T07:00' });
    const busy = await runTool('get_schedule', { date: '2026-11-09' }, ctx);
    expect((busy.result as { busy: unknown[] }).busy).toHaveLength(1);
  });
  it('never leaks password hashes or other secrets', async () => {
    const { ctx } = makeCtx();
    for (const [name, args] of [['search_clients', { query: 'example' }], ['get_client', { client_id: 'u_thandi' }], ['list_bookings', { from: '2026-11-01', to: '2026-11-30' }]] as const) {
      const r = await runTool(name, args, ctx);
      expect(JSON.stringify(r)).not.toMatch(/SECRET_HASH|passwordHash|totp/i);
    }
  });
  it('search_clients needs 2 characters and returns ids', async () => {
    const { ctx } = makeCtx();
    expect((await runTool('search_clients', { query: 'T' }, ctx)).ok).toBe(false);
    const r = await runTool('search_clients', { query: 'thandi' }, ctx);
    expect((r.result as { clients: Array<{ client_id: string }> }).clients[0].client_id).toBe('u_thandi');
  });
  it('find_free_slots caps the range at 14 days', async () => {
    const { ctx } = makeCtx();
    expect((await runTool('find_free_slots', { from: '2026-11-09', to: '2026-11-30' }, ctx)).ok).toBe(false);
    const r = await runTool('find_free_slots', { from: '2026-11-09', to: '2026-11-15' }, ctx);
    expect(r.ok).toBe(true);
    expect((r.result as { days: Array<{ starts: string[] }> }).days[0].starts).toEqual(['07:00']);
  });
});

describe('runTool writes', () => {
  it('requires reason and idempotency key', async () => {
    const { ctx } = makeCtx();
    const r = await runTool('add_session_credits', { client_id: 'u_thandi', sessions: 5 }, ctx);
    expect(r.ok).toBe(false);
    expect(r.error).toBe('invalid_params');
  });
  it('adds credits and settles the matching pending pack when a reference is given', async () => {
    const { ctx, st } = makeCtx();
    const r = await runTool('add_session_credits', { client_id: 'u_thandi', sessions: 5, reason: 'EFT received', payment_reference: 'TSE-4471', idempotency_key: key('a') }, ctx);
    expect(r.ok).toBe(true);
    expect((r.result as { sessions_left: number }).sessions_left).toBe(5);
    expect(st.packRequests[0].status).toBe('paid');
    expect(st.ledger.at(-1)!.actor).toBe('chatgpt:g_chatgpt');
  });
  it('replays the same idempotency key without applying twice', async () => {
    const { ctx } = makeCtx();
    const args = { client_id: 'u_thandi', sessions: 2, reason: 'goodwill', idempotency_key: key('b') };
    await runTool('add_session_credits', args, ctx);
    const again = await runTool('add_session_credits', args, ctx);
    expect(again.ok).toBe(true);
    expect((again.result as { status?: string }).status).toBe('replayed');
    expect(ctx.balance('u_thandi')).toBe(2);
  });
  it('enforces the daily cap of 40 sessions', async () => {
    const { ctx } = makeCtx();
    for (let i = 0; i < 2; i++) expect((await runTool('add_session_credits', { client_id: 'u_thandi', sessions: 20, reason: 'bulk load', idempotency_key: key('c' + i) }, ctx)).ok).toBe(true);
    const over = await runTool('add_session_credits', { client_id: 'u_pieter', sessions: 1, reason: 'one more', idempotency_key: key('d') }, ctx);
    expect(over.ok).toBe(false);
    expect(over.error).toBe('daily_cap');
    expect(ctx.balance('u_thandi')).toBe(DAILY_CREDIT_CAP);
  });
  it('remove_session_credits cannot go below zero', async () => {
    const { ctx } = makeCtx();
    const r = await runTool('remove_session_credits', { client_id: 'u_pieter', sessions: 5, reason: 'correction', idempotency_key: key('e') }, ctx);
    expect(r.ok).toBe(false);
    expect(ctx.balance('u_pieter')).toBe(3);
    const ok = await runTool('remove_session_credits', { client_id: 'u_pieter', sessions: 1, reason: 'correction', idempotency_key: key('f') }, ctx);
    expect(ok.ok).toBe(true);
    expect(ctx.balance('u_pieter')).toBe(2);
  });
  it('create_booking uses a session and maps local time to UTC', async () => {
    const { ctx, st } = makeCtx();
    const r = await runTool('create_booking', { client_id: 'u_pieter', start: '2026-11-12T09:00', idempotency_key: key('g') }, ctx);
    expect(r.ok).toBe(true);
    expect(st.bookings.at(-1)!.start).toBe('2026-11-12T07:00:00.000Z');
    expect(st.bookings.at(-1)!.createdBy).toBe('chatgpt');
    expect(ctx.balance('u_pieter')).toBe(2);
    const none = await runTool('create_booking', { client_id: 'u_thandi', start: '2026-11-12T10:00', idempotency_key: key('h') }, ctx);
    expect(none.ok).toBe(false);
    expect(none.error).toBe('no_credits');
  });
  it('cancel_booking refunds when asked and reports the new balance', async () => {
    const { ctx } = makeCtx();
    const r = await runTool('cancel_booking', { booking_id: 'b_1', refund_credit: true, reason: 'Client asked', idempotency_key: key('j') }, ctx);
    expect(r.ok).toBe(true);
    expect((r.result as { refunded: boolean; sessions_left: number }).sessions_left).toBe(4);
    expect((await runTool('cancel_booking', { booking_id: 'b_1', refund_credit: true, reason: 'again', idempotency_key: key('k') }, ctx)).ok).toBe(false);
  });
  it('reschedule_booking moves a future booking', async () => {
    const { ctx, st } = makeCtx();
    const r = await runTool('reschedule_booking', { booking_id: 'b_1', new_start: '2026-11-13T08:00', idempotency_key: key('l') }, ctx);
    expect(r.ok).toBe(true);
    expect(st.bookings[0].start).toBe('2026-11-13T06:00:00.000Z');
  });
});

describe('access control', () => {
  it('denies tools outside the grant scopes', async () => {
    const { ctx } = makeCtx({ scopes: ['bookings:read', 'clients:read'] });
    expect((await runTool('get_schedule', {}, ctx)).ok).toBe(true);
    const r = await runTool('add_session_credits', { client_id: 'u_thandi', sessions: 1, reason: 'test!', idempotency_key: key('i') }, ctx);
    expect(r.ok).toBe(false);
    expect(r.error).toBe('insufficient_scope');
  });
  it('denies everything once revoked', async () => {
    const { ctx } = makeCtx({ revoked: true });
    expect((await runTool('get_schedule', {}, ctx)).error).toBe('forbidden');
  });
});
