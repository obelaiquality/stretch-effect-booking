import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../../data/api';
import type { Booking, BusyBlock, ClientSummary, PackRequest, Slot } from '../../domain/types';
import { formatDay, formatDayTime, formatRand, formatTime } from '../../lib/format';
import './admin.css';
import { AdminFrame, AdminHead, AdminSkeleton, Balance, Empty, ErrorState, errText, Modal, useLoad, useToast } from './ui';
import { addDaysKey, dayKey, dayLabel, isLive, localToUtcIso, todayKey } from './format';
import { fmtHours, hoursOf, slotRanges, subtractRanges, type Range } from './ranges';

type PendingPack = PackRequest & { clientName: string };

interface HomeData {
  today: Booking[];
  busy: BusyBlock[];
  weekBookings: Booking[];
  weekSlots: Slot[];
  buffer: number;
  summary: Awaited<ReturnType<typeof api.adminSummary>>;
  /** Same counts for the 7 days before the summary window. Missing values mean "cannot compare". */
  prev: PrevWeek;
  clients: ClientSummary[];
  pending: PendingPack[];
}

interface PrevWeek {
  booked: number;
  completed: number;
  cancelled: number;
  sold?: number;
}

/** Counts for the 7 days before the summary window (today-13 to today-7), from the bookings and ledgers. */
async function loadPrevWeek(clients: ClientSummary[]): Promise<PrevWeek> {
  const t = todayKey();
  const from = addDaysKey(t, -13);
  const to = addDaysKey(t, -7);
  const bookings = await api.adminBookings(from, to);
  const prev: PrevWeek = {
    booked: bookings.filter((b) => b.status !== 'cancelled').length,
    completed: bookings.filter((b) => b.status === 'completed').length,
    cancelled: bookings.filter((b) => b.status === 'cancelled').length,
  };
  try {
    const details = await Promise.all(clients.map((c) => api.adminClient(c.user.id)));
    prev.sold = details
      .flatMap((d) => d.ledger)
      .filter((l) => l.reason === 'purchase' && l.delta > 0 && dayKey(l.createdAt) >= from && dayKey(l.createdAt) <= to)
      .reduce((n, l) => n + l.delta, 0);
  } catch {
    /* no comparison for sessions sold */
  }
  return prev;
}

/** "+3 on last week", "same as last week", "-2 on last week". */
function deltaText(now: number, before: number | undefined): string | null {
  if (before === undefined) return null;
  const d = now - before;
  if (d === 0) return 'same as last week';
  return `${d > 0 ? '+' : '\u2212'}${Math.abs(d)} on last week`;
}

async function loadHome(): Promise<HomeData> {
  const t = todayKey();
  const end = addDaysKey(t, 6);
  const [settings, today, busy, weekBookings, weekSlots, summary, clients] = await Promise.all([
    api.settings(),
    api.adminBookings(t, t),
    api.adminBusy(t, t),
    api.adminBookings(t, end),
    api.availability(t, end),
    api.adminSummary('week'),
    api.adminClients(),
  ]);
  const prev = await loadPrevWeek(clients);
  const withPending = clients.filter((c) => c.pendingPacks > 0);
  const details = await Promise.all(withPending.map((c) => api.adminClient(c.user.id)));
  const pending: PendingPack[] = details.flatMap((d) =>
    d.packs.filter((p) => p.status === 'pending').map((p) => ({ ...p, clientName: d.summary.user.name })),
  );
  return { today, busy, weekBookings, weekSlots, buffer: settings.bufferMinutes, summary, prev, clients, pending };
}

type Row =
  | { kind: 'booking'; at: string; b: Booking }
  | { kind: 'busy'; at: string; b: BusyBlock }
  | { kind: 'now'; at: string };

function Timeline({ data }: { data: HomeData }) {
  const rows = useMemo(() => {
    const t = todayKey();
    const r: Row[] = [
      ...data.today.filter((b) => b.status !== 'cancelled').map((b) => ({ kind: 'booking' as const, at: b.start, b })),
      ...data.busy.filter((b) => b.source === 'google').map((b) => ({ kind: 'busy' as const, at: b.start, b })),
    ];
    const nowIso = new Date().toISOString();
    if (dayKey(nowIso) === t) r.push({ kind: 'now', at: nowIso });
    return r.sort((a, b) => a.at.localeCompare(b.at));
  }, [data]);

  const hasBooking = rows.some((r) => r.kind === 'booking');
  const hasAny = rows.some((r) => r.kind !== 'now');
  return (
    <>
      {!hasBooking && (
        <Empty to="/admin/calendar" actionLabel="Open the calendar">No clients booked today</Empty>
      )}
      {hasAny && (
        <ol className="timeline">
          {rows.map((r, i) =>
            r.kind === 'now' ? (
              <li key={'now' + i} className="tl-now" aria-label="Current time">
                <span className="tl-time num">{formatTime(r.at)}</span>
                <span className="tl-nowline" />
              </li>
            ) : r.kind === 'busy' ? (
              <li key={'b' + i} className="tl-item tl-busy">
                <span className="tl-time num">{formatTime(r.b.start)}</span>
                <div className="tl-card">
                  <strong>{r.b.label ?? 'Busy (Google Calendar)'}</strong>
                  <span className="muted small num">
                    {formatTime(r.b.start)} to {formatTime(r.b.end)}
                  </span>
                </div>
              </li>
            ) : (
              <li key={r.b.id} className="tl-item tl-booked">
                <span className="tl-time num">{formatTime(r.b.start)}</span>
                <div className="tl-card">
                  <Link to={`/admin/clients/${r.b.userId}`}>
                    <strong>{r.b.clientName}</strong>
                  </Link>
                  <span className="muted small num">
                    {formatTime(r.b.start)} to {formatTime(r.b.end)}
                    {r.b.createdBy === 'chatgpt' ? ' · booked via ChatGPT' : ''}
                  </span>
                  {r.b.status !== 'confirmed' && (
                    <span className="tl-status">
                      <span className={'badge ' + (r.b.status === 'completed' ? 'badge-ok' : 'badge-warn')}>{r.b.status === 'completed' ? 'Completed' : 'No-show'}</span>
                    </span>
                  )}
                </div>
              </li>
            ),
          )}
        </ol>
      )}
    </>
  );
}

/** One compact row per day: name and date, a bar of booked against free hours, and the two numbers. */
function Week({ data }: { data: HomeData }) {
  const t = todayKey();
  const days = Array.from({ length: 7 }, (_, i) => addDaysKey(t, i));
  return (
    <ul className="days7">
      {days.map((k) => {
        const dayStart = new Date(localToUtcIso(k, '00:00')).getTime();
        const dayEnd = new Date(localToUtcIso(addDaysKey(k, 1), '00:00')).getTime();
        const clip = (r: Range): Range => ({ s: Math.max(r.s, dayStart), e: Math.min(r.e, dayEnd) });
        const live = data.weekBookings.filter((b) => isLive(b) && dayKey(b.start) === k);
        const bookedR: Range[] = live.map((b) => ({ s: new Date(b.start).getTime(), e: new Date(b.end).getTime() }));
        const freeR = subtractRanges(slotRanges(data.weekSlots.filter((s) => dayKey(s.start) === k)).map(clip), bookedR);
        const booked = hoursOf(bookedR);
        const free = hoursOf(freeR);
        const total = booked + free;
        const pct = total > 0 ? Math.round((booked / total) * 100) : 0;
        return (
          <li key={k}>
            <Link to="/admin/calendar" className="day7" aria-label={`${dayLabel(k)}: ${fmtHours(booked)} booked, ${fmtHours(free)} free. Open the calendar.`}>
              <span className="day7-name">
                <strong>{dayLabel(k)}</strong>
                {k === t && <span className="muted"> Today</span>}
              </span>
              <span className="day7-bar" aria-hidden="true">
                <i style={{ width: `${pct}%` }} />
              </span>
              <span className="day7-n num">
                <strong>{fmtHours(booked)}</strong> booked · {fmtHours(free)} free
              </span>
            </Link>
          </li>
        );
      })}
    </ul>
  );
}

export default function AdminHome() {
  const { data, error, reload } = useLoad(loadHome);
  const toast = useToast();
  const [paying, setPaying] = useState<PendingPack | null>(null);
  const [ref, setRef] = useState('');
  const [busy, setBusy] = useState(false);

  const low = useMemo(() => (data?.clients ?? []).filter((c) => c.balance <= 1).sort((a, b) => a.balance - b.balance), [data]);

  async function confirmPaid() {
    if (!paying) return;
    setBusy(true);
    try {
      await api.adminMarkPackPaid(paying.id, ref.trim() || undefined);
      toast.show(`${paying.sessions} sessions added for ${paying.clientName}.`);
      setPaying(null);
      setRef('');
      reload();
    } catch (e) {
      toast.show(errText(e), true);
    } finally {
      setBusy(false);
    }
  }

  const s = data?.summary;
  const kpis: Array<[string, number, string | null]> = s && data
    ? [
        ['Booked', s.bookedSessions, deltaText(s.bookedSessions, data.prev.booked)],
        ['Completed', s.completedSessions, deltaText(s.completedSessions, data.prev.completed)],
        ['Cancelled', s.cancelledSessions, deltaText(s.cancelledSessions, data.prev.cancelled)],
        ['Sessions sold', s.creditsSold, deltaText(s.creditsSold, data.prev.sold)],
        ['Free hours', Math.round(s.freeSlotHours), 'in the next 7 days'],
      ]
    : [];

  return (
    <AdminFrame>
      <AdminHead title="Today">
        <span className="head-date num">{formatDay(new Date())}</span>
      </AdminHead>
      {error && <ErrorState message={error} onRetry={reload} />}
      {!data && !error && <AdminSkeleton cards={4} />}
      {data && (
        <div className="admin-grid">
          <section className="card span-2" aria-labelledby="h-today">
            <h2 className="a-h" id="h-today">Today's schedule</h2>
            <Timeline data={data} />
          </section>

          <section className="card" aria-labelledby="h-kpi">
            <h2 className="a-h" id="h-kpi">Last 7 days</h2>
            <div className="kpis">
              {kpis.map(([label, n, delta]) => (
                <div key={label} className="kpi">
                  <div className="kpi-n num">{n}</div>
                  <div className="label">{label}</div>
                  {delta && <div className="kpi-d muted num">{delta}</div>}
                </div>
              ))}
            </div>
          </section>

          <section className="card span-3" aria-labelledby="h-week">
            <h2 className="a-h" id="h-week">Next 7 days</h2>
            <Week data={data} />
          </section>

          <section className="card span-2" aria-labelledby="h-pend">
            <h2 className="a-h" id="h-pend">
              Pack requests waiting for payment {data.pending.length > 0 && <span className="muted num">({data.pending.length})</span>}
            </h2>
            {data.pending.length === 0 ? (
              <Empty>No pack requests are waiting for payment.</Empty>
            ) : (
              <ul className="a-list">
                {data.pending.map((p) => (
                  <li key={p.id} className="a-li">
                    <div>
                      <strong>{p.clientName}</strong>
                      <div className="muted small num">
                        {p.sessions} {p.sessions === 1 ? 'session' : 'sessions'} · {formatRand(p.priceZar)} · asked {formatDayTime(p.createdAt)}
                      </div>
                    </div>
                    <button className="btn btn-ghost btn-sm" onClick={() => setPaying(p)}>
                      Mark paid
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </section>

          <section className="card" aria-labelledby="h-low">
            <h2 className="a-h" id="h-low">Running low</h2>
            {low.length === 0 ? (
              <Empty>Everyone has two or more sessions.</Empty>
            ) : (
              <ul className="a-list">
                {low.map((c) => (
                  <li key={c.user.id} className="a-li">
                    <Link to={`/admin/clients/${c.user.id}`}>
                      <strong>{c.user.name}</strong>
                    </Link>
                    <Balance n={c.balance} />
                  </li>
                ))}
              </ul>
            )}
          </section>
        </div>
      )}

      {paying && (
        <Modal
          title="Mark pack as paid"
          onClose={() => setPaying(null)}
          actions={
            <>
              <button className="btn btn-red" disabled={busy} onClick={confirmPaid}>
                Add {paying.sessions} sessions
              </button>
              <button className="btn btn-ghost" onClick={() => setPaying(null)}>
                Cancel
              </button>
            </>
          }
        >
          <p className="small silver">
            {paying.clientName} asked for {paying.sessions} {paying.sessions === 1 ? 'session' : 'sessions'} at {formatRand(paying.priceZar)}. Only continue once the money is in your account.
          </p>
          <div className="field">
            <label htmlFor="payref">Payment reference (optional)</label>
            <input id="payref" className="input" value={ref} onChange={(e) => setRef(e.target.value)} placeholder="e.g. EFT TSE-4471" maxLength={60} />
          </div>
        </Modal>
      )}
      {toast.node}
    </AdminFrame>
  );
}
