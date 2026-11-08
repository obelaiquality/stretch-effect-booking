import { NavLink } from 'react-router-dom';
import './components.css';

const TABS = [
  { to: '/app', label: 'Overview', end: true },
  { to: '/app/book', label: 'Book' },
  { to: '/app/bookings', label: 'My bookings' },
  { to: '/app/packs', label: 'Sessions' },
];

export default function ClientNav() {
  return (
    <nav className="tse-subnav" aria-label="Your account">
      {TABS.map((t) => (
        <NavLink key={t.to} to={t.to} end={t.end} className={({ isActive }) => `tse-subnav-link${isActive ? ' is-active' : ''}`}>
          {t.label}
        </NavLink>
      ))}
    </nav>
  );
}
