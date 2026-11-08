import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../../data/api';
import type { Booking } from '../../domain/types';
import BookingCard from '../../components/BookingCard';
import CancelBookingModal from '../../components/CancelBookingModal';
import ClientFrame from '../../components/ClientFrame';
import EmptyState from '../../components/EmptyState';
import PageHeader from '../../components/PageHeader';
import './client.css';

export default function Bookings() {
  const [list, setList] = useState<Booking[] | null>(null);
  const [cancelFree, setCancelFree] = useState(24);
  const [cancel, setCancel] = useState<Booking | null>(null);
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    try {
      const [b, s] = await Promise.all([api.myBookings(), api.settings()]);
      setList(b);
      setCancelFree(s.cancelFreeHours);
    } catch {
      setError('Could not load your bookings. Refresh to try again.');
    }
  }, []);
  useEffect(() => void load(), [load]);

  const now = Date.now();
  const upcoming = (list ?? []).filter((b) => b.status === 'confirmed' && new Date(b.end).getTime() > now).sort((a, b) => a.start.localeCompare(b.start));
  const past = (list ?? []).filter((b) => !upcoming.includes(b)).sort((a, b) => b.start.localeCompare(a.start));

  return (
    <ClientFrame>
      <PageHeader eyebrow="Your sessions" title="My bookings" actions={<Link to="/app/book" className="btn btn-red">Book a session</Link>}>
        Cancel up to {cancelFree} hours before and your session is refunded.
      </PageHeader>
      {error && <p className="error-text" role="alert">{error}</p>}
      {!list && !error && <div aria-busy="true" style={{ minHeight: 240 }} />}
      {list && (
        <div className="tse-stack rise">
          <section>
            <div className="tse-sectionhead"><span className="eyebrow">Upcoming</span></div>
            {upcoming.length === 0 ? (
              <EmptyState icon="calendar" title="No upcoming sessions" action={<Link to="/app/book" className="btn btn-ghost btn-sm">Pick a time</Link>}>
                Book one when you are ready.
              </EmptyState>
            ) : (
              <div className="tse-bookings-list">
                {upcoming.map((b) => (
                  <BookingCard key={b.id} booking={b} actions={<button type="button" className="btn btn-ghost btn-sm" onClick={() => setCancel(b)}>Cancel</button>} />
                ))}
              </div>
            )}
          </section>
          <section>
            <div className="tse-sectionhead"><span className="eyebrow">Past</span></div>
            {past.length === 0 ? (
              <p className="muted small">Your past sessions will show here.</p>
            ) : (
              <div className="tse-bookings-list">
                {past.map((b) => <BookingCard key={b.id} booking={b} />)}
              </div>
            )}
          </section>
        </div>
      )}
      <CancelBookingModal booking={cancel} cancelFreeHours={cancelFree} onClose={() => setCancel(null)} onDone={() => { setCancel(null); void load(); }} />
    </ClientFrame>
  );
}
