// The ONE interface the UI uses. Pages import `api` from here and never touch storage or fetch directly.
// demoApi.ts implements it in the browser (GitHub Pages). httpApi.ts calls the PHP backend (Afrihost).
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

export interface SignupInput {
  name: string;
  email: string;
  phone?: string;
  password: string;
}

export interface LoginResult {
  user?: User;
  /** Owner accounts: password ok, now needs a 6-digit TOTP code */
  needsTotp?: boolean;
}

export interface McpCallResult {
  ok: boolean;
  /** JSON result returned to ChatGPT */
  result?: unknown;
  /** One-line human summary */
  summary: string;
  error?: string;
}

export interface Api {
  mode: 'demo' | 'http';

  // ---- Auth
  me(): Promise<User | null>;
  signup(input: SignupInput): Promise<User>;
  login(email: string, password: string): Promise<LoginResult>;
  verifyTotp(code: string): Promise<User>;
  logout(): Promise<void>;

  // ---- Public
  packages(): Promise<Package[]>;
  settings(): Promise<Settings>;
  /** Free slots between two local dates (YYYY-MM-DD, inclusive). */
  availability(fromDate: string, toDate: string): Promise<Slot[]>;

  // ---- Client (signed in)
  myBalance(): Promise<number>;
  myLedger(): Promise<LedgerEntry[]>;
  myBookings(): Promise<Booking[]>;
  myPackRequests(): Promise<PackRequest[]>;
  requestPack(packageId: string): Promise<PackRequest>;
  book(slotStart: string): Promise<Booking>;
  cancelBooking(bookingId: string): Promise<{ booking: Booking; refunded: boolean }>;

  // ---- Owner (role = owner)
  adminClients(query?: string): Promise<ClientSummary[]>;
  adminClient(userId: string): Promise<{ summary: ClientSummary; ledger: LedgerEntry[]; bookings: Booking[]; packs: PackRequest[] }>;
  adminBookings(fromDate: string, toDate: string): Promise<Booking[]>;
  adminBusy(fromDate: string, toDate: string): Promise<BusyBlock[]>;
  adminAdjustCredits(userId: string, delta: number, note: string): Promise<LedgerEntry>;
  adminMarkPackPaid(packRequestId: string, paymentReference?: string): Promise<PackRequest>;
  adminBookFor(userId: string, slotStart: string): Promise<Booking>;
  adminCancelBooking(bookingId: string, refund: boolean, reason: string): Promise<Booking>;
  adminSetBookingStatus(bookingId: string, status: 'completed' | 'no_show'): Promise<Booking>;
  adminUpdateSettings(patch: Partial<Settings>): Promise<Settings>;
  adminCalendar(): Promise<CalendarConnection>;
  /** Live: returns Google consent URL to redirect to. Demo: simulates a connection. */
  adminConnectCalendar(): Promise<{ redirectUrl?: string; connection: CalendarConnection }>;
  adminDisconnectCalendar(): Promise<CalendarConnection>;
  adminSummary(period: 'week' | 'month'): Promise<BusinessSummary>;
  adminGrants(): Promise<ConnectorGrant[]>;
  adminRevokeGrant(grantId: string): Promise<void>;
  adminAudit(limit?: number): Promise<AuditEntry[]>;

  // ---- Demo only: run an MCP tool the same way ChatGPT would (simulation page).
  demoCallTool?(tool: string, args: Record<string, unknown>): Promise<McpCallResult>;
  demoReset?(): Promise<void>;
}

import { demoApi } from './demoApi';
import { httpApi } from './httpApi';

export const api: Api = import.meta.env.VITE_API_MODE === 'http' ? httpApi : demoApi;
