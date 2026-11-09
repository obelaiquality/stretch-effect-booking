import { Link } from 'react-router-dom';
import './golive.css';

const CHECKED = 'Prices checked November 2026, confirm on afrihost.com before buying.';

const steps: Array<[string, string]> = [
  ['You buy hosting and a domain', 'An Afrihost Linux shared hosting plan and a .co.za domain, bought in your own name so you own everything. We suggest starting with a one-week trial to test the few things Afrihost does not publish.'],
  ['We upload the app and database', 'The booking site, the PHP backend and the MySQL database go onto your hosting. A free SSL certificate (Let\'s Encrypt, through Afrihost AutoSSL) is switched on. Test bookings and test clients only.'],
  ['Connect Google Calendar', 'Your diary becomes the single source of truth: your busy times block the booking page and each booking is written to your calendar. You set it up once, by signing in with Google or by sharing the calendar. We confirm the best route with a short test on your real calendar.'],
  ['Connect ChatGPT (optional)', 'Add the studio as a custom connector in your own ChatGPT account, sign in with your password and 2FA, and choose what ChatGPT may do. It can start read-only if you prefer.'],
  ['Test together', 'You book, cancel and load a pack for yourself and a friend, check the calendar and the audit log, and adjust prices, packs and hours.'],
  ['Launch', 'Real clients are invited, and the link goes on Instagram and WhatsApp. Demo data is cleared first.'],
];

const needs: Array<[string, string]> = [
  ['Afrihost access', 'Either a login to your Afrihost account, or a delegated user that you create for us, so we can upload and set up.'],
  ['A Google sign-in, once', 'You sign in with Google one time to link your calendar. Clients never sign in with Google.'],
  ['Prices, packages and policies', 'Final prices for single sessions and packs, the first-session offer, working hours, buffer between clients and the cancellation window.'],
  ['Logo files', 'The best quality versions you have of the wordmark and the figure, so the site matches your brand.'],
];

const rivals: Array<[string, string, string, string]> = [
  ['Acuity Scheduling', '$20 / $34 / $61 (Starter, Standard, Premium)', 'R360 to R1,100', 'Packages and subscriptions from Standard up. One calendar on Starter.'],
  ['Calendly', 'Free, paid seats about $10 to $16', 'R0 to R290', 'One-to-one booking and calendar sync. Packs as real credit balances: not confirmed.'],
  ['Setmore', 'Free (up to 4 users), Pro $5 to $12 per user', 'R0 to R220', 'Appointments and basic payments. No pack ledger confirmed.'],
  ['Momoyoga', 'EUR 29 a month (annual), free tier', 'About R570', 'Built for yoga and pilates studios. Class passes and credit passes.'],
  ['StudioBook', '$69 per studio', 'About R1,250', 'Unlimited members and bookings.'],
  ['Glofox', 'From about $99', 'R1,500 to R4,500', 'Memberships, packs and credits. Aimed at larger studios.'],
  ['Bookamat', 'About $149 for 400 active clients', 'About R2,700', 'Booking, payments and client management for yoga, pilates and fitness.'],
  ['Mindbody', 'From $79 per location (US listing)', 'R1,400 to R12,600', 'The big all-in-one. Contracts reported as 12-month auto-renew.'],
];

export default function GoLive() {
  return (
    <div className="wrap">
      <header className="golive-hero">
        <h1 className="page-title">What it takes to go live</h1>
        <p className="silver measure" style={{ marginTop: 16 }}>
          This demo runs in your browser with made-up clients. The real thing runs on your own hosting, with your own domain, your own Google Calendar and, if you want, your own ChatGPT. This is what you would buy, what is free, and what happens next.
        </p>
      </header>

      <section className="gl-section" aria-labelledby="gl-buy">
        <h2 className="h2 gl-title" id="gl-buy">What you buy</h2>
        <div className="card" style={{ padding: 0 }}>
          <div className="table-scroll">
            <table className="table">
              <thead>
                <tr>
                  <th>Item</th>
                  <th className="r">First year</th>
                  <th className="r">Every year after</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td>
                    <strong>Afrihost Linux shared hosting, Platinum</strong>
                    <div className="muted small gl-cell">R109 a month. 4 GB disk, 200 email accounts, 5 MySQL databases. Month to month, no setup fee.</div>
                  </td>
                  <td className="r num">R1,308</td>
                  <td className="r num">R1,308</td>
                </tr>
                <tr>
                  <td>
                    <strong>A .co.za domain</strong>
                    <div className="muted small gl-cell">Registration (R197) is free for the first year with any shared hosting plan. Renewal R109 a year.</div>
                  </td>
                  <td className="r num">R0</td>
                  <td className="r num">R109</td>
                </tr>
                <tr>
                  <td>
                    <strong>SSL certificate</strong>
                    <div className="muted small gl-cell">Free AutoSSL (Let's Encrypt), installed by us. Renews every three months.</div>
                  </td>
                  <td className="r num">R0</td>
                  <td className="r num">R0</td>
                </tr>
                <tr className="total">
                  <td>Total</td>
                  <td className="r num">R1,308</td>
                  <td className="r num">R1,417</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
        <p className="gl-note measure">
          {CHECKED} Afrihost does not say whether these prices include VAT; if they are ex-VAT, add 15%. If you want more databases, Bronze Pro is R135 a month. If the trial week shows Afrihost shared hosting cannot do something we need, the fallback is a self-managed Afrihost cloud server from R460 a month (about R5,520 a year).
        </p>
      </section>

      <section className="gl-section" aria-labelledby="gl-free">
        <h2 className="h2 gl-title" id="gl-free">What is free, and what you may add</h2>
        <div className="gl-cols">
          <div className="card">
            <h3 className="h3">Free</h3>
            <ul className="ticks" style={{ marginTop: 16 }}>
              <li><strong>Google Calendar API.</strong> Standard use costs nothing, and a single studio is a tiny fraction of the free limits.</li>
              <li><strong>SSL certificate.</strong> Let's Encrypt, through Afrihost AutoSSL.</li>
            </ul>
          </div>
          <div className="card">
            <h3 className="h3">Optional</h3>
            <ul className="ticks" style={{ marginTop: 16 }}>
              <li>
                <strong>A ChatGPT plan that supports custom connectors.</strong> Business, Enterprise and Edu plans are listed as supported, with the ability to make changes. For Plus and Pro, sources disagree: some say read-only, some say changes are allowed. We have not confirmed it, so the first step is a five-minute test on your own account. If changes are blocked on your plan, the connector can be read-only and you make changes in the admin screen. We have not checked a Business plan price.
              </li>
              <li>
                <strong>Email for confirmations.</strong> Afrihost does not provide outgoing mail service by default on shared hosting, so booking emails should go through a transactional email provider. Several have free tiers; we would confirm the current limits when we choose one.
              </li>
              <li>
                <strong>Online card payments, later.</strong> Yoco or PayFast can be added in a second phase. Today, a client asks for a pack and you confirm when the money arrives. Their per-payment fees have not been researched here.
              </li>
            </ul>
          </div>
        </div>
      </section>

      <section className="gl-section" aria-labelledby="gl-steps">
        <h2 className="h2 gl-title" id="gl-steps">Step by step</h2>
        <ol className="gl-timeline">
          {steps.map(([t, d]) => (
            <li key={t}>
              <div>
                <strong className="h3" style={{ display: 'block' }}>{t}</strong>
                <p className="silver measure">{d}</p>
              </div>
            </li>
          ))}
        </ol>
      </section>

      <section className="gl-section" aria-labelledby="gl-need">
        <h2 className="h2 gl-title" id="gl-need">What we need from you</h2>
        <div className="gl-cols">
          {needs.map(([t, d]) => (
            <div key={t} className="card card-tight">
              <h3 className="h3">{t}</h3>
              <p className="silver small measure" style={{ margin: '8px 0 0' }}>{d}</p>
            </div>
          ))}
        </div>
      </section>

      <section className="gl-section" aria-labelledby="gl-vs">
        <h2 className="h2 gl-title" id="gl-vs">Versus off-the-shelf booking tools</h2>
        <div className="card" style={{ padding: 0 }}>
          <div className="table-scroll">
            <table className="table">
              <thead>
                <tr>
                  <th>Product</th>
                  <th>Listed price per month</th>
                  <th>About, in rand</th>
                  <th>Fit for session packs</th>
                </tr>
              </thead>
              <tbody>
                <tr className="total">
                  <td>This studio system</td>
                  <td className="num">Hosting R109, domain R109 a year</td>
                  <td className="num">About R118 a month</td>
                  <td>Built for packs, credits, Google Calendar and ChatGPT. We maintain it.</td>
                </tr>
                {rivals.map(([n, p, z, f]) => (
                  <tr key={n}>
                    <td><strong>{n}</strong></td>
                    <td>{p}</td>
                    <td className="num">{z}</td>
                    <td className="muted">{f}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
        <p className="gl-note measure">
          Competitor prices are monthly figures from third-party listings, mostly in US dollars, converted at roughly R18 to the dollar. They are not confirmed on the vendors' own pages, so check before you rely on them. VAT and card fees come on top. Whether Yoco, PayFast or Ozow work with the international tools is not confirmed. Our own figure is the Platinum plan plus the domain renewal, R1,417 a year divided by 12, with the VAT question above still open.
        </p>
      </section>

      <section className="gl-section" style={{ paddingBottom: 'var(--s9)' }}>
        <div className="row gl-actions">
          <Link className="btn btn-red" to="/login">Try the owner view</Link>
          <Link className="btn btn-ghost" to="/">Back to the demo</Link>
        </div>
      </section>
    </div>
  );
}
