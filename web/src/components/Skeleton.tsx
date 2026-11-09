// Loading skeletons (docs/DESIGN.md: every page and route shows one while loading).
import type { CSSProperties } from 'react';

export function Skel({ w = '100%', h = 16, style }: { w?: number | string; h?: number | string; style?: CSSProperties }) {
  return <span className="skel" style={{ width: w, height: h, ...style }} aria-hidden="true" />;
}

/** A generic page skeleton: eyebrow, title and two cards. Used by route fallbacks. */
export function PageSkeleton({ cards = 2 }: { cards?: number }) {
  return (
    <div className="wrap" style={{ padding: '48px var(--gutter) 64px' }} aria-busy="true" aria-label="Loading">
      <Skel w={120} h={13} />
      <Skel w="min(420px, 80%)" h={56} style={{ marginTop: 12 }} />
      <div
        style={{
          display: 'grid',
          gap: 24,
          marginTop: 32,
          gridTemplateColumns: `repeat(auto-fit, minmax(min(100%, 320px), 1fr))`,
        }}
      >
        {Array.from({ length: cards }, (_, i) => (
          <div key={i} className="card" style={{ display: 'grid', gap: 12 }}>
            <Skel w="40%" h={13} />
            <Skel h={48} />
            <Skel w="70%" />
            <Skel w="55%" />
          </div>
        ))}
      </div>
    </div>
  );
}
