import { useCallback, useEffect, useState } from 'react';
import { api } from '../../data/api';
import { ApiError, type Package, type PackRequest } from '../../domain/types';
import { formatLocalDateTime } from '../../domain/slots';
import ClientFrame from '../../components/ClientFrame';
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

function PacksInner() {
  const toast = useToast();
  const [pkgs, setPkgs] = useState<Package[] | null>(null);
  const [reqs, setReqs] = useState<PackRequest[]>([]);
  const [busy, setBusy] = useState<string | null>(null);

  const load = useCallback(async () => {
    const [p, r] = await Promise.all([api.packages(), api.myPackRequests()]);
    setPkgs(p);
    setReqs(r);
  }, []);
  useEffect(() => void load().catch(() => toast.push('Could not load packs.', 'error')), [load, toast]);

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

  const nameOf = (id: string) => pkgs?.find((p) => p.id === id)?.name ?? 'Session pack';

  return (
    <div className="tse-stack rise">
      <div className="eyebrow red">Example pricing for the demo</div>
      <p className="muted" style={{ margin: 0 }}>Your first session is 50% off (R275).</p>
      {!pkgs ? (
        <div aria-busy="true" style={{ minHeight: 200 }} />
      ) : (
        <div className="tse-packs">
          {pkgs.map((p) => (
            <article key={p.id} className="card tse-pack">
              <div className="tse-pack-sessions">{p.name}</div>
              <div className="tse-pack-price num">{money(p.priceZar)}</div>
              <div className="small muted">
                {p.sessions} session{p.sessions === 1 ? '' : 's'}
                {p.sessions > 1 ? ` · ${money(Math.round(p.priceZar / p.sessions))} each` : ''}
              </div>
              {p.note && <div className="small silver">{p.note}</div>}
              <button type="button" className="btn btn-red" onClick={() => request(p)} disabled={busy !== null}>
                {busy === p.id ? 'Sending' : 'Request pack'}
              </button>
            </article>
          ))}
        </div>
      )}
      <p className="small muted" style={{ margin: 0 }}>
        <Icon name="lock" size={14} style={{ display: 'inline', verticalAlign: '-2px' }} /> Pay by EFT or card at the studio. Ivan confirms and your sessions load.
      </p>

      <section aria-label="My pack requests">
        <div className="tse-sectionhead"><span className="eyebrow">My requests</span></div>
        {reqs.length === 0 ? (
          <p className="muted small">No requests yet.</p>
        ) : (
          <div className="card card-tight">
            <ul className="tse-ledger">
              {reqs.map((r) => (
                <li key={r.id}>
                  <div>
                    <div>{nameOf(r.packageId)} <span className="muted num">· {money(r.priceZar)}</span></div>
                    <div className="tiny muted num">{formatLocalDateTime(r.createdAt)}{r.paymentReference ? ` · ${r.paymentReference}` : ''}</div>
                  </div>
                  <span className={`badge ${BADGE[r.status].cls}`}>{BADGE[r.status].label}</span>
                </li>
              ))}
            </ul>
          </div>
        )}
      </section>
    </div>
  );
}

export default function Packs() {
  return (
    <ClientFrame>
      <PageHeader eyebrow="Sessions" title="Load sessions">Get stretched. Move better. Live better.</PageHeader>
      <PacksInner />
    </ClientFrame>
  );
}
