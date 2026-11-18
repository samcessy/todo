# Test strategy

**Status:** Draft for G3 · **Author:** orchestrator (adopted and maintained by the QA agent from T-002 on) · **Date:** 2026-11-18
**Inputs:** ADR-0003 (tools and CI gates), `contracts.md` (§1.1.1 statuses, §2 rules, §3 filter and query budget, §5.3 overdue, §6.1 UI contract), `security.md` §2 and §4, `coding-standards.md` §4, `traceability-matrix.md`, `governance.md` (E-1, loop guardrails), residuals RR-1 to RR-4.

## 1. Principles

1. **Acceptance criteria are the specification.** Every AC, threat and NFR row in the traceability matrix becomes at least one test whose name starts with its ID.
2. **Tests first, by an independent agent.** The QA agent writes the failing tests from the task file, the user stories, `contracts.md` and this document, **without reading the implementation**. Engineers never edit tests.
3. **Deterministic gates decide "done".** An agent's judgment does not count (CLAUDE.md rule 6).
4. **Never weaken to get green.** No skip, `todo()`, deletion, looser assertion or lowered threshold. The only approved test deletion is E-1.
5. **Test the contract, not the implementation.** Status codes, prop shapes, texts, accessible names and focus targets come from `contracts.md`. A contract change is an owner decision, then a test change.

## 2. Layers

| Layer | Tool | Location | CI job | What it proves |
|---|---|---|---|---|
| Unit | Pest 5 | `tests/Unit/` | `test-sqlite`, `test-pgsql`, `mutation` | Pure PHP logic (`TodoFilter`) |
| Feature | Pest 5 + Laravel testing | `tests/Feature/` | `test-sqlite`, `test-pgsql`, `mutation` | Routes, statuses, validation, authorisation, props, security configuration |
| Arch | Pest arch presets | `tests/Arch/` | `test-sqlite`, `mutation` | Layering and banned calls (T-12, NFR-08) |
| Browser | Pest browser plugin (Playwright) | `tests/Browser/` | `browser-chromium` (PR), `browser-matrix` (main, nightly) | Keyboard, focus, visible texts, accessibility scan |
| Frontend unit | Vitest via `vp test run` | `resources/js/**/*.spec.ts` | `js-unit` | Pure TypeScript logic (`lib/overdue.ts`) |
| CI script | Node scripts with fixtures | `scripts/check-*.mjs`, `tests/fixtures/*` | `lint`, `workflow-lint`, `scope` | NFR-03, T-18, T-20 |
| Staging | `curl` checks in workflows | `.github/workflows/deploy-staging.yml`, `nightly.yml` | after deploy | T-06, T-13, T-24 on real infrastructure; NFR-05 |
| Manual (G5) | Owner checklist | `docs/05-operations/release.md` | — | NFR-06 screen-reader smoke, real Safari, Lighthouse |

`phpunit.xml` defines the suites `Unit`, `Feature`, `Arch` and `Browser`. Non-browser jobs never load Playwright; coverage and mutation never run browser tests.

## 3. Naming and traceability

- Test names start with their IDs: `it('US-05.AC3 returns 403 …')`, `it('T-13 sends HSTS …')`. A test may name several IDs.
- AC IDs: `US-\d{2}\.AC\d+` and `CC.AC1` to `CC.AC3`. Others: `T-\d{2}`, `NFR-\d{2}`, `FR-\d{2}`.
- The matrix is regenerated in verification mode by grepping `(US-\d{2}|CC)\.AC\d+` and `(T|NFR|FR)-\d{2}` in `tests/` and `resources/js/**/*.spec.ts`. A row is **Passing** only when a test with that prefix exists and passed on the PR's CI run.
- CI-script fixture tests carry their IDs in `expected.json` entries.

## 4. Tests-first protocol (per task)

1. **Red commit.** QA writes the tests and commits them first (`test(<area>): … (red)`). The QA handoff lists each new test and the reason it fails. A test failing for the wrong reason (syntax error, missing factory, bad setup) is fixed before handing over.
2. **Implementation commits** follow. Engineers make the tests pass without editing them. If a test seems wrong, the engineer stops and writes a question in the task file.
3. **Verification mode.** QA runs the full suite, reads the diff for weakened, skipped or deleted tests (a blocker), checks `mutates()` coverage of new classes, and updates the matrix.
4. **Already-green tests.** When a test passes before implementation because the kit or framework already provides the behaviour (for example T-003 arch tests on kit code), QA records that in the handoff. It is not a failure.

## 5. Conventions

| Topic | Rule |
|---|---|
| Inertia writes | Use the `inertia()` helper in `tests/Pest.php` (`withHeaders(['X-Inertia' => 'true'])`, added in T-008) and assert the exact status from contracts §1.1.1: POST 302, PATCH/DELETE 303, 419 handler 302. Guest, 403, 404 and 429 tests may omit the header |
| Redirect target | `->from('/todos?filter=active')` then `assertRedirect('/todos?filter=active')` for every write (US-07.AC3) |
| Toasts | `assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Todo added.'])` |
| Props | One strict `assertInertia` shape test per page, no `etc()`, plus `missing('todos.0.user_id')` and `missing('todos.0.is_overdue')` |
| Time | `travelTo()` or `freezeTime()` **before** creating any model. No real-time sleeps |
| Data | Factories and states from data-model §4. Explicit titles wherever order or text is asserted. No seeders in tests (except `StagingSeederTest`) |
| IDOR | `AuthorizationTest` builds its dataset from `Route::getRoutes()` filtered on `{todo}`, so new routes are covered automatically |
| Mutation | Every domain test file declares `mutates(<Class>::class)` for the class it proves |
| Datasets | Validation cases are shared datasets reused by Store and Update tests (US-04.AC2) |
| Drivers | Every Unit, Feature and Arch test runs on SQLite and Postgres. Driver-specific assertions (counts as `int`, multibyte length) are not skipped on either |
| Production-only behaviour | Switch the environment inside the test (`app()->detectEnvironment(fn () => 'production')`) for per-request behaviour. For boot-time behaviour (`URL::forceHttps`), use the mechanism T-005 records in §8 |
| Framework-disabled behaviour | CSRF verification is off under unit tests, so CSRF is proven through configuration and a middleware instance with `runningUnitTests()` overridden (security.md T-05). Never "prove" it with a test that cannot fail |
| External services | `Http::fake()` for HIBP. No test reaches the network |
| Frontend unit | `*.spec.ts` next to the module, imports from `vite-plus/test`, local-time `Date` constructor |

## 6. Browser tests

- **Locators** come from contracts §6.1: role plus exact accessible name (`U-xx`). No CSS-class or DOM-structure locators.
- **Focus** is asserted by the id of `document.activeElement` (`F-x`), through the API T-006 records in §8.
- **Accessibility scan:** `assertNoAccessibilityIssues()` at level 2 (moderate) and `assertNoJavaScriptErrors()` on every ADR-0003 state, in light and dark. Kit pages from T-006; todo states from T-018.
- **Focus-visible probe:** Tab through `/todos`; each focused element has a computed `outline-style` or `box-shadow` other than `none`.
- **Matrix:** Chromium (desktop and mobile viewport) on every PR; Firefox and WebKit on `main` and nightly.
- **Flaky tests.** A browser test that fails and then passes on retry with no code change is a flake. The QA agent reports it in the task file and escalates to the owner; it is never skipped or marked non-blocking by an agent. ADR-0003's revisit trigger (more than 1 spurious failure per 20 runs) is the owner's call.

## 7. Coverage, mutation and CI-script tests

| Gate | Threshold | Where | Note |
|---|---|---|---|
| Line coverage | ≥ 85 % overall | `test-sqlite` (PCOV) | Pest `--coverage --min=85` |
| Line coverage per directory | ≥ 90 % on `app/Http` and `app/Policies` | `tests/coverage-dirs.php` on clover | A directory with no files passes with a notice until its first file lands |
| Mutation | ≥ 70 % | `mutation` | Over classes declared with `mutates()`; zero mutations do not fail |
| Raw HTML | 0 findings outside the allow-list | `lint` (`check:html`, `check:html:fixtures`) | T-004 |
| Workflow rules | 0 violations | `workflow-lint` | T-002 |
| Scope | 0 files out of scope | `scope` | T-002; the scope comes from the task file **on `main`** |

Coverage and mutation are CI-only (no local driver). If the kit code alone is under a threshold at T-002, QA adds tests for kit code; the threshold is never lowered (G3-9).

Each CI script has fixtures with an `expected.json` of verdicts, run by a fixture mode of the script. A script change without a matching fixture change is a G4 review finding.

## 8. Facts recorded by the spikes

Filled in by T-005 and T-006 before any task that depends on them. Each entry has a working code sample.

| # | Fact | Recorded by | Value |
|---|---|---|---|
| 1 | Status-code cells of contracts §1.1.1 | T-005 | _pending_ |
| 2 | Exact query budget for `GET /todos` | T-005 | _pending_ (contracts §3 says 2) |
| 3 | `auth.session` as printed by `route:list --json` | T-005 | _pending_ |
| 4 | `HttpsRedirectTest` mechanism for boot-time URL generation | T-005 | _pending_ |
| 5 | Password change: does the changing session stay logged in? | T-005 | _pending_ |
| 6 | Passkey landing test feasible? | T-005 | _pending_ |
| 7 | `vp test run` includes `*.spec.ts`; passes `--passWithNoTests` | T-005, T-002 | _pending_ |
| 8 | Reading `document.activeElement` attributes in the browser plugin | T-006 | _pending_ |
| 9 | Key presses `Tab`, `Shift+Tab`, `Escape`, `Space` | T-006 | _pending_ |
| 10 | Exact 320 px viewport or the `iPhone SE` preset | T-006 | _pending_ |
| 11 | Accessibility engine and rule tags | T-006 | _pending_ |
| 12 | Browser test origin is a secure context (history encryption) | T-006 | _pending_ |
| 13 | Kit inputs link errors with `aria-describedby` (RR-3 baseline) | T-006 | _pending_ |

## 9. Test file → task

Every planned file in the traceability matrix has exactly one task that creates it; later tasks may extend it.

| Test file or check | Created by | Extended by |
|---|---|---|
| `tests/Arch/ArchTest.php` | T-003 | — |
| `scripts/check-raw-html.mjs` fixtures (`tests/fixtures/raw-html/`) | T-004 | — |
| `tests/Browser/AccessibilityTest.php` | T-006 (kit pages) | T-018 (todo states, focus probe, locators) |
| `tests/Feature/Security/DatabaseDriverTest.php` | T-002 | — |
| Workflow, scope and clover fixtures; `audit`, `workflow-lint`, `scope`, coverage, mutation, lint jobs | T-002 | T-025 (route-contract step) |
| `tests/Feature/Settings/ProfileUpdateTest.php` (US-01.AC5) | T-007 (kit file) | — |
| `tests/Feature/Todos/IndexTest.php` | T-008 | T-009 |
| `tests/Feature/Todos/QueryCountTest.php` | T-008 | T-009 |
| `tests/Feature/Auth/AuthenticationTest.php` (kit file) | T-008 (US-01.AC3 retarget) | T-021 (US-01.AC4, US-01.AC6) |
| `tests/Feature/Auth/{Registration,EmailVerification,VerificationNotification}Test.php` (kit files, FR-01) | T-008 | — |
| `tests/Feature/DashboardTest.php` | deleted in T-008 (E-1) | — |
| `tests/Browser/AuthJourneyTest.php` | T-008 (US-01.AC3) | T-022 (T-07) |
| `tests/Unit/TodoFilterTest.php` | T-009 | — |
| `tests/Feature/Todos/FilterTest.php` | T-009 | T-012 (write redirects) |
| `tests/Feature/Todos/StoreTest.php` | T-010 | — |
| `tests/Feature/Todos/RateLimitTest.php` | T-010 | — |
| `tests/Feature/Todos/UpdateTest.php` | T-011 | — |
| `tests/Feature/Todos/AuthorizationTest.php` | T-011 | T-012 |
| `tests/Feature/Todos/CompletionTest.php` | T-012 | — |
| `tests/Feature/Todos/DestroyTest.php` | T-012 | — |
| `resources/js/lib/overdue.spec.ts` | T-013 | — |
| `tests/Browser/TodoJourneyTest.php` | T-014 | T-015, T-016, T-017 |
| `tests/Browser/ResponsiveTest.php` | T-018 | — |
| `tests/Feature/Security/SecurityHeadersTest.php` | T-019 | — |
| `tests/Feature/Security/HttpsRedirectTest.php` | T-019 | — |
| `tests/Feature/Security/SessionCookieTest.php` | T-019 | — |
| `tests/Feature/Security/PasswordPolicyTest.php` | T-020 | — |
| `tests/Feature/Security/AuthRateLimitTest.php` | T-021 | — |
| `tests/Feature/Auth/TwoFactorChallengeTest.php` (kit file) | T-021 | — |
| `tests/Feature/Security/SessionLifetimeTest.php` | T-022 | — |
| `tests/Feature/Security/HistoryTest.php` | T-022 | — |
| `tests/Feature/Security/DevtoolsTest.php` | T-022 | — |
| `tests/Feature/Security/CsrfConfigTest.php` | T-023 | — |
| `tests/Feature/Security/CsrfHandlerTest.php` | T-023 | — |
| `tests/Feature/Security/QueryExceptionLoggingTest.php` | T-023 | — |
| `tests/Feature/Security/SharedPropsTest.php` | T-023 | — |
| `tests/Feature/Security/BoostConfigTest.php` | T-023 | — |
| `tests/Feature/HealthTest.php` | T-023 | — |
| `tests/Feature/Seeders/StagingSeederTest.php` | T-024 | — |
| Route-contract step, staging smoke, NFR-05 warm and cold | T-025 | — |
| NFR-06 manual checks | G5 checklist (T-026 `release.md`) | — |

## 10. What is not automated

| Item | Why | Covered by |
|---|---|---|
| `aria-describedby` on the kit's own forms (CC.AC2) | axe cannot check it | Accepted residual RR-3; T-006 records the baseline |
| Real Safari, screen-reader smoke, Lighthouse | Not available in CI | G5 manual checklist (NFR-06, ADR-0003) |
| Fortify login and password-confirm truncation at 72 bytes | Not guarded by design | Accepted residual RR-1 |
| HIBP outage behaviour | Framework fails open | Accepted residual RR-2 |
| Repository settings, bot token scope, Cloud settings | Outside the codebase | T-002 and T-025 owner checklists, re-run before G5 |

## 11. Commands

| Where | Command |
|---|---|
| Local, every task | `composer test` (Pint check, PHPStan, Pest), `npm run check`, `npm run types:check`, `npm run build`, `npm run test` |
| Local, browser | `./vendor/bin/pest --testsuite=Browser` |
| CI only | coverage (`--coverage --min=85` plus `coverage-dirs.php`), mutation (`--mutate --min=70`), Postgres, browser matrix, audits |
