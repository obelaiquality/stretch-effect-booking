# The Stretch Effect: booking system demo. Build spec

Owner: Ivan Bond, The Stretch Effect, assisted stretch therapy, Brackenfell, Cape Town.
Purpose: a working demo that shows the owner what a custom booking system can do, in the
look of his brand. It runs on GitHub Pages in **demo mode** (all data in the browser). The
same code runs on Afrihost with a real PHP + MySQL backend in **live mode**.

## 1. Architecture

```
web/      React 18 + TypeScript + Vite single-page app (hash router, works on GitHub Pages)
          src/data/api.ts        the ONE interface the UI uses (Api)
          src/data/demoApi.ts    in-browser implementation (localStorage), used on GitHub Pages
          src/data/httpApi.ts    calls the PHP backend at /api/*, used on Afrihost
          src/domain/*           pure logic shared by demoApi: slots, credits, MCP tool specs
server/   PHP 8.2+ backend, no framework, no runtime Composer deps (upload-and-run on cPanel)
          public/                document root (index.php front controller, .htaccess)
          src/                   App code (PSR-4 namespace StretchEffect\)
          migrations/            mysql/*.sql and sqlite/*.sql (SQLite for local dev + tests)
          tests/                 PHPUnit tests + an end-to-end OAuth+MCP script
docs/     BUILD-SPEC.md, SECURITY.md, AFRIHOST-SETUP.md, COSTS.md, CHATGPT-CONNECTOR.md
```

Mode switch: `VITE_API_MODE=demo|http` at build time. GitHub Pages builds `demo`.

On Afrihost: `web/dist` goes into `public_html/`, `server/public` is served at
`/api`, `/oauth`, `/mcp`, `/.well-known/*` on the SAME domain (one origin, no CORS for the app).

## 2. Domain model (both implementations follow this)

- **User**: id, role (`client` | `owner`), name, email, phone, password hash, created_at.
  Owner also has TOTP secret (2FA required for owner).
- **Credit ledger**: every change to a client's sessions is a row: id, user_id, delta (+/-),
  reason (`purchase` | `booking` | `cancel_refund` | `owner_adjust` | `chatgpt_adjust` | `welcome_offer`),
  note, actor (user id or `chatgpt:<token id>`), created_at. **Balance = SUM(delta).** Never store
  a mutable balance as the source of truth.
- **Package** (session packs, example prices, owner can edit): `single` 1 session R550,
  `five` 5 sessions R2,500, `ten` 10 sessions R4,500. First session 50% off (R275), from his bio.
  In demo, "buying" creates a **pending** pack request; the owner (or ChatGPT) confirms payment,
  which adds the credits. Online card payment (Yoco / PayFast) is a phase-2 option, not built.
- **Booking**: id, user_id, start (UTC ISO), end, status (`confirmed` | `cancelled` | `completed` | `no_show`),
  google_event_id, created_by (`client` | `owner` | `chatgpt`), cancelled_at, cancel_reason.
  A confirmed booking costs 1 credit (ledger row `booking`, delta -1).
  Cancel more than 24 h before start: credit refunded (`cancel_refund`, +1). Inside 24 h: no refund
  unless the owner chooses to refund.
- **Settings**: timezone `Africa/Johannesburg` (UTC+2, no DST), session length 60 min,
  buffer 15 min, slot step 30 min, booking window 28 days ahead, minimum notice 12 h,
  working hours Mon–Fri 06:00–19:00, Sat 07:00–12:00, Sun closed.
- **Availability** = working hours − Google Calendar busy blocks − confirmed bookings (with buffer)
  − times inside minimum notice. Slot start times on the 30-minute step. A slot is free only if the
  whole session + buffer fits.
- **Google Calendar** (live mode): owner connects once with OAuth. Busy blocks read with
  `freeBusy.query`. Each booking is written as an event. Demo mode simulates a connected calendar
  with seeded busy blocks labelled "Busy (Google Calendar)".

## 3. ChatGPT connector (MCP) tools

One list, same names, same input schemas in `web/src/domain/mcpTools.ts` (demo simulation) and
`server/src/Mcp/Tools.php` (real server). Scopes in brackets.

| Tool | Kind | Scope | Input |
| --- | --- | --- | --- |
| `get_schedule` | read | bookings:read | `date` (YYYY-MM-DD, default today) |
| `list_bookings` | read | bookings:read | `from`, `to` (dates), `status?` |
| `find_free_slots` | read | bookings:read | `from`, `to` (dates, max 14 days) |
| `search_clients` | read | clients:read | `query` (min 2 chars) |
| `get_client` | read | clients:read | `client_id` |
| `get_business_summary` | read | bookings:read | `period` (`week` \| `month`) |
| `add_session_credits` | write | credits:write | `client_id`, `sessions` (1–20), `reason`, `payment_reference?`, `idempotency_key` |
| `remove_session_credits` | write, destructive | credits:write | `client_id`, `sessions` (1–20), `reason`, `idempotency_key` |
| `create_booking` | write | bookings:write | `client_id`, `start` (local ISO), `idempotency_key` |
| `cancel_booking` | write, destructive | bookings:write | `booking_id`, `refund_credit` (bool), `reason` |
| `reschedule_booking` | write | bookings:write | `booking_id`, `new_start` |

Annotations: read tools `readOnlyHint: true`; write tools `readOnlyHint: false`;
`destructiveHint: true` only on remove/cancel; all `openWorldHint: false`.
Tool output: short JSON plus a one-line `summary` text. Never return password hashes, tokens,
TOTP secrets, or other clients' data that the call did not ask for. Client free-text (names, notes)
is returned as data inside JSON, never concatenated into instructions.

## 4. Security rules (server)

See `docs/SECURITY.md` for the threat model. Non-negotiable:

1. Passwords: `password_hash(PASSWORD_ARGON2ID)` (fall back to `PASSWORD_DEFAULT`). Login rate
   limit per IP and per email; generic error messages.
2. Web sessions: HttpOnly, Secure, SameSite=Lax cookie; session id regenerated on login;
   CSRF token on every state-changing request (double-submit header `X-CSRF-Token`).
3. All SQL through PDO prepared statements. No string-built SQL with user input.
4. Owner account requires TOTP 2FA (RFC 6238) for web login AND for approving the ChatGPT connector.
5. OAuth 2.1 authorization server for the connector: authorization code + PKCE S256 only,
   dynamic client registration (RFC 7591) AND Client ID Metadata Documents (client_id is an
   https URL, e.g. `https://chatgpt.com/oauth/client.json`, fetched with an SSRF guard: https only,
   host allow-list `chatgpt.com`/`openai.com`, 5 s timeout, 64 KB cap). Redirect URIs restricted to an
   allow-list (`https://chatgpt.com/connector_platform_oauth_redirect` and
   `https://chatgpt.com/connector/oauth/*`, configurable), exact match at authorize time, `state` passed
   through, `iss` returned on the redirect (RFC 9207), `resource` (RFC 8707) accepted on authorize and
   token and must equal our `/mcp` URL. Metadata at `/.well-known/oauth-protected-resource` (RFC 9728)
   and `/.well-known/oauth-authorization-server` (RFC 8414). `/mcp` 401 responses carry
   `WWW-Authenticate: Bearer resource_metadata="https://<domain>/.well-known/oauth-protected-resource"`;
   missing scope gives 403 `insufficient_scope`. Consent screen lists scopes with checkboxes; only the
   **owner**, after password + TOTP, can approve.
   MCP transport: Streamable HTTP, POST only (GET returns 405), `application/json` replies only,
   notifications return 202. Support protocol versions `2025-06-18`, `2025-11-25` and `2026-07-28`
   (answer `initialize` if sent; also work stateless without it). Research: `research/chatgpt-mcp.md`.
6. Tokens are opaque random 32 bytes (base64url), stored only as SHA-256 hashes. Access token
   1 hour, refresh token 30 days, rotated on each use; reuse of a used refresh token revokes the
   whole grant. Tokens are bound to the resource (`https://<domain>/mcp`), audience checked.
7. `/mcp`: bearer token required, scope checked per tool, Origin header validated, per-token rate
   limit (60 calls/min, 20 writes/hour), JSON-RPC body size limit 64 KB, every call written to
   `audit_log` (tool, args hash + redacted args, result status, token id, IP).
8. Write tools need an `idempotency_key`; the same key returns the first result and does not apply
   twice. Credit changes are capped (1–20 per call, 40 per day via ChatGPT) and need a reason.
   New connections are gated by an owner-opened 15-minute **pairing window** (`POST /api/admin/connector/pairing`),
   single use; see `docs/SECURITY.md`. Payment references are once-only forever (`payment_references`, migration 002).
9. Owner admin page lists connector grants with **Revoke** and shows the audit log.
10. Security headers on every response: CSP, X-Content-Type-Options, Referrer-Policy,
    frame-ancestors 'none' (except none needed), HSTS (live only).
11. Secrets (DB password, Google client secret, encryption key) only in `server/config.php`,
    which is gitignored. `config.example.php` is committed. Google refresh token encrypted at rest
    with libsodium `secretbox`.

## 5. Visual design system

The brand (from Instagram @the.stretcheffect.cpt): black, deep red, silver-white. A faceted,
low-poly silver figure (practitioner stretching a client's leg). Wordmark "THE / STRETCH / EFFECT":
"STRETCH" in thin, wide Roman capitals; "EFFECT" small, red, widely tracked, between thin red rules.
Posters use heavy condensed capitals ("PROFESSIONAL" white, "ASSISTED STRETCHING" red), red brush
strokes, dark vignettes. Taglines: "Get stretched. Move better. Live better." and
"Move better. Recover faster. Feel stronger."

**Fonts** (Google Fonts): `Anton` (display, uppercase only), `Cinzel` (wordmark, eyebrows,
small tracked labels, 400–600), `Barlow` (body and UI, 400/500/600). Numbers in the UI use
`Barlow` with `font-variant-numeric: tabular-nums`.

**Tokens** live in `web/src/styles/tokens.css`. Use them; do not add new raw hex values in components.

| Token | Value | Use |
| --- | --- | --- |
| `--ink` | `#0a0a0b` | page background |
| `--panel` | `#131315` | cards, panels |
| `--panel-2` | `#1b1b1e` | raised, hover |
| `--line` | `#2a2a2f` | borders, rules |
| `--red` | `#c8102e` | brand red: primary buttons, key words, active states |
| `--red-hi` | `#e01a36` | hover of red |
| `--red-deep` | `#7d0a1b` | glows, pressed |
| `--silver` | `#c9ccd2` | logo, secondary text on dark |
| `--white` | `#f4f4f2` | primary text |
| `--muted` | `#8d8d95` | tertiary text |
| `--ok` | `#3fa66b` | success (sparingly) |
| `--warn` | `#d9a23a` | pending states |

Radius: 4px for buttons and inputs, 6px for cards. The brand is sharp; never pill-shaped cards.
Buttons may be 2px radius. Spacing scale: 4, 8, 12, 16, 24, 32, 48, 64, 96.

**Banned (AI-slop tells)**: purple/blue gradients, glassmorphism, emoji as icons, rounded-3xl pastel
cards, "Elevate your wellness journey" copy, Inter as the only font, fake testimonials with names or
faces, invented stats ("500+ happy clients"), stock-photo people, generic 3-column icon grids with
lorem copy, centered everything, drop shadows on everything, gradient text.

**Motion**: subtle and fast (150–250 ms, ease-out). Slots and cards fade/slide 8px. The credits
counter counts up once. Respect `prefers-reduced-motion` (no movement, only opacity).

**Icons**: inline SVG, 1.5px stroke, square caps, in `web/src/components/Icon.tsx`. No icon fonts.

**Accessibility**: WCAG AA contrast (red on black only for large text/UI accents, not body text),
visible focus ring (2px `--red-hi` outline, 2px offset), labels on all inputs, keyboard-usable
slot picker, 44px minimum touch targets on mobile.

## 6. Claims policy (copy)

- Use only the owner's own phrases and plain facts: assisted stretch therapy, Brackenfell, Cape Town,
  50% off your first session, WhatsApp booking, home visits available on request.
- No medical claims (no "cure", "treat", "heal injuries"). Say "may help", "designed to".
- Prices are **example prices**; label them on the packages section ("Example pricing for the demo").
- No testimonials. No client photos. No invented numbers.
- All clients, bookings and emails in demo data are synthetic (`@example.com`).
- A thin banner on every page: "Demo: bookings are not real. Built by Obel AI for The Stretch Effect."
- `<meta name="robots" content="noindex">` on the demo build.

## 7. File ownership (parallel build)

| Owner | Files |
| --- | --- |
| Orchestrator | `docs/*`, `web/src/styles/*`, `web/src/components/Logo.tsx`, `web/src/components/Shell.tsx`, `web/src/pages/Landing.tsx`, `web/src/data/api.ts`, `web/src/domain/types.ts`, `server/migrations/*`, `server/src/Contracts/*` |
| Agent: web-portal | `web/src/data/demoApi.ts`, `web/src/domain/slots.ts`, `web/src/domain/credits.ts`, `web/src/data/seed.ts`, `web/src/pages/auth/*`, `web/src/pages/client/*`, `web/src/components/*` (except Logo, Shell) |
| Agent: web-admin | `web/src/pages/admin/*`, `web/src/pages/GoLive.tsx`, `web/src/domain/mcpTools.ts`, `web/src/data/httpApi.ts` |
| Agent: server-core | `server/public/*`, `server/src/*` except `OAuth/` and `Mcp/`, `server/tests/Core*`, `server/config.example.php` |
| Agent: server-mcp | `server/src/OAuth/*`, `server/src/Mcp/*`, `server/tests/OAuth*`, `server/tests/Mcp*`, `server/tests/e2e/*`, `docs/CHATGPT-CONNECTOR.md` |
