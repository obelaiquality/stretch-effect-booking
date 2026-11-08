import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../../data/api';
import { useSession } from '../../data/session';
import type { Booking, LedgerEntry } from '../../domain/types';
import { formatLocalDateTime } from '../../domain/slots';
import BookingCard from '../../components/BookingCard';
import CancelBookingModal from '../../components/CancelBookingModal';
import ClientFrame from '../../components/ClientFrame';
import CreditsMeter from '../../components/CreditsMeter';
import EmptyState from '../../components/EmptyState';
import Icon from '../../components/Icon';
import PageHeader from '../../components/PageHeader';
import { firstName, REASON_LABEL } from './shared';
import './client.css';

interface Data { balance: number; ledger: LedgerEntry[]; bookings: Booking[]; cancelFreeHours: number }

export default function Dashboard() {
  const { user } = useSession();
  const [data, setData] = useState<Data | null>(null);
  const [cancel, setCancel] = useState<Booking | null>(null);
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    try {
      const [balance, ledger, bookings, settings] = await Promise.all([api.myBalance(), api.myLedger(), api.myBookings(), api.settings()]);
      setData({ balance, ledger, bookings, cancelFreeHours: settings.cancelFreeHours });
    } catch {
      setError('Could not load your account. Refresh to try again.');
    }
  }, []);
  useEffect(() => void load(), [load]);

  const now = Date.now();
  const next = data?.bookings
    .filter((b) => b.status === 'confirmed' && new Date(b.start).getTime() > now)
    .sort((a, b) => a.start.localeCompare(b.start))[0];

  return (
    <ClientFrame>
      <PageHeader eyebrow="Your account" title={`Hi ${firstName(user?.name ?? 'there')}`}>
        Get stretched. Move better. Live better.
      </PageHeader>
      {error && <p className="error-text" role="alert">{error}</p>}
      {!data && !error && <div aria-busy="true" style={{ minHeight: 240 }} />}
      {data && (
        <div className="tse-grid2 rise">
          <div className="tse-stack">
            <section className="card card-red-top tse-credit-card" aria-label="Sessions left">
              <CreditsMeter value={data.balance} />
              {data.balance === 0 ? (
                <div className="tse-prompt">
                  <strong>You have no sessions left.</strong>
                  <span className="small silver">Load a pack to book your next stretch.</span>
                  <Link to="/app/packs" className="btn btn-red">Load sessions <Icon name="arrow-right" size={18} /></Link>
                </div>
              ) : (
                <div className="row">
                  <Link to="/app/book" className="btn btn-red">Book a session <Icon name="arrow-right" size={18} /></Link>
                  <Link to="/app/packs" className="btn btn-ghost">Load more</Link>
                </div>
              )}
            </section>

            <section aria-label="Next booking">
              <div className="tse-sectionhead"><span className="eyebrow">Next session</span></div>
              {next ? (
                <BookingCard
                  booking={next}
                  highlight
                  actions={<button type="button" className="btn btn-ghost btn-sm" onClick={() => setCancel(next)}>Cancel</button>}
                />
              ) : (
                <EmptyState icon="calendar" title="Nothing booked yet" action={<Link to="/app/book" className="btn btn-ghost btn-sm">Pick a time</Link>}>
                  Choose a time that suits you. It goes straight into Ivan's calendar.
                </EmptyState>
              )}
            </section>
          </div>

          <section className="card" aria-label="Recent activity">
            <div className="tse-sectionhead"><span className="eyebrow">Recent activity</span></div>
            {data.ledger.length === 0 ? (
              <p className="muted small">Nothing yet. Your sessions will show here.</p>
            ) : (
              <ul className="tse-ledger">
                {data.ledger.slice(0, 8).map((l) => (
                  <li key={l.id}>
                    <div>
                      <div>{REASON_LABEL[l.reason]}</div>
                      <div className="tiny muted num">{formatLocalDateTime(l.createdAt)}{l.note ? ` · ${l.note}` : ''}</div>
                    </div>
                    <div className={`tse-delta num ${l.delta > 0 ? 'pos' : 'neg'}`}>{l.delta > 0 ? `+${l.delta}` : l.delta === 0 ? '0' : `−${Math.abs(l.delta)}`}</div>
                  </li>
                ))}
              </ul>
            )}
          </section>
        </div>
      )}
      <CancelBookingModal booking={cancel} cancelFreeHours={data?.cancelFreeHours ?? 24} onClose={() => setCancel(null)} onDone={() => { setCancel(null); void load(); }} />
    </ClientFrame>
  );
}
