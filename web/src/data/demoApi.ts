// In-browser implementation of the Api interface (GitHub Pages demo mode).
// All data lives in localStorage under one key. Passwords are hashed with SubtleCrypto SHA-256:
// fine for a demo; the real server uses Argon2id (see docs/SECURITY.md).
import type { Api, LoginResult, SignupInput, McpCallResult } from './api';
import {
  ApiError,
  type AuditEntry, type Booking, type BookingSource, type BusinessSummary, type BusyBlock, type CalendarConnection,
  type ClientSummary, type ConnectorGrant, type LedgerEntry, type LedgerReason, type Package, type PackRequest,
  type Settings, type Slot, type User,
} from '../domain/types';
import { balanceOf, qualifiesForRefund } from '../domain/credits';
import { addDays, computeSlots, isSlotFree, localDayKey, localDayKeyMs } from '../domain/slots';
import { buildSeed, type DemoState, type StoredUser } from './seed';
import { canonicalPaymentReference, runTool } from '../domain/mcpTools';

let pendingOwner: string | null = null;
const STORE_KEY = 'tse-demo-v1';
const SESSION_KEY = 'tse-demo-user';

// ---------------------------------------------------------------- storage (never throws)
let memState: DemoState | null = null;
const memSession: Record<string, string> = {};

function readStore(): DemoState | null {
  try {
    const raw = window.localStorage.getItem(STORE_KEY);
    if (raw) {
      const parsed = JSON.parse(raw) as DemoState;
      if (parsed && parsed.version === 1) return parsed;
    }
  } catch {
    /* fall through to memory */
  }
  return null;
}
function writeStore(s: DemoState) {
  try {
    window.localStorage.setItem(STORE_KEY, JSON.stringify(s));
  } catch {
    /* in-memory only */
  }
}
function sessGet(): string | null {
  try {
    return window.sessionStorage.getItem(SESSION_KEY) ?? memSession[SESSION_KEY] ?? null;
  } catch {
    return memSession[SESSION_KEY] ?? null;
  }
}
function sessSet(id: string | null) {
  if (id) memSession[SESSION_KEY] = id;
  else delete memSession[SESSION_KEY];
  try {
    if (id) window.sessionStorage.setItem(SESSION_KEY, id);
    else window.sessionStorage.removeItem(SESSION_KEY);
  } catch {
    /* memory only */
  }
}

function state(): DemoState {
  if (!memState) {
    memState = readStore() ?? buildSeed(Date.now());
    writeStore(memState);
  }
  return memState;
}
function save(mutator: (s: DemoState) => void) {
  const s = state();
  mutator(s);
  writeStore(s);
}

// ---------------------------------------------------------------- demo authenticator
const TOTP_SALT = 0x5e7c4a31;
function totpFor(windowIndex: number): string {
  let h = Math.imul(windowIndex ^ TOTP_SALT, 2654435761) >>> 0;
  h = Math.imul(h ^ (h >>> 15), 2246822519) >>> 0;
  h = Math.imul(h ^ (h >>> 13), 3266489917) >>> 0;
  return String(((h ^ (h >>> 16)) >>> 0) % 1_000_000).padStart(6, '0'); // >>> 0: XOR yields a signed int32, which would print a minus sign
}
/** A 6-digit code that changes every 30 seconds, like an authenticator app. Demo only. */
export function demoTotpCode(nowMs: number = Date.now()): string {
  return totpFor(Math.floor(nowMs / 30000));
}
/** Seconds until the demo code changes. */
export function demoTotpSecondsLeft(nowMs: number = Date.now()): number {
  return 30 - Math.floor((nowMs % 30000) / 1000);
}

async function hashPassword(pw: string): Promise<string> {
  if (!globalThis.crypto?.subtle) throw new ApiError('server', 'This browser cannot hash passwords (needs HTTPS).');
  const buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(`tse-demo:${pw}`));
  return Array.from(new Uint8Array(buf)).map((b) => b.toString(16).padStart(2, '0')).join('');
}

// ---------------------------------------------------------------- helpers
const nowMs = () => Date.now();
const iso = (ms: number) => new Date(ms).toISOString();
function newId(prefix: string): string {
  let id = '';
  save((s) => {
    s.seq += 1;
    id = `${prefix}_${s.seq.toString(36)}${Math.floor(Math.random() * 1296).toString(36)}`;
  });
  return id;
}
const publicUser = (u: StoredUser): User => {
  // eslint-disable-next-line @typescript-eslint/no-unused-vars
  const { passwordHash, ...rest } = u;
  return rest;
};
const balanceFor = (userId: string) => balanceOf(state().ledger.filter((l) => l.userId === userId));

function slotInput(skipNotice = false) {
  const s = state();
  return {
    settings: s.settings,
    nowMs: nowMs(),
    google: s.google,
    bookings: s.bookings.filter((b) => b.status === 'confirmed'),
    skipNotice,
  };
}

function currentUser(): StoredUser | null {
  const id = sessGet();
  if (!id) return null;
  return state().users.find((u) => u.id === id) ?? null;
}
function requireUser(): StoredUser {
  const u = currentUser();
  if (!u) throw new ApiError('unauthorized', 'Please sign in.');
  return u;
}
function requireOwner(): StoredUser {
  const u = requireUser();
  if (u.role !== 'owner') throw new ApiError('forbidden', 'Owner only.');
  return u;
}

// ---------------------------------------------------------------- store logic (shared with the MCP tools)
function adjustCredits(userId: string, delta: number, reason: LedgerReason, note: string, actor: string): LedgerEntry {
  const entry: LedgerEntry = { id: newId('l'), userId, delta, reason, note, actor, createdAt: iso(nowMs()) };
  save((s) => void s.ledger.push(entry));
  return entry;
}

function createBooking(
  userId: string, slotStart: string, createdBy: BookingSource, actor: string,
  opts: { skipNotice?: boolean } = {},
): Booking {
  const s = state();
  const user = s.users.find((u) => u.id === userId && u.role === 'client');
  if (!user) throw new ApiError('not_found', 'Client not found.');
  if (balanceFor(userId) < 1) throw new ApiError('no_credits', 'No sessions left. Load a pack first.');
  const startMs = new Date(slotStart).getTime();
  if (Number.isNaN(startMs)) throw new ApiError('validation', 'Bad start time.');
  if (!isSlotFree(slotInput(opts.skipNotice), iso(startMs))) {
    throw new ApiError('conflict', 'That time was just taken. Please pick another.');
  }
  const booking: Booking = {
    id: newId('b'), userId, clientName: user.name, start: iso(startMs), end: iso(startMs + s.settings.sessionMinutes * 60000),
    status: 'confirmed', createdBy, googleEventId: s.calendar.connected ? `gcal_demo_${Math.random().toString(36).slice(2, 10)}` : undefined,
  };
  save((st) => void st.bookings.push(booking));
  adjustCredits(userId, -1, 'booking', 'Session booked', actor);
  return booking;
}

function cancelBooking(bookingId: string, refund: boolean, reason: string, actor: string): Booking {
  const b = state().bookings.find((x) => x.id === bookingId);
  if (!b) throw new ApiError('not_found', 'Booking not found.');
  if (b.status !== 'confirmed') throw new ApiError('conflict', 'Only confirmed bookings can be cancelled.');
  save(() => {
    b.status = 'cancelled';
    b.cancelledAt = iso(nowMs());
    b.cancelReason = reason;
    b.refunded = refund;
  });
  if (refund) adjustCredits(b.userId, 1, 'cancel_refund', reason || 'Session refunded', actor);
  return { ...b };
}

function rescheduleBooking(bookingId: string, newStart: string): Booking {
  const b = state().bookings.find((x) => x.id === bookingId);
  if (!b) throw new ApiError('not_found', 'Booking not found.');
  if (b.status !== 'confirmed') throw new ApiError('conflict', 'Only confirmed bookings can be moved.');
  const st = state();
  const others = { ...slotInput(true), bookings: st.bookings.filter((x) => x.status === 'confirmed' && x.id !== b.id) };
  const ms = new Date(newStart).getTime();
  if (Number.isNaN(ms)) throw new ApiError('validation', 'Bad start time.');
  if (!isSlotFree(others, iso(ms))) throw new ApiError('conflict', 'That time is not free.');
  save(() => {
    b.start = iso(ms);
    b.end = iso(ms + st.settings.sessionMinutes * 60000);
  });
  return { ...b };
}

function markPackPaid(packRequestId: string, reference: string | undefined, actor: string): PackRequest {
  const pr = state().packRequests.find((p) => p.id === packRequestId);
  if (!pr) throw new ApiError('not_found', 'Pack request not found.');
  if (pr.status !== 'pending') throw new ApiError('conflict', 'This request is already settled.');
  save(() => {
    pr.status = 'paid';
    pr.paidAt = iso(nowMs());
    pr.paymentReference = reference;
  });
  adjustCredits(pr.userId, pr.sessions, 'purchase', `${pr.sessions} session pack${reference ? ` (${reference})` : ''}`, actor);
  return { ...pr };
}

function busyBlocks(fromDate: string, toDate: string): BusyBlock[] {
  const s = state();
  const inRange = (isoStr: string) => {
    const d = localDayKey(isoStr);
    return d >= fromDate && d <= toDate;
  };
  const g = s.google.filter((x) => inRange(x.start));
  const b: BusyBlock[] = s.bookings
    .filter((x) => x.status === 'confirmed' && inRange(x.start))
    .map((x) => ({ start: x.start, end: x.end, source: 'booking' as const, label: x.clientName }));
  return [...g, ...b].sort((p, q) => p.start.localeCompare(q.start));
}

function freeSlots(fromDate: string, toDate: string, opts: { skipNotice?: boolean } = {}): Slot[] {
  return computeSlots({ ...slotInput(opts.skipNotice), fromDate, toDate });
}

function clientSummary(u: StoredUser): ClientSummary {
  const s = state();
  const now = nowMs();
  const mine = s.bookings.filter((b) => b.userId === u.id);
  const past = mine.filter((b) => (b.status === 'completed' || b.status === 'no_show') ).map((b) => b.start).sort();
  return {
    user: publicUser(u),
    balance: balanceFor(u.id),
    upcoming: mine.filter((b) => b.status === 'confirmed' && new Date(b.start).getTime() > now).length,
    lastVisit: past[past.length - 1],
    pendingPacks: s.packRequests.filter((p) => p.userId === u.id && p.status === 'pending').length,
  };
}

// ---------------------------------------------------------------- ChatGPT connector demo plumbing
export interface DemoStoreAccess {
  /** The grant the call is made under (demo: the active "ChatGPT" grant). */
  grantId: string;
  /** Actor string written to ledger rows, e.g. "chatgpt:g_chatgpt". */
  actor: string;
  getState(): DemoState;
  save(mutator: (s: DemoState) => void): void;
  now(): number;
  newId(prefix: string): string;
  settings(): Settings;
  balance(userId: string): number;
  /** Free session starts between two local dates. */
  freeSlots(fromDate: string, toDate: string, opts?: { skipNotice?: boolean }): Slot[];
  busyBlocks(fromDate: string, toDate: string): BusyBlock[];
  /** Throws ApiError('no_credits') or ApiError('conflict'). Writes the booking and the -1 ledger row. */
  createBooking(userId: string, slotStartIso: string, createdBy: BookingSource, actor: string, opts?: { skipNotice?: boolean }): Booking;
  /** `refund` true adds the +1 cancel_refund ledger row. */
  cancelBooking(bookingId: string, refund: boolean, reason: string, actor: string): Booking;
  rescheduleBooking(bookingId: string, newStartIso: string): Booking;
  adjustCredits(userId: string, delta: number, reason: LedgerReason, note: string, actor: string): LedgerEntry;
  markPackPaid(packRequestId: string, reference: string | undefined, actor: string): PackRequest;
  clientSummary(userId: string): ClientSummary | null;
}

const GRANT_ID = 'g_chatgpt';
const WRITE_TOOLS = new Set(['add_session_credits', 'remove_session_credits', 'create_booking', 'cancel_booking', 'reschedule_booking']);

export const demoStore: DemoStoreAccess = {
  grantId: GRANT_ID,
  actor: `chatgpt:${GRANT_ID}`,
  getState: state,
  save,
  now: nowMs,
  newId,
  settings: () => state().settings,
  balance: balanceFor,
  freeSlots,
  busyBlocks,
  createBooking,
  cancelBooking,
  rescheduleBooking,
  adjustCredits,
  markPackPaid,
  clientSummary: (id) => {
    const u = state().users.find((x) => x.id === id && x.role === 'client');
    return u ? clientSummary(u) : null;
  },
};

function redact(args: Record<string, unknown>): Record<string, unknown> {
  const out: Record<string, unknown> = {};
  for (const [k, v] of Object.entries(args ?? {})) out[k] = /key|token|secret|password/i.test(k) ? '[redacted]' : v;
  return out;
}

// ---------------------------------------------------------------- the Api
function paid(sum: Array<{ delta: number }>) { return sum.reduce((a, b) => a + b.delta, 0); }

export const demoApi: Api = {
  mode: 'demo',

  async me() {
    const u = currentUser();
    return u ? publicUser(u) : null;
  },

  async signup(input: SignupInput) {
    const name = input.name.trim();
    const email = input.email.trim().toLowerCase();
    if (name.length < 2) throw new ApiError('validation', 'Please enter your name.');
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) throw new ApiError('validation', 'Please enter a valid email address.');
    if (input.password.length < 10) throw new ApiError('validation', 'Password must be at least 10 characters.');
    if (state().users.some((u) => u.email === email)) throw new ApiError('conflict', 'An account with that email already exists.');
    const user: StoredUser = {
      id: newId('u'), role: 'client', name, email, phone: input.phone?.trim() || undefined,
      createdAt: iso(nowMs()), passwordHash: await hashPassword(input.password),
    };
    save((s) => void s.users.push(user));
    // No free credits. The welcome offer is a note: the first session is 50% off.
    adjustCredits(user.id, 0, 'welcome_offer', 'First session 50% off (R275). Pay at the studio.', user.id);
    sessSet(user.id);
    return publicUser(user);
  },

  async login(email, password): Promise<LoginResult> {
    const u = state().users.find((x) => x.email === email.trim().toLowerCase());
    const hash = await hashPassword(password);
    if (!u || u.passwordHash !== hash) throw new ApiError('unauthorized', 'Email or password is not right.');
    if (u.role === 'owner' && u.totpEnabled) {
      pendingOwner = u.id;
      return { needsTotp: true };
    }
    sessSet(u.id);
    return { user: publicUser(u) };
  },

  async verifyTotp(code) {
    const clean = code.replace(/\s/g, '');
    const w = Math.floor(nowMs() / 30000);
    const ok = clean === totpFor(w) || clean === totpFor(w - 1);
    const u = pendingOwner ? state().users.find((x) => x.id === pendingOwner) : null;
    if (!u || !ok) throw new ApiError('unauthorized', 'That code is not right.');
    pendingOwner = null;
    sessSet(u.id);
    return publicUser(u);
  },

  async logout() {
    pendingOwner = null;
    sessSet(null);
  },

  async packages(): Promise<Package[]> {
    return state().packages.map((p) => ({ ...p }));
  },
  async settings() {
    return JSON.parse(JSON.stringify(state().settings)) as Settings;
  },
  async availability(fromDate, toDate) {
    return freeSlots(fromDate, toDate);
  },

  async myBalance() {
    return balanceFor(requireUser().id);
  },
  async myLedger() {
    const u = requireUser();
    return state().ledger.filter((l) => l.userId === u.id).sort((a, b) => b.createdAt.localeCompare(a.createdAt));
  },
  async myBookings() {
    const u = requireUser();
    return state().bookings.filter((b) => b.userId === u.id).sort((a, b) => a.start.localeCompare(b.start)).map((b) => ({ ...b }));
  },
  async myPackRequests() {
    const u = requireUser();
    return state().packRequests.filter((p) => p.userId === u.id).sort((a, b) => b.createdAt.localeCompare(a.createdAt)).map((p) => ({ ...p }));
  },
  async requestPack(packageId) {
    const u = requireUser();
    const pkg = state().packages.find((p) => p.id === packageId);
    if (!pkg) throw new ApiError('not_found', 'Package not found.');
    const pr: PackRequest = {
      id: newId('pr'), userId: u.id, packageId: pkg.id, sessions: pkg.sessions, priceZar: pkg.priceZar,
      status: 'pending', createdAt: iso(nowMs()),
    };
    save((s) => void s.packRequests.push(pr));
    return { ...pr };
  },
  async book(slotStart) {
    const u = requireUser();
    return createBooking(u.id, slotStart, 'client', u.id);
  },
  async cancelBooking(bookingId) {
    const u = requireUser();
    const b = state().bookings.find((x) => x.id === bookingId);
    if (!b || b.userId !== u.id) throw new ApiError('not_found', 'Booking not found.');
    const refund = qualifiesForRefund(b.start, nowMs(), state().settings.cancelFreeHours);
    const booking = cancelBooking(bookingId, refund, refund ? 'Cancelled more than 24 h before' : 'Cancelled inside 24 h, no refund', u.id);
    return { booking, refunded: refund };
  },

  async adminClients(query) {
    requireOwner();
    const q = (query ?? '').trim().toLowerCase();
    return state().users
      .filter((u) => u.role === 'client' && (!q || u.name.toLowerCase().includes(q) || u.email.toLowerCase().includes(q)))
      .map(clientSummary)
      .sort((a, b) => a.user.name.localeCompare(b.user.name));
  },
  async adminClient(userId) {
    requireOwner();
    const u = state().users.find((x) => x.id === userId && x.role === 'client');
    if (!u) throw new ApiError('not_found', 'Client not found.');
    const s = state();
    return {
      summary: clientSummary(u),
      ledger: s.ledger.filter((l) => l.userId === userId).sort((a, b) => b.createdAt.localeCompare(a.createdAt)),
      bookings: s.bookings.filter((b) => b.userId === userId).sort((a, b) => b.start.localeCompare(a.start)).map((b) => ({ ...b })),
      packs: s.packRequests.filter((p) => p.userId === userId).sort((a, b) => b.createdAt.localeCompare(a.createdAt)).map((p) => ({ ...p })),
    };
  },
  async adminBookings(fromDate, toDate) {
    requireOwner();
    return state().bookings
      .filter((b) => {
        const d = localDayKey(b.start);
        return d >= fromDate && d <= toDate;
      })
      .sort((a, b) => a.start.localeCompare(b.start))
      .map((b) => ({ ...b }));
  },
  async adminBusy(fromDate, toDate) {
    requireOwner();
    return busyBlocks(fromDate, toDate);
  },
  async adminAdjustCredits(userId, delta, note) {
    const o = requireOwner();
    if (!Number.isInteger(delta) || delta === 0) throw new ApiError('validation', 'Enter a whole number of sessions.');
    if (!state().users.some((u) => u.id === userId && u.role === 'client')) throw new ApiError('not_found', 'Client not found.');
    if (balanceFor(userId) + delta < 0) throw new ApiError('validation', 'That would take the balance below zero.');
    return adjustCredits(userId, delta, 'owner_adjust', note.trim() || 'Adjusted by Ivan', o.id);
  },
  async adminMarkPackPaid(packRequestId, paymentReference) {
    const o = requireOwner();
    const ref = paymentReference === undefined ? undefined : canonicalPaymentReference(paymentReference);
    if (ref === null) throw new ApiError('validation', 'The payment reference must be 3 to 60 characters: letters, digits, spaces and . _ / # - only.');
    return markPackPaid(packRequestId, ref, o.id);
  },
  async adminBookFor(userId, slotStart) {
    const o = requireOwner();
    return createBooking(userId, slotStart, 'owner', o.id, { skipNotice: true });
  },
  async adminCancelBooking(bookingId, refund, reason) {
    const o = requireOwner();
    return cancelBooking(bookingId, refund, reason, o.id);
  },
  async adminSetBookingStatus(bookingId, status) {
    requireOwner();
    const b = state().bookings.find((x) => x.id === bookingId);
    if (!b) throw new ApiError('not_found', 'Booking not found.');
    if (b.status === 'cancelled') throw new ApiError('conflict', 'This booking was cancelled.');
    save(() => void (b.status = status));
    return { ...b };
  },
  async adminUpdateSettings(patch) {
    requireOwner();
    save((s) => {
      s.settings = { ...s.settings, ...patch, timezone: 'Africa/Johannesburg' };
    });
    return JSON.parse(JSON.stringify(state().settings)) as Settings;
  },
  async adminCalendar() {
    requireOwner();
    return { ...state().calendar };
  },
  async adminConnectCalendar() {
    requireOwner();
    const connection: CalendarConnection = {
      connected: true, accountEmail: 'ivan@example.com', calendarId: 'primary', lastSyncAt: iso(nowMs()), mode: 'demo',
    };
    save((s) => void (s.calendar = connection));
    return { connection: { ...connection } };
  },
  async adminDisconnectCalendar() {
    requireOwner();
    const connection: CalendarConnection = { connected: false, mode: 'demo' };
    save((s) => void (s.calendar = connection));
    return { ...connection };
  },
  async adminSummary(period): Promise<BusinessSummary> {
    requireOwner();
    const s = state();
    const today = localDayKeyMs(nowMs());
    const from = period === 'week' ? addDays(today, -6) : addDays(today, -29);
    const to = today;
    const inRange = (isoStr: string) => {
      const d = localDayKey(isoStr);
      return d >= from && d <= to;
    };
    const inWin = s.bookings.filter((b) => inRange(b.start));
    const fut = computeSlots({ ...slotInput(), fromDate: today, toDate: addDays(today, period === 'week' ? 6 : 29) });
    return {
      period, from, to,
      bookedSessions: inWin.filter((b) => b.status !== 'cancelled').length,
      completedSessions: inWin.filter((b) => b.status === 'completed').length,
      cancelledSessions: inWin.filter((b) => b.status === 'cancelled').length,
      freeSlotHours: Math.round((fut.length * s.settings.slotStepMinutes) / 60),
      creditsSold: paid(s.ledger.filter((l) => l.reason === 'purchase' && inRange(l.createdAt))),
      newClients: s.users.filter((u) => u.role === 'client' && inRange(u.createdAt)).length,
    };
  },
  async adminGrants(): Promise<ConnectorGrant[]> {
    requireOwner();
    return state().grants.map((g) => ({ ...g }));
  },
  async adminRevokeGrant(grantId) {
    requireOwner();
    const g = state().grants.find((x) => x.id === grantId);
    if (!g) throw new ApiError('not_found', 'Grant not found.');
    save(() => void (g.revokedAt = g.revokedAt ?? iso(nowMs())));
  },
  async adminPairing() {
    requireOwner();
    const until = state().pairingUntil ?? 0;
    return until > nowMs() ? { open: true, until: iso(until) } : { open: false, until: null };
  },
  async adminOpenPairing() {
    requireOwner();
    const until = nowMs() + 15 * 60_000;
    save((s) => void (s.pairingUntil = until));
    return { open: true, until: iso(until) };
  },
  async adminClosePairing() {
    requireOwner();
    save((s) => void (s.pairingUntil = 0));
    return { open: false, until: null };
  },
  async adminAudit(limit = 50): Promise<AuditEntry[]> {
    requireOwner();
    return [...state().audit].sort((a, b) => b.at.localeCompare(a.at)).slice(0, limit);
  },

  async demoCallTool(tool, args): Promise<McpCallResult> {
    requireOwner();
    const s = state();
    const grant = s.grants.find((g) => g.id === GRANT_ID);
    const write = WRITE_TOOLS.has(tool);
    const log = (status: AuditEntry['status'], summary: string) => {
      save((st) => {
        st.audit.unshift({ id: newId('a'), at: iso(nowMs()), grantId: GRANT_ID, tool, args: redact(args), status, summary });
        st.audit = st.audit.slice(0, 200);
        const g = st.grants.find((x) => x.id === GRANT_ID);
        if (g && status !== 'denied') g.lastUsedAt = iso(nowMs());
      });
    };
    if (!grant || grant.revokedAt) {
      log('denied', 'Denied: connector access was revoked');
      return { ok: false, summary: 'Connector access was revoked', error: 'forbidden' };
    }
    const now = nowMs();
    save((st) => void (st.callLog = st.callLog.filter((c) => now - c.at < 3600000)));
    const lastMinute = state().callLog.filter((c) => now - c.at < 60000).length;
    const writesHour = state().callLog.filter((c) => c.write).length;
    if (lastMinute >= 60 || (write && writesHour >= 20)) {
      log('rate_limited', write && writesHour >= 20 ? 'Rate limited: 20 writes per hour' : 'Rate limited: 60 calls per minute');
      return { ok: false, summary: 'Rate limit reached. Try again shortly.', error: 'rate_limited' };
    }
    save((st) => void st.callLog.push({ at: now, write }));
    let res: McpCallResult;
    try {
      res = await runTool(tool, args, demoStore as never);
    } catch (e) {
      const msg = e instanceof Error ? e.message : 'Tool failed';
      log('error', msg);
      return { ok: false, summary: msg, error: 'error' };
    }
    log(res.ok ? 'ok' : 'error', res.summary);
    return res;
  },

  async demoReset() {
    memState = buildSeed(Date.now());
    writeStore(memState);
    pendingOwner = null;
    sessSet(null);
  },
};

