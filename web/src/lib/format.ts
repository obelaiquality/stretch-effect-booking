// One date and money format for the whole site (docs/DESIGN.md section 7).
// "Tue 3 Nov · 17:30", times in Africa/Johannesburg, year only when it is not this year.
const TZ = 'Africa/Johannesburg';

const parts = (iso: string | number | Date) => {
  const d = new Date(iso);
  const p = new Intl.DateTimeFormat('en-GB', {
    timeZone: TZ,
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  }).formatToParts(d);
  const get = (t: string) => p.find((x) => x.type === t)?.value ?? '';
  return {
    weekday: get('weekday'),
    day: get('day'),
    month: get('month'),
    year: get('year'),
    time: `${get('hour')}:${get('minute')}`,
  };
};

const thisYear = () => parts(Date.now()).year;

/** "Tue 3 Nov" (adds the year when it is not this year). */
export function formatDay(iso: string | number | Date): string {
  const p = parts(iso);
  return `${p.weekday} ${p.day} ${p.month}${p.year !== thisYear() ? ` ${p.year}` : ''}`;
}

/** "17:30" (24-hour, Cape Town time). */
export function formatTime(iso: string | number | Date): string {
  return parts(iso).time;
}

/** "Tue 3 Nov · 17:30". */
export function formatDayTime(iso: string | number | Date): string {
  return `${formatDay(iso)} · ${formatTime(iso)}`;
}

/** "17:30 to 18:30". */
export function formatTimeRange(startIso: string, endIso: string): string {
  return `${formatTime(startIso)} to ${formatTime(endIso)}`;
}

/** "R2,500". */
export function formatRand(amount: number): string {
  return `R${Math.round(amount).toLocaleString('en-US')}`;
}
