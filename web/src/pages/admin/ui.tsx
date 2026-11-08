// Small local UI pieces for the admin pages (kept here so the pages do not wait on shared components).
import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';
import { NavLink } from 'react-router-dom';

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

export function AdminHead({ eyebrow, title, children }: { eyebrow: string; title: ReactNode; children?: ReactNode }) {
  return (
    <header className="admin-head row">
      <div>
        <div className="eyebrow red">{eyebrow}</div>
        <h1 className="display admin-title">{title}</h1>
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
  useEffect(() => {
    const prev = document.activeElement as HTMLElement | null;
    ref.current?.querySelector<HTMLElement>('input,textarea,select,button')?.focus();
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('keydown', onKey);
      prev?.focus();
    };
  }, [onClose]);
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

export function Empty({ children }: { children: ReactNode }) {
  return <p className="a-empty muted">{children}</p>;
}
