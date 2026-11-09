// Deterministic tests of the in-browser API: money, booking, auth and owner rules.
import { afterAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { demoApi, demoStore, demoTotpCode, demoTotpSecondsLeft } from './demoApi';
import { ApiError, type LedgerEntry } from '../domain/types';
import { localToUtc } from '../domain/slots';

const NOW = Date.parse('2026-11-09T08:00:00.000Z'); // Mon 10:00 local
const H = 3_600_000;
const st = () => demoStore.getState();
const PW = 'stretch123';

async function code(p: Promise<unknown>): Promise<string> {
  try { await p; } catch (e) { expect(e).toBeInstanceOf(ApiError); return (e as ApiError).code; }
  return 'NO_ERROR';
}
const asClient = async (email = 'ruan@example.com') => { await demoApi.logout(); const r = await demoApi.login(email, PW); expect(r.user).toBeTruthy(); return r.user!; };
const asOwner = async () => { await demoApi.logout(); const r = await demoApi.login('ivan@example.com', PW); expect(r.needsTotp).toBe(true); return demoApi.verifyTotp(demoTotpCode()); };
const give = (userId: string, n: number) => demoStore.adjustCredits(userId, n, 'owner_adjust', 'test', 'u_ivan');
const startOf = (day: string, t: string) => localToUtc(`${day}T${t}`);

beforeEach(async () => {
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.setSystemTime(NOW);
  await demoApi.demoReset!();
  demoStore.save((s) => { s.bookings = []; s.google = []; s.ledger = []; s.packRequests = []; s.idem = {}; s.callLog = []; s.audit = []; });
});
afterAll(() => vi.useRealTimers());

describe('regression: demo authenticator codes', () => {
  it('are always exactly six digits (the XOR used to go negative and print a minus sign)', () => {
    for (let w = 0; w < 5000; w++) expect(demoTotpCode(w * 30000)).toMatch(/^\d{6}$/);
    expect(demoTotpCode(Date.UTC(2026, 10, 9, 8, 0, 0) - 60000 * 0)).toMatch(/^\d{6}$/);
  });
});

describe('auth', () => {
  it('signup validates, normalises, never exposes the hash, and adds a zero-credit welcome row', async () => {
    expect(await code(demoApi.signup({ name: 'A', email: 'a@b.co', password: 'longenough1' }))).toBe('validation');
    expect(await code(demoApi.signup({ name: ' A ', email: 'a@b.co', password: 'longenough1' }))).toBe('validation'); // trimmed to 1 char
    expect(await code(demoApi.signup({ name: 'Al', email: 'not-an-email', password: 'longenough1' }))).toBe('validation');
    expect(await code(demoApi.signup({ name: 'Al', email: 'a b@c.de', password: 'longenough1' }))).toBe('validation');
    expect(await code(demoApi.signup({ name: 'Al', email: 'a@b', password: 'longenough1' }))).toBe('validation');
    expect(await code(demoApi.signup({ name: 'Al', email: 'a@b.co', password: '123456789' }))).toBe('validation'); // 9 chars
    expect(await code(demoApi.signup({ name: 'Al', email: 'thandi@example.com', password: 'longenough1' }))).toBe('conflict');
    expect(await code(demoApi.signup({ name: 'Al', email: ' THANDI@Example.com ', password: 'longenough1' }))).toBe('conflict');
    const u = await demoApi.signup({ name: ' Al Newton ', email: '  Al@Example.COM ', phone: ' 082 ', password: '1234567890' });
    expect(u).toMatchObject({ role: 'client', name: 'Al Newton', email: 'al@example.com', phone: '082' });
    expect(JSON.stringify(u)).not.toContain('passwordHash');
    expect((await demoApi.me())!.id).toBe(u.id);
    const rows = st().ledger.filter((l) => l.userId === u.id);
    expect(rows).toHaveLength(1);
    expect(rows[0]).toMatchObject({ delta: 0, reason: 'welcome_offer', actor: u.id });
    expect(await demoApi.myBalance()).toBe(0);
    expect((await demoApi.signup({ name: 'Bo', email: 'bo@example.com', password: '1234567890' })).phone).toBeUndefined();
  });
  it('login checks email and password, trims and lowercases the email, and signs out', async () => {
    expect(await code(demoApi.login('ruan@example.com', 'wrong'))).toBe('unauthorized');
    expect(await code(demoApi.login('nobody@example.com', PW))).toBe('unauthorized');
    expect(await demoApi.me()).toBeNull();
    const r = await demoApi.login('  RUAN@example.com ', PW);
    expect(r.user!.id).toBe('u_ruan');
    expect(r.needsTotp).toBeUndefined();
    expect(JSON.stringify(r)).not.toContain('passwordHash');
    expect((await demoApi.me())!.id).toBe('u_ruan');
    await demoApi.logout();
    expect(await demoApi.me()).toBeNull();
    expect(await code(demoApi.myBalance())).toBe('unauthorized');
  });
  it('owner login needs a valid, recent authenticator code', async () => {
    await demoApi.logout();
    expect(await code(demoApi.verifyTotp(demoTotpCode()))).toBe('unauthorized'); // nobody is waiting for a code
    const r = await demoApi.login('ivan@example.com', PW);
    expect(r).toEqual({ needsTotp: true });
    expect(await demoApi.me()).toBeNull(); // not signed in until the code is right
    expect(await code(demoApi.verifyTotp('000000'))).toBe('unauthorized');
    expect(await code(demoApi.verifyTotp(''))).toBe('unauthorized');
    const u = await demoApi.verifyTotp(' ' + demoTotpCode().slice(0, 3) + ' ' + demoTotpCode().slice(3) + ' ');
    expect(u.role).toBe('owner');
    expect((await demoApi.me())!.id).toBe('u_ivan');
    // the code is single-use per login attempt
    await demoApi.logout();
    expect(await code(demoApi.verifyTotp(demoTotpCode()))).toBe('unauthorized');
  });
  it('totp accepts this and the previous 30 s window only', async () => {
    const now = Date.now();
    const cur = demoTotpCode(now);
    const prev = demoTotpCode(now - 30000);
    const old = demoTotpCode(now - 60000);
    expect(cur).toMatch(/^\d{6}$/);
    expect(new Set([cur, prev, old]).size).toBe(3);
    expect(demoTotpCode(now + 29000 - (now % 30000))).toBe(cur); // same window
    expect(demoTotpCode(now - (now % 30000) + 30000)).not.toBe(cur);
    await demoApi.logout();
    await demoApi.login('ivan@example.com', PW);
    expect(await code(demoApi.verifyTotp(old))).toBe('unauthorized');
    expect((await demoApi.verifyTotp(prev)).role).toBe('owner');
    expect(demoTotpSecondsLeft(0)).toBe(30);
    expect(demoTotpSecondsLeft(29999)).toBe(1);
    expect(demoTotpSecondsLeft(30000)).toBe(30);
    expect(demoTotpSecondsLeft(12000)).toBe(18);
  });
  it('owner endpoints refuse clients and anonymous callers', async () => {
    await demoApi.logout();
    expect(await code(demoApi.adminClients())).toBe('unauthorized');
    await asClient();
    for (const call of [
      () => demoApi.adminClients(), () => demoApi.adminClient('u_ruan'), () => demoApi.adminBookings('2026-11-01', '2026-11-30'),
      () => demoApi.adminBusy('2026-11-01', '2026-11-30'), () => demoApi.adminAdjustCredits('u_ruan', 5, 'x'),
      () => demoApi.adminMarkPackPaid('pr_1'), () => demoApi.adminBookFor('u_ruan', NOW as never),
      () => demoApi.adminCancelBooking('b', true, 'x'), () => demoApi.adminSetBookingStatus('b', 'completed'),
      () => demoApi.adminUpdateSettings({}), () => demoApi.adminCalendar(), () => demoApi.adminConnectCalendar(),
      () => demoApi.adminDisconnectCalendar(), () => demoApi.adminSummary('week'), () => demoApi.adminGrants(),
      () => demoApi.adminRevokeGrant('g_chatgpt'), () => demoApi.adminPairing(), () => demoApi.adminOpenPairing(),
      () => demoApi.adminClosePairing(), () => demoApi.adminAudit(), () => demoApi.demoCallTool!('get_schedule', {}),
    ]) expect(await code(call())).toBe('forbidden');
    expect(demoStore.balance('u_ruan')).toBe(0);
  });
});

describe('client money and bookings', () => {
  it('requestPack records a pending request and gives no credit', async () => {
    await asClient();
    expect(await code(demoApi.requestPack('nope'))).toBe('not_found');
    const pr = await demoApi.requestPack('five');
    expect(pr).toMatchObject({ userId: 'u_ruan', packageId: 'five', sessions: 5, priceZar: 2500, status: 'pending' });
    expect(await demoApi.myBalance()).toBe(0);
    expect(await demoApi.myPackRequests()).toHaveLength(1);
    vi.setSystemTime(NOW + 1000);
    await demoApi.requestPack('single');
    expect((await demoApi.myPackRequests()).map((p) => p.packageId)).toEqual(['single', 'five']); // newest first
  });
  it('book takes one credit, rejects no-credit, taken times, and shows only my own data', async () => {
    const slots = await demoApi.availability('2026-11-10', '2026-11-10');
    expect(slots[0].start).toBe(startOf('2026-11-10', '06:00'));
    await asClient();
    expect(await code(demoApi.book(slots[0].start))).toBe('no_credits');
    give('u_ruan', 2);
    give('u_pieter', 2);
    expect(await code(demoApi.book('garbage'))).toBe('validation');
    const b = await demoApi.book(slots[0].start);
    expect(b).toMatchObject({ userId: 'u_ruan', clientName: 'Ruan Steyn', start: slots[0].start, end: startOf('2026-11-10', '07:00'), status: 'confirmed', createdBy: 'client' });
    expect(b.googleEventId).toMatch(/^gcal_demo_/);
    expect(await demoApi.myBalance()).toBe(1);
    expect(st().ledger.at(-1)).toMatchObject({ delta: -1, reason: 'booking', userId: 'u_ruan', actor: 'u_ruan', note: 'Session booked' });
    expect(await code(demoApi.book(slots[0].start))).toBe('conflict'); // now taken
    expect(await code(demoApi.book(startOf('2026-11-10', '06:30')))).toBe('conflict'); // overlaps
    expect(await code(demoApi.book(startOf('2026-11-10', '07:00')))).toBe('conflict'); // inside buffer
    expect(await code(demoApi.book(startOf('2026-11-10', '06:10')))).toBe('conflict'); // off the grid
    expect((await demoApi.availability('2026-11-10', '2026-11-10')).map((s) => s.start)).not.toContain(slots[0].start);
    await asClient('pieter@example.com');
    expect(await demoApi.myBookings()).toEqual([]);
    expect(await code(demoApi.cancelBooking(b.id))).toBe('not_found'); // not mine
    expect((await demoApi.myLedger()).every((l) => l.userId === 'u_pieter')).toBe(true);
    // calendar disconnected: no event id
    await asOwner();
    await demoApi.adminDisconnectCalendar();
    await asClient();
    const b2 = await demoApi.book(startOf('2026-11-10', '08:00'));
    expect(b2.googleEventId).toBeUndefined();
  });
  it('myBookings and myLedger are sorted', async () => {
    give('u_ruan', 3);
    await asClient();
    await demoApi.book(startOf('2026-11-12', '09:00'));
    await demoApi.book(startOf('2026-11-10', '09:00'));
    expect((await demoApi.myBookings()).map((b) => b.start)).toEqual([startOf('2026-11-10', '09:00'), startOf('2026-11-12', '09:00')]);
    vi.setSystemTime(NOW + 1000);
    give('u_ruan', 1);
    expect((await demoApi.myLedger())[0].delta).toBe(1); // newest first
  });
  it('cancelling refunds only when strictly more than 24 hours before the start', async () => {
    give('u_ruan', 5);
    await asClient();
    const tomorrow10 = startOf('2026-11-10', '10:00'); // exactly 24 h after NOW (10:00 local)
    const b1 = await demoApi.book(startOf('2026-11-11', '10:00')); // 48 h
    const b2 = await demoApi.book(tomorrow10);
    const b3 = await demoApi.book(startOf('2026-11-10', '12:00')); // 26 h
    expect(await demoApi.myBalance()).toBe(2);
    const r2 = await demoApi.cancelBooking(b2.id);
    expect(r2.refunded).toBe(false); // exactly 24 h: no refund
    expect(r2.booking).toMatchObject({ status: 'cancelled', refunded: false, cancelReason: 'Cancelled inside 24 h, no refund' });
    expect(await demoApi.myBalance()).toBe(2);
    const r3 = await demoApi.cancelBooking(b3.id);
    expect(r3.refunded).toBe(true);
    expect(r3.booking).toMatchObject({ refunded: true, cancelReason: 'Cancelled more than 24 h before' });
    expect(st().ledger.at(-1)).toMatchObject({ delta: 1, reason: 'cancel_refund', actor: 'u_ruan', note: 'Cancelled more than 24 h before' });
    expect(await demoApi.myBalance()).toBe(3);
    vi.setSystemTime(NOW + 1 * H); // now 23 h before b1... no: b1 is 47 h away still
    expect((await demoApi.cancelBooking(b1.id)).refunded).toBe(true);
    expect(await demoApi.myBalance()).toBe(4);
    expect(await code(demoApi.cancelBooking(b1.id))).toBe('conflict');
    expect(await code(demoApi.cancelBooking('nope'))).toBe('not_found');
    expect(await demoApi.myBalance()).toBe(4); // never refunded twice
    expect(st().bookings.find((b) => b.id === b1.id)!.cancelledAt).toBe(new Date(NOW + H).toISOString());
  });
  it('respects a changed cancelFreeHours setting', async () => {
    give('u_ruan', 2);
    await asClient();
    const b = await demoApi.book(startOf('2026-11-10', '12:00')); // 26 h
    await asOwner();
    await demoApi.adminUpdateSettings({ cancelFreeHours: 48 });
    await asClient();
    expect((await demoApi.cancelBooking(b.id)).refunded).toBe(false);
  });
});

describe('owner actions', () => {
  it('adminAdjustCredits validates and never lets a balance go negative', async () => {
    await asOwner();
    give('u_ruan', 3);
    expect(await code(demoApi.adminAdjustCredits('u_ruan', 0, 'x'))).toBe('validation');
    expect(await code(demoApi.adminAdjustCredits('u_ruan', 1.5, 'x'))).toBe('validation');
    expect(await code(demoApi.adminAdjustCredits('u_ruan', NaN, 'x'))).toBe('validation');
    expect(await code(demoApi.adminAdjustCredits('nobody', 1, 'x'))).toBe('not_found');
    expect(await code(demoApi.adminAdjustCredits('u_ivan', 1, 'x'))).toBe('not_found');
    expect(await code(demoApi.adminAdjustCredits('u_ruan', -4, 'x'))).toBe('validation');
    expect(demoStore.balance('u_ruan')).toBe(3);
    const e = await demoApi.adminAdjustCredits('u_ruan', -3, '  fix  ');
    expect(e).toMatchObject({ delta: -3, reason: 'owner_adjust', note: 'fix', actor: 'u_ivan' });
    expect(demoStore.balance('u_ruan')).toBe(0);
    expect((await demoApi.adminAdjustCredits('u_ruan', 2, '   ')).note).toBe('Adjusted by Ivan');
  });
  it('adminMarkPackPaid adds the pack once', async () => {
    await asClient();
    const pr = await demoApi.requestPack('ten');
    await asOwner();
    expect(await code(demoApi.adminMarkPackPaid('nope'))).toBe('not_found');
    expect(await code(demoApi.adminMarkPackPaid(pr.id, '\u0420AY-100'))).toBe('validation');
    expect(await code(demoApi.adminMarkPackPaid(pr.id, 'ab'))).toBe('validation');
    const paid = await demoApi.adminMarkPackPaid(pr.id, '  EFT-77 ');
    expect(paid).toMatchObject({ status: 'paid', paymentReference: 'EFT-77' });
    expect(paid.paidAt).toBe(new Date(NOW).toISOString());
    expect(demoStore.balance('u_ruan')).toBe(10);
    expect(st().ledger.at(-1)).toMatchObject({ delta: 10, reason: 'purchase', note: '10 session pack (EFT-77)', actor: 'u_ivan' });
    expect(await code(demoApi.adminMarkPackPaid(pr.id, 'again'))).toBe('conflict');
    expect(demoStore.balance('u_ruan')).toBe(10);
    await asClient();
    const p2 = await demoApi.requestPack('single');
    await asOwner();
    const p2paid = await demoApi.adminMarkPackPaid(p2.id, '   ');
    expect(p2paid.paymentReference).toBeUndefined();
    expect(st().ledger.at(-1)!.note).toBe('1 session pack');
  });
  it('adminBookFor ignores minimum notice but not credits or clashes; adminCancelBooking refunds only on request', async () => {
    await asOwner();
    give('u_ruan', 1);
    const soon = new Date(NOW + 3600000 * 0.5 + 0).toISOString(); // 10:30 local today, inside 12 h notice
    expect(await code(demoApi.adminBookFor('u_ruan', soon))).toBe('NO_ERROR');
    expect(demoStore.balance('u_ruan')).toBe(0);
    const bk = st().bookings[0];
    expect(bk).toMatchObject({ createdBy: 'owner', userId: 'u_ruan' });
    expect(await code(demoApi.adminBookFor('u_ruan', startOf('2026-11-10', '12:00')))).toBe('no_credits');
    give('u_ruan', 1);
    expect(await code(demoApi.adminBookFor('u_ruan', soon))).toBe('conflict');
    expect(await code(demoApi.adminBookFor('u_pieter', startOf('2026-11-10', '12:00')))).toBe('no_credits');
    expect(await code(demoApi.adminBookFor('u_ivan', startOf('2026-11-10', '12:00')))).toBe('not_found');
    expect(await code(demoApi.adminCancelBooking('nope', true, 'x'))).toBe('not_found');
    const c = await demoApi.adminCancelBooking(bk.id, false, 'owner says');
    expect(c).toMatchObject({ status: 'cancelled', refunded: false, cancelReason: 'owner says' });
    expect(demoStore.balance('u_ruan')).toBe(1);
    expect(await code(demoApi.adminCancelBooking(bk.id, true, 'again'))).toBe('conflict');
    const b2 = await demoApi.adminBookFor('u_ruan', startOf('2026-11-10', '12:00'));
    await demoApi.adminCancelBooking(b2.id, true, '');
    expect(demoStore.balance('u_ruan')).toBe(1);
    expect(st().ledger.at(-1)).toMatchObject({ delta: 1, reason: 'cancel_refund', note: 'Session refunded', actor: 'u_ivan' });
  });
  it('adminSetBookingStatus and lastVisit/upcoming in client summaries', async () => {
    await asOwner();
    give('u_ruan', 3);
    const b1 = await demoApi.adminBookFor('u_ruan', startOf('2026-11-10', '09:00'));
    const b2 = await demoApi.adminBookFor('u_ruan', startOf('2026-11-12', '09:00'));
    const b3 = await demoApi.adminBookFor('u_ruan', startOf('2026-11-13', '09:00'));
    expect(await code(demoApi.adminSetBookingStatus('nope', 'completed'))).toBe('not_found');
    expect((await demoApi.adminSetBookingStatus(b1.id, 'completed')).status).toBe('completed');
    expect((await demoApi.adminSetBookingStatus(b2.id, 'no_show')).status).toBe('no_show');
    await demoApi.adminCancelBooking(b3.id, false, 'x');
    expect(await code(demoApi.adminSetBookingStatus(b3.id, 'completed'))).toBe('conflict');
    const sum = (await demoApi.adminClients('ruan'))[0];
    expect(sum).toMatchObject({ balance: 0, upcoming: 0, lastVisit: startOf('2026-11-12', '09:00'), pendingPacks: 0 });
    expect(JSON.stringify(sum)).not.toContain('passwordHash');
    await demoApi.adminBookFor('u_ruan', startOf('2026-11-16', '09:00')).catch(() => 0);
  });
  it('adminClients filters by name or email, sorts by name, and hides the owner', async () => {
    await asOwner();
    const all = await demoApi.adminClients();
    expect(all.every((c) => c.user.role === 'client')).toBe(true);
    expect(all.map((c) => c.user.name)).toEqual([...all.map((c) => c.user.name)].sort((a, b) => a.localeCompare(b)));
    expect((await demoApi.adminClients('  THANDI '))).toHaveLength(1);
    expect((await demoApi.adminClients('pieter@'))).toHaveLength(1);
    expect((await demoApi.adminClients('zzz'))).toHaveLength(0);
    expect((await demoApi.adminClients('ivan'))).toHaveLength(0);
    expect(all).toHaveLength(12);
  });
  it('adminClient returns the full record, newest first, and rejects non-clients', async () => {
    await asOwner();
    demoStore.save((s) => {
      s.ledger.push({ id: 'l1', userId: 'u_ruan', delta: 1, reason: 'purchase', actor: 'x', createdAt: '2026-11-01T00:00:00.000Z' } as LedgerEntry,
        { id: 'l2', userId: 'u_ruan', delta: 2, reason: 'purchase', actor: 'x', createdAt: '2026-11-05T00:00:00.000Z' } as LedgerEntry);
    });
    const c = await demoApi.adminClient('u_ruan');
    expect(c.ledger.map((l) => l.id)).toEqual(['l2', 'l1']);
    expect(c.summary.balance).toBe(3);
    expect(await code(demoApi.adminClient('u_ivan'))).toBe('not_found');
    expect(await code(demoApi.adminClient('nobody'))).toBe('not_found');
  });
  it('adminBookings and adminBusy filter by local day', async () => {
    await asOwner();
    give('u_ruan', 2);
    await demoApi.adminBookFor('u_ruan', startOf('2026-11-10', '06:00'));
    await demoApi.adminBookFor('u_ruan', startOf('2026-11-11', '09:00'));
    demoStore.save((s) => void s.google.push({ start: startOf('2026-11-10', '12:00'), end: startOf('2026-11-10', '13:00'), source: 'google', label: 'Busy (Google Calendar)' }));
    expect((await demoApi.adminBookings('2026-11-10', '2026-11-10')).map((b) => b.start)).toEqual([startOf('2026-11-10', '06:00')]);
    expect(await demoApi.adminBookings('2026-11-09', '2026-11-09')).toEqual([]);
    expect(await demoApi.adminBookings('2026-11-10', '2026-11-11')).toHaveLength(2);
    const busy = await demoApi.adminBusy('2026-11-10', '2026-11-10');
    expect(busy.map((b) => b.source)).toEqual(['booking', 'google']);
    expect(busy[0].label).toBe('Ruan Steyn');
    await demoApi.adminCancelBooking(st().bookings[0].id, false, 'x');
    expect((await demoApi.adminBusy('2026-11-10', '2026-11-10')).map((b) => b.source)).toEqual(['google']);
  });
  it('settings, calendar, grants, pairing and audit', async () => {
    await asOwner();
    const s = await demoApi.adminUpdateSettings({ sessionMinutes: 45, timezone: 'Europe/London' as never });
    expect(s.sessionMinutes).toBe(45);
    expect(s.timezone).toBe('Africa/Johannesburg');
    expect((await demoApi.settings()).sessionMinutes).toBe(45);
    (await demoApi.settings()).sessionMinutes = 99;
    expect((await demoApi.settings()).sessionMinutes).toBe(45); // copies, not references
    (await demoApi.packages())[0].priceZar = 1;
    expect((await demoApi.packages())[0].priceZar).toBe(550);
    expect(await demoApi.adminCalendar()).toMatchObject({ connected: true });
    expect(await demoApi.adminDisconnectCalendar()).toEqual({ connected: false, mode: 'demo' });
    expect((await demoApi.adminCalendar()).connected).toBe(false);
    const c = await demoApi.adminConnectCalendar();
    expect(c.connection).toMatchObject({ connected: true, mode: 'demo', calendarId: 'primary', lastSyncAt: new Date(NOW).toISOString() });
    expect((await demoApi.adminGrants()).map((g) => g.id)).toEqual(['g_chatgpt', 'g_old']);
    expect(await code(demoApi.adminRevokeGrant('nope'))).toBe('not_found');
    await demoApi.adminRevokeGrant('g_chatgpt');
    const firstRevoked = st().grants[0].revokedAt!;
    expect(firstRevoked).toBe(new Date(NOW).toISOString());
    vi.setSystemTime(NOW + H);
    await demoApi.adminRevokeGrant('g_chatgpt');
    expect(st().grants[0].revokedAt).toBe(firstRevoked); // revoke is idempotent
    expect(await demoApi.adminPairing()).toEqual({ open: false, until: null });
    const open = await demoApi.adminOpenPairing();
    expect(open).toEqual({ open: true, until: new Date(NOW + H + 15 * 60000).toISOString() });
    expect(await demoApi.adminPairing()).toEqual(open);
    vi.setSystemTime(NOW + H + 15 * 60000);
    expect(await demoApi.adminPairing()).toEqual({ open: false, until: null }); // closed exactly at the deadline
    await demoApi.adminOpenPairing();
    expect(await demoApi.adminClosePairing()).toEqual({ open: false, until: null });
    expect((await demoApi.adminPairing()).open).toBe(false);
    demoStore.save((s2) => { s2.audit = [
      { id: 'a1', at: '2026-11-01T00:00:00.000Z', grantId: 'g', tool: 't', args: {}, status: 'ok', summary: '1' },
      { id: 'a3', at: '2026-11-03T00:00:00.000Z', grantId: 'g', tool: 't', args: {}, status: 'ok', summary: '3' },
      { id: 'a2', at: '2026-11-02T00:00:00.000Z', grantId: 'g', tool: 't', args: {}, status: 'ok', summary: '2' }]; });
    expect((await demoApi.adminAudit()).map((a) => a.id)).toEqual(['a3', 'a2', 'a1']);
    expect((await demoApi.adminAudit(2)).map((a) => a.id)).toEqual(['a3', 'a2']);
  });
  it('adminSummary counts the right windows', async () => {
    await asOwner();
    give('u_ruan', 10);
    const day = (n: number) => new Date(NOW + n * 86400000).toISOString().slice(0, 10);
    const mk = (id: string, d: number, status: 'confirmed' | 'completed' | 'cancelled') => ({ id, userId: 'u_ruan', clientName: 'R', start: startOf(day(d), '09:00'), end: startOf(day(d), '10:00'), status, createdBy: 'client' as const });
    demoStore.save((s) => { s.bookings = [mk('a', 0, 'confirmed'), mk('b', -6, 'completed'), mk('c', -7, 'completed'), mk('d', -29, 'cancelled'), mk('e', -30, 'completed'), mk('f', 1, 'confirmed')]; });
    const week = await demoApi.adminSummary('week');
    expect(week).toMatchObject({ period: 'week', from: '2026-11-03', to: '2026-11-09', bookedSessions: 2, completedSessions: 1, cancelledSessions: 0, creditsSold: 0 });
    const month = await demoApi.adminSummary('month');
    expect(month).toMatchObject({ from: '2026-10-11', to: '2026-11-09', bookedSessions: 3, completedSessions: 2, cancelledSessions: 1 });
    expect(week.freeSlotHours).toBe(Math.round((demoStore.freeSlots('2026-11-09', '2026-11-15').length * 30) / 60));
    expect(month.freeSlotHours).toBeGreaterThan(week.freeSlotHours);
    demoStore.save((s) => void s.packRequests.push({ id: 'pz', userId: 'u_ruan', packageId: 'five', sessions: 5, priceZar: 2500, status: 'pending', createdAt: new Date(NOW).toISOString() }));
    await demoApi.adminMarkPackPaid('pz', 'REF-1');
    expect((await demoApi.adminSummary('week')).creditsSold).toBe(5);
  });
});

describe('demoCallTool: audit, redaction and rate limits', () => {
  it('writes an audit row with redacted secrets and updates lastUsedAt', async () => {
    await asOwner();
    const r = await demoApi.demoCallTool!('add_session_credits', { client_id: 'u_ruan', sessions: 2, reason: 'audit test', idempotency_key: 'audit-key-0001' });
    expect(r.ok).toBe(true);
    const a = st().audit[0];
    expect(a).toMatchObject({ tool: 'add_session_credits', status: 'ok', grantId: 'g_chatgpt', summary: r.summary });
    expect(a.args).toEqual({ client_id: 'u_ruan', sessions: 2, reason: 'audit test', idempotency_key: '[redacted]' });
    expect(st().grants[0].lastUsedAt).toBe(new Date(NOW).toISOString());
    const bad = await demoApi.demoCallTool!('add_session_credits', { client_id: 'u_ruan' });
    expect(bad.ok).toBe(false);
    expect(st().audit[0].status).toBe('error');
    expect(st().audit.length).toBe(2);
    await demoApi.demoCallTool!('get_schedule', { token: 'abc', Password: 'x', secretValue: 'y', note: 'fine' } as never).catch(() => 0);
    expect(st().audit[0].args).toEqual({ token: '[redacted]', Password: '[redacted]', secretValue: '[redacted]', note: 'fine' });
  });
  it('a revoked connector is denied, logged, and not counted as use', async () => {
    await asOwner();
    await demoApi.adminRevokeGrant('g_chatgpt');
    demoStore.save((s) => { s.grants[0].lastUsedAt = undefined; });
    expect(await demoApi.demoCallTool!('get_schedule', {})).toEqual({ ok: false, summary: 'Connector access was revoked', error: 'forbidden' });
    expect(st().audit[0]).toMatchObject({ status: 'denied', summary: 'Denied: connector access was revoked' });
    expect(st().grants[0].lastUsedAt).toBeUndefined();
    expect(st().callLog).toHaveLength(0);
  });
  it('allows 60 calls a minute and 20 writes an hour, then rate-limits', async () => {
    await asOwner();
    for (let i = 0; i < 60; i++) expect((await demoApi.demoCallTool!('get_schedule', {})).ok).toBe(true);
    const limited = await demoApi.demoCallTool!('get_schedule', {});
    expect(limited).toEqual({ ok: false, summary: 'Rate limit reached. Try again shortly.', error: 'rate_limited' });
    expect(st().audit[0]).toMatchObject({ status: 'rate_limited', summary: 'Rate limited: 60 calls per minute' });
    vi.setSystemTime(NOW + 60000); // the minute has passed
    expect((await demoApi.demoCallTool!('get_schedule', {})).ok).toBe(true);
    // writes: 20 per hour
    vi.setSystemTime(NOW + 2 * H);
    demoStore.save((s) => { s.callLog = []; });
    for (let i = 0; i < 20; i++) {
      vi.setSystemTime(NOW + 2 * H + i * 61000); // spread so the per-minute limit never matters
      expect((await demoApi.demoCallTool!('add_session_credits', { client_id: 'u_ruan', sessions: 1, reason: 'rate', idempotency_key: `rate-key-${i}-aaaa` })).ok, `write ${i}`).toBe(true);
    }
    expect(demoStore.balance('u_ruan')).toBe(20);
    vi.setSystemTime(NOW + 2 * H + 20 * 61000);
    const w = await demoApi.demoCallTool!('add_session_credits', { client_id: 'u_ruan', sessions: 1, reason: 'rate', idempotency_key: 'rate-key-21-aaaa' });
    expect(w).toEqual({ ok: false, summary: 'Rate limit reached. Try again shortly.', error: 'rate_limited' });
    expect(st().audit[0].summary).toBe('Rate limited: 20 writes per hour');
    expect(demoStore.balance('u_ruan')).toBe(20);
    expect((await demoApi.demoCallTool!('get_schedule', {})).ok).toBe(true); // reads still work
    // an hour later the old writes age out
    vi.setSystemTime(NOW + 2 * H + 20 * 61000 + 3600000);
    expect((await demoApi.demoCallTool!('add_session_credits', { client_id: 'u_ruan', sessions: 1, reason: 'rate', idempotency_key: 'rate-key-22-aaaa' })).ok).toBe(true);
    expect(st().callLog.length).toBeLessThan(3);
  });
  it('keeps only 200 audit rows', async () => {
    await asOwner();
    for (let i = 0; i < 3; i++) {
      demoStore.save((s) => { s.callLog = []; s.audit = Array.from({ length: 199 + i }, (_, k) => ({ id: `x${k}`, at: '2026-11-01T00:00:00.000Z', grantId: 'g', tool: 't', args: {}, status: 'ok' as const, summary: '' })); });
      await demoApi.demoCallTool!('get_schedule', {});
    }
    expect(st().audit.length).toBe(200);
  });
  it('demoReset restores the seed and signs out', async () => {
    await asClient();
    give('u_ruan', 4);
    await demoApi.demoReset!();
    expect(await demoApi.me()).toBeNull();
    expect(st().users.length).toBeGreaterThan(5);
    expect(demoStore.balance('u_ruan')).toBe(0); // the seed gives Ruan zero
  });
});

describe('booking rules through the store (mutation-hardening)', () => {
  it('a cancelled booking frees its slot; a completed or confirmed one does not matter for others', async () => {
    give('u_ruan', 3);
    await asClient();
    const t = startOf('2026-11-10', '09:00');
    const b = await demoApi.book(t);
    expect(await code(demoApi.book(t))).toBe('conflict');
    await demoApi.cancelBooking(b.id);
    expect((await demoApi.availability('2026-11-10', '2026-11-10')).map((s) => s.start)).toContain(t);
    expect((await demoApi.book(t)).status).toBe('confirmed');
  });
  it('rescheduling: only confirmed, ignores notice, may overlap itself, never overlaps others', async () => {
    give('u_ruan', 3);
    const b = demoStore.createBooking('u_ruan', startOf('2026-11-10', '09:00'), 'owner', 'u_ivan', { skipNotice: true });
    const other = demoStore.createBooking('u_ruan', startOf('2026-11-10', '12:00'), 'owner', 'u_ivan', { skipNotice: true });
    expect(await code(Promise.resolve().then(() => demoStore.rescheduleBooking(b.id, 'garbage')))).toBe('validation');
    expect(await code(Promise.resolve().then(() => demoStore.rescheduleBooking('nope', startOf('2026-11-11', '09:00'))))).toBe('not_found');
    expect(demoStore.rescheduleBooking(b.id, startOf('2026-11-10', '09:30')).start).toBe(startOf('2026-11-10', '09:30'));
    expect(await code(Promise.resolve().then(() => demoStore.rescheduleBooking(b.id, startOf('2026-11-10', '11:00'))))).toBe('conflict');
    // today, 11:00 local is inside the 12 h notice window but reschedule skips notice
    expect(demoStore.rescheduleBooking(b.id, startOf('2026-11-09', '11:00')).start).toBe(startOf('2026-11-09', '11:00'));
    expect(st().bookings.find((x) => x.id === b.id)!.end).toBe(startOf('2026-11-09', '12:00'));
    demoStore.cancelBooking(other.id, false, 'x', 'u_ivan');
    expect(await code(Promise.resolve().then(() => demoStore.rescheduleBooking(other.id, startOf('2026-11-11', '09:00'))))).toBe('conflict');
    expect(demoStore.balance('u_ruan')).toBe(1);
  });
  it('client summary counts upcoming (strictly future, confirmed) and pending packs', async () => {
    give('u_ruan', 5);
    demoStore.createBooking('u_ruan', startOf('2026-11-09', '10:30'), 'owner', 'u_ivan', { skipNotice: true });
    demoStore.createBooking('u_ruan', startOf('2026-11-10', '09:00'), 'owner', 'u_ivan', { skipNotice: true });
    const c = demoStore.createBooking('u_ruan', startOf('2026-11-10', '12:00'), 'owner', 'u_ivan', { skipNotice: true });
    demoStore.cancelBooking(c.id, false, 'x', 'u_ivan');
    demoStore.save((s) => {
      s.packRequests.push(
        { id: 'p1', userId: 'u_ruan', packageId: 'five', sessions: 5, priceZar: 2500, status: 'pending', createdAt: '2026-11-08T00:00:00.000Z' },
        { id: 'p2', userId: 'u_ruan', packageId: 'five', sessions: 5, priceZar: 2500, status: 'paid', createdAt: '2026-11-08T00:00:00.000Z' },
        { id: 'p3', userId: 'u_pieter', packageId: 'five', sessions: 5, priceZar: 2500, status: 'pending', createdAt: '2026-11-08T00:00:00.000Z' },
      );
    });
    expect(demoStore.clientSummary('u_ruan')).toMatchObject({ balance: 2, upcoming: 2, pendingPacks: 1 });
    vi.setSystemTime(Date.parse(startOf('2026-11-09', '10:30'))); // first booking starts right now: no longer upcoming
    expect(demoStore.clientSummary('u_ruan')!.upcoming).toBe(1);
    expect(demoStore.clientSummary('u_ivan')).toBeNull();
    expect(demoStore.clientSummary('nobody')).toBeNull();
  });
  it('adminClient only lists that client\'s bookings and packs, newest first', async () => {
    await asOwner();
    give('u_ruan', 3);
    demoStore.createBooking('u_ruan', startOf('2026-11-10', '09:00'), 'owner', 'u_ivan', { skipNotice: true });
    demoStore.createBooking('u_ruan', startOf('2026-11-12', '09:00'), 'owner', 'u_ivan', { skipNotice: true });
    give('u_pieter', 1);
    demoStore.createBooking('u_pieter', startOf('2026-11-11', '09:00'), 'owner', 'u_ivan', { skipNotice: true });
    demoStore.save((s) => {
      s.packRequests.push(
        { id: 'p1', userId: 'u_ruan', packageId: 'five', sessions: 5, priceZar: 2500, status: 'pending', createdAt: '2026-11-01T00:00:00.000Z' },
        { id: 'p2', userId: 'u_ruan', packageId: 'five', sessions: 5, priceZar: 2500, status: 'pending', createdAt: '2026-11-02T00:00:00.000Z' },
        { id: 'p3', userId: 'u_pieter', packageId: 'five', sessions: 5, priceZar: 2500, status: 'pending', createdAt: '2026-11-03T00:00:00.000Z' },
      );
    });
    const c = await demoApi.adminClient('u_ruan');
    expect(c.bookings.map((b) => b.start)).toEqual([startOf('2026-11-12', '09:00'), startOf('2026-11-10', '09:00')]);
    expect(c.packs.map((p) => p.id)).toEqual(['p2', 'p1']);
  });
  it('myBookings returns copies', async () => {
    give('u_ruan', 1);
    await asClient();
    await demoApi.book(startOf('2026-11-10', '09:00'));
    (await demoApi.myBookings())[0].status = 'completed';
    expect((await demoApi.myBookings())[0].status).toBe('confirmed');
  });
});
