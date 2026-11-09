# Server tests: unit, fuzzing, end-to-end and mutation testing

The booking backend (`server/`) is PHP with no runtime dependencies. Its money and security logic is the OAuth server
(`src/OAuth`), the MCP endpoint and tools (`src/Mcp`), and the services behind them (`src/Services`: credits, bookings,
auth, TOTP, grants, rate limits, payment references) plus `src/Support/Time.php`. Four layers test it:

1. Unit and integration tests (`server/tests/*Test.php`): exact outputs for known cases, against an in-memory SQLite database.
2. Seeded fuzzing (`server/tests/Fuzz`): hostile and random input through `/mcp`, `/oauth`, `/api` and the pure functions.
3. An end-to-end script (`server/tests/e2e/oauth_mcp_e2e.php`) that plays ChatGPT against a running server.
4. Mutation testing with [Infection](https://infection.github.io): changes the source on purpose and checks that a test fails.

The web side has its own write-up: [web/TESTING.md](../web/TESTING.md) (fast-check properties and Stryker; web mutation score 40 % to 92 %, 3 web bugs fixed).

## How to run

```bash
cd server
composer install
vendor/bin/phpunit --testsuite=server        # unit + integration, about 6 s, 296 tests
vendor/bin/phpunit                           # server + fuzz suites together (310 tests)
```

### Fuzzing

```bash
FUZZ_SEED=20261109 vendor/bin/phpunit --testsuite=fuzz                     # the CI run, fixed seed, about 20 s
FUZZ_SEED=12345 FUZZ_ITERATIONS=2000 vendor/bin/phpunit --testsuite=fuzz   # other seed, more cases per test
vendor/bin/phpunit --testsuite=fuzz                                        # no seed: a fresh random one, printed in the output
```

The seed is random unless `FUZZ_SEED` is set. It is printed in the summary line of every test and in every failure message,
together with a replay command (`FUZZ_SEED=... FUZZ_ITERATIONS=... vendor/bin/phpunit --testsuite=fuzz --filter ...`).
A failing case becomes a normal example test. One default run is about 18,000 cases across MCP, OAuth, the REST API and the pure
functions (slots, TOTP, normalisers). CI runs the fixed seed on every push and pull request, and a fresh random seed with
2000 iterations every Monday (the scheduled run is the only thing the `schedule` trigger starts).

### End to end

Needs a running server whose `app.base_url` equals the URL you pass, an owner with TOTP, and a client (`bin/seed-demo.php` creates them):

```bash
E2E_OWNER_EMAIL=ivan@example.com E2E_OWNER_PASSWORD=... E2E_TOTP_SECRET=BASE32SECRET \
  php server/tests/e2e/oauth_mcp_e2e.php http://127.0.0.1:8091
```

It checks 401 discovery, metadata, dynamic client registration, PKCE S256, consent (password + TOTP, with the pairing window
closed and then opened), code exchange, MCP calls, idempotent write replay, refresh rotation and refresh-token reuse (the grant must die).
Exit code 0 only when every step passes.

### Mutation testing

Infection needs a coverage driver. CI uses pcov through `shivammathur/setup-php` (`coverage: pcov`). Locally, pcov is not
in the default PHP; build it once (`pecl install pcov`, or compile it and keep the `.so` outside php.ini) and load it only for the run:

```bash
cd server
php -d memory_limit=2G vendor/bin/infection --threads=4 \
  --initial-tests-php-options="-d extension=$HOME/.local/php-ext/pcov.so" \
  --only-covering-test-cases --with-uncovered --no-progress \
  src/Services/CreditService.php          # one area; omit the path for the whole configured set (about 13 min)
composer mutation                          # same as the CI command, when pcov is already loaded
```

Notes:

- The scope and exclusions are in `server/infection.json5`. `excludes` paths are relative to each source directory
  (an earlier version wrote `OAuth/ConsentPage.php` and excluded nothing). Excluded: HTML (`ConsentPage`), the network fetch
  (`HttpCimdFetcher`), logging, settings JSON, audit writer, admin client list, cron helper, presenter.
- Mutants run against the fast `server` suite only (not the fuzz suite). `--only-covering-test-cases` runs just the tests that cover each mutated line.
- `memory_limit=2G` is needed: the JSON log (`var/infection/infection.json`, tens of MB) is built in memory and a 128 MB CLI default kills the run at the very end.
- `timeout` is the per-mutant limit: 60 s (it was 20 s; the full suite with coverage alone takes about 20 s on a loaded machine).
- Logs go to `server/var/infection/` (gitignored): `summary.log`, `infection.log` (escaped mutants with diffs), `infection.json`, `per-mutator.md`.
- CI (`server-tests.yml`, job `mutation`) fails below `--min-msi=85` and `--min-covered-msi=86` (about 3 points under the score reached). Raise them as the score rises.
- Tests that provoke an expected `error_log()` line set `ini_set('error_log', '/dev/null')` (as `McpTest` already did). On a loaded machine Infection's initial run was sometimes killed (exit 143) right after such a line reached stderr; `/dev/null` removed it. If an Infection run stops with "Project tests must be in a passing state" and exit code 143, just run it again.

## What the tests cover

| Area | Where | What is pinned (exact values and boundaries) |
| --- | --- | --- |
| OAuth server | `OAuthTest`, `MutationOAuthTest` | metadata documents; DCR (body 16 384 bytes, 10 registrations an hour, 500 clients, redirect allow-list: exact list, prefix rules, `.`/`..`, 128 chars, userinfo, fragment, wildcard, control characters, 500 chars, loopback http only when not live); CIMD (host list, port 443, cache 3600 s); authorize (state 500, PKCE challenge 43 chars, S256 only, resource, scopes, 60 views an hour); consent (CSRF, request lifetime 600 s, 5 attempts per record, 5 attempts per 15 minutes per IP, owner role only, TOTP reuse); pairing window 15 minutes, closes on the first grant of a new client; code 60 s, single use, replay revokes the grant; PKCE verifier 43-128 chars and S256 not plain; access 3600 s, refresh 30 days, grant ceiling 90 days, each exact at the boundary second; refresh rotation and reuse revocation; revocation (own client only, always `{}`); 30 token calls a minute |
| MCP | `McpTest`, `MutationMcpTest` | transport (origin, bearer format, body 65 536 bytes, JSON depth 32, protocol versions, JSON-RPC envelope errors, notifications); 60 calls a minute and 20 writes an hour per grant (bucket name and window checked); scope per tool (11 tools); schema bounds (1..20 sessions, key 8..64, query 2..60, 92-day and 14-day ranges); daily cap 40 (limit-th session allowed, next refused); remove cannot go below zero; payment reference once only (case and spacing ignored); idempotency (replay, mismatch, pending, stale after 120 s, released on failure); exact tool outputs and a golden file of `tools/list` (`tests/fixtures/mcp-tools-list.json`; regenerate with `UPDATE_GOLDEN=1` after a deliberate contract change); audit rows and the redactor |
| Credits, payments | `MutationServicesTest` | ledger validation, balance never below zero, note and actor limits, the daily total follows local midnight (22:00 UTC) to the second, pack requests (5 pending), `markPackPaid`, reference normalisation and the shared once-only table |
| Bookings | `CoreBookingTest`, `MutationServicesTest`, `MutationServicesExtraTest` | slot grid and buffer (including a 5-minute step and 0/15/30-minute buffers), 12 h notice and 28-day window exact to the second, double booking, no credits, refund rule at exactly 24 h (24 h 0 min 1 s refunds, 24 h does not), owner override, reschedule into the booking's own slot, Google events (own event ignored, patch, delete, failures roll back), mail, summary week and month boundaries and free hours |
| Auth | `CoreAuthTest`, `MutationServicesTest`, `MutationServicesExtraTest` | login limits (20 per IP, 5 per email and IP, 30 per email, 15-minute windows, success resets), TOTP window +-1 step, same-step reuse, older step refused, RFC 6238 vectors, 8 and 30 attempts per 15 minutes, second-factor session 600 s and 5 failures, CSRF, argon2id parameters |
| Grants, rate limiter | `MutationServicesTest`, `MutationServicesExtraTest` | list shape, revoke and revoke-all-for-user (reasons cut at 80 characters), limit-th hit allowed and the next refused, window expiry, bucket naming, long subjects hashed above 190 characters, reset |

## Results

### Test counts

| Suite | Tests | Assertions |
| --- | --- | --- |
| server (unit + integration) | 296 (145 before this work) | about 6,400 |
| fuzz (seed 20261109, default iterations) | 14 | about 1.3 million |

### Bugs found

Found by fuzzing (all fixed, each with a regression test):

| Bug | Fix |
| --- | --- |
| A future booking marked completed or no-show freed its slot, so a second client could book the same hour | completed and no-show bookings keep occupying their time |
| A NUL byte in a date made `createFromFormat()` throw a `ValueError` (HTTP 500) | reject NUL up front |
| Nested arrays in DCR `grant_types` raised a PHP warning | element-wise check instead of `array_diff` |
| `https://chatgpt.com/connector/oauth/.` passed the redirect prefix check (a trailing `.` dot segment) | `.` and `..` are refused |
| An unknown tool or bad arguments wrote no audit row | both are audited (the tool name is not logged: it is attacker-chosen) |
| Audit argument names were unbounded (64 KB keys reached the log) | names are cut to 40 characters and 20 keys |
| The PKCE and TOTP regexes accepted a trailing newline (missing `D` flag) | `D` flag |
| Payment reference and text normalisers were not idempotent (`normalize(normalize(x)) != normalize(x)`) | upper-case first, then cut, then trim |

Found by mutation testing (all fixed, each with a regression test):

| Bug | Found by | Fix |
| --- | --- | --- |
| The MCP bearer-token regex had no `D` flag: `Bearer <token>\n` was accepted | bearer format boundaries | `D` flag |
| `Time::parseIso()` had no `D` flag: a booking start of `2026-11-10T08:00:00Z\n` was accepted | `create()` with a trailing newline | `D` flag |
| `BookingService::localToUtc()` let `createFromFormat()` roll impossible dates over: `2026-13-10T10:00` became 10 January 2027 (only reachable behind the MCP schema check, which rejects it first) | local time parsing table | only accept what round-trips |
| `AuthService` called `password_needs_rehash()` without the argon2id cost options used by `hashPassword()`, so every successful login rewrote the stored hash (a wasted hash and a database write on each login) | strong hash is not rewritten on login | pass the same options |

Also fixed in the tooling: `infection.json5` excluded nothing (paths were written relative to the wrong directory), and its per-mutant timeout was raised from 20 s to 60 s.

### Mutation score (Infection 0.35.10, PHP 8.5, pcov, `--only-covering-test-cases --with-uncovered`)

MSI is the share of all mutants that tests detect; covered MSI ignores mutants on lines no test executes.

| Area | Mutants | MSI before | MSI after | Covered MSI before | Covered MSI after |
| --- | --- | --- | --- | --- | --- |
| `src/OAuth` | 1001 | 64.5 % | 90.3 % | 68.2 % | 91.1 % |
| `src/Mcp` | 1158 | 51.9 % | 86.9 % | 54.3 % | 87.9 % |
| `CreditService` | 108 | 49.1 % | 91.7 % | 52.5 % | 92.5 % |
| `PaymentReferences` | 24 | 66.7 % | 95.8 % | 72.7 % | 95.8 % |
| `BookingService` | 427 | 57.6 % | 85.5 % | 63.2 % | 86.5 % |
| `AuthService` | 169 | 62.1 % | 94.7 % | 64.7 % | 94.7 % |
| `GrantService` | 35 | 54.3 % | 94.3 % | 55.9 % | 94.3 % |
| `RateLimiter` | 70 | 60.0 % | 85.7 % | 72.4 % | 87.0 % |
| `Totp` | 101 | 71.3 % | 96.0 % | 90.0 % | 96.0 % |
| `src/Support` (`Time`) | 27 | 65.4 % | 92.6 % | 73.9 % | 92.6 % |
| **All configured files** | 3120 | **58.3 %** | **88.8 %** | **62.2 %** | **89.7 %** |

The overall figures are the sum of the per-area runs (the whole configured set takes about 13 minutes on a busy laptop, so it
was measured area by area; the sums are the same mutants and the same tests). Targets: covered MSI of at least 85 % for
`src/OAuth`, `src/Mcp`, `CreditService` and `PaymentReferences` is reached.

"Before" is the state after the fuzz tests and their bug fixes (commit `4c9ebef`), measured with the exclusions applied.

### Mutants that are left, and why

About 350 mutants survive. Nearly all fall in these groups:

- **Equivalent or defence in depth.** A second guard makes the mutated check redundant: for example the TOTP replay check in `verifyTotp`
  (`<=` vs `<`, dropping the early `return false`) is backed by the atomic `UPDATE ... WHERE totp_last_step < ?`, and `Scopes::parse`'s `trim`
  (`preg_split` with `PREG_SPLIT_NO_EMPTY` already ignores edges). `'+' . 60 . ' seconds'` vs `60 . ' seconds'` is the same instant.
- **MySQL-only code that SQLite never runs.** `FOR UPDATE` concatenation, `INSERT IGNORE` lock row, `lock()`.
- **Types that SQLite already returns right.** `(int)` and `(string)` casts on database values (`PDO::ATTR_STRINGIFY_FETCHES` is off).
- **Log and message text** that only reaches `error_log()` (`Log::error` lines, `error_log('mcp: ...')`), and audit `substr()` caps that no real message reaches
  (a 250-character summary, a 200-character tool message).
- **Retry loops for a lost race** in `RateLimiter::hit()` and `claimIdempotency()`, and its 1 in 100 random cleanup (covered statistically, not per mutant).
- **Fake limits.** `McpTest` and the OAuth tests run against fakes for the credit and booking services, so `slots > 60` truncation and the
  scheme/port handling of `originAllowed()` (the fake base URL has no port) cannot be reached there; the real services are tested separately.

## Adding tests

New tests that came from mutants are in `server/tests/Mutation*Test.php`. When an Infection run shows an escaped mutant, read its diff in
`var/infection/infection.log`, decide whether it is equivalent (document it above) or a missing assertion (add an exact-value test), and re-run that file only.
