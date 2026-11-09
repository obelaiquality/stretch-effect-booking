// Client-side calendar file for a booking (RFC 5545). Times are UTC.
import type { Booking } from '../domain/types';

const stamp = (iso: string) => new Date(iso).toISOString().replace(/[-:]/g, '').replace(/\.\d{3}/, '');

export function bookingIcs(b: Pick<Booking, 'id' | 'start' | 'end'>): string {
  return [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//The Stretch Effect//Booking//EN',
    'CALSCALE:GREGORIAN',
    'METHOD:PUBLISH',
    'BEGIN:VEVENT',
    `UID:${b.id}@thestretcheffect`,
    `DTSTAMP:${stamp(new Date().toISOString())}`,
    `DTSTART:${stamp(b.start)}`,
    `DTEND:${stamp(b.end)}`,
    'SUMMARY:Assisted stretch — The Stretch Effect',
    'LOCATION:Brackenfell\\, Cape Town',
    'END:VEVENT',
    'END:VCALENDAR',
    '',
  ].join('\r\n');
}

/** Trigger a download of the booking as an .ics file. */
export function downloadBookingIcs(b: Pick<Booking, 'id' | 'start' | 'end'>): void {
  const url = URL.createObjectURL(new Blob([bookingIcs(b)], { type: 'text/calendar;charset=utf-8' }));
  const a = document.createElement('a');
  a.href = url;
  a.download = 'stretch-session.ics';
  document.body.appendChild(a);
  a.click();
  a.remove();
  window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}
