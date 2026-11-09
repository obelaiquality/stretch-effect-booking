import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../../data/api';
import { useSession } from '../../data/session';
import type { Booking, LedgerEntry } from '../../domain/types';
import { formatDay } from '../../lib/format';
import BookingCard from '../../components/BookingCard';
import CancelBookingModal from '../../components/CancelBookingModal';
import ClientFrame from '../../components/ClientFrame';
import { DashboardSkel } from '../../components/ClientSkeletons';
import CreditsMeter from '../../components/CreditsMeter';
import EmptyState from '../../components/EmptyState';
import ErrorState from '../../components/ErrorState';
import Icon from '../../components/Icon';
import PageHeader from '../../components/PageHeader';
import { firstName } from './shared';
import './client.css';

interface Data { balance: number; ledger: LedgerEntry[]; bookings: Booking[]; pendingPacks: number; cancelFreeHours: number }

/** One line per ledger entry: what happened and when. */
function ledgerText(l: LedgerEntry): string {
  switch (l.reason) {
    case 'booking': return `Booked on ${formatDay(l.createdAt)}`;
    case 'cancel_refund': return `Session refunded on ${formatDay(l.createdAt)}`;
    case 'purchase': return `${l.note || 'Sessions loaded'} · ${formatDay(l.createdAt)}`;
    case 'welcome_offer': return `Welcome offer · ${formatDay(l.createdAt)}`;
    default: return `${l.note && l.note !== 'Adjusted by Ivan' ? l.note : 'Adjusted by Ivan'} · ${formatDay(l.createdAt)}`;
  }
}

function DashboardInner() {
  const { user } = useSession();
  const [data, setData] = useState<Data | null>(null);
  const [cancel, setCancel] = useState<Booking | null>(null);
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    setError('');
    try {
      const [balance, ledger, bookings, settings, reqs] = await Promise.all([api.myBalance(), api.myLedger(), api.myBookings(), api.settings(), api.myPackRequests()]);
      setData({ balance, ledger, bookings, cancelFreeHours: settings.cancelFreeHours, pendingPacks: reqs.filter((r) => r.status === 'pending').length });
    } catch {
      setError('Could not load your account.');
    }
  }, []);
  useEffect(() => void load(), [load]);

  const now = Date.now();
  const next = data?.bookings
    .filter((b) => b.status === 'confirmed' && new Date(b.start).getTime() > now)
    .sort((a, b) => a.start.localeCompare(b.start))[0];

  if (error) {
    return (
      <>
        <PageHeader title={`Hi ${firstName(user?.name ?? 'there')}`} />
        <ErrorState message={error} onRetry={() => void load()} />
      </>
    );
  }
  if (!data) return <DashboardSkel />;

  return (
    <>
      <PageHeader title={`Hi ${firstName(user?.name ?? 'there')}`} />
      <div className="tse-grid2 rise">
        <div className="tse-stack">
          <section className="card tse-credit-card" aria-label="Sessions left">
            <CreditsMeter value={data.balance} pending={data.pendingPacks} />
            {data.balance === 0 ? (
              <div className="tse-prompt">
                <strong>You have no sessions left.</strong>
                <span className="small silver">Buy a pack to book your next stretch.</span>
                <Link to="/app/packs" className="btn btn-red">Buy sessions <Icon name="arrow-right" size={18} /></Link>
              </div>
            ) : (
              <div className="row">
                <Link to="/app/book" className="btn btn-red">Book a session <Icon name="arrow-right" size={18} /></Link>
                <Link to="/app/packs" className="btn btn-ghost">Buy sessions</Link>
              </div>
            )}
          </section>

          <section aria-label="Next booking">
            <div className="tse-sectionhead"><span className="eyebrow">Next session</span></div>
            {next ? (
              <BookingCard
                booking={next}
                highlight
                actions={<button type="button" className="btn btn-danger btn-sm" onClick={() => setCancel(next)}>Cancel</button>}
              />
            ) : (
              <EmptyState icon="calendar" title="Nothing booked yet" action={<Link to="/app/book" className="btn btn-ghost">Book a session</Link>}>
                Choose a time that suits you and it goes straight into Ivan's calendar.
              </EmptyState>
            )}
          </section>
        </div>

        <section className="card" aria-label="Recent activity">
          <div className="tse-sectionhead"><span className="eyebrow">Recent activity</span></div>
          {data.ledger.length === 0 ? (
            <EmptyState icon="clock" title="No activity yet" action={<Link to="/app/packs" className="btn btn-ghost">Buy sessions</Link>}>
              Your sessions and bookings will show here.
            </EmptyState>
          ) : (
            <ul className="tse-ledger">
              {data.ledger.slice(0, 8).map((l) => (
                <li key={l.id}>
                  <div className="tse-ledger-main num">{ledgerText(l)}</div>
                  <div className={`tse-delta ${l.delta > 0 ? 'pos' : 'neg'}`}>{l.delta > 0 ? `+${l.delta}` : l.delta === 0 ? '0' : `−${Math.abs(l.delta)}`}</div>
                </li>
              ))}
            </ul>
          )}
        </section>
      </div>
      <CancelBookingModal booking={cancel} cancelFreeHours={data.cancelFreeHours} onClose={() => setCancel(null)} onDone={() => { setCancel(null); void load(); }} />
    </>
  );
}

export default function Dashboard() {
  return (
    <ClientFrame>
      <DashboardInner />
    </ClientFrame>
  );
}
