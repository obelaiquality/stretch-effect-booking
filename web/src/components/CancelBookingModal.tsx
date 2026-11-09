import { useState } from 'react';
import { api } from '../data/api';
import { qualifiesForRefund } from '../domain/credits';
import { formatDayTime } from '../lib/format';
import { ApiError, type Booking } from '../domain/types';
import Modal from './Modal';
import { useToast } from './Toast';

interface Props {
  booking: Booking | null;
  cancelFreeHours: number;
  onClose: () => void;
  onDone: () => void;
}

/** Confirm a client cancellation and explain the refund rule. */
export default function CancelBookingModal({ booking, cancelFreeHours, onClose, onDone }: Props) {
  const [busy, setBusy] = useState(false);
  const toast = useToast();
  if (!booking) return null;
  const refund = qualifiesForRefund(booking.start, Date.now(), cancelFreeHours);

  const go = async () => {
    setBusy(true);
    try {
      const r = await api.cancelBooking(booking.id);
      toast.push(r.refunded ? 'Booking cancelled. Your session is back on your balance.' : 'Booking cancelled. No refund, it was inside 24 hours.', 'ok');
      onDone();
    } catch (e) {
      toast.push(e instanceof ApiError ? e.message : 'Could not cancel. Try again.', 'error');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal
      open
      title="Cancel this session?"
      onClose={onClose}
      footer={
        <>
          <button className="btn btn-ghost" type="button" onClick={onClose} disabled={busy} data-autofocus>Keep booking</button>
          <button className="btn btn-danger" type="button" onClick={go} disabled={busy}>{busy ? 'Cancelling' : 'Cancel session'}</button>
        </>
      }
    >
      <p className="h3 num">{formatDayTime(booking.start)}</p>
      {refund ? (
        <p className="silver">This is more than {cancelFreeHours} hours away, so your session goes back on your balance.</p>
      ) : (
        <p className="silver">This is inside {cancelFreeHours} hours, so the session is not refunded. Message Ivan on WhatsApp if something has come up.</p>
      )}
      <p className="small muted">Cancellation policy: free up to {cancelFreeHours} hours before your session starts.</p>
    </Modal>
  );
}
