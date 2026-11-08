# HTTP API (PHP backend, live mode)

Same origin as the web app. JSON in and out, `credentials: same-origin`. Errors: `{"error":{"code","message"}}`
with `code` one of `unauthorized | forbidden | not_found | validation | conflict | no_credits | rate_limited | server`.
State-changing requests (POST, PATCH, DELETE) must send `X-CSRF-Token`, the value from `GET /api/csrf` (`{"token"}`).
`GET /api/me` returns the user object, or `{"user":null}`/401 when signed out.
Times are UTC ISO strings; dates in queries are local `YYYY-MM-DD` (Africa/Johannesburg).
Shapes are the TypeScript types in `web/src/domain/types.ts`.
The client is `web/src/data/httpApi.ts`; the `Api` interface is `web/src/data/api.ts`.

| Api method              | HTTP                                                          |
| ----------------------- | ------------------------------------------------------------- |
| me                      | GET /api/me                                                   |
| signup                  | POST /api/auth/signup                                         |
| login                   | POST /api/auth/login -> {user} or {needsTotp:true}            |
| verifyTotp              | POST /api/auth/totp {code}                                    |
| logout                  | POST /api/auth/logout                                         |
| packages                | GET /api/packages                                             |
| settings                | GET /api/settings                                             |
| availability            | GET /api/availability?from=&to=                               |
| myBalance               | GET /api/me/balance -> {balance}                              |
| myLedger                | GET /api/me/ledger                                            |
| myBookings              | GET /api/me/bookings                                          |
| myPackRequests          | GET /api/me/packs                                             |
| requestPack             | POST /api/me/packs {packageId}                                |
| book                    | POST /api/me/bookings {start}                                 |
| cancelBooking           | POST /api/me/bookings/{id}/cancel -> {booking, refunded}      |
| adminClients            | GET /api/admin/clients?q=                                     |
| adminClient             | GET /api/admin/clients/{id}                                   |
| adminBookings           | GET /api/admin/bookings?from=&to=                             |
| adminBusy               | GET /api/admin/busy?from=&to=                                 |
| adminAdjustCredits      | POST /api/admin/clients/{id}/credits {delta,note}             |
| adminMarkPackPaid       | POST /api/admin/packs/{id}/paid {paymentReference}            |
| adminBookFor            | POST /api/admin/bookings {userId,start}                       |
| adminCancelBooking      | POST /api/admin/bookings/{id}/cancel {refund,reason}          |
| adminSetBookingStatus   | POST /api/admin/bookings/{id}/status {status}                 |
| adminUpdateSettings     | PATCH /api/admin/settings                                     |
| adminCalendar           | GET /api/admin/calendar                                       |
| adminConnectCalendar    | POST /api/admin/calendar/connect -> {redirectUrl, connection} |
| adminDisconnectCalendar | POST /api/admin/calendar/disconnect                           |
| adminSummary            | GET /api/admin/summary?period=                                |
| adminGrants             | GET /api/admin/grants                                         |
| adminRevokeGrant        | POST /api/admin/grants/{id}/revoke                            |
| adminAudit              | GET /api/admin/audit?limit=                                   |
| adminConnectorPairing   | GET /api/admin/connector/pairing -> {open, until}             |
| adminOpenPairing        | POST /api/admin/connector/pairing -> {open, until}            |
| adminClosePairing       | POST /api/admin/connector/pairing/close -> {open, until}      |

**Pairing window** (owner only, CSRF required on the POSTs). `POST /api/admin/connector/pairing` opens a 15-minute window in which `/oauth/authorize` accepts a ChatGPT client that has no active grant yet; `until` is an ISO UTC time (`null` when closed). The window closes by itself after 15 minutes, when the owner closes it, and as soon as a grant is created for a new client (single use). Clients that already have a non-revoked grant can re-authorise without it. Opening and closing are written to the audit log (`connector_pairing_open`, `connector_pairing_close`), as are refused authorisations (`oauth_pairing_refused`). Setting key: `connector_pairing_until` in the `settings` table.

`POST /api/auth/signup`: `name` is 1-80 characters of letters (any script), spaces, apostrophe, hyphen and full stop only.

`GET /api/csrf` -> `{token}` (no auth needed).
