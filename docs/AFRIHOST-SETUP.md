# Going live on Afrihost

This guide takes the demo to a live site on the owner's own Afrihost hosting and domain.
Costs are in [COSTS.md](COSTS.md). The technical deploy detail is in
[server/README.md](../server/README.md#deploy-on-afrihost-cpanel--cwp).

## 1. What the owner does (about 20 minutes)

1. Buy **Linux shared hosting, Platinum** (R109 per month) on afrihost.com.
2. Choose the free `.co.za` domain with it, for example `thestretcheffect.co.za`.
   Check that the name is free on the Afrihost domain search first.
3. Give us access. Two options:
   - Preferred: add us as a delegated user in Afrihost ClientZone, if the account allows it.
   - Otherwise: share the control panel (CWP or cPanel) login through a password manager.
     Do not send passwords by WhatsApp or email.
4. Decide the real prices, packs, cancellation rule and working hours. The demo values are examples.
5. Send the original logo files (SVG or high-resolution PNG). The demo uses a redrawn mark.

## 2. What we do (about 1 day, plus a test week)

### Day 1: set up

1. **SSL.** In the control panel: Domains → AutoSSL → Install for the domain and `www`.
2. **PHP.** Select PHP 8.2 or later for the domain. Check that `sodium`, `curl`,
   `pdo_mysql`, `mbstring` and `openssl` are on.
3. **Database.** Create a MySQL database and user. Use a long random password.
4. **Upload the backend.** Upload `server/` to a folder **outside** `public_html`, without
   `vendor/`, `tests/` and `.phpunit.cache`. Copy `config.example.php` to `config.php` and fill in
   the values. Set `app.env` to `live`. Generate `app.key` with
   `php -r 'echo base64_encode(random_bytes(32));'`. Set `config.php` to `chmod 600`.
5. **Build and upload the web app.** Run `VITE_API_MODE=http npm run build` in `web/`.
   Upload the contents of `web/dist` to `public_html/`. Copy `server/deploy/app.php` and
   `server/deploy/public_html.htaccess` (as `.htaccess`) into `public_html/`.
6. **Create the tables and the owner account.** Run `php bin/migrate.php`. Then run
   `php bin/create-owner.php` interactively. The owner scans the QR code (the printed
   `otpauth://` link) with an authenticator app, for example Google Authenticator or Microsoft
   Authenticator. If the host has no SSH, run these once as cron jobs and delete the jobs after.
   Do not put the owner password in a cron command. Create the owner with a temporary password
   and change it at the first sign-in.
7. **Cron.** Add one job every 10 minutes: `php /home/USER/stretch-effect/server/bin/cron.php`.
   It syncs Google Calendar busy times and cleans old tokens and rate-limit rows.
8. **Email.** Create a free account with a transactional email provider (for example Brevo).
   Put its SMTP details in `config.php` under `mail`. Afrihost does not include SMTP sending
   by default on shared hosting.

### Day 1: connect Google Calendar

1. In Google Cloud Console, create a project, for example "The Stretch Effect Bookings".
2. Turn on the **Google Calendar API**.
3. OAuth consent screen: type External, app name "The Stretch Effect", the owner's email,
   the site's home page and privacy page. Add the scopes
   `calendar.events.owned` and `calendar.freebusy`.
4. Set the publishing status to **In production**. Do not leave it in "Testing": in Testing,
   Google expires the connection after 7 days. The app does not need Google verification,
   because only the owner signs in. He sees an "unverified app" warning once and accepts it.
5. Create an OAuth client (type Web application). Redirect URI:
   `https://<domain>/api/admin/calendar/callback`. Put the ID and secret in `config.php`.
6. The owner signs in to the site, opens **Calendar** and clicks **Connect Google Calendar**.

Alternative: share the calendar with a Google service account ("Make changes to events").
This avoids the consent screen completely. See `server/README.md`. Test it before you rely on it.

### Day 1: connect ChatGPT (optional)

Follow [CHATGPT-CONNECTOR.md](CHATGPT-CONNECTOR.md). In short: in ChatGPT, add a custom MCP
connector with the URL `https://<domain>/mcp`. Sign in as the owner with password and
authenticator code. Choose the permissions.

## 3. The test week (must pass before launch)

Shared hosting has some unknowns that only a real account shows. Test each item:

| Test | How | Pass |
| --- | --- | --- |
| Authorization header reaches PHP | `curl -i -X POST -H "Authorization: Bearer x" https://<domain>/mcp` | 401 JSON with a `WWW-Authenticate` header that names `invalid_token` |
| ModSecurity accepts JSON POSTs | Sign up, book and cancel on the live site | No 403 or 406 pages |
| ChatGPT can reach the site from overseas | Connect ChatGPT and ask "Who's booked today?" | A correct answer |
| Outbound calls to Google | Connect Google Calendar, add a busy block, check the free times | The busy block removes those times |
| Cron runs | Check `server/var/` logs and the "last sync" time on the Calendar page | Updates every 10 minutes |
| Email | Make a test booking | Confirmation email arrives, not in spam |
| Full flow | Run `server/tests/e2e/oauth_mcp_e2e.php` against the live domain with a test owner | `ALL PASSED` |

If a test fails, ask Afrihost support first (support@afrihost.com). The usual fixes are a
ModSecurity rule exception or `CGIPassAuth On`. If it still fails, move to the Afrihost
Bronze cloud server (see COSTS.md).

## 4. After launch

- **Backups.** Add a daily cron job that dumps the database to a file outside `public_html`
  and keep 14 days. Download a copy each month.
- **Updates.** We deploy updates by uploading the changed files. The database changes run with
  `bin/migrate.php`.
- **Security.** The owner can revoke the ChatGPT connection at any time on the ChatGPT page.
  To turn the connector off completely, set `connector.enabled` to `false` in `config.php`.
- **POPIA.** The site stores client names, emails, phone numbers and booking history. Publish a
  short privacy notice that says what is stored and why. The signup form asks for consent.
