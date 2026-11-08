// Deterministic synthetic demo data, generated relative to the current date at first load.
// Everything here is made up. Emails are @example.com.
import type {
  AuditEntry, Booking, BusyBlock, CalendarConnection, ConnectorGrant, LedgerEntry, Package, PackRequest, Settings, User,
} from '../domain/types';
import { addDays, localDayKeyMs, localToUtc, weekdayOf } from '../domain/slots';

export interface StoredUser extends User {
  passwordHash: string;
}

export interface DemoState {
  version: 1;
  seq: number;
  users: StoredUser[];
  ledger: LedgerEntry[];
  bookings: Booking[];
  packRequests: PackRequest[];
  packages: Package[];
  settings: Settings;
  google: BusyBlock[];
  calendar: CalendarConnection;
  grants: ConnectorGrant[];
  audit: AuditEntry[];
  /** idempotency key -> stored result */
  idem: Record<string, unknown>;
  /** timestamps (ms) of recent ChatGPT calls for demo rate limits */
  callLog: Array<{ at: number; write: boolean }>;
  /** ms timestamp until which a new ChatGPT connection may be approved */
  pairingUntil?: number;
}

/** SHA-256 hex of "tse-demo:" + password. The live server uses Argon2id; this is a demo. */
export const DEMO_PASSWORD_HASH = '945d6da3bee7f2863919263774911230dcd9a19383218e389ae3c098a85da102'; // "stretch123"

export const DEFAULT_PACKAGES: Package[] = [
  { id: 'single', name: 'Single session', sessions: 1, priceZar: 550, note: 'First session 50% off (R275)' },
  { id: 'five', name: '5 session pack', sessions: 5, priceZar: 2500 },
  { id: 'ten', name: '10 session pack', sessions: 10, priceZar: 4500 },
];

export const DEFAULT_SETTINGS: Settings = {
  timezone: 'Africa/Johannesburg',
  sessionMinutes: 60,
  bufferMinutes: 15,
  slotStepMinutes: 30,
  bookingWindowDays: 28,
  minNoticeHours: 12,
  cancelFreeHours: 24,
  workingHours: [
    { weekday: 0, open: false, start: '00:00', end: '00:00' },
    { weekday: 1, open: true, start: '06:00', end: '19:00' },
    { weekday: 2, open: true, start: '06:00', end: '19:00' },
    { weekday: 3, open: true, start: '06:00', end: '19:00' },
    { weekday: 4, open: true, start: '06:00', end: '19:00' },
    { weekday: 5, open: true, start: '06:00', end: '19:00' },
    { weekday: 6, open: true, start: '07:00', end: '12:00' },
  ],
};

function mulberry32(seed: number) {
  let a = seed >>> 0;
  return () => {
    a = (a + 0x6d2b79f5) >>> 0;
    let t = a;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

interface Person { id: string; name: string; email: string; phone: string; balance: number }
const PEOPLE: Person[] = [
  { id: 'u_thandi', name: 'Thandi Mokoena', email: 'thandi@example.com', phone: '+27 82 555 0101', balance: 4 },
  { id: 'u_pieter', name: 'Pieter van der Merwe', email: 'pieter@example.com', phone: '+27 83 555 0102', balance: 9 },
  { id: 'u_aisha', name: 'Aisha Daniels', email: 'aisha@example.com', phone: '+27 84 555 0103', balance: 2 },
  { id: 'u_liam', name: 'Liam Botha', email: 'liam@example.com', phone: '+27 72 555 0104', balance: 6 },
  { id: 'u_naledi', name: 'Naledi Khumalo', email: 'naledi@example.com', phone: '+27 73 555 0105', balance: 1 },
  { id: 'u_ruan', name: 'Ruan Steyn', email: 'ruan@example.com', phone: '+27 74 555 0106', balance: 0 },
  { id: 'u_megan', name: 'Megan Adams', email: 'megan@example.com', phone: '+27 76 555 0107', balance: 3 },
  { id: 'u_sipho', name: 'Sipho Dlamini', email: 'sipho@example.com', phone: '+27 78 555 0108', balance: 5 },
  { id: 'u_chloe', name: 'Chloé Jacobs', email: 'chloe@example.com', phone: '+27 79 555 0109', balance: 7 },
  { id: 'u_yusuf', name: 'Yusuf Ebrahim', email: 'yusuf@example.com', phone: '+27 60 555 0110', balance: 8 },
  { id: 'u_kayla', name: 'Kayla Pretorius', email: 'kayla@example.com', phone: '+27 61 555 0111', balance: 2 },
  { id: 'u_johan', name: 'Johan Nel', email: 'johan@example.com', phone: '+27 62 555 0112', balance: 1 },
];

const iso = (ms: number) => new Date(ms).toISOString();

export function buildSeed(nowMs: number = Date.now()): DemoState {
  const rnd = mulberry32(Number(localDayKeyMs(nowMs).replace(/-/g, '')));
  const today = localDayKeyMs(nowMs);
  const settings: Settings = JSON.parse(JSON.stringify(DEFAULT_SETTINGS));
  let seq = 1000;
  const nid = (p: string) => `${p}_${(++seq).toString(36)}`;

  const users: StoredUser[] = [
    {
      id: 'u_ivan', role: 'owner', name: 'Ivan Bond', email: 'ivan@example.com', phone: '+27 82 555 0100',
      createdAt: iso(nowMs - 120 * 86400000), totpEnabled: true, passwordHash: DEMO_PASSWORD_HASH,
    },
    ...PEOPLE.map((p, i): StoredUser => ({
      id: p.id, role: 'client', name: p.name, email: p.email, phone: p.phone,
      createdAt: iso(nowMs - (110 - i * 7) * 86400000), passwordHash: DEMO_PASSWORD_HASH,
    })),
  ];

  // ---- Google Calendar busy blocks (past 3 weeks to +28 days)
  const google: BusyBlock[] = [];
  const addG = (day: string, from: string, to: string, label: string) =>
    google.push({ start: localToUtc(`${day}T${from}`), end: localToUtc(`${day}T${to}`), source: 'google', label });
  for (let i = -21; i <= 28; i++) {
    const day = addDays(today, i);
    const wd = weekdayOf(day);
    if (wd === 2 || wd === 4) addG(day, '12:00', '13:00', 'Gym');
    if (wd === 1 || wd === 3) addG(day, '07:30', '08:30', 'School run');
    if (wd === 5) addG(day, '10:00', '11:30', 'Admin');
    if (wd === 6 && i % 2 === 0) addG(day, '09:00', '10:00', 'Gym');
  }
  const gOverlap = (a: number, b: number) =>
    google.some((g) => a < new Date(g.end).getTime() + 15 * 60000 && b + 15 * 60000 > new Date(g.start).getTime());

  // ---- Bookings
  const slotTimes = ['06:00', '07:30', '09:00', '16:00', '17:30'];
  const bookings: Booking[] = [];
  const ledger: LedgerEntry[] = [];
  const consumed: Record<string, number> = {};
  const clients = PEOPLE;
  let pick = 0;
  let noShowDone = false;
  let cancelDone = false;

  const make = (day: string, t: string, who: Person, status: Booking['status'], extra: Partial<Booking> = {}) => {
    const startMs = new Date(localToUtc(`${day}T${t}`)).getTime();
    const b: Booking = {
      id: nid('b'), userId: who.id, clientName: who.name, start: iso(startMs), end: iso(startMs + 3600000),
      status, createdBy: 'client', googleEventId: `gcal_demo_${seq}`, ...extra,
    };
    bookings.push(b);
    ledger.push({
      id: nid('l'), userId: who.id, delta: -1, reason: 'booking', note: 'Session booked',
      actor: who.id, createdAt: iso(Math.min(startMs - 3 * 86400000, nowMs - 3600000)),
    });
    if (status === 'cancelled' && extra.refunded) {
      ledger.push({
        id: nid('l'), userId: who.id, delta: 1, reason: 'cancel_refund', note: 'Cancelled more than 24 h before',
        actor: who.id, createdAt: extra.cancelledAt ?? iso(startMs - 2 * 86400000),
      });
    } else {
      consumed[who.id] = (consumed[who.id] ?? 0) + 1;
    }
  };

  for (let i = -21; i <= 14; i++) {
    const day = addDays(today, i);
    if (weekdayOf(day) === 0) continue;
    const times = weekdayOf(day) === 6 ? ['08:00', '09:30'] : slotTimes;
    for (const t of times) {
      const startMs = new Date(localToUtc(`${day}T${t}`)).getTime();
      const endMs = startMs + 3600000;
      if (gOverlap(startMs, endMs)) continue;
      if (rnd() > 0.6) continue;
      if (i >= 0 && startMs < nowMs + 12 * 3600000) continue;
      if (i < 0 && startMs > nowMs) continue;
      if (i === 0 && startMs < nowMs) continue;
      pick = (pick + 1 + Math.floor(rnd() * 3)) % clients.length;
      const who = clients[pick];
      if (startMs < nowMs) {
        if (!noShowDone && i < -10 && who.id !== 'u_thandi') {
          noShowDone = true;
          make(day, t, who, 'no_show');
        } else if (!cancelDone && i < -5 && who.id !== 'u_thandi') {
          cancelDone = true;
          const cAt = iso(startMs - 2 * 86400000);
          make(day, t, who, 'cancelled', { cancelledAt: cAt, cancelReason: 'Client cancelled', refunded: true });
        } else {
          make(day, t, who, 'completed');
        }
      } else {
        make(day, t, who, 'confirmed');
      }
    }
  }
  // Guarantee the demo client has one upcoming booking.
  if (!bookings.some((b) => b.userId === 'u_thandi' && b.status === 'confirmed')) {
    for (let i = 2; i <= 10; i++) {
      const day = addDays(today, i);
      if (weekdayOf(day) === 0 || weekdayOf(day) === 6) continue;
      const startMs = new Date(localToUtc(`${day}T17:30`)).getTime();
      if (gOverlap(startMs, startMs + 3600000)) continue;
      if (bookings.some((b) => b.status === 'confirmed' && Math.abs(new Date(b.start).getTime() - startMs) < 4500000)) continue;
      make(day, '17:30', PEOPLE[0], 'confirmed');
      break;
    }
  }
  bookings.sort((a, b) => a.start.localeCompare(b.start));

  // ---- Purchases so that final balances match each target
  for (const p of PEOPLE) {
    const total = p.balance + (consumed[p.id] ?? 0);
    if (total <= 0) continue;
    const first = Math.ceil(total / 2);
    const parts = total > 3 ? [first, total - first] : [total];
    parts.forEach((n, k) => {
      ledger.push({
        id: nid('l'), userId: p.id, delta: n, reason: 'purchase',
        note: n >= 10 ? '10 session pack' : n >= 5 ? '5 session pack' : `${n} session${n === 1 ? '' : 's'} loaded`,
        actor: 'u_ivan', createdAt: iso(nowMs - (40 - k * 9) * 86400000),
      });
    });
  }
  ledger.push({
    id: nid('l'), userId: 'u_johan', delta: 0, reason: 'welcome_offer', note: 'First session 50% off',
    actor: 'u_johan', createdAt: iso(nowMs - 45 * 86400000),
  });
  ledger.sort((a, b) => a.createdAt.localeCompare(b.createdAt));

  const packRequests: PackRequest[] = [
    {
      id: nid('pr'), userId: 'u_aisha', packageId: 'five', sessions: 5, priceZar: 2500, status: 'pending',
      createdAt: iso(nowMs - 6 * 3600000),
    },
    {
      id: nid('pr'), userId: 'u_pieter', packageId: 'ten', sessions: 10, priceZar: 4500, status: 'paid',
      createdAt: iso(nowMs - 40 * 86400000), paidAt: iso(nowMs - 39 * 86400000), paymentReference: 'EFT-DEMO-0042',
    },
  ];

  const grants: ConnectorGrant[] = [
    {
      id: 'g_chatgpt', clientName: 'ChatGPT', scopes: ['bookings:read', 'bookings:write', 'clients:read', 'credits:write'],
      createdAt: iso(nowMs - 12 * 86400000), lastUsedAt: iso(nowMs - 2 * 3600000),
    },
    {
      id: 'g_old', clientName: 'ChatGPT (old laptop)', scopes: ['bookings:read', 'clients:read'],
      createdAt: iso(nowMs - 50 * 86400000), lastUsedAt: iso(nowMs - 33 * 86400000), revokedAt: iso(nowMs - 30 * 86400000),
    },
  ];

  const aud = (hAgo: number, tool: string, args: Record<string, unknown>, summary: string, status: AuditEntry['status'] = 'ok', grantId = 'g_chatgpt'): AuditEntry => ({
    id: nid('a'), at: iso(nowMs - hAgo * 3600000), grantId, tool, args, status, summary,
  });
  const audit: AuditEntry[] = [
    aud(2, 'get_schedule', { date: today }, 'Schedule for today: 3 sessions'),
    aud(2.2, 'search_clients', { query: 'Aisha' }, '1 client matched'),
    aud(26, 'find_free_slots', { from: today, to: addDays(today, 6) }, '14 free slots in the next 7 days'),
    aud(27, 'add_session_credits', { client_id: 'u_pieter', sessions: 10, reason: 'EFT received', idempotency_key: '[redacted]' }, 'Added 10 sessions to Pieter van der Merwe'),
    aud(50, 'get_business_summary', { period: 'week' }, 'Week summary: 9 booked, 6 completed'),
    aud(75, 'remove_session_credits', { client_id: 'u_naledi', sessions: 25, reason: 'test', idempotency_key: '[redacted]' }, 'Denied: sessions must be 1 to 20', 'denied'),
    aud(100, 'list_bookings', { from: addDays(today, -7), to: today }, '11 bookings'),
    aud(24 * 31, 'get_schedule', { date: addDays(today, -31) }, 'Schedule for that day: 2 sessions', 'ok', 'g_old'),
  ].sort((a, b) => b.at.localeCompare(a.at));

  return {
    version: 1, seq, users, ledger, bookings, packRequests,
    packages: JSON.parse(JSON.stringify(DEFAULT_PACKAGES)), settings, google,
    calendar: { connected: true, accountEmail: 'ivan@example.com', calendarId: 'primary', lastSyncAt: iso(nowMs - 600000), mode: 'demo' },
    grants, audit, idem: {}, callLog: [],
  };
}
