import type { ReactNode } from 'react';
import './components.css';

interface Props { eyebrow?: string; title: string; actions?: ReactNode; children?: ReactNode }

export default function PageHeader({ eyebrow, title, actions, children }: Props) {
  return (
    <header className="tse-pagehead">
      <div>
        {eyebrow && <div className="eyebrow red">{eyebrow}</div>}
        <h1 className="display tse-pagehead-title">{title}</h1>
        {children && <p className="muted tse-pagehead-sub">{children}</p>}
      </div>
      {actions && <div className="row">{actions}</div>}
    </header>
  );
}
