# Server (PHP 8.2+, MySQL or SQLite)

No framework, no Composer packages at runtime. Upload the `server/` folder as is. Composer is only used locally for PHPUnit.
Needs PHP extensions `pdo_mysql`, `sodium`, `curl`, `mbstring` (all standard on Afrihost).

## Run locally

```bash
cd server
composer install                                   # dev only (PHPUnit)
cp config.example.php config.php                   # then edit: env=dev, db.dsn=sqlite:/abs/path/server/var/dev.sqlite, app.key
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'   # paste into app.key
php bin/migrate.php                                # applies migrations/<mysql|sqlite>/*.sql, tracked in schema_migrations
php bin/create-owner.php                           # owner account + TOTP secret / otpauth:// URI
php bin/seed-demo.php [--with-owner]               # synthetic @example.com data, refuses when env=live
php -S 127.0.0.1:8090 -t public public/index.php   # API on http://127.0.0.1:8090/api/...
vendor/bin/phpunit                                 # all tests (Core*, OAuth*, Mcp*)
```

`SE_CONFIG=/path/to/other-config.php` overrides the config file for the web entry point and all `bin/` scripts.
In dev, e-mails go to `var/mail.log`. Errors go to `var/error.log`. Sessions live in `var/sessions/`.

## Layout

```
public/index.php, .htaccess   front controller (only folder that may be web-reachable)
src/                          StretchEffect\ (own PSR-4 autoloader in src/bootstrap.php)
  App.php                     service container, App::fromConfig(array $config, array $options)
  Api/Router.php              every /api/* endpoint
  Services/                   Auth, Booking, Credit, Client, Audit, RateLimiter, Settings, Grant, Maintenance, Totp
  Google/                     plain-cURL Google Calendar client + HttpTransport interface
  Mail/                       NullMailer (dev log), NativeMailer (mail()), SmtpMailer, Notifier
  OAuth/, Mcp/                ChatGPT connector (separate owner)
bin/                          migrate, create-owner, seed-demo, cron
deploy/                       files for public_html on cPanel/CWP
tests/                        PHPUnit (SQLite in memory, frozen clock, fake Google)
```

## API

Errors: `{"error":{"code","message"}}` with `DomainError::httpStatus()`. All non-GET requests under `/api` need the header
`X-CSRF-Token` (value from `GET /api/csrf`, tied to the session cookie). Arrays are returned bare, objects where noted.

| Endpoint | Returns |
| --- | --- |
| `GET /api/csrf` | `{token}` |
| `GET /api/me` | `{user: User or null}` |
| `POST /api/auth/signup` `{name,email,phone?,password(min 10),consent:true}` | `{user}` 201, signed in |
| `POST /api/auth/login` `{email,password}` | `{user}` or `{needsTotp:true}` (owner) |
| `POST /api/auth/totp` `{code}` / `POST /api/auth/logout` | `{user}` / `{ok:true}` |
| `GET /api/packages`, `GET /api/settings` | `Package[]`, `Settings` |
| `GET /api/availability?from=&to=` (YYYY-MM-DD, max 31 days) | `Slot[]` |
| `GET /api/me/balance` / `ledger` / `bookings` / `packs` | `{balance}` / `LedgerEntry[]` / `Booking[]` / `PackRequest[]` |
| `POST /api/me/packs` `{packageId}` | `PackRequest` (pending) |
| `POST /api/me/bookings` `{start}` | `Booking` 201 |
| `POST /api/me/bookings/{id}/cancel` | `{booking, refunded}` |
| `GET /api/admin/clients?q=` | `ClientSummary[]` |
| `GET /api/admin/clients/{id}` | `{summary, ledger, bookings, packs}` |
| `GET /api/admin/bookings?from=&to=`, `GET /api/admin/busy?from=&to=` | `Booking[]`, `BusyBlock[]` |
| `POST /api/admin/clients/{id}/credits` `{delta,note}` | `LedgerEntry` |
| `POST /api/admin/packs/{id}/paid` `{paymentReference?}` | `PackRequest` |
| `POST /api/admin/bookings` `{userId,start}` | `Booking` (owner bypasses minimum notice only) |
| `POST /api/admin/bookings/{id}/cancel` `{refund:bool,reason?}` | `Booking` (with `refunded`) |
| `POST /api/admin/bookings/{id}/status` `{status: completed or no_show}` | `Booking` |
| `PATCH /api/admin/settings` (partial `Settings`) | `Settings` |
| `GET /api/admin/calendar`, `POST .../connect`, `POST .../disconnect` | `CalendarConnection`, `{redirectUrl, connection}`, `CalendarConnection` |
| `GET /api/admin/calendar/callback` | 302 to `/#/admin/calendar` (Google redirects here) |
| `GET /api/admin/summary?period=week or month` | `BusinessSummary` |
| `GET /api/admin/grants`, `POST /api/admin/grants/{id}/revoke`, `GET /api/admin/audit?limit=` | `ConnectorGrant[]`, `{ok:true}`, `AuditEntry[]` |
| `POST /api/cron/sync` (header `X-Cron-Token`, no CSRF) | `{ok, calendar, synced, cleaned}` |

Owner endpoints need role `owner` and a completed TOTP step. A password-only owner session has no user (`/api/me` returns null).

## Booking rules

Availability = working hours (Africa/Johannesburg) - cached Google busy - confirmed bookings padded by the buffer - minimum notice - booking window.
A slot occupies session + buffer; the slot must end inside working hours. Create runs in one transaction (MySQL: lock row in `settings`
and `SELECT ... FOR UPDATE`; SQLite: `BEGIN IMMEDIATE`), re-checks the slot, re-checks Google `freeBusy` live when connected, needs a credit,
inserts the booking and the ledger row (-1), then the Google event (deterministic id `se<bookingId>`, `extendedProperties.private.bookingId`).
If the event insert fails, the whole booking rolls back. Cancel: more than `cancelFreeHours` before the start refunds unless `refund === false`;
inside that window it refunds only if `refund === true`.

## Google Calendar

Implemented: OAuth web flow for the owner (scopes `calendar.events.owned` and `calendar.freebusy`, `access_type=offline`, `prompt=consent`, state bound to the
session). The refresh token is stored encrypted (libsodium secretbox, key `app.key`) in `settings.google`. Create a "Web application" OAuth client in
Google Cloud Console, add `<base_url>/api/admin/calendar/callback` as authorised redirect URI, put id/secret in `config.php`, publish the consent screen
("In production") so refresh tokens do not expire after 7 days, then press Connect in the admin area.
Busy blocks are cached in `calendar_busy` by cron (`bin/cron.php` or `POST /api/cron/sync`) and lazily when older than 2 minutes.

Alternative (not implemented): a service account. Create a service account, share Ivan's calendar with its e-mail ("Make changes to events"), sign a JWT
(RS256) with the key file and exchange it at the token endpoint for an access token. Only `GoogleCalendar::accessToken()` would change; no refresh token
or consent screen is needed, so there is no 7-day expiry. Keep the key file outside `public_html`.

## Cron

```
*/10 * * * * /usr/bin/php /home/USER/stretch-effect/server/bin/cron.php >/dev/null 2>&1
```
(or `curl -s -X POST -H "X-Cron-Token: ..." https://your-domain/api/cron/sync`). Syncs Google busy blocks and deletes expired OAuth codes/tokens,
rate-limit rows, idempotency keys older than 7 days.

## Deploy on Afrihost (cPanel / CWP)

```
/home/USER/stretch-effect/server/        <- upload server/ here, OUTSIDE public_html (config.php, var/, migrations/, src/ stay private)
/home/USER/public_html/                  <- contents of web/dist (built with VITE_API_MODE=http)
/home/USER/public_html/app.php           <- copy of server/deploy/app.php (set $serverDir)
/home/USER/public_html/.htaccess         <- copy of server/deploy/public_html.htaccess
```
1. Create the MySQL database and user in the control panel. Put the DSN in `config.php`: `mysql:host=localhost;dbname=USER_stretch;charset=utf8mb4`.
2. Upload `server/` (without `vendor/`, `tests/`, `.phpunit.cache`). Copy `config.example.php` to `config.php`, set `app.env=live`, `app.base_url`, `app.key`, DB, Google, cron token. `chmod 600 config.php`; make `var/` writable.
3. Run `php bin/migrate.php` and `php bin/create-owner.php` over SSH, or as a one-time cron job (`php /home/USER/stretch-effect/server/bin/migrate.php`) if SSH is not available. `create-owner.php` also takes `--name= --email= --password=` for non-interactive use.
4. Choose PHP 8.2+ for the domain in the PHP selector and make sure `sodium`, `curl`, `pdo_mysql` are enabled.
5. `public_html/.htaccess` serves the SPA (history fallback to index.html) and forwards `/api`, `/oauth`, `/mcp` and `/.well-known/oauth-*` to `app.php`, which requires `server/public/index.php`. It also forwards the `Authorization` header (needed by the ChatGPT connector; test it with `curl -H "Authorization: Bearer x" https://domain/mcp`, expect a 401 JSON with `WWW-Authenticate`).
6. The front controller also works when `server/public` is the document root of a sub-domain (use `server/public/.htaccess`).

Not trusted: `X-Forwarded-For` (client IP for rate limits is `REMOTE_ADDR`). If the host puts a proxy in front, adjust `RequestFactory`.
