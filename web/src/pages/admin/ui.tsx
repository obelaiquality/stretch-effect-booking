// Small local UI pieces for the admin pages (kept here so the pages do not wait on shared components).
import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';
import { Link, NavLink } from 'react-router-dom';
import { Skel } from '../../components/Skeleton';

export function AdminNav() {
  const items = [
    ['/admin', 'Today', true],
    ['/admin/clients', 'Clients', false],
    ['/admin/calendar', 'Calendar', false],
    ['/admin/chatgpt', 'ChatGPT', false],
  ] as const;
  return (
    <nav className="admin-nav" aria-label="Owner sections">
      <div className="wrap admin-nav-in">
        {items.map(([to, label, end]) => (
          <NavLink key={to} to={to} end={end} className={({ isActive }) => 'admin-nav-link' + (isActive ? ' on' : '')}>
            {label}
          </NavLink>
        ))}
      </div>
    </nav>
  );
}

export function AdminHead({ eyebrow, title, children }: { eyebrow?: string; title: ReactNode; children?: ReactNode }) {
  return (
    <header className="admin-head row">
      <div>
        {eyebrow && <div className="eyebrow">{eyebrow}</div>}
        <h1 className="page-title admin-title">{title}</h1>
      </div>
      <div className="spacer" />
      {children}
    </header>
  );
}

export function AdminFrame({ children }: { children: ReactNode }) {
  return (
    <div className="admin">
      <div className="wrap admin-body">{children}</div>
    </div>
  );
}

export function Modal({
  title,
  onClose,
  children,
  actions,
}: {
  title: string;
  onClose: () => void;
  children: ReactNode;
  actions: ReactNode;
}) {
  const ref = useRef<HTMLDivElement>(null);
  const closeRef = useRef(onClose);
  closeRef.current = onClose;
  useEffect(() => {
    const prev = document.activeElement as HTMLElement | null;
    const sel = 'input,textarea,select,button:not([disabled]),a[href]';
    ref.current?.querySelector<HTMLElement>(sel)?.focus();
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') return closeRef.current();
      if (e.key !== 'Tab' || !ref.current) return;
      const els = [...ref.current.querySelectorAll<HTMLElement>(sel)];
      if (els.length === 0) return;
      const first = els[0];
      const last = els[els.length - 1];
      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
      }
    };
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('keydown', onKey);
      prev?.focus();
    };
  }, []);
  return (
    <div className="a-modal-back" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
      <div className="a-modal card" role="dialog" aria-modal="true" aria-label={title} ref={ref}>
        <h2 className="h3">{title}</h2>
        <div className="a-modal-body stack">{children}</div>
        <div className="row a-modal-actions">{actions}</div>
      </div>
    </div>
  );
}

export function useToast() {
  const [msg, setMsg] = useState<{ text: string; bad?: boolean } | null>(null);
  const t = useRef<number>();
  const show = useCallback((text: string, bad = false) => {
    setMsg({ text, bad });
    window.clearTimeout(t.current);
    t.current = window.setTimeout(() => setMsg(null), 4000);
  }, []);
  const node = msg ? (
    <div className={'a-toast' + (msg.bad ? ' bad' : '')} role="status" aria-live="polite">
      {msg.text}
    </div>
  ) : null;
  return { show, node };
}

export function errText(e: unknown): string {
  return e instanceof Error ? e.message : 'Something went wrong.';
}

/** Load async data on mount and after reload(). */
export function useLoad<T>(fn: () => Promise<T>, deps: unknown[] = []) {
  const [data, setData] = useState<T | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [tick, setTick] = useState(0);
  useEffect(() => {
    let live = true;
    fn().then(
      (d) => live && (setData(d), setError(null)),
      (e) => live && setError(errText(e)),
    );
    return () => {
      live = false;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [...deps, tick]);
  return { data, error, reload: () => setTick((n) => n + 1) };
}

/** Empty state: one sentence and, when there is a next step, one action (a link or a button). */
export function Empty({
  children,
  to,
  actionLabel,
  onAction,
}: {
  children: ReactNode;
  to?: string;
  actionLabel?: string;
  onAction?: () => void;
}) {
  return (
    <div className="a-empty">
      <p className="muted measure">{children}</p>
      {actionLabel && to && <Link className="btn btn-ghost" to={to}>{actionLabel}</Link>}
      {actionLabel && !to && onAction && <button className="btn btn-ghost" onClick={onAction}>{actionLabel}</button>}
    </div>
  );
}

/** Error state with a retry button. */
export function ErrorState({ message, onRetry }: { message: string; onRetry?: () => void }) {
  return (
    <div className="a-empty" role="alert">
      <p className="error-text measure">{message}</p>
      {onRetry && <button className="btn btn-ghost" onClick={onRetry}>Try again</button>}
    </div>
  );
}

/** Skeleton lines for the inside of a card. */
export function CardSkel({ lines = 3 }: { lines?: number }) {
  return (
    <div className="stack" aria-busy="true" aria-label="Loading">
      {Array.from({ length: lines }, (_, i) => (
        <Skel key={i} w={i === 0 ? '60%' : i % 2 ? '90%' : '75%'} h={i === 0 ? 24 : 16} />
      ))}
    </div>
  );
}

/** Skeleton shaped like an owner page: heading bar, then a card grid. */
export function AdminSkeleton({ cards = 3 }: { cards?: number }) {
  return (
    <div aria-busy="true" aria-label="Loading">
      <div className="admin-grid">
        {Array.from({ length: cards }, (_, i) => (
          <div key={i} className={'card' + (i === 0 ? ' span-2' : '')}>
            <Skel w={140} h={16} style={{ marginBottom: 16 }} />
            <CardSkel lines={4} />
          </div>
        ))}
      </div>
    </div>
  );
}

/** Balance as a status-style pill: "0 left" is an error-like state, "1 left" a warning. */
export function Balance({ n, long }: { n: number; long?: boolean }) {
  const cls = n === 0 ? 'bal bal-0' : n === 1 ? 'bal bal-1' : 'bal';
  return <span className={cls}>{long ? `${n} ${n === 1 ? 'session' : 'sessions'} left` : `${n} left`}</span>;
}
