// Shared domain types. Both demoApi (browser) and httpApi (PHP backend) return these shapes.
// Times: ISO 8601 strings in UTC ("2026-11-09T06:00:00.000Z"). Display in Africa/Johannesburg.

export type Role = 'client' | 'owner';

export interface User {
  id: string;
  role: Role;
  name: string;
  email: string;
  phone?: string;
  createdAt: string;
  /** Owner only: 2FA enabled */
  totpEnabled?: boolean;
}

export type LedgerReason =
  | 'purchase'
  | 'booking'
  | 'cancel_refund'
  | 'owner_adjust'
  | 'chatgpt_adjust'
  | 'welcome_offer';

export interface LedgerEntry {
  id: string;
  userId: string;
  delta: number; // +n adds sessions, -n uses sessions
  reason: LedgerReason;
  note?: string;
  actor: string; // user id, or "chatgpt:<grantId>"
  createdAt: string;
}

export interface Package {
  id: 'single' | 'five' | 'ten' | string;
  name: string;
  sessions: number;
  priceZar: number;
  note?: string;
}

export type PackRequestStatus = 'pending' | 'paid' | 'cancelled';

export interface PackRequest {
  id: string;
  userId: string;
  packageId: string;
  sessions: number;
  priceZar: number;
  status: PackRequestStatus;
  createdAt: string;
  paidAt?: string;
  paymentReference?: string;
}

export type BookingStatus = 'confirmed' | 'cancelled' | 'completed' | 'no_show';
export type BookingSource = 'client' | 'owner' | 'chatgpt';

export interface Booking {
  id: string;
  userId: string;
  clientName: string;
  start: string; // UTC ISO
  end: string; // UTC ISO
  status: BookingStatus;
  createdBy: BookingSource;
  googleEventId?: string;
  cancelledAt?: string;
  cancelReason?: string;
  refunded?: boolean;
}

export interface Slot {
  start: string; // UTC ISO
  end: string; // UTC ISO
}

export interface BusyBlock {
  start: string;
  end: string;
  source: 'google' | 'booking';
  label?: string; // owner-only view, e.g. "Busy (Google Calendar)"
}

/** Weekday 0 = Sunday ... 6 = Saturday. Times are local "HH:MM" in Africa/Johannesburg. */
export interface WorkingDay {
  weekday: number;
  open: boolean;
  start: string; // "06:00"
  end: string; // "19:00"
}

export interface Settings {
  timezone: 'Africa/Johannesburg';
  sessionMinutes: number; // 60
  bufferMinutes: number; // 15
  slotStepMinutes: number; // 30
  bookingWindowDays: number; // 28
  minNoticeHours: number; // 12
  cancelFreeHours: number; // 24
  workingHours: WorkingDay[];
}

export interface ClientSummary {
  user: User;
  balance: number;
  upcoming: number; // count of upcoming confirmed bookings
  lastVisit?: string;
  pendingPacks: number;
}

export interface CalendarConnection {
  connected: boolean;
  accountEmail?: string;
  calendarId?: string;
  lastSyncAt?: string;
  mode: 'demo' | 'live';
}

export type Scope = 'bookings:read' | 'bookings:write' | 'clients:read' | 'credits:write';

export interface ConnectorGrant {
  id: string;
  clientName: string; // e.g. "ChatGPT"
  scopes: Scope[];
  createdAt: string;
  lastUsedAt?: string;
  revokedAt?: string;
}

export interface AuditEntry {
  id: string;
  at: string;
  grantId: string;
  tool: string;
  args: Record<string, unknown>; // redacted
  status: 'ok' | 'denied' | 'error' | 'rate_limited' | 'replayed';
  summary: string;
}

export interface BusinessSummary {
  period: 'week' | 'month';
  from: string;
  to: string;
  bookedSessions: number;
  completedSessions: number;
  cancelledSessions: number;
  freeSlotHours: number;
  creditsSold: number;
  newClients: number;
}

export class ApiError extends Error {
  constructor(
    public code:
      | 'unauthorized'
      | 'forbidden'
      | 'not_found'
      | 'validation'
      | 'conflict'
      | 'no_credits'
      | 'rate_limited'
      | 'server',
    message: string,
  ) {
    super(message);
  }
}
