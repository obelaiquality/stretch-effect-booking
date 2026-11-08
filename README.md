# The Stretch Effect: booking system

A booking system for **The Stretch Effect**, assisted stretch therapy by Ivan Bond in
Brackenfell, Cape Town. Built by Obel AI as a working demo of what the owner can run on his
own Afrihost hosting and domain.

**Live demo:** https://obelaiquality.github.io/stretch-effect-booking/
The demo runs fully in the browser with synthetic clients. Nothing is sent anywhere.

| Try | How |
| --- | --- |
| Client | Sign in → "Try as a client (Thandi)" → password `stretch123` |
| Owner | Sign in → "Try as the owner (Ivan)" → password `stretch123` → use the demo authenticator code on screen |
| Reset | "Reset demo data" in the red bar at the top |

## What it does

**Clients**
- Create an account (POPIA consent), sign in.
- See how many sessions they have left, their next booking and their history.
- Request a session pack (single, 5 or 10). The owner confirms payment and the sessions load.
- See free times that come from the owner's working hours **minus his Google Calendar**,
  and book in two taps. Each booking uses one session and goes into his calendar.
- Cancel up to 24 hours before for a refund of the session.

**Owner (Ivan)**
- Sign in with password **and** an authenticator app code.
- Today's schedule, the next 7 days, pending payments, low-balance clients.
- Client list and client pages: add or remove sessions, book for a client, cancel, mark
  completed or no-show, full session history (who changed what).
- Google Calendar connection, working hours, session length, buffer, notice and cancel rules.
- **ChatGPT connector**: connect his own ChatGPT to the studio and ask in plain words
  ("Who's booked today?", "Thandi paid for a 5-pack, load it"). See the security design below.

## How it is built

| Part | Folder | Stack |
| --- | --- | --- |
| Web app | `web/` | React 18 + TypeScript + Vite. `VITE_API_MODE=demo` (browser data, GitHub Pages) or `http` (real backend) |
| Backend | `server/` | PHP 8.2+ and MySQL, no framework, no runtime dependencies. Runs on Afrihost shared hosting |
| ChatGPT connector | `server/src/OAuth`, `server/src/Mcp` | Remote MCP server (Streamable HTTP, JSON) with its own OAuth 2.1 authorization server |

Docs:
- [docs/BUILD-SPEC.md](docs/BUILD-SPEC.md): domain rules, design system, security rules
- [docs/COSTS.md](docs/COSTS.md): what going live costs
- [docs/AFRIHOST-SETUP.md](docs/AFRIHOST-SETUP.md): step-by-step setup on Afrihost
- [docs/SECURITY.md](docs/SECURITY.md): ChatGPT connector threat model and controls
- [docs/CHATGPT-CONNECTOR.md](docs/CHATGPT-CONNECTOR.md): how the owner connects ChatGPT
- [docs/API.md](docs/API.md): REST endpoints used by the web app

## ChatGPT connector security, in short

- Only the owner can connect it: password + authenticator code on a consent screen, with
  per-permission checkboxes (see the diary, book and cancel, look up clients, add or remove sessions).
- OAuth 2.1 with PKCE (S256 only), exact redirect allow-list for ChatGPT, short-lived opaque
  tokens stored only as hashes, refresh-token rotation with reuse detection.
- Every tool checks its permission. Writes need an idempotency key, credit changes are capped
  (1 to 20 per call, 40 per day) and need a reason. Cancels and removals are marked destructive,
  so ChatGPT asks for confirmation.
- Rate limits, request size limits, Origin checks, an audit log of every call, and a Revoke
  button (plus a config kill switch) that cuts access at once.
- Client-entered text is returned as data, and the server tells the model to treat it as data,
  not instructions.

## Run locally

```bash
cd web && npm install && npm run dev        # demo mode at http://localhost:5180
cd web && npx vitest run                    # web unit tests
cd server && composer install && vendor/bin/phpunit   # backend tests (SQLite)
```

See [server/README.md](server/README.md) for running the PHP backend locally.
