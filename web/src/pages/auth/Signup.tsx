import { useState, type FormEvent } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { api } from '../../data/api';
import { useSession } from '../../data/session';
import { ApiError } from '../../domain/types';
import PageHeader from '../../components/PageHeader';
import '../client/client.css';

export default function Signup() {
  const nav = useNavigate();
  const { setUser } = useSession();
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [phone, setPhone] = useState('');
  const [password, setPassword] = useState('');
  const [consent, setConsent] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setError('');
    if (password.length < 10) return setError('Password must be at least 10 characters.');
    if (!consent) return setError('Please tick the box to agree before you continue.');
    setBusy(true);
    try {
      const u = await api.signup({ name, email, phone: phone || undefined, password });
      setUser(u);
      nav('/app', { replace: true });
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Could not create your account. Try again.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="wrap">
      <div className="tse-authcard">
        <PageHeader eyebrow="Get stretched" title="Create account">
          Your first session is 50% off. Pay at the studio.
        </PageHeader>
        <form className="stack" onSubmit={submit} noValidate>
          <div className="field">
            <label htmlFor="name">Full name</label>
            <input id="name" className="input" autoComplete="name" value={name} onChange={(e) => setName(e.target.value)} required />
          </div>
          <div className="field">
            <label htmlFor="email">Email</label>
            <input id="email" className="input" type="email" autoComplete="email" value={email} onChange={(e) => setEmail(e.target.value)} required />
          </div>
          <div className="field">
            <label htmlFor="phone">Phone (optional)</label>
            <input id="phone" className="input" type="tel" autoComplete="tel" value={phone} onChange={(e) => setPhone(e.target.value)} />
          </div>
          <div className="field">
            <label htmlFor="password">Password</label>
            <input id="password" className="input" type="password" autoComplete="new-password" minLength={10} value={password} onChange={(e) => setPassword(e.target.value)} aria-describedby="pw-rule" required />
            <span id="pw-rule" className="hint">At least 10 characters.</span>
          </div>
          <label className="row" style={{ alignItems: 'flex-start', flexWrap: 'nowrap', cursor: 'pointer', minHeight: 44 }}>
            <input type="checkbox" checked={consent} onChange={(e) => setConsent(e.target.checked)} style={{ width: 22, height: 22, marginTop: 2, accentColor: 'var(--red)', flex: 'none' }} />
            <span className="small silver">I agree that The Stretch Effect stores my details to manage my bookings</span>
          </label>
          {error && <p className="error-text" role="alert">{error}</p>}
          <button className="btn btn-red btn-block" type="submit" disabled={busy}>{busy ? 'Creating' : 'Create account'}</button>
        </form>
        <p className="small muted" style={{ marginTop: 'var(--s5)' }}>
          Already have an account? <Link to="/login" className="silver">Sign in</Link>
        </p>
      </div>
    </div>
  );
}
