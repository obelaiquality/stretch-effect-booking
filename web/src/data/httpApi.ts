/*
 * HTTP implementation of Api: talks to the PHP backend on the same origin (Afrihost, live mode).
 * Also documented in docs/API.md. JSON in and out. Errors are {error:{code,message}}.
 * State-changing requests send X-CSRF-Token (token from GET /api/csrf, fetched once).
 *
 * | Api method              | HTTP                                                          |
 * | ----------------------- | ------------------------------------------------------------- |
 * | me                      | GET /api/me                                                   |
 * | signup                  | POST /api/auth/signup                                         |
 * | login                   | POST /api/auth/login -> {user} or {needsTotp:true}            |
 * | verifyTotp              | POST /api/auth/totp {code}                                    |
 * | logout                  | POST /api/auth/logout                                         |
 * | packages                | GET /api/packages                                             |
 * | settings                | GET /api/settings                                             |
 * | availability            | GET /api/availability?from=&to=                               |
 * | myBalance               | GET /api/me/balance -> {balance}                              |
 * | myLedger                | GET /api/me/ledger                                            |
 * | myBookings              | GET /api/me/bookings                                          |
 * | myPackRequests          | GET /api/me/packs                                             |
 * | requestPack             | POST /api/me/packs {packageId}                                |
 * | book                    | POST /api/me/bookings {start}                                 |
 * | cancelBooking           | POST /api/me/bookings/{id}/cancel -> {booking, refunded}      |
 * | adminClients            | GET /api/admin/clients?q=                                     |
 * | adminClient             | GET /api/admin/clients/{id}                                   |
 * | adminBookings           | GET /api/admin/bookings?from=&to=                             |
 * | adminBusy               | GET /api/admin/busy?from=&to=                                 |
 * | adminAdjustCredits      | POST /api/admin/clients/{id}/credits {delta,note}             |
 * | adminMarkPackPaid       | POST /api/admin/packs/{id}/paid {paymentReference}            |
 * | adminBookFor            | POST /api/admin/bookings {userId,start}                       |
 * | adminCancelBooking      | POST /api/admin/bookings/{id}/cancel {refund,reason}          |
 * | adminSetBookingStatus   | POST /api/admin/bookings/{id}/status {status}                 |
 * | adminUpdateSettings     | PATCH /api/admin/settings                                     |
 * | adminCalendar           | GET /api/admin/calendar                                       |
 * | adminConnectCalendar    | POST /api/admin/calendar/connect -> {redirectUrl, connection} |
 * | adminDisconnectCalendar | POST /api/admin/calendar/disconnect                           |
 * | adminSummary            | GET /api/admin/summary?period=                                |
 * | adminGrants             | GET /api/admin/grants                                         |
 * | adminRevokeGrant        | POST /api/admin/grants/{id}/revoke                            |
 * | adminAudit              | GET /api/admin/audit?limit=                                   |
 * | adminPairing            | GET /api/admin/connector/pairing                              |
 * | adminOpenPairing        | POST /api/admin/connector/pairing                             |
 * | adminClosePairing       | POST /api/admin/connector/pairing/close                       |
 *
 * demoCallTool and demoReset are not implemented in http mode.
 */
import type { Api, LoginResult, PairingWindow, SignupInput } from './api';
import { ApiError } from '../domain/types';
import type {
  AuditEntry,
  Booking,
  BusinessSummary,
  BusyBlock,
  CalendarConnection,
  ClientSummary,
  ConnectorGrant,
  LedgerEntry,
  Package,
  PackRequest,
  Settings,
  Slot,
  User,
} from '../domain/types';

type ErrCode = ConstructorParameters<typeof ApiError>[0];
const CODES: ErrCode[] = ['unauthorized', 'forbidden', 'not_found', 'validation', 'conflict', 'no_credits', 'rate_limited', 'server'];

let csrfToken: string | null = null;
let csrfPending: Promise<string> | null = null;

async function getCsrf(force = false): Promise<string> {
  if (!force && csrfToken) return csrfToken;
  if (force) csrfToken = null;
  if (!csrfPending) {
    csrfPending = fetch('/api/csrf', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(async (r) => {
        if (!r.ok) throw new ApiError('server', 'Could not start a secure session.');
        const j = (await r.json()) as { token: string };
        csrfToken = j.token;
        return j.token;
      })
      .finally(() => {
        csrfPending = null;
      });
  }
  return csrfPending;
}

function toError(status: number, body: unknown): ApiError {
  const e = (body as { error?: { code?: string; message?: string } } | null)?.error;
  let code: ErrCode = 'server';
  if (e?.code && (CODES as string[]).includes(e.code)) code = e.code as ErrCode;
  else if (status === 401) code = 'unauthorized';
  else if (status === 403) code = 'forbidden';
  else if (status === 404) code = 'not_found';
  else if (status === 409) code = 'conflict';
  else if (status === 422 || status === 400) code = 'validation';
  else if (status === 429) code = 'rate_limited';
  return new ApiError(code, e?.message ?? `Request failed (${status}).`);
}

async function call<T>(method: 'GET' | 'POST' | 'PATCH' | 'DELETE', path: string, body?: unknown, retried = false): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (method !== 'GET') headers['X-CSRF-Token'] = await getCsrf();
  let res: Response;
  try {
    res = await fetch(path, {
      method,
      credentials: 'same-origin',
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
    });
  } catch {
    throw new ApiError('server', 'Could not reach the server. Check your connection and try again.');
  }
  let json: unknown = null;
  const text = await res.text();
  if (text) {
    try {
      json = JSON.parse(text);
    } catch {
      json = null;
    }
  }
  if (!res.ok) {
    const err = toError(res.status, json);
    // A stale CSRF token (session rotated): refresh once and retry.
    if (res.status === 403 && method !== 'GET' && !retried && /csrf/i.test(err.message)) {
      await getCsrf(true);
      return call<T>(method, path, body, true);
    }
    throw err;
  }
  return json as T;
}

const qs = (o: Record<string, string | number | undefined>) => {
  const p = new URLSearchParams();
  for (const [k, v] of Object.entries(o)) if (v !== undefined && v !== '') p.set(k, String(v));
  const s = p.toString();
  return s ? `?${s}` : '';
};
const enc = encodeURIComponent;

export const httpApi: Api = {
  mode: 'http',

  async me() {
    try {
      const r = await call<{ user: User | null } | User | null>('GET', '/api/me');
      if (r && typeof r === 'object' && 'user' in r) return (r as { user: User | null }).user;
      return (r as User | null) ?? null;
    } catch (e) {
      if (e instanceof ApiError && e.code === 'unauthorized') return null;
      throw e;
    }
  },
  async signup(input: SignupInput) {
    const r = await call<{ user: User }>('POST', '/api/auth/signup', input);
    csrfToken = null;
    return r.user;
  },
  async login(email, password) {
    const r = await call<LoginResult>('POST', '/api/auth/login', { email, password });
    csrfToken = null;
    return r;
  },
  async verifyTotp(code) {
    const r = await call<{ user: User }>('POST', '/api/auth/totp', { code });
    csrfToken = null;
    return r.user;
  },
  async logout() {
    await call<unknown>('POST', '/api/auth/logout', {});
    csrfToken = null;
  },

  packages: () => call<Package[]>('GET', '/api/packages'),
  settings: () => call<Settings>('GET', '/api/settings'),
  availability: (from, to) => call<Slot[]>('GET', `/api/availability${qs({ from, to })}`),

  async myBalance() {
    const r = await call<{ balance: number }>('GET', '/api/me/balance');
    return r.balance;
  },
  myLedger: () => call<LedgerEntry[]>('GET', '/api/me/ledger'),
  myBookings: () => call<Booking[]>('GET', '/api/me/bookings'),
  myPackRequests: () => call<PackRequest[]>('GET', '/api/me/packs'),
  requestPack: (packageId) => call<PackRequest>('POST', '/api/me/packs', { packageId }),
  book: (start) => call<Booking>('POST', '/api/me/bookings', { start }),
  cancelBooking: (id) => call<{ booking: Booking; refunded: boolean }>('POST', `/api/me/bookings/${enc(id)}/cancel`, {}),

  adminClients: (q) => call<ClientSummary[]>('GET', `/api/admin/clients${qs({ q })}`),
  adminClient: (id) =>
    call<{ summary: ClientSummary; ledger: LedgerEntry[]; bookings: Booking[]; packs: PackRequest[] }>('GET', `/api/admin/clients/${enc(id)}`),
  adminBookings: (from, to) => call<Booking[]>('GET', `/api/admin/bookings${qs({ from, to })}`),
  adminBusy: (from, to) => call<BusyBlock[]>('GET', `/api/admin/busy${qs({ from, to })}`),
  adminAdjustCredits: (id, delta, note) => call<LedgerEntry>('POST', `/api/admin/clients/${enc(id)}/credits`, { delta, note }),
  adminMarkPackPaid: (id, paymentReference) => call<PackRequest>('POST', `/api/admin/packs/${enc(id)}/paid`, { paymentReference }),
  adminBookFor: (userId, start) => call<Booking>('POST', '/api/admin/bookings', { userId, start }),
  adminCancelBooking: (id, refund, reason) => call<Booking>('POST', `/api/admin/bookings/${enc(id)}/cancel`, { refund, reason }),
  adminSetBookingStatus: (id, status) => call<Booking>('POST', `/api/admin/bookings/${enc(id)}/status`, { status }),
  adminUpdateSettings: (patch) => call<Settings>('PATCH', '/api/admin/settings', patch),
  adminCalendar: () => call<CalendarConnection>('GET', '/api/admin/calendar'),
  adminConnectCalendar: () => call<{ redirectUrl?: string; connection: CalendarConnection }>('POST', '/api/admin/calendar/connect', {}),
  adminDisconnectCalendar: () => call<CalendarConnection>('POST', '/api/admin/calendar/disconnect', {}),
  adminSummary: (period) => call<BusinessSummary>('GET', `/api/admin/summary${qs({ period })}`),
  adminGrants: () => call<ConnectorGrant[]>('GET', '/api/admin/grants'),
  async adminRevokeGrant(id) {
    await call<unknown>('POST', `/api/admin/grants/${enc(id)}/revoke`, {});
  },
  adminAudit: (limit) => call<AuditEntry[]>('GET', `/api/admin/audit${qs({ limit })}`),
  adminPairing: () => call<PairingWindow>('GET', '/api/admin/connector/pairing'),
  adminOpenPairing: () => call<PairingWindow>('POST', '/api/admin/connector/pairing', {}),
  adminClosePairing: () => call<PairingWindow>('POST', '/api/admin/connector/pairing/close', {}),
};
