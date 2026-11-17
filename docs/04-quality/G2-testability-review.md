# G2 testability review

**Status:** Draft for G2 · **Author:** QA engineer agent (design-review mode) · **Date:** 2026-11-16
**Inputs:** `CLAUDE.md`, `docs/governance.md`, `docs/00-product/*`, `docs/01-research/G1-decision-packet.md` (binding), `docs/01-research/R-003-test-stack.md` (with its red-team review), `docs/02-architecture/*`, `docs/adr/0001`–`0006`.
**Companion:** `docs/04-quality/traceability-matrix.md` (draft, every row has the status Planned).

## Question
Can every acceptance criterion and NFR be checked by a deterministic test that a QA agent writes from `user-stories.md` and `contracts.md` alone, before any implementation exists?

## Verdict: **Testable with changes**

All **37 of 37** AC IDs map to a concrete planned test: the 34 story ACs plus the 3 cross-cutting criteria, which get the proposed IDs `CC.AC1`–`CC.AC3` (F-04).
The server side is in good shape. Routes, middleware order, validation rules, filter semantics, the ordering tie-break, the prop shape, the toast texts and the overdue rule are specific enough to write Pest feature tests now.

Three gaps would force a tests-first QA agent to guess or to stop:

1. The 303 status depends on a request header that the contract doesn't mention (F-01).
2. There is no UI contract: accessible names and visible strings are missing, so the browser ACs can't be written without the implementation (F-02).
3. Q1 requires deleting a kit test, which governance forbids unless the owner grants an explicit exception (F-03).

All three are fixed by doc edits only. None of them changes the architecture.

### Framework facts verified for this review
I checked these against the spike copy of the kit (`scratchpad/redteam/kit`, Laravel 13.42.0, inertia-laravel v3.7.0), not from memory:

| Fact | Evidence |
|---|---|
| Inertia rewrites 302 to 303 for PUT, PATCH, DELETE and QUERY **only when the request has the `X-Inertia` header**. Without the header, the middleware returns before the rewrite | `vendor/inertiajs/inertia-laravel/src/Middleware.php:162` (`if (! $request->header(Header::INERTIA)) { … return $response; }`) comes before `:179` (`setStatusCode(303)`) |
| `assertInertiaFlash($key, $expected)` and `assertInertiaFlashMissing()` exist as TestResponse macros. They read the session key `inertia.flash_data`. `AssertableInertia::hasFlash()` is also available | `src/Testing/TestResponseMacros.php:65,84`; `src/Testing/AssertableInertia.php:259`; `src/Support/SessionKey.php:15` |
| Laravel 13's CSRF middleware is `PreventRequestForgery`. It **skips verification when running unit tests**, and also passes when the request has a valid Origin header | `vendor/laravel/framework/src/Illuminate/Foundation/Http/Middleware/PreventRequestForgery.php:97–103,130–132` |
| `config/app.php` hard-codes `'timezone' => 'UTC'`. `phpunit.xml` sets `SESSION_DRIVER=array` and `CACHE_STORE=array` | kit `config/app.php:68`, `phpunit.xml:25,31` |
| `tests/Pest.php` binds `TestCase` and `RefreshDatabase` **only to `Feature`** | kit `tests/Pest.php` (`->in('Feature')`) |
| The kit's `TwoFactorChallengeTest` has only 2 tests: a guest is redirected, and the challenge page renders. No test submits a TOTP or recovery code | kit `tests/Feature/Auth/TwoFactorChallengeTest.php:13,19` |
| The kit's `users are rate limited` test pre-increments the limiter by 5 instead of making 5 failed attempts | kit `tests/Feature/Auth/AuthenticationTest.php:68–79` |
| Besides `routes/web.php`, `config/fortify.php` and the tests, `dashboard` appears in `AppSidebar.vue`, `AppHeader.vue`, `Welcome.vue` and **`PasskeyVerify.vue:33` (`router.visit(response.redirect ?? '/dashboard')`, a plain string)** | `grep -rn dashboard resources/js app routes config` in the spike |

## Findings

| ID | Severity | Location | Issue | Required change (doc) |
|---|---|---|---|---|
| F-01 | **High** | `contracts.md` §1.1 (Success/Failure columns) and Notes bullet 2; `architecture.md` §2 step 5 | **The 303 status is conditional, but the contract states it unconditionally.** The adapter rewrites 302 to 303 only for requests carrying `X-Inertia: true` (verified, `Middleware.php:162` before `:179`). A plain Pest `$this->patch()` or `$this->delete()` gets **302**, so a tests-first `assertStatus(303)` fails even against a correct implementation. The column also says "302/303 back with `errors`" for `todos.update` but only "303" for `todos.completion.update`, which is ambiguous | `contracts.md` §1.1: state the status per request type. For an Inertia request (`X-Inertia: true`), POST success or validation failure gives **302**, and PATCH or DELETE success or validation failure gives **303**. A non-Inertia request gives 302 in every case. Add a "Test convention" note: feature tests for write routes send `X-Inertia: true` (one helper in `tests/Pest.php`) and assert the exact status; guest and 403 tests may stay header-free. Remove the "302/303" wording |
| F-02 | **High** | `contracts.md` §6 (Pages and components) | **There is no UI contract.** Browser tests locate elements by role and accessible name, so the tests for US-02.AC6, US-03.AC3, US-03.AC4 (display), US-05.AC5, US-06.AC1/AC2/AC4, US-07.AC3/AC4 and CC.AC1/AC2 need exact names and strings. Only two are defined: the checkbox name "Mark "<title>" complete" (and only for the active state) and the badge text "Overdue". Everything else is undefined: the title, description and due-date labels; the submit, edit, save, delete, confirm and cancel buttons; the dialog titles; the filter link texts and how their counts are formatted; the empty-state texts; the checkbox name for a completed todo; the error-element id pattern | Add `contracts.md` §6.1 **"UI contract"**: a table of element → role → accessible name or visible text → AC. At minimum: the inputs `Title`, `Description`, `Due date`; the button `Add todo`; the checkbox `Mark "<title>" complete` or `Mark "<title>" as active`; the buttons `Edit "<title>"` and `Delete "<title>"`; the dialogs `Edit todo` and `Delete todo?` with the buttons `Save`, `Delete` and `Cancel`; the nav `Filter todos` with the links `All`, `Active` and `Completed`, and the count format (for example `Active (3)`); the empty-state texts for all, active and completed; error ids `<form>-<field>-error`. Changing this table later is a contract change |
| F-03 | **High** | `architecture.md` Q1; `governance.md` ("never delete tests"); `.claude/agents/qa-engineer.md` (verification mode: a deleted test is a blocker) | **Q1 can only be done by deleting a test.** The `dashboard` route is removed, so the kit's `tests/Feature/DashboardTest.php` (2 tests) can't survive. Governance forbids deleting tests, and QA's own verification mode treats a deleted test as a blocker, so a QA agent following its role file would stop here. Six more assertions change from `route('dashboard')` to `route('todos.index')`: `AuthenticationTest:24`, `RegistrationTest:26`, `EmailVerificationTest:47,92,107` and `VerificationNotificationTest:33`. Separately, `architecture.md` §5 lists only `AppSidebar.vue` as a frontend edit. It misses `AppHeader.vue` and `Welcome.vue` (Wayfinder `dashboard()` imports, which the build catches) and **`PasskeyVerify.vue:33`**, whose `'/dashboard'` string fallback no tool catches. Passkey login would then land on a 404 whenever the server omits `redirect`, and US-01.AC3 would go untested for passkeys | `architecture.md` Q1: record that, once Q1 is accepted, the owner approves an **exception**: `DashboardTest` is *replaced* 1:1 by `IndexTest` `US-01.AC1` (guest → login) plus a test that an authenticated, verified user gets 200, and the six assertions are retargeted to `todos.index`. The task file for this work must list it under `human_approval_required`. `architecture.md` §5: add `AppHeader.vue`, `Welcome.vue` and `PasskeyVerify.vue` (replace the string with the Wayfinder `index()` helper) |
| F-04 | Medium | `user-stories.md` "Cross-cutting acceptance"; `coding-standards.md` §4 (grep `US-\d{2}\.AC\d+`) | The three cross-cutting criteria have **no IDs**. They can't prefix a test name, the matrix grep can't find them, and the vision's "100% of Must AC covered" can't be measured for them | `user-stories.md`: number them `CC.AC1` (accessible name and visible focus), `CC.AC2` (errors next to the field, linked by `aria-describedby`) and `CC.AC3` (no raw HTML). This edits a G0 document, so it needs owner approval. `coding-standards.md` §4: widen the regex to `(US-\d{2}\|CC)\.AC\d+` |
| F-05 | Medium | `requirements.md` NFR-05; `ADR-0006` §NFR-05 | **Warm p95 has no instrument.** ADR-0006 measures "app-side duration", but no tool supplies that number: monitoring is undecided (Q3), and Cloud metrics as a source are unstated. The run also needs an *authenticated, verified* session on staging. Cold start has no defined idle period before the measured request | `ADR-0006` §NFR-05: name the instrument. Proposal in "NFR measurability" below: a `curl`-based staging job with no new dependency, using the seeded demo user, with the network round-trip subtracted against `/up`. State the minimum idle time before the cold request, taken from Cloud docs (UNVERIFIED value) |
| F-06 | Medium | `security.md` T-05 "Verified by" | **The planned CSRF test can't fail.** "A POST without a token returns a redirect back with the flash toast" passes trivially, because `PreventRequestForgery` skips verification under unit tests (verified, `:99`, `:130–132`). The browser server runs in the same test process (inferred, UNVERIFIED), so it is bypassed too. | `security.md` T-05: (a) test the **419 handler** directly with a test-only route that throws `TokenMismatchException`, asserting a redirect back plus `assertInertiaFlash('toast', ['type' => 'warning', 'message' => 'The page expired, please try again.'])`; (b) prove CSRF is **enforced** by configuration, not behaviour: the `web` group contains the CSRF middleware (route-list diff in §1.2) and its `except` list is empty |
| F-07 | Medium | `contracts.md` §3 / `architecture.md` §3; `ADR-0003` (N+1 row, item 2) | **The query budget is not in the contract.** The exact `expectsDatabaseQueryCount(n)` test needs `n`, and the contract says only "two todo queries plus the fixed session and user queries". Under the test environment (`actingAs`, `SESSION_DRIVER=array`) the middleware queries are probably 0, but that is unconfirmed | `contracts.md` §3: "With `actingAs()` and the testing env, `GET /todos` (any filter) executes exactly **2** queries." The T-001 spike confirms or corrects the number before G3. Until then, QA writes only the 1-vs-100 equality test |
| F-08 | Medium | `ADR-0003` §CI gates; kit `tests/Pest.php` | **CI jobs don't say which suites they run.** `test-sqlite`, `mutation` and `test-pgsql` all run plain `pest --parallel`, which would include `tests/Browser`: Playwright would be needed in every job, and browser tests would run inside coverage and mutation. R-003 §4 says to keep them out, but the ADR table doesn't. Also, `Pest.php` binds `TestCase` and `RefreshDatabase` only to `Feature` (verified), so browser tests get no database reset unless that is configured | `ADR-0003`: define `phpunit.xml` test suites `Unit`, `Feature`, `Arch` and `Browser`. Non-browser jobs run `--testsuite=Unit,Feature,Arch`, and the browser jobs run `--testsuite=Browser`. `test-strategy.md`: `pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature', 'Browser')` |
| F-09 | Medium | `ADR-0003` (browser row); R-003 E6, E7 | **Browser plugin APIs are still UNVERIFIED for 4 ACs and 2 NFRs.** Unconfirmed: how to read `document.activeElement` (US-02.AC6, US-06.AC4, CC.AC1), sequential Tab presses inside a dialog (US-06.AC4), an exact 320 px viewport (NFR-09), and which engine `assertNoAccessibilityIssues()` uses (NFR-06, contrast) | The T-001 spike must confirm, and `test-strategy.md` must record: (1) a script or evaluate call that returns `document.activeElement` attributes; (2) key presses (`Tab`, `Shift+Tab`, `Escape`, `Space`); (3) an exact viewport, or the Playwright `iPhone SE` preset (320×568); (4) that the accessibility engine is axe-core and which rule tags it applies. Any API that is missing becomes a documented manual check with the owner's approval, not a silent gap |
| F-10 | Medium | `contracts.md` §6 (`TodoDeleteDialog`, `TodoItem`, `TodoEditDialog`) | **Focus has nowhere defined to go when the item leaves the list.** US-06.AC4 says focus returns to the trigger, but after a *confirmed* delete the trigger is gone. The same happens after completing a todo under `?filter=active`, or uncompleting one under `?filter=completed`. Where focus goes after saving in the edit dialog is also unstated. A test can't assert an undefined destination, and focus falling back to `<body>` breaks NFR-06 ("every action works by keyboard") | `contracts.md` §6: define it, for example "If the item no longer exists or no longer matches the filter, focus moves to the next item's checkbox, else the previous item's, else the title input. After a save, focus returns to the `Edit` trigger" |
| F-11 | Medium | `contracts.md` §6 `TodoEmptyState` vs `user-stories.md` US-03.AC3 | "Invites the user to add a todo **and focuses the title field**" is ambiguous. Does focus move on render (autofocus) or when the user activates a control? Autofocus on page load takes screen-reader users past the page heading and makes the US-03.AC3 browser test order-dependent | `contracts.md` §6: "No autofocus on load. The empty state has a button `Add your first todo` that moves focus to the title input." Give its exact text in §6.1 (F-02) |
| F-12 | Medium | `requirements.md` NFR-06; `ADR-0003` (browser row) | "`assertNoAccessibilityIssues()` on every page" doesn't say which pages or **which states**. It also doesn't cover visible focus, which axe doesn't test, or dark mode (the kit has light, dark and system appearances). The kit's own pages may already fail at the moderate level. That is unknown until T-001 runs | `ADR-0003` / `test-strategy.md`: list the states to scan (guest: `/`, `/login`, `/register`, `/forgot-password`; unverified user: `/email/verify`; `/todos` empty, populated with an overdue todo, with validation errors, edit dialog open, delete dialog open; the settings pages), each in light and dark. Add a focus-visible probe (see "NFR measurability"). T-001 records the kit's baseline result, and any kit failure is fixed or owner-approved |
| F-13 | Medium | `user-stories.md` US-01.AC6; `contracts.md` §7 (missing row) | **US-01.AC6 is not "covered by the kit's own tests".** The kit only tests the redirect to the challenge and that the challenge page renders (verified). No test logs in with a valid TOTP, rejects an invalid one, or accepts a recovery code | Fix the US-01.AC6 note in `user-stories.md` (G0 doc, owner approval). Add a §7 row in `contracts.md`. QA adds tests to `tests/Feature/Auth/TwoFactorChallengeTest.php`: generate a valid code from the user's secret through Fortify's 2FA provider, then accept a valid code, reject an invalid one, and accept a recovery code exactly once |
| F-14 | Low | `user-stories.md` US-05.AC3 ("toggle") vs `contracts.md` §1.1 / ADR-0005 (desired state) | This is a wording difference, not a contradiction. The UI toggles, and the API takes `{completed: bool}`. A tests-first author might send only one direction | `contracts.md` §1.1 note: "'Toggle' in the stories means the UI action. Authorisation tests cover both `completed: true` and `completed: false`." Also define the toast for a repeated request (`true` → already completed). Proposal: the same "Todo completed." |
| F-15 | Low | `contracts.md` §5.1 (`completed_at`, `created_at`) | "ISO 8601, UTC" allows several string forms (`Z`, `+00:00`, with or without fractional seconds). The strict shape test needs exact values | §5.1: "Format `Y-m-d\TH:i:sP` (Carbon `toIso8601String()`), for example `2026-03-10T12:00:00+00:00`" |
| F-16 | Low | `contracts.md` §2 | How `PATCH /todos/{todo}` treats omitted fields is unstated. Under the listed rules, an omitted `description` or `due_date` is absent from `validated()` and stays unchanged, an explicit `null` clears it, and `title` is always required | §2: state that rule explicitly. US-04.AC1 and AC2 tests depend on it |
| F-17 | Low | `contracts.md` §3 | The "All" filter link's URL isn't defined: `/todos` or `/todos?filter=all`. The US-07.AC3 browser test asserts the URL | §3: "The All link points to `/todos` with no query string." Or choose `?filter=all` explicitly |
| F-18 | Low | `contracts.md` §2 (`completed`) | The `boolean` rule accepts `true`, `false`, `1`, `0`, `"1"` and `"0"`, and rejects `"true"` and `"false"`. The contract doesn't list which values are accepted, so the rejection dataset is a guess | §2: list the accepted and rejected values (the UI sends a JSON boolean) |
| F-19 | Low | `data-model.md` §4 | Factory states compute dates when they are defined (`overdue()` uses `today()->subDay()`, `dueToday()`, `completed()` uses `now()`), so they are wrong if a test calls `travelTo()` *afterwards*. The ADR-0005 idempotency test needs a fixed `completed_at`. Faker titles are random, which makes ordering and browser tests read badly | §4: build states with closures, evaluated when the model is made. Add `completedAt(CarbonInterface $t)`. Tests that assert order or text use explicit titles. Rule: freeze time with `travelTo()` **before** creating models |
| F-20 | Low | `contracts.md` §7; kit `AuthenticationTest:68` | US-01.AC4's kit test skips the "5 failed attempts in a row" behaviour by pre-incrementing the limiter (verified). §7 also omits US-01.AC6 and the CC rows | QA adds a real 5-attempt test (6th → 429) and keeps the kit test. §7: add the missing rows (or point to the traceability matrix as the source of truth) |
| F-21 | Low | `architecture.md` Q5; `contracts.md` §5.1 `is_overdue` | Overdue depends on Q5 (UTC "today"). The rule itself is consistent: `data-model.md` (`due_date < today()->startOfDay()`) matches FR-08, and `config/app.php` hard-codes UTC (verified). If the owner rejects the Q5 recommendation, the boundary tests change. `travelTo` inside the browser-test server is UNVERIFIED | No change if Q5 is accepted. QA adds a guard test `config('app.timezone') === 'UTC'`. The browser overdue test uses `due_date = 2000-01-01`, so it needs no time travel |
| F-22 | Low | `ADR-0003` (coverage row) vs NFR-07 | NFR-07 says **line** coverage. The per-directory clover script "sums statements" (R-003 §2). In PHPUnit clover, file-level `statements` are executable lines, but the metric should be named. NFR-07's "changed domain code" for mutation versus ADR's "all classes declared with `mutates()`, on every PR" is a stricter superset, so it is consistent. Thresholds 85/90/90/70 and NFR-08 (level 8; zero Pint, `vp check` and vue-tsc errors) match | `ADR-0003`: "The per-directory script uses clover file-level `statements` and `coveredstatements`, which PHPUnit counts as executable lines." No threshold change |

**Counts:** 3 High, 10 Medium, 9 Low.

## 1. AC → test mapping (summary)
The full mapping is in `traceability-matrix.md`.

| Story | AC IDs | Layer(s) | Fully specified today? |
|---|---|---|---|
| US-01 | AC1–AC6 | Pest feature (AC1 and AC2 new; AC3–AC6 in edited kit tests) | Yes once F-03 (AC3) and F-13 (AC6) are fixed |
| US-02 | AC1–AC6 | Pest feature (AC1–AC5) + browser (AC1, AC6) | AC1–AC5 yes, with F-01. AC6 needs F-02 and F-09 |
| US-03 | AC1–AC6 | Pest feature (all) + browser (AC3, AC4, AC5 display) | Feature yes. AC6's exact budget needs F-07. Browser needs F-02 and F-11 |
| US-04 | AC1–AC3 | Pest feature | Yes, with F-01 and F-16 |
| US-05 | AC1–AC5 | Pest feature (AC1–AC4) + browser (AC5) | Feature yes. AC5 needs F-02 (completed-state label) |
| US-06 | AC1–AC4 | Pest feature (AC1, AC3) + browser (AC1, AC2, AC4) | Feature yes. Browser needs F-02, F-09 and F-10 |
| US-07 | AC1–AC4 | Pest feature + unit (`TodoFilter`) + browser (AC3, AC4) | Feature yes. Browser needs F-02 and F-17 |
| Cross-cutting | CC.AC1–CC.AC3 (proposed) | Browser + CI check (`npm run check:html` with fixtures) | Needs F-04 (IDs), F-02, F-09 and F-12 |

**Mapped: 37 / 37.**
- **22** can be written in full as soon as F-01 is fixed.
- **8** can be written in part: the feature-level tests are ready, but one test waits on a finding (US-01.AC3, US-02.AC1, US-03.AC3, US-03.AC4, US-03.AC6, US-06.AC1, US-07.AC3, CC.AC3).
- **7** are blocked entirely until F-02, F-04 and F-09 are resolved (US-02.AC6, US-05.AC5, US-06.AC2, US-06.AC4, US-07.AC4, CC.AC1, CC.AC2).

The matrix marks each blocking finding in its Contract reference column.

### What is already deterministic (no change needed)
- **Authorisation before validation** (`can:` route middleware runs before the FormRequest resolves). A non-owner always gets 403 with the row unchanged, even with an invalid payload. Nonexistent id → 404 (binding in `web`). Guest → `assertRedirect(route('login'))` (`Authenticate` runs before `SubstituteBindings` by middleware priority).
- **Validation message** for US-02.AC2: the exact text "The title field is required." (Laravel default; whitespace-only input becomes `null` through `TrimStrings` and `ConvertEmptyStringsToNull`).
- **Due-date rules**: `date_format:Y-m-d` with 2000-01-01 to 2099-12-31 bounds, which gives a clean accept and reject dataset.
- **Filter semantics** are fully enumerated, including `ACTIVE`, an empty value and `filter[]=x`. Counts ignore the filter.
- **Ordering** `created_at DESC, id DESC`. Ties are fully deterministic: create several todos under one `travelTo()` instant and expect descending ids.
- **Toast texts** are exact and assertable with the verified `assertInertiaFlash('toast', [...])` macro, read from the redirect response's session.
- **Prop shape**: one strict `assertInertia` with no `etc()`, and `missing('todos.0.user_id')`.
- **Back-redirect with filter**: `->from('/todos?filter=active')` on the write, then `assertRedirect('/todos?filter=active')`.

## 2. Contradiction check
| Pair | Result |
|---|---|
| US-05 "toggle" vs `PATCH …/completion {completed: bool}` | Not contradictory. It is a UI-versus-API term. Clarify with F-14 |
| 302 vs 303 | Contradiction with the *framework*, not between documents: the 303 only applies to Inertia requests (F-01) |
| Error messages | Consistent. US-02.AC2 gives the exact text, AC3 and AC4 only say "a validation error", and the tests assert the field key |
| 403 vs 404 | Consistent. The ACs require 403 for another user's todo. A nonexistent id gives 404, which is allowed and documented (`security.md` T-01 accepts the existence leak) |
| US-01.AC6 "covered by the kit's tests" vs the kit | **Contradiction** (F-13) |
| US-03.AC3 empty state vs `contracts.md` §6 "focuses the title field" | Ambiguous (F-11) |
| US-06.AC4 "focus returns to the trigger" vs a deleted trigger | Gap (F-10) |
| ADR-0003 thresholds vs NFR-07 and NFR-08 | Consistent: 85 / 90 / 90 / 70, level 8, zero lint and type errors. Metric naming only (F-22) |
| `architecture.md` §3 "`due_date < today()`" vs `data-model.md` `isOverdue(now())` with `startOfDay()` | Consistent (same rule) |
| `contracts.md` §3 "counts ignore the filter" vs filter links `only: ['todos','filter']` | Consistent |

## 3. NFR measurability
| NFR | CI? | Concrete check |
|---|---|---|
| NFR-02 | **CI** (feature) | `AuthorizationTest`, a dataset over all 4 `{todo}` routes × {guest → login, other user → 403 and row unchanged, nonexistent → 404}, plus `IndexTest` `missing` other users' ids. Route-list diff (§1.2) asserts the `can:` middleware |
| NFR-03 | **CI** | `npm run check:html` plus fixture tests (`v-html` fails, `:innerHTML` fails, the allow-listed QR passes, a second `v-html` in the same file fails). Browser: the title `<img src=x onerror=alert(1)>` renders as literal text and `assertNoJavaScriptErrors()` passes |
| NFR-05 query count | **CI** (feature) | US-03.AC6: 1 vs 100 todos, equal query counts, for each filter value. The exact budget follows once F-07 is fixed. `preventLazyLoading` throws in tests |
| NFR-05 warm | **Staging only** (post-deploy CI job, not the PR path) | Proposal with no new dependency: `deploy-staging.yml` logs in as the seeded demo user (fetch `XSRF-TOKEN`, POST `/login` with a cookie jar; the password comes from a repository secret, staging-only synthetic data). It sends 10 discarded warm-up requests, then 200 sequential `curl -o /dev/null -w '%{time_starttransfer}\n' /todos` requests at 100 seeded todos, and the same for `/up`. p95(`/todos`) − median(`/up`) must be < 300 ms (sort, take the 190th value). This approximates app time from the runner, so the report states the method. If Q3 adds monitoring, its p95 replaces this |
| NFR-05 cold | **Staging only** (nightly) | A scheduled job sends nothing to staging for longer than the Cloud idle timeout (value UNVERIFIED, from Cloud docs), then sends one authenticated `GET /todos`, asserts `time_total` < 2 s and reports it separately |
| NFR-06 | **CI** (browser), plus manual before G5 | (1) `assertNoAccessibilityIssues()` at level 2 over the state list in F-12, light and dark. (2) Scripted keyboard ACs (US-02.AC6, US-05.AC5, US-06.AC4, US-07.AC4). (3) **Focus-visible probe**: Tab through `/todos` and, for each focused element, assert that its computed `outline-style` is not `none` or its `box-shadow` is not `none`. (4) Error linkage (CC.AC2): `aria-describedby` resolves to an element containing the error text. Manual: a Lighthouse or axe pass on staging and a screen-reader smoke test before G5 (axe covers only part of WCAG 2.2 AA) |
| NFR-07 / NFR-08 | **CI** | As in ADR-0003, with F-08 (suite split) and F-22 (metric) |
| NFR-09 | **CI** (Chromium on PRs; Firefox and WebKit on `main` and nightly), plus a manual Safari check before G5 | At 320×568 (exact viewport, or the `iPhone SE` preset, F-09): run the journey (add, complete, edit, delete, filter) and assert `document.documentElement.scrollWidth <= window.innerWidth` (no horizontal scroll), plus the accessibility scan. Desktop at 1280×800 |
| NFR-10 | Partly CI | Feature test: `GET /up` returns 200. Logs without PII: the arch test (no `Log` facade in `App\Http`). Error tracking: G5 checklist (Q3) |
| NFR-11 | **CI** (both drivers) | US-01.AC5 test asserts `Todo::where('user_id', $id)->count() === 0` after deletion |

## 4. Test data
| Need | Plan |
|---|---|
| Users | Kit `UserFactory`, which has `unverified()` and `withTwoFactor()` (verified). One owner and one "other" user per authorisation test |
| Todos | `TodoFactory` states from `data-model.md` §4, plus `completedAt($t)`, all built with closures (F-19). Explicit titles wherever order or text is asserted |
| Time | Pest/Laravel `travelTo()` (or `freezeTime()`) **before** creating models. Overdue boundaries: at `2026-03-10 00:00:00` UTC, due `2026-03-09` is overdue and due `2026-03-10` is not. At `2026-03-10 23:59:59`, due `2026-03-10` is not overdue. Completed with a past due date, and a null due date, are never overdue. `completed_at` assertions compare against the frozen `now()` |
| Ordering ties | Create 3 todos at one frozen instant and expect `id` descending. Separately, create them with `createdAt()` at different instants, inserted out of order |
| Counts | 2 active + 1 completed. Assert integers exactly (`where('counts.total', 3)`, a strict comparison that the pgsql job also runs) |
| Query count | 1 todo vs 100 (`Todo::factory()->count(100)->for($user)`), mixed completed and overdue states |
| Rate limits | US-01.AC4: 5 real failed POSTs, then the 6th → 429. `todo-writes`: 120 POSTs, then the 121st → 429. The `array` cache store is reset per test |
| Inertia requests | Helper `inertia()` = `withHeaders(['X-Inertia' => 'true'])` for write-status assertions (F-01) |

## 5. Kit tests affected by Q1 (Dashboard removal)
| Kit test | Change (QA only, under the exception in F-03) |
|---|---|
| `tests/Feature/DashboardTest.php` (2 tests) | **Replaced** by `Feature/Todos/IndexTest`: `US-01.AC1 …` (guest → login) and `US-03 … renders todos/Index for a verified user` (200 + component) |
| `Auth/AuthenticationTest.php:24` | `route('dashboard', absolute: false)` → `route('todos.index', absolute: false)`. Rename the test with the `US-01.AC3` prefix |
| `Auth/AuthenticationTest.php:27` | Prefix `US-01.AC6` (redirect to the challenge). No assertion change |
| `Auth/AuthenticationTest.php:68` | Prefix `US-01.AC4`. Keep it, and add the real 5-attempt test (F-20) |
| `Auth/RegistrationTest.php:26` | Retarget to `todos.index`. Behaviour: a new, unverified user is redirected to `/todos` and then to verify (non-AC) |
| `Auth/EmailVerificationTest.php:47,92,107` | Retarget to `todos.index` (`?verified=1` kept) |
| `Auth/VerificationNotificationTest.php:33` | Retarget to `todos.index` |
| `Auth/TwoFactorChallengeTest.php` | Add the US-01.AC6 code-submission tests (F-13) |
| `Settings/ProfileUpdateTest.php:96` | Prefix `US-01.AC5`. Extend it to create todos for the user (and for another user) and assert only the user's own todos are gone (NFR-11) |
| `tests/Pest.php` | Bind `TestCase` and `RefreshDatabase` to `Browser` as well (F-08). Add the `inertia()` helper |

Frontend (not tests, but it affects the `npm run build` and `types:check` gates): `AppSidebar.vue`, `AppHeader.vue`, `Welcome.vue`, `PasskeyVerify.vue`, and `pages/Dashboard.vue` removed (F-03).

## 6. Gaps a tests-first QA agent would otherwise have to guess
All of these are covered by the findings above: the request header for the 303 (F-01); every accessible name and visible string (F-02); the kit-test exception (F-03); the cross-cutting IDs (F-04); the query budget (F-07); the focus destinations (F-10, F-11); the timestamp format (F-15); the partial-update semantics (F-16); the All-link URL (F-17); the accepted boolean values (F-18); the toast for a repeated completion (F-14).
Nothing in the design is **untestable** in principle. NFR-05 warm and cold are measurable only on staging, and part of NFR-06 (a full WCAG audit, screen readers) and real Safari for NFR-09 stay manual before G5.

## Recommendation to the owner (G2)
Approve the architecture **once F-01, F-02 and F-03 are fixed in the docs**. Before G3, either fix the Medium findings (F-04 to F-13) or carry each into a named T-001 spike item. The Low findings can be fixed in the `contracts.md` edit that QA's first tests-first task depends on.

## Re-check (2026-11-17)

**Author:** QA engineer agent (design-review mode) · **Scope:** the three High findings only (G2 decision packet, "After approval" step 2), a sanity check of the Medium/Low dispositions, and a contradiction scan of the revised docs.
**Inputs:** `docs/02-architecture/G2-decision-packet.md` (approved 2026-11-17), revised `contracts.md`, `architecture.md`, `security.md`, `data-model.md`, `coding-standards.md`, ADR-0001 to ADR-0006, `docs/00-product/user-stories.md` (now with `CC.AC1`–`CC.AC3`), `requirements.md`, `docs/governance.md` (exception E-1), `.claude/agents/qa-engineer.md`, `.claude/hooks/scopes.json`.

### Verdict: **Testable with changes** (the changes are not G2 blockers)
All three High findings are **resolved**. I found no new contradiction that blocks G2. Three new issues (N-1 to N-3 below) would make a tests-first QA agent stop or guess on 3 rows. Each is a doc-only fix and should land before G3, or before the task that writes those rows.

### High findings
| ID | Status | Where it is resolved | Notes |
|---|---|---|---|
| F-01 | **RESOLVED** | `contracts.md` §1.1.1 (status table per request type, lines 19–37, with the "Test convention" paragraph); `architecture.md` §2 step 5 (lines 52–53) and §6 "Write responses" | Inertia POST → 302, Inertia PATCH/DELETE → 303, no header → 302, the 419 handler → 302 for every method. The `inertia()` helper is named in the contract. The "302/303" wording is gone. The T-001 spike checks each cell against the kit before QA writes the status tests. That's acceptable, because any change needs owner approval |
| F-02 | **RESOLVED** | `contracts.md` §6.1 "UI contract": elements U-01 to U-23 and focus targets F-a to F-l (lines 242–288); §3 link targets (line 132); `user-stories.md` line 85 points to §6.1 | Every name and string I asked for is defined: the labels, buttons, dialog titles, filter-link format `<Label> (<n>)`, empty-state texts, error ids `<form>-<field>-error` and the `aria-invalid` rule. One proposal was rejected with a reason I accept (`architecture.md` §9 "Rejected review items"): the checkbox keeps one name, `Mark "<title>" complete`, in both states, and the test asserts `checked` instead. F-10 (focus after delete, completion, edit or filter change) and F-11 (no autofocus, plus the `Add your first todo` button) are resolved in the same table |
| F-03 | **RESOLVED** | `governance.md` §Exceptions **E-1** (line 59); `architecture.md` §5.1 (lines 140–155: complete file list including `AppHeader.vue`, `Welcome.vue` and `PasskeyVerify.vue:33`); `contracts.md` §1.1 "Kit routes changed" and §7 row US-01.AC1 | The exception is scoped: delete `DashboardTest`, replace it 1:1 with `IndexTest` (`US-01.AC1` plus 200 for a verified user), retarget 6 assertions, all in one QA task with `human_approval_required: [E-1]`. My role file still says a deleted test is a blocker, so in verification mode I will treat E-1 as the only allowed deletion. For the `PasskeyVerify.vue` string, which no tool catches, the fallback is a grep in the handoff plus a passkey check "when feasible" after T-001. The matrix carries that check as a conditional row |

### Medium and Low findings: sanity check
All 19 (F-04 to F-22) are mapped in `architecture.md` §9. F-06 is mapped with A-02 ("A-02 / F-06"), and F-21 is marked superseded by G2-13, with its guard test rejected for a reason I accept. I re-checked these only as far as the matrix needed (F-04, F-07, F-10, F-11, F-17). All are reflected in the docs.

### New issues (contradictions a tests-first agent would hit)
| ID | Severity | Location | Issue | Required change (doc) |
|---|---|---|---|---|
| N-1 | **Medium** (stops one row) | ADR-0003 "Frontend unit tests"; `contracts.md` §5.3 and §7; `coding-standards.md` §4 (lines 59, 67); `architecture.md` §5 vs `governance.md` "Loop guardrails", `.claude/agents/qa-engineer.md` line 23, `.claude/hooks/scopes.json` (`qa-engineer.allow: resources/js/*.spec.ts`; `vue-engineer.deny: *.spec.ts`) | **The QA agent may not create `resources/js/lib/overdue.test.ts`.** Governance and the path guard let QA write only `*.spec.ts` under `resources/js/`, and they *don't* stop the vue-engineer from writing or editing a `*.test.ts`. So the US-03.AC5 unit test is either blocked for QA, which escalates under the governance rule, or it ends up owned by the implementer, which breaks tests-first independence | Rename it to **`resources/js/lib/overdue.spec.ts`** in ADR-0003, contracts §5.3 and §7, `coding-standards.md` §4 (file pattern and matrix grep `resources/js/**/*.spec.ts`) and `architecture.md` §5. Vitest's default include matches `*.spec.ts`, so `vp test run` needs no config (UNVERIFIED for Vite+'s defaults: the overdue task confirms it). The other option, widening QA's scope to `*.test.ts`, means owner-only edits to `scopes.json` and governance. Until N-1 is decided, the matrix keeps the requested name and notes the issue |
| N-2 | **Medium** | `security.md` T-24 "Verified by" (`HttpsRedirectTest`); `architecture.md` §5 (`URL::forceHttps(...)` in `AppServiceProvider`) | **"With the environment switched to production … `route('todos.index')` starts with `https://`" can fail against a correct implementation.** `URL::forceHttps(! app()->environment(['local','testing']))` is evaluated once in `boot()`. The test app boots as `testing`, so changing the environment inside the test doesn't re-run the provider (inferred from where the call sits. Same class of problem as F-01). The 308 half is fine, because `SecurityHeaders` decides per request. `PasswordPolicyTest` is unaffected if the kit's `Password::defaults()` stays a closure, which it is in the kit | State the mechanism in T-24 or `test-strategy.md`, for example: switch the environment, then re-run `AppServiceProvider::boot()` (or call `$this->refreshApplication()` with `APP_ENV=production`) before asserting URL generation. Add it to the T-001 spike list |
| N-3 | **Low** | `user-stories.md` CC.AC2 ("**Every** form") vs `contracts.md` §6.1 U-07 and §7 CC.AC2 (only `todos.store` and `todos.update`) | It's unclear whether the kit's forms (login, register, settings) must link errors with `aria-describedby`. A tests-first agent can't tell whether to write CC.AC2 tests for them. The kit's `InputError` is probably a sibling `<div>` with no id link (UNVERIFIED), and the axe scan doesn't check this | `contracts.md` §7: state that CC.AC2 covers the todo forms only and that kit forms are covered by the CC.AC1 scan (owner-visible scope note), **or** add the kit forms to U-07 and record the kit baseline at T-001 |

Smaller items, no doc change needed:
- `security.md` T-05 point (3) (the middleware with `runningUnitTests()` overridden to false) names no file. The matrix puts it in `CsrfConfigTest`.
- A user with zero todos who visits `/todos?filter=active` sees U-21 `No active todos.`, which has no invitation. So the US-03.AC3 browser test uses `/todos` (U-20).
- `security.md` §4 puts the todo-write limiter test in `Feature/Todos/RateLimitTest`. The previous matrix had it in `StoreTest`, and the matrix now follows security.md.

Checked and consistent: US-03.AC5 ("browser date") vs contracts §5.3 vs ADR-0003 vs data-model (no server `isOverdue`, factory `overdue()` = `2000-01-01`); US-06.AC4 vs F-g/F-h; US-05 "toggle" vs §1.1 note (both values in the IDOR tests); the query budget (exactly 2) in contracts §3, architecture §3 and ADR-0003; the filter link targets in contracts §3 vs U-19; the security.md §4 test list vs `architecture.md` §5 `tests/` layout (every named file appears in both); ADR-0003's CI suites vs where the browser tests live.

### Matrix update
`traceability-matrix.md` has been rewritten to match the revised design:
- **37 / 37** AC IDs mapped (34 story ACs + `CC.AC1`–`CC.AC3`), in 63 AC rows.
- 0 rows are still blocked by F-findings.
- 4 rows carry a pending condition: the exact query budget after T-001, the passkey landing check after T-001, and the two overdue unit-test rows (file name, N-1).
- 44 security, NFR and supporting rows: 36 keyed by a threat ID, 7 by an NFR alone and 1 by FR-01. The UTC guard row is removed.
