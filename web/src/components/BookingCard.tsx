import type { ReactNode } from 'react';
import type { Booking } from '../domain/types';
import { formatLocalDate, formatLocalTime } from '../domain/slots';
import Icon from './Icon';
import './components.css';

const STATUS: Record<Booking['status'], { label: string; cls: string }> = {
  confirmed: { label: 'Confirmed', cls: 'badge-ok' },
  completed: { label: 'Completed', cls: '' },
  cancelled: { label: 'Cancelled', cls: '' },
  no_show: { label: 'No show', cls: 'badge-warn' },
};

interface Props {
  booking: Booking;
  actions?: ReactNode;
  showClient?: boolean;
  highlight?: boolean;
}

export default function BookingCard({ booking, actions, showClient, highlight }: Props) {
  const st = STATUS[booking.status];
  const dim = booking.status === 'cancelled';
  return (
    <article className={`card card-tight tse-bcard${highlight ? ' card-red-top' : ''}${dim ? ' is-dim' : ''}`}>
      <div className="tse-bcard-date">
        <div className="display tse-bcard-day">{formatLocalDate(booking.start)}</div>
        <div className="tse-bcard-time num">
          <Icon name="clock" size={16} /> {formatLocalTime(booking.start)} to {formatLocalTime(booking.end)}
        </div>
        {showClient && <div className="small silver">{booking.clientName}</div>}
      </div>
      <div className="tse-bcard-side">
        <span className={`badge ${st.cls}`}>{st.label}</span>
        {booking.status === 'cancelled' && (
          <span className="tiny muted">{booking.refunded ? 'Session refunded' : 'No refund'}</span>
        )}
        {actions}
      </div>
    </article>
  );
}
