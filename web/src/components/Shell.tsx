import { useEffect, useState, type ReactNode } from 'react';
import { Link, NavLink, useLocation, useNavigate } from 'react-router-dom';
import Logo from './Logo';
import { ToastProvider } from './Toast';
import { useSession } from '../data/session';
import { api } from '../data/api';
import './shell.css';

const WHATSAPP = 'https://wa.me/27641173145';
const INSTAGRAM = 'https://www.instagram.com/the.stretcheffect.cpt/';

type NavItem = { to: string; label: string; end?: boolean };

const GUEST: NavItem[] = [
  { to: '/#how', label: 'How it works' },
  { to: '/#packs', label: 'Packages' },
  { to: '/go-live', label: 'Go live' },
];
const CLIENT: NavItem[] = [
  { to: '/app', label: 'My sessions', end: true },
  { to: '/app/book', label: 'Book' },
  { to: '/app/bookings', label: 'Bookings' },
  { to: '/app/packs', label: 'Load sessions' },
];
const OWNER: NavItem[] = [
  { to: '/admin', label: 'Today', end: true },
  { to: '/admin/clients', label: 'Clients' },
  { to: '/admin/calendar', label: 'Calendar' },
  { to: '/admin/chatgpt', label: 'ChatGPT' },
];

function useSectionLinks() {
  const navigate = useNavigate();
  const loc = useLocation();
  return (to: string) => {
    const [path, id] = to.split('#');
    if (!id) return false;
    const go = () => document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    if (loc.pathname === (path || '/')) go();
    else {
      navigate(path || '/');
      setTimeout(go, 80);
    }
    return true;
  };
}

export default function Shell({ children }: { children: ReactNode }) {
  const { user, logout } = useSession();
  const [open, setOpen] = useState(false);
  const loc = useLocation();
  const navigate = useNavigate();
  const section = useSectionLinks();

  useEffect(() => {
    setOpen(false);
    if (!loc.hash) window.scrollTo(0, 0);
  }, [loc.pathname, loc.hash]);

  const items = user?.role === 'owner' ? OWNER : user ? CLIENT : GUEST;

  const onSignOut = async () => {
    await logout();
    navigate('/');
  };

  const renderItem = (it: NavItem) =>
    it.to.includes('#') ? (
      <a
        key={it.to}
        href={`#${it.to}`}
        className="nav-link"
        onClick={(e) => {
          e.preventDefault();
          setOpen(false);
          section(it.to);
        }}
      >
        {it.label}
      </a>
    ) : (
      <NavLink key={it.to} to={it.to} end={it.end} className={({ isActive }) => `nav-link${isActive ? ' active' : ''}`}>
        {it.label}
      </NavLink>
    );

  return (
    <div className="shell">
      <div className="demo-bar" role="note">
        <span>
          <strong>Demo</strong> · bookings are not real.
          <span className="demo-by"> Built by Obel AI for The Stretch Effect.</span>
        </span>
        {api.mode === 'demo' && api.demoReset && (
          <button
            className="demo-reset"
            onClick={async () => {
              await api.demoReset!();
              window.location.reload();
            }}
          >
            Reset demo data
          </button>
        )}
      </div>

      <header className="site-header">
        <div className="wrap header-inner">
          <Link to={user?.role === 'owner' ? '/admin' : user ? '/app' : '/'} className="header-logo" aria-label="The Stretch Effect home">
            <Logo />
          </Link>
          {user?.role === 'owner' && <span className="badge badge-red owner-badge">Owner</span>}

          <nav className={`site-nav${open ? ' open' : ''}`} aria-label="Main">
            {items.map(renderItem)}
            <div className="nav-actions">
              {user ? (
                <>
                  <span className="nav-user small muted">{user.name.split(' ')[0]}</span>
                  <button className="btn btn-ghost btn-sm" onClick={onSignOut}>
                    Sign out
                  </button>
                </>
              ) : (
                <>
                  <Link to="/login" className="btn btn-quiet btn-sm">
                    Sign in
                  </Link>
                  <Link to="/signup" className="btn btn-red btn-sm">
                    Book a session
                  </Link>
                </>
              )}
            </div>
          </nav>

          <button
            className="menu-btn"
            aria-expanded={open}
            aria-controls="main-nav"
            aria-label={open ? 'Close menu' : 'Open menu'}
            onClick={() => setOpen((o) => !o)}
          >
            <span />
            <span />
            <span />
          </button>
        </div>
      </header>

      <main className="site-main">
        <ToastProvider>{children}</ToastProvider>
      </main>

      <footer className="site-footer">
        <div className="wrap footer-inner">
          <div className="footer-brand">
            <Logo />
            <p className="footer-tag">
              Get stretched. Move better. <span className="red">Live better.</span>
            </p>
          </div>
          <div className="footer-cols">
            <div>
              <div className="eyebrow">Find us</div>
              <p className="small silver">
                Brackenfell, Cape Town
                <br />
                Home visits on request
              </p>
            </div>
            <div>
              <div className="eyebrow">Talk to Ivan</div>
              <p className="small">
                <a href={WHATSAPP} target="_blank" rel="noreferrer">
                  WhatsApp
                </a>
                <br />
                <a href={INSTAGRAM} target="_blank" rel="noreferrer">
                  Instagram
                </a>
              </p>
            </div>
            <div>
              <div className="eyebrow">This demo</div>
              <p className="small">
                <Link to="/go-live">What it takes to go live</Link>
                <br />
                <span className="muted">Built by Obel AI</span>
              </p>
            </div>
          </div>
        </div>
      </footer>
    </div>
  );
}
