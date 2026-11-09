import { useEffect, useState } from 'react';
import './components.css';

interface Props { value: number; label?: string; pending?: number }

const reduced = () => typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

/** Big count of sessions left, with a label under it. Counts up once. */
export default function CreditsMeter({ value, label = 'Sessions left', pending = 0 }: Props) {
  const [shown, setShown] = useState(reduced() ? value : 0);
  const [played, setPlayed] = useState(false);

  useEffect(() => {
    if (played || reduced()) {
      setShown(value);
      return;
    }
    setPlayed(true);
    const t0 = performance.now();
    const dur = 700;
    let raf = 0;
    const step = (t: number) => {
      const p = Math.min(1, (t - t0) / dur);
      setShown(Math.round(value * (1 - Math.pow(1 - p, 3))));
      if (p < 1) raf = requestAnimationFrame(step);
    };
    raf = requestAnimationFrame(step);
    return () => cancelAnimationFrame(raf);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value]);

  return (
    <div className="tse-meter" role="img" aria-label={`${value} ${label.toLowerCase()}${pending ? `, ${pending} pack waiting for payment` : ''}`}>
      <div className="figure tse-meter-num">{shown}</div>
      <div className="label">{label}</div>
      {pending > 0 && <div className="small muted">{pending} pack{pending === 1 ? '' : 's'} waiting for payment</div>}
    </div>
  );
}
