import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../../data/api';
import { addDays, localDayKeyMs } from '../../domain/slots';
import { ApiError, type Booking, type Settings, type Slot } from '../../domain/types';
import ClientFrame from '../../components/ClientFrame';
import { BookSkel } from '../../components/ClientSkeletons';
import EmptyState from '../../components/EmptyState';
import ErrorState from '../../components/ErrorState';
import Icon from '../../components/Icon';
import { downloadBookingIcs } from '../../components/ics';
import Modal from '../../components/Modal';
import PageHeader from '../../components/PageHeader';
import SlotPicker from '../../components/SlotPicker';
import { useToast } from '../../components/Toast';
import { formatDay, formatDayTime, formatTimeRange } from '../../lib/format';
import './client.css';

function BookInner() {
  const toast = useToast();
  const [slots, setSlots] = useState<Slot[] | null>(null);
  const [balance, setBalance] = useState<number | null>(null);
  const [settings, setSettings] = useState<Settings | null>(null);
  const [mine, setMine] = useState<Booking[]>([]);
  const [selected, setSelected] = useState<string | null>(null);
  const [confirm, setConfirm] = useState(false);
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState<Booking | null>(null);
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    setError('');
    try {
      const today = localDayKeyMs(Date.now());
      const [sl, bal, st, bk] = await Promise.all([api.availability(today, addDays(today, 27)), api.myBalance(), api.settings(), api.myBookings()]);
      setSlots(sl);
      setBalance(bal);
      setSettings(st);
      const now = Date.now();
      setMine(bk.filter((b) => b.status === 'confirmed' && new Date(b.end).getTime() > now));
    } catch {
      setError('Could not load times.');
    }
  }, []);
  useEffect(() => void load(), [load]);

  const book = async () => {
    if (!selected) return;
    setBusy(true);
    try {
      const b = await api.book(selected);
      setDone(b);
      setConfirm(false);
      setSelected(null);
    } catch (e) {
      setConfirm(false);
      if (e instanceof ApiError && e.code === 'conflict') {
        toast.push('That time was just taken. Please pick another.', 'error');
        setSelected(null);
        void load();
      } else if (e instanceof ApiError && e.code === 'no_credits') {
        toast.push('You have no sessions left. Buy sessions first.', 'error');
        void load();
      } else {
        toast.push(e instanceof ApiError ? e.message : 'Could not book. Try again.', 'error');
      }
    } finally {
      setBusy(false);
    }
  };

  if (done) {
    return (
      <div className="card tse-success rise">
        <div className="tse-success-mark"><Icon name="check" size={26} /></div>
        <h1 className="page-title">You are booked</h1>
        <p className="h3 num">{formatDayTime(done.start)}</p>
        <p className="muted measure" style={{ margin: 0 }}>Added to Ivan's calendar.</p>
        <p className="small silver measure" style={{ margin: 0 }}>Brackenfell, Cape Town · exact address in your confirmation email</p>
        <div className="row">
          <Link to="/app/bookings" className="btn btn-red">My bookings</Link>
          <button type="button" className="btn btn-ghost" onClick={() => downloadBookingIcs(done)}>
            <Icon name="download" size={18} /> Add to my calendar
          </button>
          <button type="button" className="btn btn-quiet" onClick={() => { setDone(null); void load(); }}>Book another</button>
        </div>
      </div>
    );
  }

  if (error) {
    return (
      <>
        <PageHeader eyebrow="Book" title="Pick a time" />
        <ErrorState message={error} onRetry={() => void load()} />
      </>
    );
  }
  if (!slots || balance === null || !settings) return <BookSkel />;

  if (balance < 1) {
    return (
      <>
        <PageHeader eyebrow="Book" title="Pick a time" />
        <EmptyState icon="plus" title="Buy sessions to book" action={<Link to="/app/packs" className="btn btn-red">Buy sessions</Link>}>
          You have no sessions left. Buy a pack, then pick your time.
        </EmptyState>
      </>
    );
  }

  const sel = selected ? slots.find((s) => s.start === selected) : null;
  return (
    <div className="rise">
      <PageHeader eyebrow="Book" title="Pick a time">
        {settings.sessionMinutes}-minute sessions · Cape Town time · You have {balance} session{balance === 1 ? '' : 's'} left
      </PageHeader>
      <div className="tse-stack">
        {slots.length === 0 ? (
          <EmptyState icon="calendar" title="No free times right now" action={<button type="button" className="btn btn-ghost" onClick={() => void load()}>Check again</button>}>
            Ivan's diary is full for the next four weeks.
          </EmptyState>
        ) : (
          <SlotPicker
            slots={slots}
            value={selected}
            onChange={(s) => { setSelected(s); setConfirm(true); }}
            settings={settings}
            mine={mine}
            weeks={4}
          />
        )}
      </div>

      <Modal
        open={confirm && !!sel}
        title="Confirm booking"
        onClose={() => setConfirm(false)}
        footer={
          <>
            <button type="button" className="btn btn-ghost" onClick={() => setConfirm(false)} disabled={busy}>Back</button>
            <button type="button" className="btn btn-red" onClick={book} disabled={busy} data-autofocus>{busy ? 'Booking' : 'Confirm booking'}</button>
          </>
        }
      >
        {sel && (
          <>
            <dl className="tse-confirm-rows">
              <div><dt>Date</dt><dd className="num">{formatDay(sel.start)}</dd></div>
              <div><dt>Time</dt><dd className="num">{formatTimeRange(sel.start, sel.end)}</dd></div>
              <div><dt>Length</dt><dd>{settings.sessionMinutes} minutes</dd></div>
              <div><dt>Cost</dt><dd>Uses 1 session, {balance - 1} left after</dd></div>
            </dl>
            <p className="small muted" style={{ margin: 0 }}>
              Cancel up to {settings.cancelFreeHours} hours before and your session is refunded. Inside {settings.cancelFreeHours} hours it is not.
            </p>
          </>
        )}
      </Modal>
    </div>
  );
}

export default function Book() {
  return (
    <ClientFrame>
      <BookInner />
    </ClientFrame>
  );
}
