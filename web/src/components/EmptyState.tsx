import type { ReactNode } from 'react';
import Icon, { type IconName } from './Icon';
import './components.css';

interface Props { icon?: IconName; title: string; children?: ReactNode; action?: ReactNode }

export default function EmptyState({ icon = 'calendar', title, children, action }: Props) {
  return (
    <div className="tse-empty">
      <Icon name={icon} size={28} />
      <h3 className="tse-empty-title">{title}</h3>
      {children && <p className="muted tse-empty-text">{children}</p>}
      {action}
    </div>
  );
}
