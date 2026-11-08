import { createContext, useCallback, useContext, useMemo, useRef, useState, type ReactNode } from 'react';
import Icon from './Icon';
import './components.css';

type Kind = 'ok' | 'error' | 'info';
interface ToastItem { id: number; kind: Kind; message: string }
interface Value { push: (message: string, kind?: Kind) => void }

const Ctx = createContext<Value>({ push: () => undefined });

export function ToastProvider({ children }: { children: ReactNode }) {
  const [items, setItems] = useState<ToastItem[]>([]);
  const n = useRef(0);
  const push = useCallback((message: string, kind: Kind = 'info') => {
    const id = ++n.current;
    setItems((p) => [...p, { id, kind, message }]);
    window.setTimeout(() => setItems((p) => p.filter((t) => t.id !== id)), 4500);
  }, []);
  const value = useMemo(() => ({ push }), [push]);
  return (
    <Ctx.Provider value={value}>
      {children}
      <div className="tse-toasts" role="status" aria-live="polite">
        {items.map((t) => (
          <div key={t.id} className={`tse-toast tse-toast-${t.kind} rise`}>
            <Icon name={t.kind === 'error' ? 'alert' : 'check'} size={18} />
            <span>{t.message}</span>
          </div>
        ))}
      </div>
    </Ctx.Provider>
  );
}

export function useToast(): Value {
  return useContext(Ctx);
}
