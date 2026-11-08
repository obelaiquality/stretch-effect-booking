import { useEffect, useMemo, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { LogoMark, Wordmark } from '../components/Logo';
import { api } from '../data/api';
import { useSession } from '../data/session';
import type { Package, Slot } from '../domain/types';
import './landing.css';

const TZ = 'Africa/Johannesburg';
const dayFmt = new Intl.DateTimeFormat('en-ZA', { timeZone: TZ, weekday: 'short', day: 'numeric', month: 'short' });
const timeFmt = new Intl.DateTimeFormat('en-ZA', { timeZone: TZ, hour: '2-digit', minute: '2-digit', hour12: false });
const keyFmt = new Intl.DateTimeFormat('en-CA', { timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit' });

function localDate(offsetDays: number): string {
  return keyFmt.format(new Date(Date.now() + offsetDays * 86400000));
}

const rand = (n: number) => `R${n.toLocaleString('en-ZA').replace(/\s/g, ',')}`;

function NextSlots() {
  const [slots, setSlots] = useState<Slot[] | null>(null);
  const { user } = useSession();
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

  const target = user?.role === 'client' ? '/app/book' : user ? '/admin' : '/signup';

  return (
    <div className="next-slots card" aria-live="polite">
      <div className="ns-head">
        <span className="eyebrow">Next free sessions</span>
        <span className="ns-live">
          <i /> Live from Ivan&rsquo;s calendar
        </span>
      </div>
      {slots === null ? (
        <div className="ns-loading" aria-busy="true">
          <span />
          <span />
          <span />
        </div>
      ) : days.length === 0 ? (
        <p className="muted small">Fully booked this week. New times open daily.</p>
      ) : (
        <ul className="ns-days">
          {days.map(([k, list], i) => (
            <li key={k} className="ns-day rise" style={{ animationDelay: `${i * 60}ms` }}>
              <span className="ns-date">{dayFmt.format(new Date(list[0].start))}</span>
              <span className="ns-times">
                {list.slice(0, 4).map((s) => (
                  <Link key={s.start} to={target} className="ns-time num">
                    {timeFmt.format(new Date(s.start))}
                  </Link>
                ))}
                {list.length > 4 && <span className="ns-more">+{list.length - 4}</span>}
              </span>
            </li>
          ))}
        </ul>
      )}
      <Link to={target} className="btn btn-red btn-block">
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
  {
    n: '01',
    title: 'Create your account',
    body: 'Sign up in a minute. Your sessions, bookings and history live in one place.',
  },
  {
    n: '02',
    title: 'Load your sessions',
    body: 'Pick a single session or a pack. Ivan confirms payment and your sessions appear.',
  },
  {
    n: '03',
    title: 'Book a free time',
    body: 'See real open times from Ivan’s calendar. Book in two taps. Cancel up to 24 hours before.',
  },
];

export default function Landing() {
  const [packs, setPacks] = useState<Package[]>([]);
  const loc = useLocation();

  useEffect(() => {
    api.packages().then(setPacks).catch(() => setPacks([]));
  }, []);

  useEffect(() => {
    const id = loc.hash.replace('#', '');
    if (id) document.getElementById(id)?.scrollIntoView({ block: 'start' });
  }, [loc.hash]);

  return (
    <div className="landing">
      {/* ---------- Hero ---------- */}
      <section className="hero grain">
        <div className="hero-glow" aria-hidden="true" />
        <div className="wrap hero-grid">
          <div className="hero-copy">
            <p className="eyebrow">Assisted stretch therapy · Brackenfell, Cape Town</p>
            <h1 className="display hero-title">
              Professional
              <span className="red">assisted stretching</span>
            </h1>
            <p className="hero-tag">
              Move better. <span className="red">Recover faster.</span> Feel stronger.
            </p>
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
            <div className="row hero-ctas">
              <Link to="/signup" className="btn btn-red">
                Book your session
              </Link>
              <a href="#how" className="btn btn-ghost" onClick={(e) => { e.preventDefault(); document.getElementById('how')?.scrollIntoView({ behavior: 'smooth' }); }}>
                How it works
              </a>
            </div>
          </div>
          <div className="hero-side">
            <div className="hero-mark" aria-hidden="true">
              <LogoMark size={420} />
            </div>
            <NextSlots />
          </div>
        </div>
      </section>

      {/* ---------- Statement ---------- */}
      <section className="statement">
        <div className="wrap">
          <p className="statement-text">
            Don&rsquo;t just stretch. <span className="red">Feel the Stretch Effect.</span>
          </p>
        </div>
      </section>

      {/* ---------- Compare ---------- */}
      <section className="section" aria-labelledby="compare-title">
        <div className="wrap">
          <p className="eyebrow red">Why assisted</p>
          <h2 id="compare-title" className="h2">
            Assisted stretching <span className="muted">vs</span> on your own
          </h2>
          <div className="compare">
            <div className="compare-col compare-us">
              <div className="compare-head">
                <span>Assisted stretching</span>
              </div>
              <ul>
                {COMPARE.map(([us]) => (
                  <li key={us}>
                    <svg viewBox="0 0 16 16" aria-hidden="true">
                      <path d="M3 8.5l3 3 7-7" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="square" />
                    </svg>
                    {us}
                  </li>
                ))}
              </ul>
            </div>
            <div className="compare-vs" aria-hidden="true">
              VS
            </div>
            <div className="compare-col compare-them">
              <div className="compare-head">
                <span>Stretching on your own</span>
              </div>
              <ul>
                {COMPARE.map(([, them]) => (
                  <li key={them}>
                    <svg viewBox="0 0 16 16" aria-hidden="true">
                      <path d="M4 4l8 8M12 4l-8 8" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="square" />
                    </svg>
                    {them}
                  </li>
                ))}
              </ul>
            </div>
          </div>
        </div>
      </section>

      {/* ---------- How booking works ---------- */}
      <section id="how" className="section section-panel" aria-labelledby="how-title">
        <div className="wrap">
          <p className="eyebrow red">How it works</p>
          <h2 id="how-title" className="h2">
            Book in under a minute
          </h2>
          <ol className="steps">
            {STEPS.map((s) => (
              <li key={s.n} className="step">
                <span className="step-n">{s.n}</span>
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
            <div>
              <p className="eyebrow red">Sessions</p>
              <h2 id="packs-title" className="h2">
                Load sessions, book any time
              </h2>
            </div>
            <span className="badge">Example pricing for the demo</span>
          </div>
          <div className="packs">
            {packs.map((p, i) => (
              <article key={p.id} className={`pack${i === 1 ? ' pack-featured' : ''}`}>
                {i === 1 && <span className="pack-flag">Popular choice</span>}
                <span className="pack-count num">{p.sessions}</span>
                <span className="pack-unit">{p.sessions === 1 ? 'session' : 'sessions'}</span>
                <h3 className="h3">{p.name}</h3>
                <p className="pack-price num">{rand(p.priceZar)}</p>
                <p className="small muted">
                  {p.sessions > 1 ? `${rand(Math.round(p.priceZar / p.sessions))} per session` : p.note ?? '60-minute session'}
                  {p.note && p.sessions > 1 ? ` · ${p.note}` : ''}
                </p>
                <Link to="/signup" className={`btn ${i === 1 ? 'btn-red' : 'btn-ghost'} btn-block`}>
                  Choose
                </Link>
              </article>
            ))}
          </div>
          <p className="small muted packs-note">
            New clients: your first session is 50% off. Each session is 60 minutes. Home visits on request.
          </p>
        </div>
      </section>

      {/* ---------- For Ivan ---------- */}
      <section className="section owner-teaser grain" aria-labelledby="owner-title">
        <div className="wrap owner-grid">
          <div>
            <p className="eyebrow red">Behind the scenes</p>
            <h2 id="owner-title" className="h2">
              Run the studio <span className="red">from ChatGPT</span>
            </h2>
            <p className="silver owner-lead">
              Ivan&rsquo;s Google Calendar decides which times are free. His own ChatGPT can check the day, load a
              client&rsquo;s sessions after payment and move bookings. Only Ivan can connect it, with his password and
              authenticator code. Every request goes into an audit log, and he can cut the connection at any time.
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
          <div className="chat-peek card" aria-label="Example ChatGPT exchange">
            <div className="cp-msg cp-user">Thandi paid for a 5-pack, EFT ref TSE-4471. Load it.</div>
            <div className="cp-tool">
              <span className="cp-tool-name">add_session_credits</span>
              <code>{'{ "client": "Thandi M.", "sessions": 5, "reference": "TSE-4471" }'}</code>
            </div>
            <div className="cp-approve">
              <span className="small">Allow this action?</span>
              <span className="cp-btns">
                <span className="cp-btn">Deny</span>
                <span className="cp-btn cp-allow">Allow</span>
              </span>
            </div>
            <div className="cp-msg cp-bot">Done. Thandi now has 7 sessions. Logged as ChatGPT, ref TSE-4471.</div>
          </div>
        </div>
      </section>

      {/* ---------- Closing ---------- */}
      <section className="closing">
        <div className="wrap closing-inner">
          <Wordmark size="lg" />
          <p className="closing-tag">
            Get stretched. Move better. <span className="red">Live better.</span>
          </p>
          <Link to="/signup" className="btn btn-red">
            Book your first session
          </Link>
        </div>
      </section>
    </div>
  );
}
