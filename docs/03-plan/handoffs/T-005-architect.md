# Handoff: T-005 (architect)

## What changed
- `docs/02-architecture/T-005-spike-report.md` (new): one row per item (1-13) and per re-verified line reference, with the command, a short output and the verdict. Also: the measured status per cell, test-strategy snippets (items 4, 5, 8, 9, 10), proposed corrections C-1 to C-18, the owner-approval list and the strongest argument against.
- `docs/02-architecture/contracts.md`: §1.1.1 (419 row → 303 for Inertia PATCH/DELETE, rewritten explanation, 409 GET case, test convention), §1.1 (new `redirectUsersTo('/todos')` bullet), §1.2 note, §3 query budget confirmed, §4 `auth.user` row, §5.2 pdo_pgsql note. Every change is marked "(T-005)".
- `docs/02-architecture/security.md`: T-04, T-05, T-06, T-08, T-19, T-23 and T-24 updated with the spike results, marked "(T-005)".
- `docs/02-architecture/architecture.md` §5.1: grep re-run confirmed; passkey row (a feature test is feasible); new `bootstrap/app.php` row.
- Commit `eb770fc6`. Not pushed.

## Decisions made inside the task scope
- **The 419 handler's redirect becomes 303 on Inertia PATCH/DELETE in the contract,** matching the measured behaviour. The handler is not changed to force 302. The cause is the package's global `EnsureGetOnRedirect` (`inertia-laravel/src/ServiceProvider.php:78`).
- **`HttpsRedirectTest` uses "re-run the provider boot"** after `detectEnvironment(fn () => 'production')`. `refreshApplication()` is rejected because it drops the sqlite `:memory:` database.
- **Passkey landing:** a feature test with a replaced `VerifyPasskey` is feasible and is recorded as the check. The handoff grep stays for the client fallback string.
- **Item 13** is verified from php-src and the PostgreSQL docs, not live: no local PostgreSQL server, and the Docker daemon was not started.
- **Spike headers:** item 1 used the exact header set from `@inertiajs/core` 3.11.0 (`dist/index.js:3472-3480`) and an `X-Inertia`-only set. The statuses are identical for writes.

## Deviations from the spec
- **This handoff file lives in `docs/03-plan/handoffs/`,** which is outside the task's `scope.paths` (`docs/02-architecture/*`). It is required by CLAUDE.md rule 8 and was requested by the orchestrator. The CI scope check (T-002) may flag it. The task's `scope.paths` should list `docs/03-plan/handoffs/T-005-*.md`, or the owner accepts it at G4.
- **One new contract item that the spike items did not ask for:** C-6, `redirectUsersTo('/todos')`. It came out of item 12 (an authenticated user on a guest route lands on `/` once `dashboard` is removed). It needs owner approval as a contract and auth change, or it can be dropped.

## Commands run and results
```
npm run build && php artisan test (spike baseline)            →  pass: 42 tests, 147 assertions
php artisan route:list --json --path=todos | jq -S … ; diff   →  IDENTICAL to the contracts §1.2 block; "auth.session" printed as the alias
tests/Feature/Spike/StatusTableTest.php                         →  84 requests recorded; only the 419 cell differs (Inertia PATCH/DELETE 303)
tests/Feature/Spike/QueryBudgetTest.php                         →  expectsDatabaseQueryCount(2): 10/10 pass; real-session login = 3 queries
tests/Feature/Spike/SharedPropsStrictTest.php                   →  kit share: FAIL (extra two_factor_confirmed_at, created_at, updated_at); with allow-list: pass
tests/Feature/Spike/PasswordSessionTest.php                     →  pass: changing session 200, other session 302 → /login
tests/Feature/Spike/BoostKeysTest.php                           →  pass: 7 assertions
tests/Feature/Spike/HttpsRedirectTest.php                       →  A (re-run boot) pass; B (refreshApplication) https but users table gone
tests/Feature/Spike/PasskeyLandingTest.php                      →  pass: 200 {"redirect":"http://localhost/todos"}
tests/Feature/Spike/GuestRedirectTest.php (3 variants)          →  /dashboard; / (dashboard removed); /todos (redirectUsersTo)
npx vp test run (spec + test files)                             →  Test Files 2 passed; .spec.ts alone 1 passed
grep -rn dashboard resources/js app routes config tests         →  exactly the architecture §5.1 list + 6 assertions
Final: php artisan test tests/Feature/Spike                     →  105 tests, 104 passed (1 intended failure: kit auth.user share)
WebFetch nodejs schedule.json, nodejs.org previous-releases, laravel.com/cloud/docs/{runtimes,base-images,environments},
  postgresql.org/docs/18/functions-aggregate.html, php-src PHP-8.4 pgsql_statement.c (all 2026-11-18)
```

## Open questions / follow-ups
1. **Owner:** approve or reject the contract and security corrections listed under "Owner approval needed" in the report (C-1 to C-17, especially C-2: 419 → 303, and C-6: `redirectUsersTo`).
2. **Orchestrator:** architecture §2 step 5, ADR-0001 (lines 20 and 86) and `G2-decision-packet.md` line 17 describe the 303 rewrite through `Middleware.php:162/:179` only. The values are still right; the mechanism also includes the global `EnsureGetOnRedirect`.
3. **Orchestrator/owner:** ADR-0006 "Node 26, or 24 if T-001 finds 26 is not LTS": Node 26 is Active LTS since 2026-10-28. The Node version in Cloud's PHP build image is UNVERIFIED. Check `node --version` in the staging console (T-002/T-025).
4. **QA:** copy the report's snippets and the `$this->call()` pitfall into `docs/04-quality/test-strategy.md`. Write the exact query-budget test now that the value of 2 is confirmed.
5. **Engineer (the T-04 allow-list task):** narrow the kit's `resources/js/types/auth.ts` `User` type to the four keys plus `avatar?`.
