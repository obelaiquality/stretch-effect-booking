import { useEffect, useMemo, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { LogoMark } from '../components/Logo';
import { api } from '../data/api';
import { useSession } from '../data/session';
import type { Package, Slot } from '../domain/types';
import { formatDay, formatRand, formatTime } from '../lib/format';
import { PHOTOS } from '../content/photos';
import './landing.css';

const TZ = 'Africa/Johannesburg';
const keyFmt = new Intl.DateTimeFormat('en-CA', { timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit' });
const localDate = (offsetDays: number) => keyFmt.format(new Date(Date.now() + offsetDays * 86400000));
const BASE = import.meta.env.BASE_URL;

function useBookTarget() {
  const { user } = useSession();
  return user?.role === 'client' ? '/app/book' : user ? '/admin' : '/signup';
}

function NextSlots() {
  const [slots, setSlots] = useState<Slot[] | null>(null);
  const target = useBookTarget();
  useEffect(() => {
    api
      .availability(localDate(0), localDate(6))
      .then(setSlots)
      .catch(() => setSlots([]));
  }, []);

  const days = useMemo(() => {
    const map = new Map<string, Slot[]>();
    for (const s of slots ?? []) {
      const k = keyFmt.format(new Date(s.start));
      if (!map.has(k)) map.set(k, []);
      map.get(k)!.push(s);
    }
    return [...map.entries()].slice(0, 3);
  }, [slots]);

  return (
    <div className="next-slots card" aria-live="polite">
      <div className="ns-head">
        <h2 className="label">Next free sessions</h2>
        <span className="ns-live">From Ivan&rsquo;s calendar</span>
      </div>
      {slots === null ? (
        <div className="ns-loading" aria-busy="true">
          <span className="skel" />
          <span className="skel" />
          <span className="skel" />
        </div>
      ) : days.length === 0 ? (
        <p className="silver small">Fully booked this week. New times open every day.</p>
      ) : (
        <ul className="ns-days">
          {days.map(([k, list]) => (
            <li key={k} className="ns-day">
              <span className="ns-date">{formatDay(list[0].start)}</span>
              <span className="ns-times">
                {list.slice(0, 4).map((s) => (
                  <Link key={s.start} to={target} className="ns-time num" aria-label={`Book ${formatDay(s.start)} at ${formatTime(s.start)}`}>
                    {formatTime(s.start)}
                  </Link>
                ))}
                {list.length > 4 && <span className="ns-more">+{list.length - 4} more</span>}
              </span>
            </li>
          ))}
        </ul>
      )}
      <Link to={target} className="btn btn-ghost btn-block">
        See all times
      </Link>
    </div>
  );
}

const COMPARE: [string, string][] = [
  ['Deeper stretch, done safely', 'Limited by your own range'],
  ['Correct technique and alignment', 'Hard to hold good form'],
  ['Reaches areas you can’t on your own', 'Some muscles are hard to isolate'],
  ['Fits in a focused 60 minutes', 'Easy to rush or skip'],
  ['Matched to your body and goals', 'One-size-fits-all routines'],
];

const STEPS = [
  { n: '01', title: 'Create your account', body: 'Sign up in a minute. Your sessions, bookings and history live in one place.' },
  { n: '02', title: 'Load your sessions', body: 'Pick a single session or a pack. Ivan confirms payment and your sessions appear.' },
  { n: '03', title: 'Book a free time', body: 'See real open times from Ivan’s calendar. Book in two taps. Cancel up to 24 hours before.' },
];

function Check() {
  return (
    <svg viewBox="0 0 16 16" aria-hidden="true">
      <path d="M3 8.5l3 3 7-7" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="square" />
    </svg>
  );
}
function Cross() {
  return (
    <svg viewBox="0 0 16 16" aria-hidden="true">
      <path d="M4 4l8 8M12 4l-8 8" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="square" />
    </svg>
  );
}

export default function Landing() {
  const [packs, setPacks] = useState<Package[]>([]);
  const loc = useLocation();
  const target = useBookTarget();
  const hero = PHOTOS.find((p) => p.role === 'hero');
  const gallery = PHOTOS.filter((p) => p.role === 'gallery').slice(0, 6);

  useEffect(() => {
    api.packages().then(setPacks).catch(() => setPacks([]));
  }, []);

  useEffect(() => {
    const id = loc.hash.replace('#', '');
    if (id) document.getElementById(id)?.scrollIntoView({ block: 'start' });
  }, [loc.hash]);

  // Lowest price per session is a fact we can state; "popular" is not.
  const cheapest = packs.length ? packs.reduce((a, b) => (b.priceZar / b.sessions < a.priceZar / a.sessions ? b : a)) : null;
  const featured = packs.find((p) => p.id === 'five') ?? packs[1];

  return (
    <div className="landing">
      {/* ---------- Hero ---------- */}
      <section className={`hero grain${hero ? ' hero-photo' : ''}`}>
        {hero && (
          <picture className="hero-bg" aria-hidden="true">
            {hero.srcPhone && <source media="(max-width: 767px)" srcSet={BASE + hero.srcPhone} />}
            <img src={BASE + hero.src} alt="" />
          </picture>
        )}
        <div className="hero-glow" aria-hidden="true" />
        <div className="wrap hero-grid">
          <div className="hero-copy">
            <p className="eyebrow">Assisted stretch therapy · Brackenfell, Cape Town</p>
            <h1 className="display hero-title">
              Professional
              <span className="red">assisted stretching</span>
            </h1>
            <p className="hero-tag">Move better. Recover faster. Feel stronger.</p>
            <div className="hero-offer">
              <svg className="brush" viewBox="0 0 320 70" preserveAspectRatio="none" aria-hidden="true">
                <path
                  d="M6 38 C 40 16, 90 10, 160 12 C 230 14, 290 8, 314 22 C 300 34, 312 46, 296 58 C 230 62, 150 64, 70 60 C 40 58, 14 56, 4 50 C 10 46, 2 42, 6 38 Z"
                  fill="var(--red)"
                />
              </svg>
              <span className="offer-text">
                <strong>50% off</strong> your first stretch
              </span>
            </div>
            <p className="hero-facts silver">
              60-minute sessions{cheapest ? ` from ${formatRand(cheapest.priceZar / cheapest.sessions)}` : ''} · home visits on request
            </p>
            <div className="row hero-ctas">
              <Link to={target} className="btn btn-red">
                Book a session
              </Link>
              <a
                href="#how"
                className="btn btn-ghost"
                onClick={(e) => {
                  e.preventDefault();
                  document.getElementById('how')?.scrollIntoView({ behavior: 'smooth' });
                }}
              >
                How it works
              </a>
            </div>
          </div>
          <div className="hero-side">
            <NextSlots />
          </div>
        </div>
      </section>

      {/* ---------- Compare ---------- */}
      <section className="section" aria-labelledby="compare-title">
        <div className="wrap">
          <h2 id="compare-title" className="h2">
            Don&rsquo;t just stretch. Feel the Stretch Effect.
          </h2>
          <p className="section-lead silver measure">Assisted stretching compared with stretching on your own.</p>
          <div className="compare">
            <div className="compare-col compare-us">
              <div className="compare-head">Assisted stretching</div>
              <ul>
                {COMPARE.map(([us]) => (
                  <li key={us}>
                    <Check />
                    {us}
                  </li>
                ))}
              </ul>
            </div>
            <div className="compare-col compare-them">
              <div className="compare-head">On your own</div>
              <ul>
                {COMPARE.map(([, them]) => (
                  <li key={them}>
                    <Cross />
                    {them}
                  </li>
                ))}
              </ul>
            </div>
          </div>
        </div>
      </section>

      {/* ---------- Gallery (only when Ivan's photos exist) ---------- */}
      {gallery.length > 0 && (
        <section className="section gallery-section" aria-label="The studio">
          <div className="wrap gallery">
            {gallery.map((p) => (
              <img key={p.src} src={BASE + p.src} alt={p.alt} loading="lazy" />
            ))}
          </div>
        </section>
      )}

      {/* ---------- How booking works ---------- */}
      <section id="how" className="section section-panel" aria-labelledby="how-title">
        <div className="wrap">
          <h2 id="how-title" className="h2">
            Book in under a minute
          </h2>
          <ol className="steps">
            {STEPS.map((s) => (
              <li key={s.n} className="step">
                <span className="step-n" aria-hidden="true">
                  {s.n}
                </span>
                <h3 className="h3">{s.title}</h3>
                <p className="silver">{s.body}</p>
              </li>
            ))}
          </ol>
        </div>
      </section>

      {/* ---------- Packages ---------- */}
      <section id="packs" className="section" aria-labelledby="packs-title">
        <div className="wrap">
          <div className="packs-head">
            <h2 id="packs-title" className="h2">
              Load sessions, book any time
            </h2>
            <span className="badge">Example prices for the demo</span>
          </div>
          <div className="packs">
            {packs.map((p) => {
              const isFeatured = p.id === featured?.id;
              return (
                <article key={p.id} className={`pack${isFeatured ? ' pack-featured' : ''}`}>
                  {cheapest?.id === p.id && p.sessions > 1 && <span className="pack-flag">Lowest price per session</span>}
                  <span className="pack-count num">{p.sessions}</span>
                  <span className="label">{p.sessions === 1 ? 'Session' : 'Sessions'}</span>
                  <h3 className="h3">{p.name}</h3>
                  <p className="pack-price num">{formatRand(p.priceZar)}</p>
                  <p className="small silver pack-note">
                    {p.sessions > 1 ? `${formatRand(p.priceZar / p.sessions)} per session` : 'First session 50% off for new clients'}
                  </p>
                  <Link to="/signup" className={`btn ${isFeatured ? 'btn-red' : 'btn-ghost'} btn-block`}>
                    Buy {p.sessions === 1 ? '1 session' : `${p.sessions} sessions`}
                  </Link>
                </article>
              );
            })}
          </div>
          <p className="small silver packs-note">Each session is 60 minutes. Home visits on request.</p>
        </div>
      </section>

      {/* ---------- For Ivan (demo pitch: remove on the live site) ---------- */}
      <section className="section owner-teaser" aria-labelledby="owner-title">
        <div className="wrap owner-grid">
          <div>
            <p className="eyebrow">For Ivan</p>
            <h2 id="owner-title" className="h2">
              Run the studio from ChatGPT
            </h2>
            <p className="silver owner-lead measure">
              Your Google Calendar decides which times are free. Your own ChatGPT can check the day, load a client&rsquo;s
              sessions after payment and move bookings. Only you can connect it, with your password and authenticator code.
              Every request goes into an audit log, and you can cut the connection at any time.
            </p>
            <div className="row">
              <Link to="/login" className="btn btn-ghost">
                Try the owner view
              </Link>
              <Link to="/go-live" className="btn btn-quiet">
                What it takes to go live
              </Link>
            </div>
          </div>
          <figure className="chat-peek" aria-label="Example: loading a paid pack from ChatGPT">
            <div className="cp-msg cp-user">Thandi paid for a 5-pack, EFT ref TSE-4471. Load it.</div>
            <div className="cp-tool">
              <span className="cp-tool-name">add_session_credits</span>
              <code>{'{ "client": "Thandi M.", "sessions": 5, "reference": "TSE-4471" }'}</code>
            </div>
            <div className="cp-approved">
              <Check /> Allowed by Ivan
            </div>
            <div className="cp-msg cp-bot">Done. Thandi now has 7 sessions. Logged as ChatGPT, ref TSE-4471.</div>
          </figure>
        </div>
      </section>

      {/* ---------- Closing ---------- */}
      <section className="closing">
        <div className="wrap closing-grid">
          <div>
            <h2 className="h2">Your first stretch is half price</h2>
            <p className="closing-tag">Get stretched. Move better. Live better.</p>
            <Link to={target} className="btn btn-red">
              Book a session
            </Link>
          </div>
          <div className="closing-mark" aria-hidden="true">
            <LogoMark size={360} />
          </div>
        </div>
      </section>
    </div>
  );
}
