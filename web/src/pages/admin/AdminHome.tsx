import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../../data/api';
import type { Booking, BusyBlock, ClientSummary, PackRequest, Slot } from '../../domain/types';
import './admin.css';
import { AdminFrame, AdminHead, Empty, errText, Modal, useLoad, useToast } from './ui';
import {
  addDaysKey,
  dayKey,
  dayLabel,
  fmtTime,
  fmtWhen,
  rand,
  todayKey,
  WEEKDAYS_SHORT,
  weekdayOf,
} from './format';

type PendingPack = PackRequest & { clientName: string };

interface HomeData {
  today: Booking[];
  busy: BusyBlock[];
  weekBookings: Booking[];
  weekSlots: Slot[];
  buffer: number;
  summary: Awaited<ReturnType<typeof api.adminSummary>>;
  clients: ClientSummary[];
  pending: PendingPack[];
}

/** Count how many sessions of the day could be sold back to back from a list of possible starts. */
function countFree(slots: Slot[], bufferMin: number): number {
  const sorted = [...slots].sort((a, b) => a.start.localeCompare(b.start));
  let lastEnd = 0;
  let n = 0;
  for (const s of sorted) {
    const st = new Date(s.start).getTime();
    if (st >= lastEnd) {
      n++;
      lastEnd = new Date(s.end).getTime() + bufferMin * 60000;
    }
  }
  return n;
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
  const withPending = clients.filter((c) => c.pendingPacks > 0);
  const details = await Promise.all(withPending.map((c) => api.adminClient(c.user.id)));
  const pending: PendingPack[] = details.flatMap((d) =>
    d.packs.filter((p) => p.status === 'pending').map((p) => ({ ...p, clientName: d.summary.user.name })),
  );
  return { today, busy, weekBookings, weekSlots, buffer: settings.bufferMinutes, summary, clients, pending };
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

  if (!rows.some((r) => r.kind !== 'now')) return <Empty>Nothing booked today. The day is open.</Empty>;
  return (
    <ol className="timeline">
      {rows.map((r, i) =>
        r.kind === 'now' ? (
          <li key={'now' + i} className="tl-now" aria-label="Current time">
            <span className="tl-time num">{fmtTime(r.at)}</span>
            <span className="tl-nowline" />
          </li>
        ) : r.kind === 'busy' ? (
          <li key={'b' + i} className="tl-item tl-busy">
            <span className="tl-time num">{fmtTime(r.b.start)}</span>
            <div className="tl-card">
              <strong>{r.b.label ?? 'Busy (Google Calendar)'}</strong>
              <span className="muted small num">
                {fmtTime(r.b.start)} to {fmtTime(r.b.end)}
              </span>
            </div>
          </li>
        ) : (
          <li key={r.b.id} className="tl-item tl-booked">
            <span className="tl-time num">{fmtTime(r.b.start)}</span>
            <div className="tl-card">
              <Link to={`/admin/clients/${r.b.userId}`}>
                <strong>{r.b.clientName}</strong>
              </Link>
              <span className="muted small num">
                {fmtTime(r.b.start)} to {fmtTime(r.b.end)}
              </span>
              <span className="row" style={{ gap: 6 }}>
                {r.b.status !== 'confirmed' && <span className="badge">{r.b.status === 'completed' ? 'Completed' : 'No-show'}</span>}
                {r.b.createdBy === 'chatgpt' && <span className="badge badge-red">via ChatGPT</span>}
              </span>
            </div>
          </li>
        ),
      )}
    </ol>
  );
}

function Week({ data }: { data: HomeData }) {
  const t = todayKey();
  const days = Array.from({ length: 7 }, (_, i) => addDaysKey(t, i));
  return (
    <div className="week-cols" role="list">
      {days.map((k) => {
        const booked = data.weekBookings.filter((b) => (b.status === 'confirmed' || b.status === 'completed') && dayKey(b.start) === k).length;
        const slots = data.weekSlots.filter((s) => dayKey(s.start) === k);
        const free = countFree(slots, data.buffer);
        return (
          <div key={k} role="listitem" className={'week-col' + (k === t ? ' today' : '')}>
            <div className="eyebrow">{WEEKDAYS_SHORT[weekdayOf(k)]}</div>
            <div className="week-date num">{Number(k.slice(8))}</div>
            <div className="week-n num">{booked}</div>
            <div className="tiny muted">booked</div>
            <div className="week-free num">{free}</div>
            <div className="tiny muted">free</div>
          </div>
        );
      })}
    </div>
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
  const kpis = s
    ? [
        ['Booked', s.bookedSessions],
        ['Completed', s.completedSessions],
        ['Cancelled', s.cancelledSessions],
        ['Free hours', Math.round(s.freeSlotHours)],
        ['Sessions sold', s.creditsSold],
      ]
    : [];

  return (
    <AdminFrame>
      <AdminHead eyebrow="Owner" title={<>Today<span className="red">.</span></>}>
        <span className="muted small">{dayLabel(todayKey())}</span>
      </AdminHead>
      {error && <p className="error-text">{error}</p>}
      {!data && !error && <p className="muted" aria-busy="true">Loading...</p>}
      {data && (
        <div className="admin-grid">
          <section className="card card-red-top span-2" aria-labelledby="h-today">
            <h2 className="a-h" id="h-today">Today's schedule</h2>
            <Timeline data={data} />
          </section>

          <section className="card" aria-labelledby="h-kpi">
            <h2 className="a-h" id="h-kpi">This week</h2>
            <div className="kpis">
              {kpis.map(([label, n]) => (
                <div key={label} className="kpi">
                  <div className="kpi-n num">{n}</div>
                  <div className="eyebrow">{label}</div>
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
              Pack requests waiting for payment {data.pending.length > 0 && <span className="badge badge-warn">{data.pending.length}</span>}
            </h2>
            {data.pending.length === 0 ? (
              <Empty>No pack requests waiting. Nice and clear.</Empty>
            ) : (
              <ul className="a-list">
                {data.pending.map((p) => (
                  <li key={p.id} className="a-li">
                    <div>
                      <strong>{p.clientName}</strong>
                      <div className="muted small num">
                        {p.sessions} {p.sessions === 1 ? 'session' : 'sessions'} · {rand(p.priceZar)} · asked {fmtWhen(p.createdAt)}
                      </div>
                    </div>
                    <button className="btn btn-red btn-sm" onClick={() => setPaying(p)}>
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
                    <span className={'badge ' + (c.balance === 0 ? 'badge-red' : 'badge-warn')}>
                      {c.balance} {c.balance === 1 ? 'session' : 'sessions'}
                    </span>
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
            {paying.clientName} asked for {paying.sessions} {paying.sessions === 1 ? 'session' : 'sessions'} at {rand(paying.priceZar)}. Only continue once the money is in your account.
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
