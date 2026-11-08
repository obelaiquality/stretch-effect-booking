import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../../data/api';
import { addDays, formatLocalDate, formatLocalDateTime, formatLocalTime, localDayKeyMs } from '../../domain/slots';
import { ApiError, type Booking, type Settings, type Slot } from '../../domain/types';
import ClientFrame from '../../components/ClientFrame';
import EmptyState from '../../components/EmptyState';
import Icon from '../../components/Icon';
import Modal from '../../components/Modal';
import PageHeader from '../../components/PageHeader';
import SlotPicker from '../../components/SlotPicker';
import { useToast } from '../../components/Toast';
import './client.css';

function BookInner() {
  const toast = useToast();
  const [slots, setSlots] = useState<Slot[] | null>(null);
  const [balance, setBalance] = useState<number | null>(null);
  const [settings, setSettings] = useState<Settings | null>(null);
  const [selected, setSelected] = useState<string | null>(null);
  const [confirm, setConfirm] = useState(false);
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState<Booking | null>(null);
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    try {
      const today = localDayKeyMs(Date.now());
      const [sl, bal, st] = await Promise.all([api.availability(today, addDays(today, 27)), api.myBalance(), api.settings()]);
      setSlots(sl);
      setBalance(bal);
      setSettings(st);
    } catch {
      setError('Could not load times. Refresh to try again.');
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
        toast.push('You have no sessions left. Load a pack first.', 'error');
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
      <div className="card card-red-top tse-success rise">
        <div className="tse-success-mark"><Icon name="check" size={26} /></div>
        <h2 className="display" style={{ fontSize: 44 }}>You are booked</h2>
        <p className="h3 num">{formatLocalDateTime(done.start)}</p>
        <p className="muted" style={{ margin: 0 }}>Added to Ivan's calendar. See you at the studio in Brackenfell.</p>
        <div className="row">
          <Link to="/app/bookings" className="btn btn-red">My bookings</Link>
          <button type="button" className="btn btn-ghost" onClick={() => { setDone(null); void load(); }}>Book another</button>
        </div>
      </div>
    );
  }

  if (error) return <p className="error-text" role="alert">{error}</p>;
  if (!slots || balance === null || !settings) return <div aria-busy="true" style={{ minHeight: 320 }} />;

  if (balance < 1) {
    return (
      <EmptyState icon="plus" title="Load sessions to book" action={<Link to="/app/packs" className="btn btn-red">Load sessions</Link>}>
        You have no sessions left. Load a pack, then pick your time.
      </EmptyState>
    );
  }

  const sel = selected ? slots.find((s) => s.start === selected) : null;
  return (
    <div className="tse-stack rise">
      <p className="muted" style={{ margin: 0 }}>
        {settings.sessionMinutes} minute sessions. Times are Cape Town time. You have <strong className="num" style={{ color: 'var(--white)' }}>{balance}</strong> session{balance === 1 ? '' : 's'} left.
      </p>
      {slots.length === 0 ? (
        <EmptyState icon="calendar" title="No free times right now">Ivan's diary is full for the next four weeks. Message him on WhatsApp.</EmptyState>
      ) : (
        <SlotPicker slots={slots} value={selected} onChange={setSelected} weeks={4} />
      )}
      <div className="row" style={{ position: 'sticky', bottom: 0, background: 'var(--ink)', padding: 'var(--s3) 0', borderTop: '1px solid var(--line)' }}>
        <span className="num silver" style={{ flex: 1, minWidth: 140 }}>
          {sel ? `${formatLocalDate(sel.start)} · ${formatLocalTime(sel.start)}` : 'Pick a time'}
        </span>
        <button type="button" className="btn btn-red" disabled={!sel} onClick={() => setConfirm(true)}>Continue</button>
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
              <div><dt>Date</dt><dd className="num">{formatLocalDate(sel.start)}</dd></div>
              <div><dt>Time</dt><dd className="num">{formatLocalTime(sel.start)} to {formatLocalTime(sel.end)}</dd></div>
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
      <PageHeader eyebrow="Book" title="Pick a time">Move better. Recover faster. Feel stronger.</PageHeader>
      <BookInner />
    </ClientFrame>
  );
}
