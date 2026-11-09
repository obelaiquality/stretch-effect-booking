import { useCallback, useEffect, useState } from 'react';
import { api } from '../../data/api';
import { ApiError, type Package, type PackRequest } from '../../domain/types';
import { formatDayTime } from '../../lib/format';
import ClientFrame from '../../components/ClientFrame';
import { PacksSkel } from '../../components/ClientSkeletons';
import EmptyState from '../../components/EmptyState';
import ErrorState from '../../components/ErrorState';
import Icon from '../../components/Icon';
import PageHeader from '../../components/PageHeader';
import { useToast } from '../../components/Toast';
import { money } from './shared';
import './client.css';

const BADGE: Record<PackRequest['status'], { label: string; cls: string }> = {
  pending: { label: 'Waiting for payment', cls: 'badge-warn' },
  paid: { label: 'Paid, sessions loaded', cls: 'badge-ok' },
  cancelled: { label: 'Cancelled', cls: '' },
};

const RECOMMENDED = 'five';

function PacksInner() {
  const toast = useToast();
  const [pkgs, setPkgs] = useState<Package[] | null>(null);
  const [reqs, setReqs] = useState<PackRequest[]>([]);
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    setError('');
    try {
      const [p, r] = await Promise.all([api.packages(), api.myPackRequests()]);
      setPkgs(p);
      setReqs(r);
    } catch {
      setError('Could not load packs.');
    }
  }, []);
  useEffect(() => void load(), [load]);

  const request = async (p: Package) => {
    setBusy(p.id);
    try {
      await api.requestPack(p.id);
      toast.push(`${p.name} requested. Pay Ivan by EFT or card at the studio and your sessions load.`, 'ok');
      await load();
    } catch (e) {
      toast.push(e instanceof ApiError ? e.message : 'Could not send the request.', 'error');
    } finally {
      setBusy(null);
    }
  };

  if (error) {
    return (
      <>
        <PageHeader title="Load sessions" />
        <ErrorState message={error} onRetry={() => void load()} />
      </>
    );
  }
  if (!pkgs) return <PacksSkel />;

  const nameOf = (id: string) => pkgs.find((p) => p.id === id)?.name ?? 'Session pack';
  const each = (p: Package) => p.priceZar / p.sessions;
  const multi = pkgs.filter((p) => p.sessions > 1);
  const cheapest = multi.length > 1 ? multi.reduce((a, b) => (each(b) < each(a) ? b : a)) : null;

  return (
    <>
      <PageHeader title="Load sessions" />
      <div className="tse-stack rise">
        <div className="row">
          <span className="badge">Example prices for the demo</span>
          <span className="small muted">Your first session is 50% off (R275).</span>
        </div>
        <div className="tse-packs">
          {pkgs.map((p) => {
            const rec = p.id === RECOMMENDED;
            return (
              <article key={p.id} className="tse-pack">
                <span className="label tse-pack-flag">{cheapest?.id === p.id ? 'Lowest price per session' : ''}</span>
                <span className="tse-pack-count num">{p.sessions}</span>
                <span className="tse-pack-unit">{p.sessions === 1 ? 'session' : 'sessions'}</span>
                <h3 className="h3">{p.name}</h3>
                <p className="tse-pack-price num">{money(p.priceZar)}</p>
                <p className="small muted tse-pack-each">
                  {p.sessions > 1 ? `${money(Math.round(each(p)))} per session` : p.note ?? '60-minute session'}
                  {p.note && p.sessions > 1 ? ` · ${p.note}` : ''}
                </p>
                <button type="button" className={`btn ${rec ? 'btn-red' : 'btn-ghost'}`} onClick={() => request(p)} disabled={busy !== null}>
                  {busy === p.id ? 'Sending' : 'Request pack'}
                </button>
              </article>
            );
          })}
        </div>
        <p className="small muted measure" style={{ margin: 0 }}>
          <Icon name="card" size={16} style={{ display: 'inline', verticalAlign: '-3px' }} /> Pay by EFT or card at the studio. Ivan confirms and your sessions load.
        </p>

        <section aria-label="My pack requests">
          <div className="tse-sectionhead"><span className="eyebrow">My requests</span></div>
          {reqs.length === 0 ? (
            <EmptyState icon="card" title="No requests yet">Pick a pack above and Ivan will load your sessions once you have paid.</EmptyState>
          ) : (
            <div className="card card-tight">
              <ul className="tse-ledger">
                {reqs.map((r) => (
                  <li key={r.id}>
                    <div className="tse-ledger-main">
                      <div>{nameOf(r.packageId)} <span className="muted num">· {money(r.priceZar)}</span></div>
                      <div className="tiny muted num">{formatDayTime(r.createdAt)}{r.paymentReference ? ` · ${r.paymentReference}` : ''}</div>
                    </div>
                    <span className={`badge ${BADGE[r.status].cls}`}>{BADGE[r.status].label}</span>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </section>
      </div>
    </>
  );
}

export default function Packs() {
  return (
    <ClientFrame>
      <PacksInner />
    </ClientFrame>
  );
}
