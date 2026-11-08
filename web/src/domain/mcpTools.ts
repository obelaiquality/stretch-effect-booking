// ChatGPT connector tools, in-browser simulation. Same names and input schemas as server/src/Mcp/Tools.php
// (docs/BUILD-SPEC.md section 3). demoApi.demoCallTool wraps runTool with rate limits and the audit log.
//
// Rules enforced here: unknown fields rejected, types and limits checked, write tools need a reason and/or an
// idempotency key (replays return the first result with status "replayed"), credit changes are capped at
// 1-20 per call and 40 per day via ChatGPT, scopes are checked against the grant, and results are minimal
// (no password hashes, tokens or other clients' data the call did not ask for).
import type { McpCallResult } from '../data/api';
import type { DemoStoreAccess } from '../data/demoApi';
import { ApiError, type Scope } from './types';
import { addDays, localDayKey, localDayKeyMs, localToUtc, formatLocalTime } from './slots';

export type ToolContext = DemoStoreAccess;

export const DAILY_CREDIT_CAP = 40;
export const MAX_CREDITS_PER_CALL = 20;

export interface JsonSchema {
  type: 'object';
  properties: Record<string, PropSchema>;
  required: string[];
  additionalProperties: false;
}
export interface PropSchema {
  type: 'string' | 'integer' | 'boolean';
  description: string;
  enum?: string[];
  pattern?: string;
  minLength?: number;
  maxLength?: number;
  minimum?: number;
  maximum?: number;
  default?: string | number | boolean;
}

export interface ToolDef {
  name: string;
  title: string;
  description: string;
  inputSchema: JsonSchema;
  annotations: { readOnlyHint: boolean; destructiveHint: boolean; openWorldHint: false };
  scope: Scope;
  /** true for tools that change data */
  write: boolean;
}

const DATE = '^\\d{4}-\\d{2}-\\d{2}$';
const LOCAL_DT = '^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}(:\\d{2})?$';
const KEY = '^[A-Za-z0-9_-]{8,64}$';

const p = {
  date: (d: string): PropSchema => ({ type: 'string', description: d, pattern: DATE, minLength: 10, maxLength: 10 }),
  clientId: { type: 'string', description: 'Client id from search_clients.', minLength: 1, maxLength: 64, pattern: '^[A-Za-z0-9_-]+$' } as PropSchema,
  bookingId: { type: 'string', description: 'Booking id from get_schedule, list_bookings or get_client.', minLength: 1, maxLength: 64, pattern: '^[A-Za-z0-9_-]+$' } as PropSchema,
  sessions: { type: 'integer', description: 'Number of sessions, 1 to 20.', minimum: 1, maximum: MAX_CREDITS_PER_CALL } as PropSchema,
  reason: { type: 'string', description: 'Why, in plain words. Stored in the audit log.', minLength: 3, maxLength: 200 } as PropSchema,
  idem: { type: 'string', description: 'Unique key for this action (8 to 64 letters, digits, - or _). Sending the same key again repeats nothing.', pattern: KEY, minLength: 8, maxLength: 64 } as PropSchema,
  localStart: (d: string): PropSchema => ({ type: 'string', description: d, pattern: LOCAL_DT, minLength: 16, maxLength: 19 }),
};
const obj = (properties: Record<string, PropSchema>, required: string[]): JsonSchema => ({ type: 'object', properties, required, additionalProperties: false });
const READ = { readOnlyHint: true, destructiveHint: false, openWorldHint: false } as const;
const WRITE = { readOnlyHint: false, destructiveHint: false, openWorldHint: false } as const;
const DESTRUCTIVE = { readOnlyHint: false, destructiveHint: true, openWorldHint: false } as const;

export const TOOLS: ToolDef[] = [
  {
    name: 'get_schedule',
    title: 'Get schedule for a day',
    description: 'Lists the studio bookings and Google Calendar busy blocks for one day (local time, Africa/Johannesburg). Defaults to today.',
    inputSchema: obj({ date: p.date('Day as YYYY-MM-DD. Defaults to today.') }, []),
    annotations: READ, scope: 'bookings:read', write: false,
  },
  {
    name: 'list_bookings',
    title: 'List bookings',
    description: 'Lists bookings between two dates (inclusive, maximum 31 days), optionally filtered by status.',
    inputSchema: obj(
      {
        from: p.date('First day, YYYY-MM-DD.'),
        to: p.date('Last day, YYYY-MM-DD.'),
        status: { type: 'string', description: 'Only bookings with this status.', enum: ['confirmed', 'cancelled', 'completed', 'no_show'] },
      },
      ['from', 'to'],
    ),
    annotations: READ, scope: 'bookings:read', write: false,
  },
  {
    name: 'find_free_slots',
    title: 'Find free slots',
    description: 'Lists session start times that are free between two dates (inclusive, maximum 14 days). Working hours, Google Calendar and existing bookings are taken into account.',
    inputSchema: obj({ from: p.date('First day, YYYY-MM-DD.'), to: p.date('Last day, YYYY-MM-DD.') }, ['from', 'to']),
    annotations: READ, scope: 'bookings:read', write: false,
  },
  {
    name: 'search_clients',
    title: 'Search clients',
    description: 'Finds clients by part of their name or email. Returns at most 5 matches with their id and sessions left.',
    inputSchema: obj({ query: { type: 'string', description: 'Part of a name or email, at least 2 characters.', minLength: 2, maxLength: 60 } }, ['query']),
    annotations: READ, scope: 'clients:read', write: false,
  },
  {
    name: 'get_client',
    title: 'Get one client',
    description: 'Returns one client: sessions left, upcoming bookings, last visit and any pack waiting for payment.',
    inputSchema: obj({ client_id: p.clientId }, ['client_id']),
    annotations: READ, scope: 'clients:read', write: false,
  },
  {
    name: 'get_business_summary',
    title: 'Business summary',
    description: 'Totals for the last 7 days (week) or 30 days (month): sessions booked, completed and cancelled, free hours ahead, sessions sold, new clients.',
    inputSchema: obj({ period: { type: 'string', description: 'week or month.', enum: ['week', 'month'] } }, ['period']),
    annotations: READ, scope: 'bookings:read', write: false,
  },
  {
    name: 'add_session_credits',
    title: 'Add session credits',
    description: 'Adds sessions to a client after payment. Give the payment reference when you have one. At most 20 per call and 40 per day.',
    inputSchema: obj(
      {
        client_id: p.clientId,
        sessions: p.sessions,
        reason: p.reason,
        payment_reference: { type: 'string', description: 'Bank or EFT reference, if known.', maxLength: 60 },
        idempotency_key: p.idem,
      },
      ['client_id', 'sessions', 'reason', 'idempotency_key'],
    ),
    annotations: WRITE, scope: 'credits:write', write: true,
  },
  {
    name: 'remove_session_credits',
    title: 'Remove session credits',
    description: 'Removes sessions from a client, for corrections. Cannot take a balance below zero. At most 20 per call and 40 per day.',
    inputSchema: obj({ client_id: p.clientId, sessions: p.sessions, reason: p.reason, idempotency_key: p.idem }, ['client_id', 'sessions', 'reason', 'idempotency_key']),
    annotations: DESTRUCTIVE, scope: 'credits:write', write: true,
  },
  {
    name: 'create_booking',
    title: 'Create a booking',
    description: 'Books a free session for a client and uses one of their sessions. The start time is local time (Africa/Johannesburg).',
    inputSchema: obj({ client_id: p.clientId, start: p.localStart('Local start, YYYY-MM-DDTHH:MM.'), idempotency_key: p.idem }, ['client_id', 'start', 'idempotency_key']),
    annotations: WRITE, scope: 'bookings:write', write: true,
  },
  {
    name: 'cancel_booking',
    title: 'Cancel a booking',
    description: 'Cancels a future confirmed booking. Set refund_credit to give the session back.',
    inputSchema: obj(
      {
        booking_id: p.bookingId,
        refund_credit: { type: 'boolean', description: 'true to give the session back to the client.' },
        reason: p.reason,
        idempotency_key: p.idem,
      },
      ['booking_id', 'refund_credit', 'reason', 'idempotency_key'],
    ),
    annotations: DESTRUCTIVE, scope: 'bookings:write', write: true,
  },
  {
    name: 'reschedule_booking',
    title: 'Reschedule a booking',
    description: 'Moves a future confirmed booking to another free time (local time, Africa/Johannesburg). Costs no extra session.',
    inputSchema: obj(
      { booking_id: p.bookingId, new_start: p.localStart('New local start, YYYY-MM-DDTHH:MM.'), idempotency_key: p.idem },
      ['booking_id', 'new_start', 'idempotency_key'],
    ),
    annotations: WRITE, scope: 'bookings:write', write: true,
  },
];

// ---------------------------------------------------------------- validation
export function validateArgs(schema: JsonSchema, args: unknown): string[] {
  const errs: string[] = [];
  if (args === null || typeof args !== 'object' || Array.isArray(args)) return ['arguments must be an object'];
  const a = args as Record<string, unknown>;
  for (const k of Object.keys(a)) if (!(k in schema.properties)) errs.push(`unknown field "${k}"`);
  for (const k of schema.required) if (a[k] === undefined || a[k] === null) errs.push(`"${k}" is required`);
  for (const [k, def] of Object.entries(schema.properties)) {
    const v = a[k];
    if (v === undefined) continue;
    if (def.type === 'string') {
      if (typeof v !== 'string') { errs.push(`"${k}" must be a string`); continue; }
      if (def.minLength !== undefined && v.length < def.minLength) errs.push(`"${k}" is too short (min ${def.minLength})`);
      if (def.maxLength !== undefined && v.length > def.maxLength) errs.push(`"${k}" is too long (max ${def.maxLength})`);
      if (def.pattern && !new RegExp(def.pattern).test(v)) errs.push(`"${k}" has the wrong format`);
      if (def.enum && !def.enum.includes(v)) errs.push(`"${k}" must be one of ${def.enum.join(', ')}`);
    } else if (def.type === 'integer') {
      if (typeof v !== 'number' || !Number.isInteger(v)) { errs.push(`"${k}" must be a whole number`); continue; }
      if (def.minimum !== undefined && v < def.minimum) errs.push(`"${k}" must be at least ${def.minimum}`);
      if (def.maximum !== undefined && v > def.maximum) errs.push(`"${k}" must be at most ${def.maximum}`);
    } else if (typeof v !== 'boolean') errs.push(`"${k}" must be true or false`);
  }
  return errs;
}

// ---------------------------------------------------------------- helpers
const fail = (summary: string, error: string): McpCallResult => ({ ok: false, summary, error });
const clean = (s: string) => s.replace(/[\u0000-\u001f\u007f]/g, ' ').slice(0, 60);
/** UTC ISO -> local "YYYY-MM-DDTHH:MM" */
const toLocal = (isoUtc: string) => `${localDayKey(isoUtc)}T${formatLocalTime(isoUtc)}`;
const daysBetween = (a: string, b: string) => Math.round((Date.parse(b + 'T00:00:00Z') - Date.parse(a + 'T00:00:00Z')) / 86400000);

function findClient(ctx: ToolContext, id: string) {
  return ctx.getState().users.find((u) => u.id === id && u.role === 'client');
}

function usedToday(ctx: ToolContext): number {
  const today = localDayKeyMs(ctx.now());
  return ctx
    .getState()
    .ledger.filter((l) => l.actor.startsWith('chatgpt') && (l.reason === 'chatgpt_adjust' || l.reason === 'purchase') && localDayKey(l.createdAt) === today)
    .reduce((n, l) => n + Math.abs(l.delta), 0);
}

function bookingRow(b: { id: string; userId: string; clientName: string; start: string; end: string; status: string }) {
  return { booking_id: b.id, client_id: b.userId, client_name: clean(b.clientName), start: toLocal(b.start), end: toLocal(b.end), status: b.status };
}

// ---------------------------------------------------------------- handlers
type Args = Record<string, unknown>;
type Handler = (a: Args, ctx: ToolContext) => McpCallResult;

const HANDLERS: Record<string, Handler> = {
  get_schedule(a, ctx) {
    const date = (a.date as string | undefined) ?? localDayKeyMs(ctx.now());
    const st = ctx.getState();
    const bookings = st.bookings
      .filter((b) => b.status !== 'cancelled' && localDayKey(b.start) === date)
      .sort((x, y) => x.start.localeCompare(y.start))
      .map(bookingRow);
    const busy = ctx
      .busyBlocks(date, date)
      .filter((b) => b.source === 'google')
      .map((b) => ({ start: toLocal(b.start), end: toLocal(b.end), label: 'Busy (Google Calendar)' }));
    return {
      ok: true,
      result: { date, bookings, busy },
      summary: `${bookings.length} ${bookings.length === 1 ? 'booking' : 'bookings'} and ${busy.length} calendar ${busy.length === 1 ? 'block' : 'blocks'} on ${date}`,
    };
  },

  list_bookings(a, ctx) {
    const from = a.from as string;
    const to = a.to as string;
    if (to < from) return fail('"to" is before "from"', 'invalid_params');
    if (daysBetween(from, to) > 30) return fail('Range is too long (maximum 31 days)', 'invalid_params');
    const status = a.status as string | undefined;
    const items = ctx
      .getState()
      .bookings.filter((b) => localDayKey(b.start) >= from && localDayKey(b.start) <= to && (!status || b.status === status))
      .sort((x, y) => x.start.localeCompare(y.start))
      .slice(0, 100)
      .map(bookingRow);
    return { ok: true, result: { from, to, count: items.length, bookings: items }, summary: `${items.length} ${items.length === 1 ? 'booking' : 'bookings'} from ${from} to ${to}` };
  },

  find_free_slots(a, ctx) {
    const from = a.from as string;
    const to = a.to as string;
    if (to < from) return fail('"to" is before "from"', 'invalid_params');
    if (daysBetween(from, to) > 13) return fail('Range is too long (maximum 14 days)', 'invalid_params');
    const byDay = new Map<string, string[]>();
    for (const s of ctx.freeSlots(from, to)) {
      const d = localDayKey(s.start);
      byDay.set(d, [...(byDay.get(d) ?? []), formatLocalTime(s.start)]);
    }
    const days = [...byDay.entries()].map(([date, starts]) => ({ date, starts }));
    const total = days.reduce((n, d) => n + d.starts.length, 0);
    return { ok: true, result: { days }, summary: `${total} free start times across ${days.length} ${days.length === 1 ? 'day' : 'days'}` };
  },

  search_clients(a, ctx) {
    const q = (a.query as string).trim().toLowerCase();
    if (q.length < 2) return fail('Query must be at least 2 characters', 'invalid_params');
    const hits = ctx
      .getState()
      .users.filter((u) => u.role === 'client' && (u.name.toLowerCase().includes(q) || u.email.toLowerCase().includes(q)))
      .slice(0, 5)
      .map((u) => ({ client_id: u.id, name: clean(u.name), sessions_left: ctx.balance(u.id) }));
    return { ok: true, result: { clients: hits }, summary: `${hits.length} ${hits.length === 1 ? 'client matches' : 'clients match'}` };
  },

  get_client(a, ctx) {
    const u = findClient(ctx, a.client_id as string);
    if (!u) return fail('Client not found', 'not_found');
    const sum = ctx.clientSummary(u.id);
    const st = ctx.getState();
    const upcoming = st.bookings
      .filter((b) => b.userId === u.id && b.status === 'confirmed' && Date.parse(b.start) > ctx.now())
      .sort((x, y) => x.start.localeCompare(y.start))
      .map(bookingRow);
    const pending = st.packRequests
      .filter((r) => r.userId === u.id && r.status === 'pending')
      .map((r) => ({ sessions: r.sessions, price_zar: r.priceZar }));
    return {
      ok: true,
      result: {
        client_id: u.id,
        name: clean(u.name),
        email: u.email,
        sessions_left: sum?.balance ?? ctx.balance(u.id),
        upcoming_bookings: upcoming,
        last_visit: sum?.lastVisit ? toLocal(sum.lastVisit) : null,
        packs_waiting_for_payment: pending,
      },
      summary: `${clean(u.name)} has ${ctx.balance(u.id)} sessions left and ${upcoming.length} upcoming`,
    };
  },

  get_business_summary(a, ctx) {
    const period = a.period as 'week' | 'month';
    const st = ctx.getState();
    const today = localDayKeyMs(ctx.now());
    const from = addDays(today, period === 'week' ? -6 : -29);
    const inRange = (iso: string) => localDayKey(iso) >= from && localDayKey(iso) <= today;
    const win = st.bookings.filter((b) => inRange(b.start));
    const ahead = ctx.freeSlots(today, addDays(today, period === 'week' ? 6 : 29));
    const result = {
      period,
      from,
      to: today,
      sessions_booked: win.filter((b) => b.status !== 'cancelled').length,
      sessions_completed: win.filter((b) => b.status === 'completed').length,
      sessions_cancelled: win.filter((b) => b.status === 'cancelled').length,
      free_hours_ahead: Math.round((ahead.length * ctx.settings().slotStepMinutes) / 60),
      sessions_sold: st.ledger.filter((l) => l.reason === 'purchase' && inRange(l.createdAt)).reduce((n, l) => n + l.delta, 0),
      new_clients: st.users.filter((u) => u.role === 'client' && inRange(u.createdAt)).length,
    };
    return { ok: true, result, summary: `${result.sessions_booked} booked, ${result.sessions_completed} completed, ${result.sessions_cancelled} cancelled over the last ${period === 'week' ? 7 : 30} days` };
  },

  add_session_credits(a, ctx) {
    const u = findClient(ctx, a.client_id as string);
    if (!u) return fail('Client not found', 'not_found');
    const n = a.sessions as number;
    if (usedToday(ctx) + n > DAILY_CREDIT_CAP) return fail(`Daily limit reached: ChatGPT can change at most ${DAILY_CREDIT_CAP} sessions a day. Use the admin screen for more.`, 'daily_cap');
    const ref = (a.payment_reference as string | undefined)?.trim() || undefined;
    const note = `${a.reason as string}${ref ? ` (ref ${ref})` : ''}`;
    // A matching pack request waiting for payment gets settled, so the owner's pending list stays right.
    const pending = ctx.getState().packRequests.find((r) => r.userId === u.id && r.status === 'pending' && r.sessions === n);
    let settled = false;
    if (ref && pending) {
      ctx.markPackPaid(pending.id, ref, ctx.actor);
      settled = true;
    } else {
      ctx.adjustCredits(u.id, n, 'chatgpt_adjust', note, ctx.actor);
    }
    const left = ctx.balance(u.id);
    return {
      ok: true,
      result: { client_id: u.id, name: clean(u.name), added: n, sessions_left: left, pack_request_settled: settled },
      summary: `Added ${n} ${n === 1 ? 'session' : 'sessions'} to ${clean(u.name)}. They now have ${left}.`,
    };
  },

  remove_session_credits(a, ctx) {
    const u = findClient(ctx, a.client_id as string);
    if (!u) return fail('Client not found', 'not_found');
    const n = a.sessions as number;
    if (ctx.balance(u.id) < n) return fail(`${clean(u.name)} only has ${ctx.balance(u.id)} sessions; cannot remove ${n}`, 'insufficient_balance');
    if (usedToday(ctx) + n > DAILY_CREDIT_CAP) return fail(`Daily limit reached: ChatGPT can change at most ${DAILY_CREDIT_CAP} sessions a day. Use the admin screen for more.`, 'daily_cap');
    ctx.adjustCredits(u.id, -n, 'chatgpt_adjust', a.reason as string, ctx.actor);
    const left = ctx.balance(u.id);
    return {
      ok: true,
      result: { client_id: u.id, name: clean(u.name), removed: n, sessions_left: left },
      summary: `Removed ${n} ${n === 1 ? 'session' : 'sessions'} from ${clean(u.name)}. They now have ${left}.`,
    };
  },

  create_booking(a, ctx) {
    const u = findClient(ctx, a.client_id as string);
    if (!u) return fail('Client not found', 'not_found');
    const startUtc = localToUtc(a.start as string);
    const b = ctx.createBooking(u.id, startUtc, 'chatgpt', ctx.actor);
    return {
      ok: true,
      result: { ...bookingRow(b), sessions_left: ctx.balance(u.id) },
      summary: `Booked ${clean(u.name)} for ${toLocal(b.start).replace('T', ' ')}`,
    };
  },

  cancel_booking(a, ctx) {
    const b = ctx.getState().bookings.find((x) => x.id === a.booking_id);
    if (!b) return fail('Booking not found', 'not_found');
    if (b.status !== 'confirmed') return fail('Only confirmed bookings can be cancelled', 'conflict');
    if (Date.parse(b.start) <= ctx.now()) return fail('That booking is in the past; mark it in the admin screen instead', 'conflict');
    const refund = a.refund_credit as boolean;
    const done = ctx.cancelBooking(b.id, refund, a.reason as string, ctx.actor);
    return {
      ok: true,
      result: { ...bookingRow(done), refunded: refund, sessions_left: ctx.balance(done.userId) },
      summary: `Cancelled ${clean(done.clientName)} on ${toLocal(done.start).replace('T', ' ')}${refund ? ', session refunded' : ', no refund'}`,
    };
  },

  reschedule_booking(a, ctx) {
    const b = ctx.getState().bookings.find((x) => x.id === a.booking_id);
    if (!b) return fail('Booking not found', 'not_found');
    if (b.status !== 'confirmed') return fail('Only confirmed bookings can be moved', 'conflict');
    if (Date.parse(b.start) <= ctx.now()) return fail('That booking is in the past', 'conflict');
    const moved = ctx.rescheduleBooking(b.id, localToUtc(a.new_start as string));
    return { ok: true, result: bookingRow(moved), summary: `Moved ${clean(moved.clientName)} to ${toLocal(moved.start).replace('T', ' ')}` };
  },
};

// ---------------------------------------------------------------- entry point
export async function runTool(name: string, args: unknown, ctx: ToolContext): Promise<McpCallResult> {
  const tool = TOOLS.find((t) => t.name === name);
  if (!tool) return fail(`Unknown tool "${name}"`, 'unknown_tool');

  const errs = validateArgs(tool.inputSchema, args ?? {});
  if (errs.length) return fail(`Invalid arguments: ${errs.slice(0, 3).join('; ')}`, 'invalid_params');
  const a = (args ?? {}) as Args;

  const grant = ctx.getState().grants.find((g) => g.id === ctx.grantId);
  if (!grant || grant.revokedAt) return fail('Connector access was revoked', 'forbidden');
  if (!grant.scopes.includes(tool.scope)) return fail(`This connection does not have the "${tool.scope}" permission`, 'insufficient_scope');

  const idemKey = tool.write && typeof a.idempotency_key === 'string' ? `${ctx.grantId}:${tool.name}:${a.idempotency_key}` : null;
  if (idemKey) {
    const first = ctx.getState().idem[idemKey] as McpCallResult | undefined;
    if (first) {
      const result = first.result && typeof first.result === 'object' ? { ...(first.result as object), status: 'replayed' } : { status: 'replayed' };
      return { ...first, result, summary: `${first.summary} (already done, not repeated)`, replayed: true } as McpCallResult;
    }
  }

  let res: McpCallResult;
  try {
    res = HANDLERS[tool.name](a, ctx);
  } catch (e) {
    if (e instanceof ApiError) return fail(e.message, e.code);
    return fail('The studio system could not do that', 'server');
  }
  if (res.ok && idemKey) {
    ctx.save((s) => {
      s.idem[idemKey] = res;
    });
  }
  return res;
}
