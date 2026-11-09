# Web tests: fuzzing and mutation testing

The web app's money, booking and security logic is `src/domain/*` (slots, credits, MCP tools) and
`src/data/demoApi.ts` (the in-browser API). Three layers test it:

1. Example tests (`*.test.ts`): exact outputs for known cases.
2. Property-based fuzzing with [fast-check](https://fast-check.dev) (`*.fuzz.test.ts`): random inputs, invariants must hold.
3. Mutation testing with [Stryker](https://stryker-mutator.io): changes the source on purpose and checks that some test fails.

## How to run

```bash
cd web
npm run test:fuzz                       # all fuzz tests, fixed seed (20261109), about 10 s
FC_SEED=42 FC_RUNS=5000 npm run test:fuzz   # explore: other seed, more runs per property
npx vitest run src/domain src/data      # everything for domain + data
npm run test:mutation                   # Stryker, about 5 minutes, report in web/reports/mutation/index.html
```

`FC_SEED` and `FC_RUNS` are read in `src/domain/fuzz.setup.ts`. When a property fails, fast-check prints the seed and the
shrunk counterexample; re-run with that `FC_SEED` and turn the counterexample into a normal example test.

CI: `pages.yml` runs `npm run test:fuzz` (fixed seed) before the build. `web-mutation.yml` runs Stryker on pull requests that
touch `web/src/domain/**` or `demoApi.ts` and fails below the `break` threshold in `web/stryker.config.json` (86).

## What is fuzzed

| Target | Properties (invariants) | Runs / seed |
| --- | --- | --- |
| `slots.ts` `computeSlots` | random settings (working hours with closed days, session 30-120, buffer 0-30, step 15/30/60, notice 0-48 h, window 1-60 days), busy blocks, bookings, now: inside working hours, no overlap with busy or booking plus buffer, notice and window respected, sorted and unique, length equals session, on the step grid; completeness (every valid candidate is returned); `isSlotFree` agrees | 400 + 250 |
| `slots.ts` time helpers | local/UTC round trip, month and year ends, leap days, `addDays`/`daysBetween`/`weekdayOf`/`startOfWeek` consistent | 1000 each |
| `credits.ts` | balance equals sum of deltas (any order, any split); refund strictly more than the free hours (exact at 24 h, 1 ms either side); monotonic in time; guarded ledger sequences never go negative | 300-1000 |
| `mcpTools.ts` `runTool` on the real demo store | random and hostile tool names and arguments (wrong types, NaN, 1e21, unicode, 5000-char strings, `__proto__`, `constructor`, extra fields): never throws, no state change when `ok:false`, `Object.prototype` never polluted, no secrets in output, every unknown field rejected, daily cap 40 and 1-20 per call never exceeded, replays never apply twice, no overlapping bookings or negative balances | 150-300 each |
| `demoApi.ts` `demoCallTool` | at most 20 write calls an hour and 60 calls a minute get through | 25 |
| `demoApi.ts` state machine | random sequences of signup, login, book, cancel, requestPack, adminAdjustCredits, adminMarkPackPaid, adminCancelBooking, adminBookFor, time travel against a model: balance equals model, no two confirmed bookings overlap (with buffer), a client never sees another client's rows, refunds follow the 24 h rule and never happen twice, client calls to owner endpoints are refused | 60 sequences of up to 40 steps |
| demo authenticator | code is always six digits | 2000 |

## Results

Bugs the tests found (all fixed, each with a regression test):

| Bug | Found by | Fix |
| --- | --- | --- |
| `validateArgs` used `k in schema.properties`, so `__proto__`, `constructor`, `toString` passed as "known" fields and bypassed unknown-field rejection | mcpTools fuzz (unknown fields) | `Object.hasOwn` for known-field and value reads |
| `localToUtc` rolled impossible dates over: `create_booking` for `2026-02-30T09:00` silently booked 2 March (also hour 25, minute 60, years 0-99 mapped to 19xx) | slots fuzz + mcpTools fuzz | validate by round trip, throw; tools map it to a `validation` error |
| `demoTotpCode` could return a negative number (`-168588`), roughly every second 30 s window: the demo authenticator showed a seven-character code with a minus sign | deterministic demoApi test (then a fuzz property) | `>>> 0` before the modulo |

Mutation score (Stryker 9.6.1, vitest runner, `src/domain/**` + `src/data/demoApi.ts`, tool descriptions excluded as prose):

| Scope | Before (existing tests only) | After fuzz + behaviour tests | Final |
| --- | --- | --- | --- |
| `domain/` | 54.3 % | 95.8 % | 96.0 % |
| `credits.ts` | 100 % | 100 % | 100 % |
| `slots.ts` | 88.9 % | 93.2 % | 93.2 % |
| `mcpTools.ts` | 44.7 % | 96.3 % | 96.7 % |
| `demoApi.ts` | 27.2 % | 82.6 % | 87.7 % |
| All files | 40.4 % | 89.0 % | 91.8 % |

"Before" is the state after the fuzz tests and bug fixes were added but before the exact-output tests
(`mcpTools.behaviour.test.ts`, `demoApi.test.ts`, extra slots tests), which is what the first Stryker run measured.
Survivors that are left are mostly equivalent mutants (clamps that are re-checked later, string messages in `demoApi.ts`) and
owner dashboard code with no money effect. Stryker runs the fuzz tests with `FC_RUNS=40` to stay fast.
