# Traceability matrix

**Status:** Draft (G2 design review, 2026-11-16, QA engineer agent). No tests exist yet. Every row is **Planned**.
**Source of truth after implementation:** grep `(US-\d{2}|CC)\.AC\d+` in `tests/` (`coding-standards.md` §4, regex widened per G2 review F-04). This file is regenerated from that grep in verification mode.
**Blocked by** notes refer to findings in `G2-testability-review.md`. A row marked blocked can't be written tests-first until that doc change lands.

Conventions:
- Write-route tests send `X-Inertia: true` through a `tests/Pest.php` helper and assert the exact status: POST 302, PATCH and DELETE 303 (F-01).
- Toasts are asserted with `assertInertiaFlash('toast', [...])` (verified in inertia-laravel 3.7.0).
- Time is frozen with `travelTo()` before any model is created.

Layers:
- **Feature**: Pest feature test (SQLite job and pgsql job).
- **Unit**: Pest unit test (pure PHP).
- **Browser**: Pest browser plugin (Chromium on PRs; Firefox and WebKit on `main` and nightly).
- **Kit feature**: an edited starter-kit Pest test.
- **CI check**: a non-Pest CI job.

## Acceptance criteria

| AC ID | Test layer | Planned test file | Planned test name (prefixed with AC ID) | Contract reference | Status |
|---|---|---|---|---|---|
| US-01.AC1 | Feature | tests/Feature/Todos/IndexTest.php | `US-01.AC1 redirects a guest from /todos to the login page` | contracts §1.1 `todos.index` (302 → `/login`) | Planned |
| US-01.AC2 | Feature | tests/Feature/Todos/IndexTest.php | `US-01.AC2 redirects an unverified user from /todos to the email verification notice` | contracts §1.1 `todos.index` (302 → `/email/verify`); kit `UserFactory::unverified()` | Planned |
| US-01.AC3 | Kit feature | tests/Feature/Auth/AuthenticationTest.php | `US-01.AC3 lands a user on /todos after a valid login` | contracts §1.1 "Kit routes changed" (`fortify.home=/todos`); blocked by F-03 (exception for kit test edit) | Planned |
| US-01.AC3 | Browser | tests/Browser/AuthJourneyTest.php | `US-01.AC3 lands a user on /todos after logging in through the login form` | ADR-0001 Auth; F-03 (`PasskeyVerify.vue` fallback) | Planned |
| US-01.AC4 | Kit feature | tests/Feature/Auth/AuthenticationTest.php | `US-01.AC4 rate-limits login once the limiter holds 5 attempts` (kit test, renamed) | security.md T-08 | Planned |
| US-01.AC4 | Feature | tests/Feature/Auth/AuthenticationTest.php | `US-01.AC4 returns 429 on the sixth login after five real failed attempts` | security.md T-08 (Fortify `login` limiter, 5/min per email+IP); F-20 | Planned |
| US-01.AC5 | Kit feature | tests/Feature/Settings/ProfileUpdateTest.php | `US-01.AC5 deletes the user and all their todos when the password is confirmed` (other user's todos remain) | data-model §2 (FK cascade), §5; NFR-11; security.md T-10 | Planned |
| US-01.AC5 | Kit feature | tests/Feature/Settings/ProfileUpdateTest.php | `US-01.AC5 keeps the user and their todos when the password is wrong` | data-model §5 | Planned |
| US-01.AC6 | Kit feature | tests/Feature/Auth/AuthenticationTest.php | `US-01.AC6 redirects a user with 2FA enabled to the two-factor challenge` (kit test, renamed) | ADR-0001 Auth (G1-3) | Planned |
| US-01.AC6 | Feature | tests/Feature/Auth/TwoFactorChallengeTest.php | `US-01.AC6 logs in with a valid TOTP code`; `US-01.AC6 rejects an invalid TOTP code`; `US-01.AC6 accepts a recovery code only once` | security.md T-09; F-13 (kit does not cover this) | Planned |
| US-02.AC1 | Feature | tests/Feature/Todos/StoreTest.php | `US-02.AC1 creates an active todo, redirects back with "Todo added." and lists it first` | contracts §1.1 `todos.store` (302, toast), §3 ordering, §5.2 | Planned |
| US-02.AC1 | Browser | tests/Browser/TodoJourneyTest.php | `US-02.AC1 shows a newly added todo at the top of the list` | contracts §6 `TodoCreateForm`; blocked by F-02 | Planned |
| US-02.AC2 | Feature | tests/Feature/Todos/StoreTest.php | `US-02.AC2 rejects an empty, whitespace-only or missing title with "The title field is required." and creates nothing` | contracts §2 `title` | Planned |
| US-02.AC3 | Feature | tests/Feature/Todos/StoreTest.php | `US-02.AC3 rejects a 256-character title and creates nothing`; `US-02.AC3 accepts a 255-character title including multibyte characters` | contracts §2 `title` (`max:255`); data-model §2 `varchar(255)` | Planned |
| US-02.AC4 | Feature | tests/Feature/Todos/StoreTest.php | `US-02.AC4 rejects an invalid due date and creates nothing` (dataset: `2026-02-30`, `16/11/2026`, `tomorrow`, `1999-12-31`, `2100-01-01`, array); `US-02.AC4 accepts 2000-01-01, 2099-12-31, a past date and null` | contracts §2 `due_date` | Planned |
| US-02.AC5 | Feature | tests/Feature/Todos/StoreTest.php | `US-02.AC5 ignores a posted user_id, completed_at and id and assigns the todo to the author` | contracts §2 (keys not in `rules()`); data-model §3 Fillable; security.md T-02 | Planned |
| US-02.AC6 | Browser | tests/Browser/TodoJourneyTest.php | `US-02.AC6 returns focus to the title field after adding a todo, allowing several adds by keyboard` | contracts §6 `TodoCreateForm`; blocked by F-02, F-09 | Planned |
| US-03.AC1 | Feature | tests/Feature/Todos/IndexTest.php | `US-03.AC1 lists only the signed-in user's todos` (`missing` other users' ids) | contracts §5.2; architecture §4 Tenancy | Planned |
| US-03.AC2 | Feature | tests/Feature/Todos/IndexTest.php | `US-03.AC2 orders todos newest first by created_at`; `US-03.AC2 breaks created_at ties by id descending` | contracts §3 (`created_at DESC, id DESC`) | Planned |
| US-03.AC3 | Feature | tests/Feature/Todos/IndexTest.php | `US-03.AC3 returns an empty todos array and zero counts for a user with no todos` | contracts §5.2 | Planned |
| US-03.AC3 | Browser | tests/Browser/TodoJourneyTest.php | `US-03.AC3 shows the empty state inviting the user to add a todo` | contracts §6 `TodoEmptyState`; blocked by F-02, F-11 | Planned |
| US-03.AC4 | Feature | tests/Feature/Todos/IndexTest.php | `US-03.AC4 returns correct integer total, active and completed counts`; `US-03.AC4 returns the same counts under every filter` | contracts §3 (counts ignore filter), §5.1 `TodoCounts`, §5.2 | Planned |
| US-03.AC4 | Browser | tests/Browser/TodoJourneyTest.php | `US-03.AC4 shows each count in the filter navigation` | contracts §6 `TodoFilterNav`; blocked by F-02 (count format) | Planned |
| US-03.AC5 | Feature | tests/Feature/Todos/IndexTest.php | `US-03.AC5 marks an active todo due before today (UTC) as overdue` (boundary dataset with `travelTo`); `US-03.AC5 never marks completed, due-today or undated todos overdue` | contracts §5.1 `is_overdue`; data-model §3 `isOverdue`; architecture Q5; F-21 | Planned |
| US-03.AC5 | Browser | tests/Browser/TodoJourneyTest.php | `US-03.AC5 shows the text "Overdue" on an overdue todo` (due 2000-01-01) | contracts §6 `TodoItem` (badge text "Overdue") | Planned |
| US-03.AC6 | Feature | tests/Feature/Todos/QueryCountTest.php | `US-03.AC6 runs the same number of queries for 1 and 100 todos under every filter` | ADR-0003 N+1 row (1); architecture §3; ADR-0004 | Planned |
| US-03.AC6 | Feature | tests/Feature/Todos/QueryCountTest.php | `US-03.AC6 stays within the fixed query budget` (`expectsDatabaseQueryCount`) | ADR-0003 N+1 row (2); blocked by F-07 (budget not in contract) | Planned |
| US-04.AC1 | Feature | tests/Feature/Todos/UpdateTest.php | `US-04.AC1 saves a changed title, description and due date and redirects back with "Todo updated."`; `US-04.AC1 clears description and due date when null is sent` | contracts §1.1 `todos.update` (303, toast), §2; F-16 | Planned |
| US-04.AC2 | Feature | tests/Feature/Todos/UpdateTest.php | `US-04.AC2 applies the US-02.AC2 to AC4 rules on update and leaves the todo unchanged` (shared datasets) | contracts §2 (shared `TodoValidationRules`) | Planned |
| US-04.AC3 | Feature | tests/Feature/Todos/AuthorizationTest.php | `US-04.AC3 returns 403 and leaves another user's todo unchanged on update`; `US-04.AC3 returns 403 even when the payload is invalid` | contracts §1.1 `can:update,todo`, Notes "Ordering"; security.md T-01 | Planned |
| US-05.AC1 | Feature | tests/Feature/Todos/CompletionTest.php | `US-05.AC1 sets completed_at to now when an active todo is completed and flashes "Todo completed."`; `US-05.AC1 lists the completed todo under the Completed filter` | contracts §1.1 `todos.completion.update` (303), §2 `completed`, §3; ADR-0005 | Planned |
| US-05.AC2 | Feature | tests/Feature/Todos/CompletionTest.php | `US-05.AC2 sets completed_at to null when a completed todo is marked active and flashes "Todo marked as active."` | contracts §1.1, §2; ADR-0005 | Planned |
| US-05.AC3 | Feature | tests/Feature/Todos/AuthorizationTest.php | `US-05.AC3 returns 403 and leaves another user's todo unchanged when setting completed to true or false` | contracts §1.1 `can:update,todo`; F-14 | Planned |
| US-05.AC4 | Feature | tests/Feature/Todos/AuthorizationTest.php | `US-05.AC4 redirects a guest to login when setting completion and changes nothing` | contracts §1.1 (`auth`); architecture §2 step 2 | Planned |
| US-05.AC5 | Browser | tests/Browser/TodoJourneyTest.php | `US-05.AC5 toggles completion with the Space key on a labelled checkbox` (both directions) | contracts §6 `TodoItem`; blocked by F-02 (completed-state label), F-09 | Planned |
| US-06.AC1 | Feature | tests/Feature/Todos/DestroyTest.php | `US-06.AC1 deletes the owner's todo and redirects back with "Todo deleted."` | contracts §1.1 `todos.destroy` (303, toast) | Planned |
| US-06.AC1 | Browser | tests/Browser/TodoJourneyTest.php | `US-06.AC1 deletes a todo after confirming in the dialog` | contracts §6 `TodoDeleteDialog`; blocked by F-02 | Planned |
| US-06.AC2 | Browser | tests/Browser/TodoJourneyTest.php | `US-06.AC2 keeps the todo when the delete confirmation is cancelled` (row still exists, no DELETE sent) | contracts §6 `TodoDeleteDialog`; blocked by F-02 | Planned |
| US-06.AC3 | Feature | tests/Feature/Todos/AuthorizationTest.php | `US-06.AC3 returns 403 and keeps another user's todo on delete` | contracts §1.1 `can:delete,todo`; security.md T-01 | Planned |
| US-06.AC4 | Browser | tests/Browser/TodoJourneyTest.php | `US-06.AC4 traps focus in the delete dialog, closes on Escape and restores focus to the trigger` | contracts §6 `TodoDeleteDialog`; blocked by F-02, F-09, F-10 | Planned |
| US-07.AC1 | Feature | tests/Feature/Todos/FilterTest.php | `US-07.AC1 shows only active, only completed, or all todos for active, completed, all and no filter` | contracts §3 (filter table) | Planned |
| US-07.AC2 | Feature | tests/Feature/Todos/FilterTest.php | `US-07.AC2 falls back to all for unknown filter values without error or redirect` (dataset: `foo`, `ACTIVE`, empty, `filter[]=x`) | contracts §3 | Planned |
| US-07.AC2 | Unit | tests/Unit/TodoFilterTest.php | `US-07.AC2 TodoFilter::fromQuery maps unknown and non-string values to All` | contracts §3 (`fromQuery`) | Planned |
| US-07.AC3 | Feature | tests/Feature/Todos/FilterTest.php | `US-07.AC3 echoes the filter in the filter prop`; `US-07.AC3 redirects every write back to the filtered URL` (`from('/todos?filter=active')`) | contracts §1.1 Notes ("back"), §3 | Planned |
| US-07.AC3 | Browser | tests/Browser/TodoJourneyTest.php | `US-07.AC3 keeps the filter in the URL after clicking a filter link and reloading` | contracts §3 (filter links); blocked by F-02, F-17 | Planned |
| US-07.AC4 | Browser | tests/Browser/TodoJourneyTest.php | `US-07.AC4 sets aria-current="page" on the active filter link only` | contracts §3, §6 `TodoFilterNav`; blocked by F-02 | Planned |
| CC.AC1 (proposed ID) | Browser | tests/Browser/AccessibilityTest.php | `CC.AC1 has no accessibility issues at moderate or above in every listed page state, light and dark`; `CC.AC1 shows a visible focus indicator on every interactive control` | ADR-0003 browser row; coding-standards §3 Accessibility; blocked by F-04, F-09, F-12 | Planned |
| CC.AC2 (proposed ID) | Browser | tests/Browser/AccessibilityTest.php | `CC.AC2 links create and edit validation errors to their fields with aria-describedby` | contracts §6 `TodoEditDialog`; coding-standards §3; blocked by F-02, F-04 | Planned |
| CC.AC3 (proposed ID) | CI check | scripts/check-raw-html.mjs + tests/fixtures/raw-html/*.vue (`npm run check:html`) | `CC.AC3 fails on v-html and :innerHTML outside the allow-list` (fixture run) | ADR-0003 NFR-03 row; security.md T-03; blocked by F-04 (ID) | Planned |
| CC.AC3 (proposed ID) | Browser | tests/Browser/TodoJourneyTest.php | `CC.AC3 renders HTML in a todo title as literal text without JavaScript errors` | security.md T-03 | Planned |

**Coverage:** 37 of 37 AC IDs mapped: 34 story ACs plus 3 cross-cutting (proposed IDs).

## Supporting NFR, threat and contract tests (not AC-prefixed)

| ID | Test layer | Planned test file | Planned test name | Contract reference | Status |
|---|---|---|---|---|---|
| NFR-02 | Feature | tests/Feature/Todos/AuthorizationTest.php | `NFR-02 guards every {todo} route against guests (login), other users (403, unchanged) and unknown ids (404)` (dataset over 4 routes) | contracts §1.1; security.md T-01 | Planned |
| Contract §1.2 | CI check | CI job (T-002) | Route-list JSON diff; `dashboard` route count = 0 | contracts §1.2 | Planned |
| ADR-0002 | Feature | tests/Feature/Todos/IndexTest.php | `ADR-0002 todos/Index props match the strict contract shape` (no `etc()`, `missing('todos.0.user_id')`) | contracts §5.2 | Planned |
| ADR-0005 | Feature | tests/Feature/Todos/CompletionTest.php | `ADR-0005 keeps the original completed_at when completed=true is re-sent` | ADR-0005; F-14, F-19 | Planned |
| FR-05 | Feature | tests/Feature/Todos/CompletionTest.php | `FR-05 rejects a missing or non-boolean completed value with 303 back and errors` | contracts §2 `completed`; F-18 | Planned |
| T-02 | Feature | tests/Feature/Todos/UpdateTest.php | `T-02 ignores posted user_id and completed_at on update` | security.md T-02 | Planned |
| T-04 | Feature | tests/Feature/Security/SharedPropsTest.php | `T-04 never shares password, 2FA secrets or remember_token in auth.user` | security.md T-04 | Planned |
| T-05 | Feature | tests/Feature/Security/CsrfTest.php | `T-05 turns a token mismatch into a redirect back with the page-expired toast` (test-only route) | security.md T-05; F-06 | Planned |
| T-07 | Feature + Browser | tests/Feature/Security/HistoryTest.php, tests/Browser/AuthJourneyTest.php | `T-07 sets clearHistory on logout and account deletion`; `T-07 hides todo titles after logout and back navigation` | security.md T-07 | Planned |
| T-08 | Feature | tests/Feature/Todos/StoreTest.php | `T-08 returns 429 on the 121st todo write within a minute` | contracts §1.1 limiter | Planned |
| T-11 | Feature | tests/Feature/Security/DevtoolsTest.php | `T-11 denies Inertia devtools endpoints outside local` | security.md T-11 | Planned |
| T-13 | Feature | tests/Feature/Security/SecurityHeadersTest.php | `T-13 sends X-Frame-Options, nosniff and Referrer-Policy on /todos and /login` | security.md T-13 | Planned |
| T-19 | Feature | tests/Feature/Security/BoostConfigTest.php | `T-19 keeps the Boost tinker tool and rule recording disabled` | security.md T-19 | Planned |
| D4 | Feature | tests/Feature/Security/DatabaseDriverTest.php | `D4 runs on the expected driver and database` | ADR-0003 Postgres row | Planned |
| Q5 | Feature | tests/Feature/Todos/IndexTest.php | `Q5 runs the app in UTC` | architecture Q5; F-21 | Planned |
| Arch | Feature (arch) | tests/Arch/ArchTest.php | php, security and laravel presets; no debug calls; no `DB` facade in controllers; no `Log` facade in `App\Http` | ADR-0003; security.md T-12 | Planned |
| NFR-05 | CI check (staging) | `.github/workflows/deploy-staging.yml` (T-002) | Warm p95 < 300 ms; cold < 2 s (nightly) | ADR-0006 §NFR-05; F-05 | Planned |
| NFR-06 | Browser + manual | tests/Browser/AccessibilityTest.php; G5 checklist | See CC.AC1, CC.AC2; manual Lighthouse and screen-reader pass before G5 | F-12 | Planned |
| NFR-07 | CI check | `test-sqlite`, `mutation` jobs | Coverage 85 / 90 / 90; mutation 70 | ADR-0003; F-08, F-22 | Planned |
| NFR-08 | CI check | `lint` job | Pint, PHPStan level 8, `vp check`, vue-tsc, build: 0 errors | ADR-0003 | Planned |
| NFR-09 | Browser | tests/Browser/ResponsiveTest.php | `NFR-09 completes the todo journey at 320 px without horizontal scroll` | ADR-0003 browser matrix; F-09 | Planned |
| NFR-10 | Feature | tests/Feature/HealthTest.php | `NFR-10 /up returns 200` | contracts §1.1 Kit routes | Planned |
| NFR-11 | Kit feature | tests/Feature/Settings/ProfileUpdateTest.php | Covered by US-01.AC5 (runs on both drivers) | data-model §5 | Planned |
