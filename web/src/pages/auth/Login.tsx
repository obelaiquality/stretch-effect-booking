import { useEffect, useState, type FormEvent } from 'react';
import { Link, Navigate, useNavigate, useSearchParams } from 'react-router-dom';
import { api } from '../../data/api';
import { demoTotpCode, demoTotpSecondsLeft } from '../../data/demoApi';
import { useSession } from '../../data/session';
import { ApiError, type User } from '../../domain/types';
import Icon from '../../components/Icon';
import PageHeader from '../../components/PageHeader';
import PasswordField from '../../components/PasswordField';
import { Skel } from '../../components/Skeleton';
import '../client/client.css';

const homeFor = (u: User, next: string | null) => {
  const safe = next && next.startsWith('/') && !next.startsWith('//') ? next : null;
  return safe ?? (u.role === 'owner' ? '/admin' : '/app');
};

const spaced = (c: string) => `${c.slice(0, 3)} ${c.slice(3)}`;

export default function Login() {
  const [params] = useSearchParams();
  const nav = useNavigate();
  const { user, loading, setUser } = useSession();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [step, setStep] = useState<'password' | 'totp'>('password');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [tick, setTick] = useState(0);
  const demo = api.mode === 'demo';

  useEffect(() => {
    if (!demo || step !== 'totp') return;
    const t = window.setInterval(() => setTick((n) => n + 1), 1000);
    return () => window.clearInterval(t);
  }, [demo, step]);

  const done = (u: User) => {
    setUser(u);
    nav(homeFor(u, params.get('next')), { replace: true });
  };

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setError('');
    setBusy(true);
    try {
      if (step === 'password') {
        const r = await api.login(email, password);
        if (r.needsTotp) setStep('totp');
        else if (r.user) done(r.user);
      } else {
        done(await api.verifyTotp(code));
      }
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Could not sign in. Try again.');
    } finally {
      setBusy(false);
    }
  };

  const fill = (em: string) => {
    setEmail(em);
    setPassword('stretch123');
    setError('');
  };

  // Already signed in: go to your home instead of showing the form.
  if (loading) {
    return (
      <div className="wrap" aria-busy="true" aria-label="Loading">
        <div className="tse-authcard" style={{ display: 'grid', gap: 16 }}>
          <Skel w={110} h={13} /><Skel w="70%" h={48} /><Skel h={64} /><Skel h={64} /><Skel h={44} />
        </div>
      </div>
    );
  }
  if (user && step === 'password') return <Navigate to={homeFor(user, params.get('next'))} replace />;

  void tick;
  const live = demoTotpCode();

  return (
    <div className="wrap">
      <div className="tse-authcard">
        <PageHeader eyebrow="Welcome back" title={step === 'password' ? 'Sign in' : 'Check it is you'} />
        <form className="stack" onSubmit={submit} noValidate>
          {step === 'password' ? (
            <>
              <div className="field">
                <label htmlFor="email">Email</label>
                <input id="email" className="input" type="email" autoComplete="username" value={email} onChange={(e) => setEmail(e.target.value)} required />
              </div>
              <PasswordField id="password" label="Password" autoComplete="current-password" value={password} onChange={setPassword} />
            </>
          ) : (
            <div className="field">
              <label htmlFor="code">6-digit code from your authenticator app</label>
              <input
                id="code" className="input" inputMode="numeric" autoComplete="one-time-code" maxLength={7} placeholder="123 456"
                value={code} onChange={(e) => setCode(e.target.value.replace(/[^\d ]/g, ''))} autoFocus required aria-invalid={error ? true : undefined}
              />
              <span className="hint"><Icon name="lock" size={14} style={{ display: 'inline', verticalAlign: '-2px' }} /> The owner account needs a second step.</span>
            </div>
          )}
          {error && <p className="error-text" role="alert">{error}</p>}
          <button className="btn btn-red btn-block" type="submit" disabled={busy}>
            {busy ? 'Checking' : step === 'password' ? 'Sign in' : 'Verify'}
          </button>
          {step === 'totp' && (
            <button className="btn btn-quiet btn-block" type="button" onClick={() => { setStep('password'); setCode(''); setError(''); }}>
              Back
            </button>
          )}
        </form>

        {step === 'password' && (
          <p className="small muted" style={{ marginTop: 'var(--s5)' }}>
            New here? <Link to="/signup" className="silver">Create an account</Link>. Your first session is 50% off.
          </p>
        )}

        {demo && (
          <aside className="tse-demo-panel" aria-label="Demo accounts">
            <div className="eyebrow">Demo accounts</div>
            {step === 'password' ? (
              <>
                <p className="small muted" style={{ margin: 0 }}>Nothing here is real. Pick a role to look around.</p>
                <button type="button" className="btn btn-ghost" onClick={() => fill('thandi@example.com')}>Try as a client (Thandi)</button>
                <button type="button" className="btn btn-ghost" onClick={() => fill('ivan@example.com')}>Try as the owner (Ivan)</button>
                <p className="tiny muted" style={{ margin: 0 }}>Password for both: stretch123. Then press Sign in.</p>
              </>
            ) : (
              <>
                <p className="small muted" style={{ margin: 0 }}>Demo authenticator (changes every 30 seconds, {demoTotpSecondsLeft()}s left)</p>
                <div className="tse-code num" aria-live="off">{spaced(live)}</div>
                <button type="button" className="btn btn-ghost btn-sm" onClick={() => setCode(spaced(live))}>Use this code</button>
              </>
            )}
          </aside>
        )}
      </div>
    </div>
  );
}
