import { useEffect, useState } from 'react';
import './components.css';

interface Props { value: number; label?: string }

const reduced = () => typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

/** Big count of sessions left with a thin segmented bar. Counts up once. */
export default function CreditsMeter({ value, label = 'sessions left' }: Props) {
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

  const segs = Math.max(5, Math.min(12, value));
  const filled = Math.min(value, segs);
  return (
    <div className="tse-meter" role="img" aria-label={`${value} ${label}`}>
      <div className="tse-meter-num display">{shown}</div>
      <div className="tse-meter-side">
        <div className="eyebrow">{label}</div>
        <div className="tse-meter-bar" aria-hidden="true">
          {Array.from({ length: segs }, (_, i) => (
            <span key={i} className={i < filled ? 'on' : ''} />
          ))}
        </div>
      </div>
    </div>
  );
}
