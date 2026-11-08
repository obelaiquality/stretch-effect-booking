import { useEffect, useMemo, useState } from 'react';
import type { Slot } from '../domain/types';
import { addDays, formatLocalDate, formatLocalTime, localDayKey, localDayKeyMs, startOfWeek } from '../domain/slots';
import Icon from './Icon';
import './components.css';

interface Props {
  slots: Slot[];
  value: string | null; // selected slot start (UTC ISO)
  onChange: (start: string) => void;
  weeks?: number; // how many weeks can be browsed
}

const DOW = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

function useWide() {
  const q = '(min-width: 760px)';
  const [wide, setWide] = useState(() => typeof window !== 'undefined' && window.matchMedia?.(q).matches);
  useEffect(() => {
    const m = window.matchMedia?.(q);
    if (!m) return;
    const on = () => setWide(m.matches);
    m.addEventListener('change', on);
    return () => m.removeEventListener('change', on);
  }, []);
  return wide;
}

export default function SlotPicker({ slots, value, onChange, weeks = 4 }: Props) {
  const today = localDayKeyMs(Date.now());
  const firstWeek = startOfWeek(today);
  const [weekStart, setWeekStart] = useState(firstWeek);
  const wide = useWide();

  const byDay = useMemo(() => {
    const m = new Map<string, Slot[]>();
    for (const s of slots) {
      const k = localDayKey(s.start);
      (m.get(k) ?? m.set(k, []).get(k)!).push(s);
    }
    return m;
  }, [slots]);

  const days = Array.from({ length: 7 }, (_, i) => addDays(weekStart, i));
  const [activeDay, setActiveDay] = useState<string>(today);

  // Keep the mobile day inside the visible week, preferring a day that has times.
  useEffect(() => {
    if (!days.includes(activeDay)) {
      setActiveDay(days.find((d) => d >= today && (byDay.get(d)?.length ?? 0) > 0) ?? days.find((d) => d >= today) ?? days[0]);
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
  const rangeLabel = `${formatLocalDate(days[0])} to ${formatLocalDate(days[6])}`;

  const slotBtn = (s: Slot) => {
    const sel = s.start === value;
    return (
      <button
        key={s.start}
        type="button"
        className={`tse-slot num${sel ? ' is-selected' : ''}`}
        aria-pressed={sel}
        aria-label={`${formatLocalDate(s.start)} at ${formatLocalTime(s.start)}`}
        onClick={() => onChange(s.start)}
      >
        {formatLocalTime(s.start)}
      </button>
    );
  };

  return (
    <div className="tse-picker">
      <div className="tse-picker-head">
        <button type="button" className="tse-iconbtn" disabled={!canPrev} onClick={() => setWeekStart(addDays(weekStart, -7))} aria-label="Previous week">
          <Icon name="chevron-left" />
        </button>
        <div className="tse-picker-range" aria-live="polite">{rangeLabel}</div>
        <button type="button" className="tse-iconbtn" disabled={!canNext} onClick={() => setWeekStart(addDays(weekStart, 7))} aria-label="Next week">
          <Icon name="chevron-right" />
        </button>
      </div>

      {wide ? (
        <div className="tse-week" role="group" aria-label="Available times this week">
          {days.map((d, i) => {
            const list = byDay.get(d) ?? [];
            const past = d < today;
            const [, , dd] = d.split('-');
            return (
              <div key={d} className={`tse-col${past ? ' is-past' : ''}`}>
                <div className="tse-col-head">
                  <span className="eyebrow">{DOW[i]}</span>
                  <span className="tse-col-date num">{Number(dd)}</span>
                </div>
                <div className="tse-col-slots">
                  {past ? <span className="tiny muted">Past</span> : list.length === 0 ? <span className="tiny muted">No times</span> : list.map(slotBtn)}
                </div>
              </div>
            );
          })}
        </div>
      ) : (
        <>
          <div className="tse-strip" role="group" aria-label="Choose a day">
            {days.map((d, i) => {
              const past = d < today;
              const n = byDay.get(d)?.length ?? 0;
              return (
                <button
                  key={d}
                  type="button"
                  disabled={past}
                  aria-pressed={d === activeDay}
                  className={`tse-daybtn${d === activeDay ? ' is-active' : ''}${n === 0 ? ' is-empty' : ''}`}
                  onClick={() => setActiveDay(d)}
                >
                  <span className="eyebrow">{DOW[i]}</span>
                  <span className="num tse-daybtn-n">{Number(d.split('-')[2])}</span>
                </button>
              );
            })}
          </div>
          <div className="tse-list-day">{formatLocalDate(activeDay)}</div>
          <div className="tse-list">
            {(byDay.get(activeDay) ?? []).length === 0 ? (
              <p className="muted small">No free times this day. Try another day.</p>
            ) : (
              (byDay.get(activeDay) ?? []).map(slotBtn)
            )}
          </div>
        </>
      )}
    </div>
  );
}
