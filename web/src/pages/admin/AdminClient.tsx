import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { api } from '../../data/api';
import type { Booking, Slot } from '../../domain/types';
import { formatDayTime, formatRand, formatTime } from '../../lib/format';
import './admin.css';
import { AdminFrame, AdminHead, CardSkel, Empty, ErrorState, Modal, errText, useLoad, useToast } from './ui';
import { Skel } from '../../components/Skeleton';
import { actorLabel, addDaysKey, dayKey, dayLabel, statusLabel, todayKey } from './format';

const REASON_LABEL: Record<string, string> = {
  purchase: 'Pack paid',
  booking: 'Session booked',
  cancel_refund: 'Cancellation refund',
  owner_adjust: 'Adjustment',
  chatgpt_adjust: 'Adjustment',
  welcome_offer: 'Welcome offer',
};

/** Small day + time chooser for booking on a client's behalf. */
function SlotChooser({ onPick, picked }: { onPick: (iso: string) => void; picked: string | null }) {
  const [slots, setSlots] = useState<Slot[] | null>(null);
  const [day, setDay] = useState<string | null>(null);
  useEffect(() => {
    const t = todayKey();
    api.availability(t, addDaysKey(t, 13)).then((s) => {
      setSlots(s);
      const first = s[0] ? dayKey(s[0].start) : null;
      setDay(first);
    }, () => setSlots([]));
  }, []);
  if (!slots) return <div className="stack" aria-busy="true"><Skel h={44} /><Skel h={44} w="70%" /></div>;
  const days = [...new Set(slots.map((s) => dayKey(s.start)))];
  if (days.length === 0) return <Empty>No free times in the next two weeks.</Empty>;
  return (
    <div className="stack">
      <div className="chips" role="tablist" aria-label="Day">
        {days.map((d) => (
          <button key={d} role="tab" aria-selected={d === day} className={'chip' + (d === day ? ' on' : '')} onClick={() => setDay(d)}>
            {dayLabel(d)}
          </button>
        ))}
      </div>
      <div className="chips" aria-label="Times">
        {slots
          .filter((s) => dayKey(s.start) === day)
          .map((s) => (
            <button key={s.start} className={'chip' + (picked === s.start ? ' on' : '')} aria-pressed={picked === s.start} onClick={() => onPick(s.start)}>
              <span className="num">{formatTime(s.start)}</span>
            </button>
          ))}
      </div>
    </div>
  );
}

export default function AdminClient() {
  const { id = '' } = useParams();
  const { data, error, reload } = useLoad(() => api.adminClient(id), [id]);
  const toast = useToast();
  const [delta, setDelta] = useState(1);
  const [note, setNote] = useState('');
  const [pick, setPick] = useState<string | null>(null);
  const [booking, setBooking] = useState(false);
  const [cancelling, setCancelling] = useState<Booking | null>(null);
  const [refund, setRefund] = useState(true);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);

  async function run(fn: () => Promise<unknown>, ok: string) {
    setBusy(true);
    try {
      await fn();
      toast.show(ok);
      reload();
      return true;
    } catch (e) {
      toast.show(errText(e), true);
      return false;
    } finally {
      setBusy(false);
    }
  }

  if (error)
    return (
      <AdminFrame>
        <p className="small"><Link to="/admin/clients">Clients</Link></p>
        <ErrorState message={error} onRetry={reload} />
      </AdminFrame>
    );
  if (!data)
    return (
      <AdminFrame>
        <p className="small"><Link to="/admin/clients">Clients</Link></p>
        <Skel w="min(360px, 80%)" h={56} style={{ margin: '12px 0 8px' }} />
        <Skel w={200} h={16} style={{ marginBottom: 24 }} />
        <div className="client-grid" aria-busy="true" aria-label="Loading">
          <div className="card"><CardSkel lines={5} /></div>
          <div className="card"><CardSkel lines={6} /></div>
        </div>
      </AdminFrame>
    );

  const { summary, ledger, bookings, packs } = data;
  const u = summary.user;
  const nowIso = new Date().toISOString();
  const upcoming = bookings.filter((b) => b.status === 'confirmed' && b.end >= nowIso).sort((a, b) => a.start.localeCompare(b.start));
  const past = bookings.filter((b) => !upcoming.includes(b)).sort((a, b) => b.start.localeCompare(a.start));
  const pending = packs.filter((p) => p.status === 'pending');
  const ledgerSorted = [...ledger].sort((a, b) => b.createdAt.localeCompare(a.createdAt));

  const first = u.name.split(' ')[0];
  const resolveButtons = (b: Booking) => (
    <>
      <button className="btn btn-ghost btn-sm" disabled={busy} onClick={() => run(() => api.adminSetBookingStatus(b.id, 'completed'), 'Marked completed.')}>Completed</button>
      <button className="btn btn-ghost btn-sm" disabled={busy} onClick={() => run(() => api.adminSetBookingStatus(b.id, 'no_show'), 'Marked no-show.')}>No-show</button>
    </>
  );

  return (
    <AdminFrame>
      <p className="small"><Link to="/admin/clients">Clients</Link> <span className="muted">/</span> {u.name}</p>
      <AdminHead title={u.name}>
        <div className="balance-box">
          <div className={'figure num' + (summary.balance === 0 ? ' text-error' : '')}>{summary.balance}</div>
          <div className="label">{summary.balance === 1 ? 'session left' : 'sessions left'}</div>
        </div>
      </AdminHead>
      <p className="muted small">{u.email}{u.phone ? ` · ${u.phone}` : ''}</p>

      <div className="client-grid">
        <div className="client-main">
          <section className="card" aria-labelledby="h-bk">
            <h2 className="a-h" id="h-bk">Bookings</h2>
            {bookings.length === 0 && <Empty to="/admin/calendar" actionLabel="Open the calendar">No bookings yet.</Empty>}
            {upcoming.length > 0 && <div className="label" style={{ marginBottom: 8 }}>Upcoming</div>}
            <ul className="a-list">
              {upcoming.map((b) => (
                <li key={b.id} className="a-li wrap-li">
                  <div>
                    <strong className="num">{formatDayTime(b.start)}</strong>
                    {b.createdBy !== 'client' && <span className="muted small"> · {b.createdBy === 'chatgpt' ? 'booked via ChatGPT' : 'booked by you'}</span>}
                  </div>
                  <div className="row row-actions">
                    {new Date(b.start) <= new Date() && resolveButtons(b)}
                    <button className="btn btn-danger btn-sm" onClick={() => { setCancelling(b); setRefund(true); setReason(''); }}>Cancel</button>
                  </div>
                </li>
              ))}
            </ul>
            {past.length > 0 && <div className="label" style={{ margin: '16px 0 8px' }}>History</div>}
            <ul className="a-list">
              {past.map((b) => (
                <li key={b.id} className="hist-row">
                  <span className="num">{formatDayTime(b.start)}</span>
                  <span className="hist-status">
                    <span className={'badge ' + (b.status === 'completed' ? 'badge-ok' : b.status === 'no_show' || b.status === 'confirmed' ? 'badge-warn' : '')}>{statusLabel[b.status]}</span>
                  </span>
                  {b.status === 'cancelled' && (
                    <span className="hist-note muted small">{b.refunded ? 'Refunded' : 'Not refunded'}{b.cancelReason ? ` · ${b.cancelReason}` : ''}</span>
                  )}
                  {b.status === 'confirmed' && <span className="hist-note row row-actions">{resolveButtons(b)}</span>}
                </li>
              ))}
            </ul>
          </section>

          <section className="card" aria-labelledby="h-led">
            <h2 className="a-h" id="h-led">Session history</h2>
            {ledgerSorted.length === 0 ? (
              <Empty>No session changes yet.</Empty>
            ) : (
              <div className="table-scroll">
                <table className="table">
                  <thead>
                    <tr><th>When</th><th>Change</th><th>What</th><th>Note</th><th>By</th></tr>
                  </thead>
                  <tbody>
                    {ledgerSorted.map((l) => {
                      const what = REASON_LABEL[l.reason] ?? l.reason;
                      const note = l.note && l.note.trim().toLowerCase() !== what.toLowerCase() ? l.note : '';
                      return (
                        <tr key={l.id}>
                          <td className="num nowrap">{formatDayTime(l.createdAt)}</td>
                          <td className="num"><strong className={l.delta > 0 ? 'plus' : ''}>{l.delta > 0 ? '+' : '\u2212'}{Math.abs(l.delta)}</strong></td>
                          <td>{what}</td>
                          <td className="muted">{note}</td>
                          <td className="silver">{actorLabel(l.actor, u.id)}</td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </section>
        </div>

        <aside className="card client-actions" aria-label="Actions">
          <section aria-labelledby="h-adj">
            <h2 className="a-h" id="h-adj">Adjust sessions</h2>
            <div className="stepper" role="group" aria-label="Sessions to add or remove">
              <button className="btn btn-ghost" aria-label="One fewer" onClick={() => setDelta((d) => Math.max(-20, d - 1))}>-</button>
              <output className="stepper-n num" aria-live="polite">{delta > 0 ? '+' : delta < 0 ? '\u2212' : ''}{Math.abs(delta)}</output>
              <button className="btn btn-ghost" aria-label="One more" onClick={() => setDelta((d) => Math.min(20, d + 1))}>+</button>
            </div>
            <div className="field" style={{ marginTop: 'var(--s3)' }}>
              <label htmlFor="adjnote">Note (required)</label>
              <input id="adjnote" className="input" value={note} onChange={(e) => setNote(e.target.value)} maxLength={120} placeholder="e.g. Cash paid at the studio" />
            </div>
            <button
              className="btn btn-ghost btn-block"
              style={{ marginTop: 'var(--s3)' }}
              disabled={busy || delta === 0 || note.trim().length < 3}
              onClick={async () => {
                if (await run(() => api.adminAdjustCredits(id, delta, note.trim()), `${delta > 0 ? 'Added' : 'Removed'} ${Math.abs(delta)} ${Math.abs(delta) === 1 ? 'session' : 'sessions'}.`)) {
                  setNote('');
                  setDelta(1);
                }
              }}
            >
              Apply
            </button>
          </section>

          {pending.length > 0 && (
            <section aria-labelledby="h-pp">
              <h2 className="a-h" id="h-pp">Pending packs</h2>
              <ul className="a-list">
                {pending.map((p) => (
                  <li key={p.id} className="a-li">
                    <span className="small num">{p.sessions} sessions · {formatRand(p.priceZar)}</span>
                    <button className="btn btn-ghost btn-sm" disabled={busy} onClick={() => run(() => api.adminMarkPackPaid(p.id), `${p.sessions} sessions added.`)}>
                      Mark paid
                    </button>
                  </li>
                ))}
              </ul>
            </section>
          )}

          <section aria-labelledby="h-book">
            <h2 className="a-h" id="h-book">Book for {first}</h2>
            {!booking ? (
              <button className="btn btn-ghost btn-block" onClick={() => setBooking(true)}>Choose a time</button>
            ) : (
              <div className="stack">
                <SlotChooser onPick={setPick} picked={pick} />
                <button
                  className="btn btn-red btn-block"
                  disabled={!pick || busy}
                  onClick={async () => {
                    if (pick && (await run(() => api.adminBookFor(id, pick), `Booked ${formatDayTime(pick)}.`))) {
                      setPick(null);
                      setBooking(false);
                    }
                  }}
                >
                  {pick ? `Book ${formatDayTime(pick)}` : 'Pick a time'}
                </button>
                <button className="btn btn-quiet btn-block" onClick={() => { setBooking(false); setPick(null); }}>Close</button>
                <p className="hint">Uses one of their sessions. Clients with no sessions left cannot be booked.</p>
              </div>
            )}
          </section>
        </aside>
      </div>

      {cancelling && (
        <Modal
          title="Cancel this booking"
          onClose={() => setCancelling(null)}
          actions={
            <>
              <button
                className="btn btn-danger"
                disabled={busy || reason.trim().length < 3}
                onClick={async () => {
                  const b = cancelling;
                  if (await run(() => api.adminCancelBooking(b.id, refund, reason.trim()), refund ? 'Cancelled and session refunded.' : 'Cancelled, no refund.')) setCancelling(null);
                }}
              >
                Cancel booking
              </button>
              <button className="btn btn-ghost" onClick={() => setCancelling(null)}>Keep it</button>
            </>
          }
        >
          <p className="small silver">{u.name}, {formatDayTime(cancelling.start)}</p>
          <label className="check">
            <input type="checkbox" checked={refund} onChange={(e) => setRefund(e.target.checked)} />
            <span>Give the session back</span>
          </label>
          <div className="field">
            <label htmlFor="creason">Reason (required)</label>
            <input id="creason" className="input" value={reason} onChange={(e) => setReason(e.target.value)} maxLength={120} placeholder="e.g. Client is unwell" />
          </div>
        </Modal>
      )}
      {toast.node}
    </AdminFrame>
  );
}
