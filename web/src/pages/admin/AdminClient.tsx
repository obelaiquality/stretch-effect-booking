import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { api } from '../../data/api';
import type { Booking, Slot } from '../../domain/types';
import './admin.css';
import { AdminFrame, AdminHead, Empty, errText, Modal, useLoad, useToast } from './ui';
import { actorLabel, addDaysKey, dayKey, dayLabel, fmtTime, fmtWhen, rand, statusLabel, todayKey } from './format';

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
  if (!slots) return <p className="muted small">Loading free times...</p>;
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
              <span className="num">{fmtTime(s.start)}</span>
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

  if (error) return <AdminFrame><p className="error-text">{error}</p><Link to="/admin/clients">Back to clients</Link></AdminFrame>;
  if (!data) return <AdminFrame><p className="muted" aria-busy="true">Loading...</p></AdminFrame>;

  const { summary, ledger, bookings, packs } = data;
  const u = summary.user;
  const nowIso = new Date().toISOString();
  const upcoming = bookings.filter((b) => b.status === 'confirmed' && b.end >= nowIso).sort((a, b) => a.start.localeCompare(b.start));
  const past = bookings.filter((b) => !upcoming.includes(b)).sort((a, b) => b.start.localeCompare(a.start));
  const pending = packs.filter((p) => p.status === 'pending');
  const ledgerSorted = [...ledger].sort((a, b) => b.createdAt.localeCompare(a.createdAt));

  return (
    <AdminFrame>
      <p className="small"><Link to="/admin/clients">Clients</Link> <span className="muted">/</span> {u.name}</p>
      <AdminHead eyebrow="Client" title={u.name}>
        <div className="balance-box">
          <div className="kpi-n num">{summary.balance}</div>
          <div className="eyebrow">{summary.balance === 1 ? 'session left' : 'sessions left'}</div>
        </div>
      </AdminHead>
      <p className="muted small">{u.email}{u.phone ? ` · ${u.phone}` : ''}</p>

      <div className="admin-grid">
        <section className="card" aria-labelledby="h-adj">
          <h2 className="a-h" id="h-adj">Adjust sessions</h2>
          <div className="stepper" role="group" aria-label="Sessions to add or remove">
            <button className="btn btn-ghost" aria-label="One fewer" onClick={() => setDelta((d) => Math.max(-20, d - 1))}>-</button>
            <output className="stepper-n num" aria-live="polite">{delta > 0 ? '+' : ''}{delta}</output>
            <button className="btn btn-ghost" aria-label="One more" onClick={() => setDelta((d) => Math.min(20, d + 1))}>+</button>
          </div>
          <div className="field" style={{ marginTop: 'var(--s4)' }}>
            <label htmlFor="adjnote">Note (required)</label>
            <input id="adjnote" className="input" value={note} onChange={(e) => setNote(e.target.value)} maxLength={120} placeholder="e.g. Cash paid at the studio" />
          </div>
          <button
            className="btn btn-red btn-block"
            style={{ marginTop: 'var(--s4)' }}
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
          {pending.length > 0 && (
            <div style={{ marginTop: 'var(--s5)' }}>
              <div className="eyebrow">Pending packs</div>
              <ul className="a-list">
                {pending.map((p) => (
                  <li key={p.id} className="a-li">
                    <span className="small num">{p.sessions} sessions · {rand(p.priceZar)}</span>
                    <button className="btn btn-ghost btn-sm" disabled={busy} onClick={() => run(() => api.adminMarkPackPaid(p.id), `${p.sessions} sessions added.`)}>
                      Mark paid
                    </button>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </section>

        <section className="card span-2" aria-labelledby="h-book">
          <h2 className="a-h" id="h-book">Book for {u.name.split(' ')[0]}</h2>
          {!booking ? (
            <button className="btn btn-ghost" onClick={() => setBooking(true)}>Choose a time</button>
          ) : (
            <div className="stack">
              <SlotChooser onPick={setPick} picked={pick} />
              <div className="row">
                <button
                  className="btn btn-red"
                  disabled={!pick || busy}
                  onClick={async () => {
                    if (pick && (await run(() => api.adminBookFor(id, pick), `Booked ${fmtWhen(pick)}.`))) {
                      setPick(null);
                      setBooking(false);
                    }
                  }}
                >
                  {pick ? `Book ${fmtWhen(pick)}` : 'Pick a time'}
                </button>
                <button className="btn btn-quiet" onClick={() => { setBooking(false); setPick(null); }}>Close</button>
              </div>
              <p className="hint">Uses one of their sessions. Clients with no sessions left cannot be booked.</p>
            </div>
          )}
        </section>

        <section className="card span-3" aria-labelledby="h-bk">
          <h2 className="a-h" id="h-bk">Bookings</h2>
          {bookings.length === 0 && <Empty>No bookings yet.</Empty>}
          {upcoming.length > 0 && <div className="eyebrow" style={{ marginBottom: 8 }}>Upcoming</div>}
          <ul className="a-list">
            {upcoming.map((b) => (
              <li key={b.id} className="a-li wrap-li">
                <div>
                  <strong className="num">{fmtWhen(b.start)}</strong>
                  {b.createdBy !== 'client' && <span className="badge" style={{ marginLeft: 8 }}>{b.createdBy === 'chatgpt' ? 'via ChatGPT' : 'by you'}</span>}
                </div>
                <div className="row">
                  {new Date(b.start) <= new Date() && (
                    <>
                      <button className="btn btn-ghost btn-sm" disabled={busy} onClick={() => run(() => api.adminSetBookingStatus(b.id, 'completed'), 'Marked completed.')}>Completed</button>
                      <button className="btn btn-ghost btn-sm" disabled={busy} onClick={() => run(() => api.adminSetBookingStatus(b.id, 'no_show'), 'Marked no-show.')}>No-show</button>
                    </>
                  )}
                  <button className="btn btn-ghost btn-sm" onClick={() => { setCancelling(b); setRefund(true); setReason(''); }}>Cancel</button>
                </div>
              </li>
            ))}
          </ul>
          {past.length > 0 && <div className="eyebrow" style={{ margin: '16px 0 8px' }}>History</div>}
          <ul className="a-list">
            {past.map((b) => (
              <li key={b.id} className="a-li wrap-li">
                <div>
                  <span className="num">{fmtWhen(b.start)}</span>{' '}
                  <span className={'badge ' + (b.status === 'completed' ? 'badge-ok' : b.status === 'no_show' ? 'badge-warn' : '')}>{statusLabel[b.status]}</span>
                  {b.status === 'confirmed' && (
                    <span className="row" style={{ display: 'inline-flex', marginLeft: 8 }}>
                      <button className="btn btn-ghost btn-sm" disabled={busy} onClick={() => run(() => api.adminSetBookingStatus(b.id, 'completed'), 'Marked completed.')}>Completed</button>
                      <button className="btn btn-ghost btn-sm" disabled={busy} onClick={() => run(() => api.adminSetBookingStatus(b.id, 'no_show'), 'Marked no-show.')}>No-show</button>
                    </span>
                  )}
                </div>
                {b.status === 'cancelled' && <span className="muted small">{b.refunded ? 'Refunded' : 'Not refunded'}{b.cancelReason ? ` · ${b.cancelReason}` : ''}</span>}
              </li>
            ))}
          </ul>
        </section>

        <section className="card span-3" aria-labelledby="h-led">
          <h2 className="a-h" id="h-led">Session history</h2>
          {ledgerSorted.length === 0 ? (
            <Empty>No changes yet.</Empty>
          ) : (
            <div className="table-scroll">
              <table className="table">
                <thead>
                  <tr><th>When</th><th>Change</th><th>What</th><th>Note</th><th>By</th></tr>
                </thead>
                <tbody>
                  {ledgerSorted.map((l) => (
                    <tr key={l.id}>
                      <td className="num nowrap">{fmtWhen(l.createdAt)}</td>
                      <td className="num"><strong className={l.delta > 0 ? 'plus' : 'minus'}>{l.delta > 0 ? '+' : ''}{l.delta}</strong></td>
                      <td>{REASON_LABEL[l.reason] ?? l.reason}</td>
                      <td className="muted">{l.note ?? ''}</td>
                      <td><span className={'badge' + (l.actor.startsWith('chatgpt') ? ' badge-red' : '')}>{actorLabel(l.actor, u.id)}</span></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>
      </div>

      {cancelling && (
        <Modal
          title="Cancel this booking"
          onClose={() => setCancelling(null)}
          actions={
            <>
              <button
                className="btn btn-red"
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
          <p className="small silver">{u.name}, {fmtWhen(cancelling.start)}</p>
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
