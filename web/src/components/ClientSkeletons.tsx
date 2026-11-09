import { Skel } from './Skeleton';

/** Page title block shared by the client skeletons. */
export function TitleSkel({ sub = true }: { sub?: boolean }) {
  return (
    <div style={{ padding: '32px 0 24px', display: 'grid', gap: 12 }}>
      <Skel w={110} h={13} />
      <Skel w="min(380px, 80%)" h={48} />
      {sub && <Skel w="min(460px, 90%)" h={16} />}
    </div>
  );
}

function CardSkel({ rows = 3 }: { rows?: number }) {
  return (
    <div className="card" style={{ display: 'grid', gap: 12 }}>
      <Skel w="35%" h={13} />
      {Array.from({ length: rows }, (_, i) => <Skel key={i} w={i === rows - 1 ? '60%' : '100%'} h={i === 0 ? 40 : 16} />)}
    </div>
  );
}

export function DashboardSkel() {
  return (
    <div aria-busy="true" aria-label="Loading">
      <TitleSkel sub={false} />
      <div className="tse-grid2">
        <div className="tse-stack"><CardSkel rows={4} /><CardSkel rows={2} /></div>
        <CardSkel rows={6} />
      </div>
    </div>
  );
}

export function BookSkel() {
  return (
    <div aria-busy="true" aria-label="Loading">
      <TitleSkel />
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(7, minmax(0, 1fr))', gap: 6 }}>
        {Array.from({ length: 7 }, (_, i) => <Skel key={i} h={72} />)}
      </div>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, minmax(0, 1fr))', gap: 8, marginTop: 16 }}>
        {Array.from({ length: 6 }, (_, i) => <Skel key={i} h={44} />)}
      </div>
    </div>
  );
}

export function ListSkel() {
  return (
    <div aria-busy="true" aria-label="Loading">
      <TitleSkel />
      <div style={{ display: 'grid', gap: 12 }}>
        {Array.from({ length: 3 }, (_, i) => <Skel key={i} h={84} />)}
      </div>
    </div>
  );
}

export function PacksSkel() {
  return (
    <div aria-busy="true" aria-label="Loading">
      <TitleSkel sub={false} />
      <div className="tse-packs">
        {Array.from({ length: 3 }, (_, i) => <CardSkel key={i} rows={5} />)}
      </div>
    </div>
  );
}
