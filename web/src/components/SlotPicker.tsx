import { useEffect, useMemo, useState } from 'react';
import type { Booking, Settings, Slot } from '../domain/types';
import { addDays, localDayKey, localDayKeyMs, localToUtc, startOfWeek, weekdayOf } from '../domain/slots';
import { formatDay, formatTime } from '../lib/format';
import Icon from './Icon';
import './components.css';

interface Props {
  slots: Slot[];
  value: string | null; // selected slot start (UTC ISO)
  onChange: (start: string) => void; // fires on tap; the page opens the confirmation at once
  settings: Settings;
  mine?: Booking[]; // the client's own upcoming confirmed bookings, shown as "Yours"
  weeks?: number; // how many weeks can be browsed
}

const DOW = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const PERIODS = [
  { key: 'morning', label: 'Morning', test: (h: number) => h < 12 },
  { key: 'afternoon', label: 'Afternoon', test: (h: number) => h >= 12 && h < 17 },
  { key: 'evening', label: 'Evening', test: (h: number) => h >= 17 },
] as const;

function useWide() {
  const q = '(min-width: 860px)';
  const [wide, setWide] = useState(() => typeof window !== 'undefined' && !!window.matchMedia?.(q).matches);
  useEffect(() => {
    const m = window.matchMedia?.(q);
    if (!m) return;
    const on = () => setWide(m.matches);
    m.addEventListener('change', on);
    return () => m.removeEventListener('change', on);
  }, []);
  return wide;
}

type Item = { kind: 'free'; start: string } | { kind: 'mine'; start: string };

export default function SlotPicker({ slots, value, onChange, settings, mine = [], weeks = 4 }: Props) {
  const today = localDayKeyMs(Date.now());
  const firstWeek = startOfWeek(today);
  const [weekStart, setWeekStart] = useState(firstWeek);
  const wide = useWide();

  const byDay = useMemo(() => {
    const m = new Map<string, Item[]>();
    const add = (k: string, it: Item) => (m.get(k) ?? m.set(k, []).get(k)!).push(it);
    for (const s of slots) add(localDayKey(s.start), { kind: 'free', start: s.start });
    for (const b of mine) if (b.status === 'confirmed') add(localDayKey(b.start), { kind: 'mine', start: b.start });
    for (const list of m.values()) list.sort((a, b) => a.start.localeCompare(b.start));
    return m;
  }, [slots, mine]);

  const freeCount = (d: string) => (byDay.get(d) ?? []).filter((i) => i.kind === 'free').length;
  const lastBookable = addDays(today, settings.bookingWindowDays - 1);

  const days = Array.from({ length: 7 }, (_, i) => addDays(weekStart, i));
  const [activeDay, setActiveDay] = useState<string>(today);

  // Keep the phone day inside the visible week, preferring a day that has times.
  useEffect(() => {
    if (!days.includes(activeDay)) {
      setActiveDay(days.find((d) => d >= today && freeCount(d) > 0) ?? days.find((d) => d >= today) ?? days[0]);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [weekStart]);

  // Jump to the first week with availability the first time slots arrive.
  const [jumped, setJumped] = useState(false);
  useEffect(() => {
    if (jumped || slots.length === 0) return;
    setJumped(true);
    const k = localDayKey(slots[0].start);
    setWeekStart(startOfWeek(k));
    setActiveDay(k);
  }, [slots, jumped]);

  const canPrev = weekStart > firstWeek;
  const canNext = weekStart < addDays(firstWeek, (weeks - 1) * 7);
  const rangeLabel = `${formatDay(days[0])} to ${formatDay(days[6])}`;

  const nextFree = (d: string) => {
    for (let i = 1; i <= 60; i++) {
      const k = addDays(d, i);
      if (freeCount(k) > 0) return k;
    }
    return null;
  };

  const goTo = (d: string) => {
    setWeekStart(startOfWeek(d));
    setActiveDay(d);
  };

  /** Why a day has no free times: a distinct text for each case. */
  const emptyReason = (d: string): string => {
    if (d < today) return 'Past';
    if (d > lastBookable) return 'Not open yet';
    const wh = settings.workingHours.find((w) => w.weekday === weekdayOf(d));
    if (!wh || !wh.open) return 'Closed';
    const dayEnd = new Date(localToUtc(`${d}T${wh.end}`)).getTime();
    if (dayEnd <= Date.now() + settings.minNoticeHours * 3_600_000) return 'Too soon to book';
    return 'Fully booked';
  };

  const chip = (it: Item) => {
    if (it.kind === 'mine') {
      return (
        <div key={`m-${it.start}`} className="tse-slot is-mine num" role="img" aria-label={`Your session, ${formatDay(it.start)} at ${formatTime(it.start)}`}>
          <span className="tse-slot-sub">Yours</span>
          {formatTime(it.start)}
        </div>
      );
    }
    const sel = it.start === value;
    return (
      <button
        key={it.start}
        type="button"
        className={`tse-slot num${sel ? ' is-selected' : ''}`}
        aria-pressed={sel}
        aria-label={`${formatDay(it.start)} at ${formatTime(it.start)}`}
        onClick={() => onChange(it.start)}
      >
        {sel && <Icon name="check" size={16} />}
        {formatTime(it.start)}
      </button>
    );
  };

  const dayBody = (d: string, compact: boolean) => {
    const items = byDay.get(d) ?? [];
    const free = freeCount(d);
    if (free === 0) {
      const reason = emptyReason(d);
      const nf = d < today || d > lastBookable ? null : nextFree(d);
      return (
        <div className="tse-dayempty">
          {items.length > 0 && <div className="tse-groups">{items.map(chip)}</div>}
          <span className={compact ? 'tiny muted' : 'small muted'}>{reason}</span>
          {nf && reason !== 'Past' && (
            <button type="button" className="tse-linkbtn" onClick={() => goTo(nf)}>
              Next free: {formatDay(nf)}
            </button>
          )}
        </div>
      );
    }
    return (
      <div className="tse-groups">
        {PERIODS.map((p) => {
          const g = items.filter((i) => p.test(Number(formatTime(i.start).slice(0, 2))));
          if (g.length === 0) return null;
          return (
            <div key={p.key} className="tse-group">
              <div className="label tse-group-label">{p.label}</div>
              <div className="tse-group-slots">{g.map(chip)}</div>
            </div>
          );
        })}
      </div>
    );
  };

  return (
    <div className="tse-picker">
      <div className="tse-picker-head">
        <button type="button" className="tse-iconbtn" disabled={!canPrev} onClick={() => setWeekStart(addDays(weekStart, -7))} aria-label="Previous week">
          <Icon name="chevron-left" />
        </button>
        <div className="label tse-picker-range" aria-live="polite">{rangeLabel}</div>
        <button type="button" className="tse-iconbtn" disabled={!canNext} onClick={() => setWeekStart(addDays(weekStart, 7))} aria-label="Next week">
          <Icon name="chevron-right" />
        </button>
      </div>

      {wide ? (
        <div className="tse-week" role="group" aria-label="Available times this week">
          {days.map((d, i) => {
            const past = d < today;
            const [, , dd] = d.split('-');
            return (
              <div key={d} className={`tse-col${past ? ' is-past' : ''}`}>
                <div className="tse-col-head">
                  <span className="eyebrow">{DOW[i]}</span>
                  <span className="tse-col-date num">{Number(dd)}</span>
                </div>
                <div className="tse-col-slots">{dayBody(d, true)}</div>
              </div>
            );
          })}
        </div>
      ) : (
        <>
          <div className="tse-strip" role="group" aria-label="Choose a day">
            {days.map((d, i) => {
              const past = d < today;
              const n = freeCount(d);
              return (
                <button
                  key={d}
                  type="button"
                  disabled={past}
                  aria-pressed={d === activeDay}
                  aria-label={formatDay(d)}
                  className={`tse-daybtn${d === activeDay ? ' is-active' : ''}${n === 0 ? ' is-empty' : ''}`}
                  onClick={() => setActiveDay(d)}
                >
                  <span className="eyebrow">{DOW[i]}</span>
                  <span className="num tse-daybtn-n">{Number(d.split('-')[2])}</span>
                </button>
              );
            })}
          </div>
          <div className="tse-list-day">{formatDay(activeDay)}</div>
          {dayBody(activeDay, false)}
        </>
      )}
    </div>
  );
}
