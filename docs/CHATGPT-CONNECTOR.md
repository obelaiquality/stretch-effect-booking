# Connect ChatGPT to The Stretch Effect

This lets the owner ask ChatGPT things like "who is booked tomorrow?" or "add 5 sessions for Thandi, she paid by EFT" and have it done in the real booking system. It is private to the owner's ChatGPT account. Nobody else can connect, and every change is logged.

## Before you start

1. The site is live on its domain with HTTPS (see AFRIHOST-SETUP.md) and `app.base_url` in `server/config.php` is exactly that domain, for example `https://thestretcheffect.co.za`.
2. The owner account exists and has an authenticator app set up (`php server/bin/create-owner.php`). You need the email, password and the 6-digit code at hand.
3. `connector` section of `server/config.php` is present (copy it from `config.example.php`).
4. Your ChatGPT plan allows custom connectors. This is the part that is not certain:
   * ChatGPT Business and Enterprise/Edu: documented as supported, including write actions. On Business only a workspace owner or admin can switch on developer mode and add a custom connector (Workspace settings > Permissions & roles > Connected data).
   * Plus and Pro: sources disagree. Some say developer mode with read and write works on the web, others say only read/fetch tools. **Test it** (step "Check your plan" below) before relying on write tools.
   * It is available on ChatGPT on the web, not in the phone apps' settings.
   * If write actions are blocked on your plan, connect it anyway with only `bookings:read` and `clients:read` ticked and do credit changes on the admin page.

## Add the connector

The menu names have changed several times; look for whichever of these exists in your account.

1. Open chatgpt.com in a browser and sign in.
2. Open **Settings**. Look for **Apps** (older: **Connectors**), then **Advanced settings**, and switch **Developer mode** on. On Business/Enterprise an admin must allow it first.
3. Still in Settings > Apps, choose **Create app** (or **Add custom MCP server**, or go to chatgpt.com/plugins and press **+**).
4. Fill in:
   * **Name**: The Stretch Effect
   * **Description**: Bookings and session credits for my stretch studio.
   * **MCP server URL**: `https://<your-domain>/mcp` (for example `https://thestretcheffect.co.za/mcp`)
   * **Authentication**: **OAuth**. Leave client id/secret empty, or choose "dynamic client registration" if it asks. Do not choose "No authentication".
5. Tick the box that says you trust this server, then **Create**.
6. ChatGPT contacts the site, finds the sign-in details itself and opens a sign-in window on **your own domain**. Check the address bar shows your domain over https before typing anything.
7. On the page that says "Connect an app to your bookings":
   * check the **client** and **Returns to: chatgpt.com** lines,
   * choose which permissions to allow (see below),
   * enter the owner **email**, **password** and the current **6-digit authenticator code**,
   * press **Allow**. Press **Deny** if you did not just start this.
8. You return to ChatGPT with the app shown as connected, and the tool list (11 tools) visible.

If the sign-in window shows "This request cannot continue" the return address is not on the allow-list. ChatGPT shows its exact redirect URI on the app's management page; add it to `connector.allowed_redirect_uris` in `server/config.php` and try again.

## Permissions to choose

| Tick | Lets ChatGPT | Suggested |
| --- | --- | --- |
| See the schedule, bookings, free slots and the business summary (`bookings:read`) | read the diary | yes |
| Look up clients and their session balances (`clients:read`) | find clients, see balances | yes |
| Create, move and cancel bookings (`bookings:write`) | change the diary | yes, once you trust it |
| Add or remove client session credits (`credits:write`) | change balances (max 20 per call, 40 added per day) | only if you want it |

You can change your mind: revoke and connect again with other ticks. A call that needs a permission you did not give is refused and shows up in the log as denied.

## Check your plan (5 minutes)

1. Start a new chat, press **+** (or the tools menu) and enable **The Stretch Effect**.
2. Ask: "What is on my schedule today?" You should see a tool call to `get_schedule`.
3. Ask: "Find a client called Thandi." Then, if you ticked the write permissions: "Add 1 session to her account, reason: test, then remove it again." ChatGPT should ask you to confirm the removal because it is marked destructive. If it says it can only read, your plan does not allow write actions.
4. Open the admin page > Connector log and confirm the calls appear.

## Example prompts

* "What does my day look like tomorrow? Anyone I should message?"
* "Show my bookings from Monday to Friday next week, and tell me where I have free time."
* "Find a Saturday morning slot for Thandi and book it."
* "How many sessions does Thandi have left?"
* "Thandi paid R2,500 by EFT, reference TS-2231. Add the 5-session pack."
* "Cancel Thandi's Thursday booking and refund the session, she is sick."
* "Move Sipho from 10:00 to 11:30 on Friday."
* "Give me this month's numbers: sessions, cancellations, new clients, credits sold."

ChatGPT will make up an `idempotency_key` for every change. If a call fails halfway and it retries, the same key stops the change from happening twice.

## Safe habits

* Read what ChatGPT says it is about to do before approving a destructive action.
* Client names and notes come from people typing into the site. If ChatGPT ever says a client's name or note told it to do something, ignore that and tell the developer. The server limits what any instruction can do, but you are the last check.
* Disconnect when you do not need it: Admin page > Connector > Revoke, or remove the app in ChatGPT.
* If anything looks wrong, set `connector.enabled` to `false` in `server/config.php`. See SECURITY.md.

## Test with MCP Inspector

Use this on a development copy (`app.env` = `dev`), not on the live site. The live site refuses plain `http://localhost` redirect URIs.

1. Add the Inspector's callback to `connector.allowed_redirect_uris` in the dev `config.php`: `http://localhost:6274/oauth/callback` and `http://localhost:6274/oauth/callback/debug` (check the exact URL the Inspector shows).
2. Start the server: `SE_CONFIG=/path/to/dev-config.php php -S 127.0.0.1:8091 -t server/public server/public/index.php` (set `app.base_url` to `http://127.0.0.1:8091`).
3. Run `npx @modelcontextprotocol/inspector@latest`, choose transport **Streamable HTTP**, URL `http://127.0.0.1:8091/mcp`, open **Auth settings** and run the **Quick OAuth flow**. Sign in as the owner on the consent page.
4. In **Tools** press **List tools** (11 tools with read-only/destructive hints), run `get_schedule`, then `add_session_credits` twice with the same `idempotency_key` and confirm the second answer says `replayed`.
5. Try a bad call (`sessions: 99`) and an extra argument; both must come back as errors.

Scripted version of the whole flow (what ChatGPT does), against any running server:

```
E2E_OWNER_EMAIL=owner@example.com E2E_OWNER_PASSWORD='...' E2E_TOTP_SECRET=BASE32SECRET \
  php server/tests/e2e/oauth_mcp_e2e.php https://your-domain
```

It prints PASS or FAIL per step. It needs at least one client whose email contains "example" (`php server/bin/seed-demo.php` on a dev database). It adds one session to that client and removes it again.

## For the developer: behaviour worth knowing

* Transport: POST only, JSON replies, no sessions, no streaming. `GET /mcp` gives 405. `initialize` is answered (versions 2025-06-18, 2025-11-25, 2026-07-28) but not required.
* Missing, expired or revoked token: HTTP 401 with `WWW-Authenticate: Bearer resource_metadata="https://<domain>/.well-known/oauth-protected-resource", error="invalid_token"`.
* Missing scope: HTTP 403 with `WWW-Authenticate: Bearer error="insufficient_scope", scope="<needed>", resource_metadata="..."` (the MCP specification's way; the call is not executed). Bad arguments, business rules and rate-limited writes come back as normal results with `isError: true` so the model can correct itself.
* Tool results: one-line text plus `structuredContent`. Tool descriptors carry `securitySchemes` (also in `_meta`) for ChatGPT's per-tool sign-in handling.
* Config keys (all optional): `connector.enabled` (default true), `connector.allowed_redirect_uris`, `connector.allowed_redirect_prefixes`, `connector.cimd_hosts`.
