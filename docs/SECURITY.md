# The Stretch Effect: connector security

Scope: the OAuth 2.1 authorization server (`server/src/OAuth/`) and the remote MCP server (`server/src/Mcp/`) that let the owner manage bookings and client session credits from ChatGPT. Web-app login and the `/api` routes are covered by `BUILD-SPEC.md` section 4 and server-core.

Principle: ChatGPT cannot use API keys or static tokens, so the only way in is OAuth. The only person who can approve a connection is the owner, with password plus authenticator code, on a page served by this app. Everything a connection can do is limited by scopes, caps and rate limits that the server enforces, whatever the model asks for.

## What a connection can do

| Scope | Tools |
| --- | --- |
| `bookings:read` | get_schedule, list_bookings, find_free_slots, get_business_summary |
| `clients:read` | search_clients, get_client |
| `bookings:write` | create_booking, cancel_booking, reschedule_booking |
| `credits:write` | add_session_credits, remove_session_credits |

The owner can untick scopes on the consent page. There is no admin scope, no wildcard, no way to read password hashes, TOTP secrets, tokens or other settings, and no tool that deletes a client or a ledger row.

## Threat model

| Threat | Control |
| --- | --- |
| **Stolen access token** | Opaque 32-byte random token, only its SHA-256 is stored. Lifetime 1 hour. Bound to one grant and to the resource `https://<domain>/mcp` (checked on every call). Revoking the grant kills it at once (every request looks the grant up). Scopes, 20 writes per hour, 60 requests per minute and the 40-credit daily cap limit what a thief could do in the time left. |
| **Stolen refresh token** | Rotated on every use. Presenting an already-used refresh token revokes the whole grant (`refresh_reuse` in the audit log), so a thief and the real client cannot both keep going. Lifetime 30 days, and never beyond 90 days after the owner approved (then the owner approves again). Public client, no secret to leak. |
| **Stolen or replayed authorization code** | Single use, 60 seconds, stored hashed, bound to client, redirect URI, resource and PKCE challenge. PKCE S256 only (`plain` is refused), compared with `hash_equals`. Replaying a used code revokes the grant that was created from it. |
| **Prompt injection through client names, notes, references** | Anyone can type anything into a booking form, so every string from the database is treated as hostile. Free text is stripped of control characters, length-capped and returned only inside `structuredContent` fields; the one-line `content` text never contains names or notes. The server `instructions` tell the model that tool output is data. More importantly the server does not rely on the model: amounts are capped (1-20 per call, 40 added per day), balances cannot go negative, writes need a reason and an idempotency key, are rate limited (20 per hour), are attributed to the connection in the ledger and are audit logged. ChatGPT also asks the owner to confirm destructive tools (`remove_session_credits`, `cancel_booking` are marked `destructiveHint: true`). |
| **Confused deputy / token passthrough** | This server is its own authorization server; it never forwards a ChatGPT token anywhere and never accepts a token issued for another resource (RFC 8707 `resource` must equal `https://<domain>/mcp` at authorize, at token and on every call). Consent is asked on every authorization, never remembered in a cookie, and names the client and the exact redirect host. |
| **CSRF on the consent page** | The consent form carries a random single-use token that is tied to a server-side record of that exact authorization request (client, redirect URI, state, PKCE challenge, resource, scopes). The record expires after 10 minutes, is consumed on approve or deny, and is removed after 5 failed attempts. The page sends `frame-ancestors 'none'`, `X-Frame-Options: DENY`, `no-store` and `Referrer-Policy: no-referrer`, so it cannot be framed (clickjacking) or cached. No session cookie is used or trusted for consent. |
| **Open redirect** | The `redirect_uri` must exactly equal one registered for that client, and must also still be on the configured allow-list (`connector.allowed_redirect_uris` exact, or `connector.allowed_redirect_prefixes` with a strict path pattern). https only (plain http only for localhost on a non-live install), no fragments, no wildcards, no credentials, no dot segments. Until the client and redirect URI are validated the server shows an HTML error and never redirects. Every authorization response, success or error, carries `state` and `iss` (RFC 9207). |
| **SSRF through Client ID Metadata Documents** | The client id URL is fetched only if https, default port, no credentials, and the host is in `connector.cimd_hosts` (default `chatgpt.com`, `openai.com`). DNS is resolved by us; every address must be public (private, loopback, link-local, reserved and IPv4-mapped IPv6 are refused); the connection is pinned to the checked address (no second lookup, so no DNS rebinding); no redirects; 5 second timeout; 64 KB cap; the document's `client_id` must equal the URL; its redirect URIs go through the same allow-list. Results are cached for an hour. |
| **Rogue client registration (DCR)** | Public clients only, redirect URIs restricted to the allow-list, 10 registrations per hour per IP, at most 5 redirect URIs, name sanitized and capped at 100 characters, 500 registrations in total. Registering gives no access: only the owner's consent creates a grant. |
| **Brute force of the consent login** | Password and authenticator code are both required. 5 attempts per 15 minutes per IP, 5 attempts per authorization request, failures give one generic message (the page does not say which part was wrong, or whether the account is the owner), and non-owner accounts can never approve. TOTP codes are single use per time step. The web login limits per IP and email apply on top (AuthService). |
| **Replayed or duplicated writes** | Credit tools and `create_booking` require an `idempotency_key` (8-64 characters). The key is claimed atomically as `<grantId>:<tool>:<key>`; the same key with the same arguments returns the first result (audit status `replayed`), the same key with different arguments is refused. A failed call releases the key. Payment references are unique across ChatGPT calls and pack requests. |
| **Rogue or over-trusting ChatGPT user** | The connection belongs to the owner's account only, because only the owner can approve. Consent is per client, scopes can be narrowed, and the owner sees every call in the audit log. Workspace admins in ChatGPT can additionally restrict who may use developer mode or custom connectors (see CHATGPT-CONNECTOR.md). |
| **Server compromise / SQL injection** | Prepared statements only. Tokens, codes and CSRF values are stored as hashes, so a database read does not give working credentials. Secrets live in `server/config.php` outside the web root's reach (see AFRIHOST-SETUP.md). Errors returned to ChatGPT never contain SQL, paths or stack traces; details go to the server error log without values. |
| **Cross-site requests to /mcp (DNS rebinding, browsers)** | POST only (GET and DELETE give 405), `Content-Type: application/json` required, bearer token required, `Origin` rejected with 403 unless it is this site, `https://chatgpt.com` or `https://chat.openai.com`. No cookies are read, so a browser cannot ride a session. |
| **Resource exhaustion** | Body limit 64 KB, no batches, lists capped (50 bookings, 60 slots, 20 clients), date ranges capped, per-IP and per-grant rate limits, no streaming responses or sessions to hold open. |
| **Insufficient scope / privilege creep** | Each tool names its scope. A call without it returns HTTP 403 with `WWW-Authenticate: Bearer error="insufficient_scope", scope="<needed>", resource_metadata="..."`, is not executed, and is logged as `denied`. |

## Token and code lifetimes

| Item | Lifetime |
| --- | --- |
| Authorization request (consent page) | 10 minutes, single use |
| Authorization code | 60 seconds, single use |
| Access token | 1 hour |
| Refresh token | 30 days, rotated on every use; a grant ends 90 days after approval |
| Client registration (DCR) | Until removed; harmless on its own |
| CIMD cache | 1 hour |

## How to revoke access

1. **One connection**: Admin page > Connector > Revoke. This stamps `oauth_grants.revoked_at`, which invalidates the access and refresh token on the very next call.
2. **From ChatGPT**: disconnect the app in ChatGPT; it calls `/oauth/revoke`, which ends the grant too.
3. **Kill switch** (everything at once): set `connector.enabled` to `false` in `server/config.php`. `/mcp`, `/oauth/*` and the `.well-known/oauth-*` documents then answer `503` and nothing can authorize or call. Set it back to `true` to resume; existing unexpired grants continue to work unless revoked.
4. **Emergency, direct on the database**: `UPDATE oauth_grants SET revoked_at = UTC_TIMESTAMP() WHERE revoked_at IS NULL;`
5. Change the owner password and re-enrol the authenticator if the owner credentials may be known.

## What is logged

Every connector event writes one `audit_log` row: time, grant id, actor (`chatgpt:<grantId>` for tool calls, `owner:<id>` for approvals, `anonymous` before sign-in), action, status (`ok`, `denied`, `error`, `rate_limited`, `replayed`), a one-line summary and the client IP.

* Tool calls: tool name and redacted arguments. Ids, numbers, booleans, dates and enum values are kept; free text (reasons, queries, payment references) is cut to 40 characters; nested values are dropped; a short hash of the full arguments is added so repeats can be spotted. Results are summarised without client names.
* Auth events: client registration, consent page shown, approval (client and scopes), denial, failed sign-in on the consent page (no email or password logged), PKCE failure, authorization code replay, refresh token reuse, token issue and rotation, revocation, refused origin, throttled attempts, and missing or invalid bearer tokens (at most 10 rows a minute per IP).
* Never logged: passwords, TOTP codes, tokens, codes, CSRF values, TOTP secrets, full free text.

The admin page shows the log. Look for `refresh_reuse`, `code_reuse`, repeated `denied` rows and `rate_limited` rows.

## Known limits and things to check

* ChatGPT's own mTLS client certificate and IP ranges are not verified (shared hosting rarely allows it). Bearer tokens with a 1 hour life are the control.
* The daily 40-credit cap is checked inside the same database transaction as the ledger insert, but MySQL's default isolation could in theory let two simultaneous calls both pass. The write limit (20 per hour) and single owner make this very unlikely; the admin page shows the true balance.
* Code-to-grant and authorization-request records are stored in `idempotency_keys` (prefixes `codegrant:` and `authreq:`) because the schema has no columns for them. A migration adding `oauth_codes.grant_id` would be cleaner.
* Which MCP protocol revision ChatGPT sends is unconfirmed; the server accepts `2025-06-18`, `2025-11-25` and `2026-07-28` and works without `initialize`.
* Sending client names and balances to OpenAI is a disclosure of personal information under POPIA. Keep ChatGPT's data controls set to not train on the owner's chats, keep the tool outputs minimal (they are), and take advice on the operator/processor position.
