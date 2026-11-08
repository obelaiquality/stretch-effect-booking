// The Stretch Effect lockup: a faceted, low-poly figure (practitioner stretching a client's raised leg)
// above the "THE / STRETCH / — EFFECT —" wordmark. Drawn for the demo in the spirit of the brand mark;
// swap in the original logo files from the owner before launch.
import './logo.css';

type P = [number, number];
type Tri = [P, P, P, 1 | 2 | 3 | 4];

// Each quad is split into a lit and a shaded triangle for the faceted look.
function quad(a: P, b: P, c: P, d: P, lit: 1 | 2 = 1, shade: 3 | 4 = 3): Tri[] {
  return [
    [a, b, c, lit],
    [a, c, d, shade],
  ];
}

function hex(cx: number, cy: number, r: number): Tri[] {
  const pts: P[] = Array.from({ length: 6 }, (_, i) => {
    const a = (Math.PI / 3) * i - Math.PI / 2 + 0.25;
    return [cx + r * Math.cos(a), cy + r * Math.sin(a)];
  });
  // Fan from the top-left vertex: four facets, lit from the upper left.
  const shades: (1 | 2 | 3 | 4)[] = [1, 2, 3, 4];
  return [1, 2, 3, 4].map((i, k) => [pts[0], pts[i], pts[i + 1], shades[k]] as Tri);
}

const FIGURE: Tri[] = [
  // Client, lying on the table, head to the left
  ...hex(26, 98, 8.5),
  ...quad([36, 93], [64, 92], [64, 107], [36, 105], 2, 4),
  ...quad([64, 92], [92, 95], [92, 108], [64, 107], 1, 3),
  ...quad([92, 99], [150, 102], [150, 108], [92, 108], 2, 4),
  ...quad([150, 102], [158, 96], [161, 108], [150, 108], 1, 3),
  // Client's raised leg
  ...quad([84, 96], [94, 92], [106, 58], [97, 56], 1, 3),
  ...quad([97, 56], [106, 58], [116, 26], [108, 24], 2, 4),
  ...quad([108, 24], [116, 26], [122, 16], [112, 14], 1, 3),
  // Practitioner, leaning into the stretch
  ...hex(139, 30, 7.5),
  ...quad([122, 42], [140, 39], [164, 70], [150, 78], 1, 3),
  ...quad([108, 38], [124, 42], [126, 50], [110, 46], 2, 4),
  ...quad([128, 46], [132, 52], [110, 60], [108, 54], 1, 4),
  ...quad([150, 76], [160, 72], [150, 98], [140, 96], 2, 3),
  ...quad([140, 96], [150, 98], [146, 118], [136, 118], 1, 4),
  ...quad([158, 72], [166, 70], [180, 96], [172, 100], 2, 4),
  ...quad([172, 100], [180, 96], [186, 118], [176, 118], 1, 3),
];

const FILL = { 1: 'var(--facet-1)', 2: 'var(--facet-2)', 3: 'var(--facet-3)', 4: 'var(--facet-4)' } as const;

export function LogoMark({ size = 120, title = 'The Stretch Effect' }: { size?: number; title?: string }) {
  return (
    <svg
      className="logo-mark"
      viewBox="0 0 200 130"
      width={size}
      height={(size * 130) / 200}
      role="img"
      aria-label={title}
    >
      <defs>
        <radialGradient id="tse-glow" cx="50%" cy="50%" r="50%">
          <stop offset="0%" stopColor="var(--red)" stopOpacity="0.55" />
          <stop offset="100%" stopColor="var(--red)" stopOpacity="0" />
        </radialGradient>
      </defs>
      <ellipse cx="96" cy="114" rx="92" ry="12" fill="url(#tse-glow)" />
      <line x1="10" y1="111.5" x2="190" y2="111.5" stroke="var(--red)" strokeWidth="1.2" />
      <g stroke="var(--ink)" strokeWidth="0.6" strokeLinejoin="round">
        {FIGURE.map(([a, b, c, s], i) => (
          <polygon key={i} points={`${a[0]},${a[1]} ${b[0]},${b[1]} ${c[0]},${c[1]}`} fill={FILL[s]} />
        ))}
      </g>
    </svg>
  );
}

export function Wordmark({ size = 'md' }: { size?: 'sm' | 'md' | 'lg' }) {
  return (
    <span className={`wordmark wordmark-${size}`} aria-label="The Stretch Effect">
      <span className="wm-the" aria-hidden="true">The</span>
      <span className="wm-stretch" aria-hidden="true">Stretch</span>
      <span className="wm-effect" aria-hidden="true">
        <i />
        Effect
        <i />
      </span>
    </span>
  );
}

export default function Logo({ variant = 'horizontal' }: { variant?: 'horizontal' | 'stacked' }) {
  if (variant === 'stacked') {
    return (
      <span className="logo logo-stacked">
        <LogoMark size={260} />
        <Wordmark size="lg" />
      </span>
    );
  }
  return (
    <span className="logo logo-horizontal">
      <LogoMark size={54} />
      <Wordmark size="sm" />
    </span>
  );
}
