# ADR-0003: Test and quality stack

**Status:** Proposed · **Date:** 2026-11-16, revised 2026-11-17 (G2-6, G2-13, G2-14, G2-16) · **Approver:** project owner (pending, after the G2 blocker re-check)

## Context
- **Targets:**
  - NFR-02: IDOR tests.
  - NFR-03: no raw HTML from user content (reworded at G1-4).
  - NFR-05: fixed query count.
  - NFR-06: WCAG 2.2 AA.
  - NFR-07: coverage at least 85% overall, at least 90% on `app/Http` and `app/Policies`, mutation score at least 70%.
  - NFR-08: Larastan level 8 or higher, zero lint and type errors.
  - NFR-09: browsers and 320 px.
  - D4: SQLite plus a Postgres CI job.
- **R-003** recommends Pest 5, PCOV, Larastan level 8, a 1-vs-100 query-count test and a Postgres matrix.
- **The R-003 red team refuted the Oxlint route for NFR-03.** Neither native Oxlint nor its JS plugins can lint `<template>`, and both `vue/no-v-html` and grep miss `:innerHTML` (R-003 red-team finding 1). The red team also found:
  - CI env vars override `phpunit.xml`, but `DB_DATABASE=:memory:` survives unless it is set too (finding 3);
  - there is no local coverage driver (finding 4);
  - PHPStan level 8 on the kit gives 10 errors (finding 5);
  - the Actions-minutes cost is real (finding 7).
- **G1 decisions:**
  - G1-4: NFR-03 is enforced by a `vue/compiler-sfc` check with an allow-list.
  - G1-3: allow-list the QR `v-html`.
  - G1-6: PHP 8.4, as Pest 5 requires.
- **G2 decisions (2026-11-17):**
  - G2-14: accepting this ADR approves the two browser-test dependencies.
  - G2-13: overdue is computed in the browser, so the first pure TypeScript logic (`resources/js/lib/overdue.ts`) needs `vp test` now.
  - G2-6: write-route status codes depend on `X-Inertia` (contracts §1.1.1).
  - G2-16: QA findings F-08, F-09, F-12 and F-22 and security advisories A-02 and A-15 change this ADR.
- **Kit facts (R-001 E19–E23):**
  - Pest 5.3.6 is installed through `laravel new --pest`.
  - Vite+ 1.4.0 bundles Oxlint 1.89, Oxfmt and Vitest 5.0.5.
  - There is no ESLint, no `@vue/test-utils` and no DOM environment (`jsdom` or `happy-dom`). Checked by `ls node_modules` in the spike, 2026-11-16.

## Decision

### Backend
| Concern | Tool / rule |
|---|---|
| Test runner | **Pest 5** (`pestphp/pest ^5.3`, `pest-plugin-laravel ^5.0`). The kit's tests are **converted to Pest style** at scaffold time, because the owner runs the installer outside the agent env (R-001 red-team finding 1, G1-7) |
| Arch tests | `arch()->preset()->php()`, `->security()`, `->laravel()`, plus: no `dd`, `dump` or `ray`; controllers don't use the `DB` facade; `App\Models` extend `Model`. **Not** the `strict` preset (kit classes aren't final, see coding-standards.md). Kit or Fortify classes may need `ignoring()`. Each ignore is reviewed once at T-001 |
| Coverage | **PCOV, in CI only** (`shivammathur/setup-php` `coverage: pcov`). Gate: `--coverage --min=85` overall. For **at least 90% on `app/Http` and `app/Policies`**, a committed script of about 20 lines (`tests/coverage-dirs.php`) reads `--coverage-clover build/clover.xml` (Pest's `--min` is global only, R-003 E10). The script uses the clover file-level `statements` and `coveredstatements` attributes, which PHPUnit counts as executable **lines**, so it measures NFR-07's line coverage (F-22). Local runs skip coverage: no driver is installed (R-003 red-team finding 4), and `CLAUDE.md` already says so |
| Mutation | `pest --mutate --parallel --min=70 --ignore-min-score-on-zero-mutations`, run over every class declared with `mutates()` in a test file (Policy, FormRequests, controllers, Resource, the `TodoFilter` enum). It runs on every PR. Revisit when the job takes more than 5 minutes: switch to diff-scoped `--class=` (R-003 §3). Any `@pest-mutate-ignore` is a reviewed exception, the same as weakening a test |
| N+1 (NFR-05, US-03.AC6) | (1) A query-count equality test: `GET /todos` with 1 todo and with 100 todos must give the same count, for each filter value. (2) An exact `expectsDatabaseQueryCount(2)` budget on the same endpoint (contracts §3; written once the T-001 spike confirms the number, F-07). (3) `Model::preventLazyLoading()` outside production (R-003 §5) |
| Static analysis | **Larastan 3 / PHPStan 2 at level 8**. Fix the kit's 10 level-8 errors in T-001, with **no baseline file**. Set `composer types:check` to `phpstan analyse --memory-limit=1G` (R-001 E23). Revisit trigger: try level 9 after the MVP (it needs 3 more fixes, R-003 red-team finding 5) |
| Style | **Pint** `laravel` preset (kit `pint.json`), via `composer lint` / `lint:check` |
| Postgres (D4) | CI job `pgsql`: `services: postgres:18` (matches Cloud, ADR-0006) with a health check, `extensions: pdo_pgsql`, and **every** DB variable set (`DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=testing DB_USERNAME=postgres DB_PASSWORD=postgres`). Otherwise `DB_DATABASE=:memory:` leaks in from `phpunit.xml` (R-003 red-team finding 3). One test asserts that the driver **and** the database name match `EXPECTED_DB_DRIVER` / `EXPECTED_DB_DATABASE` when those are set. Runs with `--parallel`. No coverage or mutation in this job |

### Browser, accessibility and frontend
| Concern | Tool / rule |
|---|---|
| Browser and accessibility tests | **`pestphp/pest-plugin-browser ^5.2` + npm `playwright`**. Both are new dependencies, and **accepting this ADR approves exactly these two** (G2-14), plus `ext-sockets` in CI. Tests locate elements by the role and accessible name in contracts §6.1 and assert focus by its ids. Keyboard and focus ACs (US-02.AC6, US-05.AC5, US-06.AC4, US-07.AC4, CC.AC1, CC.AC2) are scripted. `tests/Pest.php` binds `TestCase` and `RefreshDatabase` to `Feature` **and `Browser`** (F-08). **Accessibility scan (F-12, NFR-06, CC.AC1):** `assertNoAccessibilityIssues()` at **level 2 (moderate)** plus `assertNoJavaScriptErrors()` on each of these states, **in light and in dark appearance**: guest `/`, `/login`, `/register`, `/forgot-password`; unverified `/email/verify`; `/todos` empty, populated (including an overdue todo), with validation errors, with the edit dialog open, with the delete dialog open; the settings pages (`profile`, `security`, `appearance`). **Focus-visible probe:** Tab through `/todos` and assert that each focused element has a computed `outline-style` other than `none` or a `box-shadow` other than `none` (axe does not test visible focus). T-001 records the kit's baseline result; each kit failure is fixed or owner-approved |
| T-001 spike items for the browser plugin (F-09) | Confirm and record in `test-strategy.md`: (1) a script or evaluate call that returns `document.activeElement` attributes; (2) key presses `Tab`, `Shift+Tab`, `Escape`, `Space`; (3) an exact 320 px viewport, or the Playwright `iPhone SE` preset (320×568); (4) that the accessibility engine is axe-core, and which rule tags it applies; (5) whether the browser server's origin is a secure context (`localhost`), for history encryption (security.md T-07). Any API that is missing becomes a documented manual check with the owner's approval, never a silent gap |
| Browser matrix | On every PR: **Chromium** (it also stands in for Edge), at desktop size and a mobile viewport. On pushes to `main` and nightly: **Firefox and WebKit** (the Safari engine, not Safari). This saves Actions minutes (R-003 red-team finding 7). A manual Safari check happens before G5 (R-003 red-team finding 9). Whether the plugin can set an exact 320 px viewport is **UNVERIFIED**. If it can't, T-001 records the closest device preset and NFR-09 is checked by hand |
| NFR-03 raw-HTML check (G1-4) | `scripts/check-raw-html.mjs`, about 40 lines, run by `npm run check:html` and in CI. It parses every `resources/js/**/*.vue` with **`@vue/compiler-sfc`**, which is a direct dependency of `vue` (`npm ls`: `vue@3.5.45 └── @vue/compiler-sfc@3.5.45`), so there is **no new dependency**. It walks the template AST and **fails on** `v-html`, and on any `v-bind`/`:` binding whose argument is `innerHTML` or `outerHTML`. It also scans `<script>` blocks and `resources/js/**/*.ts` for `innerHTML`, `outerHTML`, `insertAdjacentHTML` and `document.write`. **Allow-list:** `scripts/raw-html-allowlist.json` holds one entry, `{ "file": "resources/js/components/TwoFactorSetupModal.vue", "expr": "qrCodeSvg", "reason": "Fortify-generated QR SVG, no user input (G1-3)" }`. The allow-list is matched on **file + expression**, so a second `v-html` in the same file still fails. Changing the allow-list counts as a security change: it needs the owner's approval at G4. Known limitation: the object-spread form `v-bind="{ innerHTML: x }"` is caught only by the script-side grep, and only if it is written literally in the template. That is acceptable, and the security review checks for it |
| Frontend lint and format | The kit's **Vite+ `vp check`** (Oxfmt + Oxlint, `denyWarnings`, `typeAware`) through `npm run check` / `check:fix` |
| Type check | **vue-tsc** through `npm run types:check` |
| Frontend unit tests | **Pure TypeScript logic tests only, through `vp test run`. Vue component tests are deferred.** Spike, 2026-11-16, kit copy: a `*.test.ts` importing from `vite-plus/test` ran under bundled Vitest 5.0.5 with **no new dependency** ("Test Files 1 passed, Tests 2 passed"). Component tests would need `@vue/test-utils` plus `jsdom` or `happy-dom` (none installed), or a Playwright provider for `@vitest/browser`, so they are **deferred**. Pest browser tests cover user-visible component behaviour. **The first logic test is `resources/js/lib/overdue.test.ts`** (G2-13; cases in contracts §5.3), so the `test` npm script (`vp test run`) is added in the overdue task and runs in the CI `js-unit` job. Vitest coverage is not gated (no new dependency); mutation testing does not apply to TypeScript |
| Security configuration tests | Behaviour the framework disables under tests is checked through configuration (A-02, F-06): the `web` group contains `PreventRequestForgery`, its except-list is empty and `allowSameSite` is false; the 419 handler is tested with a test-only route that throws `TokenMismatchException`. Full list: security.md §4 |
| Dependency CVEs (NFR-04) | `composer audit` and `npm audit --audit-level=high` in CI |

### CI gates
Written into T-002 and `test-strategy.md`. `phpunit.xml` defines the test suites **`Unit`, `Feature`, `Arch` and `Browser`** (F-08). Non-browser jobs never load Playwright, and browser tests never run inside coverage or mutation.

| Job (trigger) | Commands | Threshold |
|---|---|---|
| `lint` (PR) | `composer lint:check`, `composer types:check`, `npm run check`, `npm run check:html`, `npm run types:check`, `npm run build` | 0 diffs, 0 errors, 0 warnings |
| `js-unit` (PR) | `npm run test` (`vp test run`) | green |
| `workflow-lint` (PR) | `npm run check:workflows` (security.md T-18) and its fixture tests | 0 violations |
| `test-sqlite` (PR) | `pest --testsuite=Unit,Feature,Arch --parallel --coverage --min=85 --coverage-clover build/clover.xml`, then `php tests/coverage-dirs.php build/clover.xml app/Http=90 app/Policies=90` | 85 / 90 / 90 |
| `mutation` (PR) | `pest --testsuite=Unit,Feature,Arch --mutate --parallel --min=70 --ignore-min-score-on-zero-mutations` | 70 |
| `test-pgsql` (PR) | `pest --testsuite=Unit,Feature,Arch --parallel` with every `DB_*` variable set | green |
| `browser-chromium` (PR) | `pest --testsuite=Browser --ci --parallel --trace`. Traces and screenshots are uploaded on failure | green, no accessibility issues at moderate or above |
| `browser-matrix` (`main` + nightly) | same, with `--browser firefox` and `--browser safari` | green |
| `audit` (PR + weekly) | `composer audit`, `npm audit --audit-level=high` | 0 high or critical |
| `scope` (PR) | Path-ownership check against the task's `scope.paths` (G1-11) | 0 files out of scope |

The required status checks on `main` are every PR job above (security.md T-16). Every job runs PHP 8.4 and Node 26 and installs with `npm ci` and `composer install`. **Workflow posture (G2-5, A-15; ADR-0006, security.md T-18):** top-level `permissions: contents: read` (or `{}`), triggers `pull_request`, `push` to `main`, `schedule` and `workflow_dispatch` only (never `pull_request_target` or `workflow_run`), every action pinned to a full commit SHA, `actions/checkout` with `persist-credentials: false`, and no secrets in any PR job. `workflow-lint` enforces all of this.

## Alternatives considered
| Alternative | Why it was rejected |
|---|---|
| PHPUnit 12 (the kit's upstream default) | No built-in mutation, arch or browser testing (R-003 option B) |
| Oxlint + `eslint-plugin-vue` through `jsPlugins` | Refuted: JS plugins can't parse Vue templates (R-003 red-team finding 1) |
| A minimal ESLint config just for `vue/no-v-html` | 4 new dependencies, and it still misses `:innerHTML` |
| `grep v-html` only | Misses `:innerHTML` and has no allow-list semantics |
| Laravel Dusk | Chrome only, so it can't meet NFR-09 |
| Standalone `@playwright/test` + `@axe-core/playwright` | A second test language and runner, with app boot and seeding done by hand |
| Vitest component tests now | They need new dependencies (`@vue/test-utils`, a DOM environment), and the behaviour is already covered by browser tests |
| Xdebug or PCOV installed locally | The Herd install method is UNVERIFIED. CI is authoritative, and the owner can opt in later |
| Global `--min` only | Can't express "at least 90% on `app/Http` and `app/Policies`" |

## Consequences
**Positive**
- Every NFR from 02 to 09 has a deterministic CI gate, apart from the staging-only parts of NFR-05 and the manual parts of NFR-06 and NFR-09.
- Only two new dependencies, both for browser tests. The rest, including `vp test` for the overdue logic, comes with the kit or the platform.

**Negative**
- "Done" for coverage and mutation is **CI-only**. Agents can't reproduce those gates locally.
- Browser tests are the most likely source of flaky failures. Flakiness is escalated to the owner, never skipped (governance).
- WebKit on Linux is not real Safari.
- The custom raw-HTML, workflow-lint and clover scripts are about 100 lines of our own tooling to maintain. They need their own small tests: a fixture `.vue` with `v-html` and `:innerHTML` must fail and the allow-listed fixture must pass; a workflow fixture without `permissions`, with `pull_request_target`, with a tag-pinned action or without `persist-credentials: false` must fail.

**Follow-up work**
- T-001: PHPStan level 8, Pest conversion check, `check-raw-html.mjs` and its fixtures, the browser-plugin spike items above, the query budget (contracts §3) and the status table (contracts §1.1.1).
- T-002: the CI workflow above, `scripts/check-workflows.mjs` and its fixtures, plus measuring Actions minutes per run.
- QA: `test-strategy.md` and the traceability matrix.

## Revisit triggers
- The mutation job takes more than 5 minutes. Scope it to the diff.
- The browser suite has more than 1 spurious failure per 20 runs. Move it to non-blocking and reconsider standalone Playwright.
- Oxlint gains template-level Vue rules, or `vp lint` supports `vue/no-v-html`. Replace the custom script.
- The first logic-heavy component (more than about 30 lines of non-template logic) appears. Reconsider component tests (dependency approval needed).
- Actions minutes exceed about 80% of the plan quota. Reduce the PR matrix.
- The MVP ships with no baseline entries. Try PHPStan level 9.

## Sources
- R-003 (E1–E38, §1–§8, red-team findings 1–10). R-001 E19–E23. G1-3, G1-4, G1-6.
- https://pestphp.com/docs/test-coverage (5.x)
- https://pestphp.com/docs/mutation-testing (5.x)
- https://pestphp.com/docs/arch-testing (5.x)
- https://pestphp.com/docs/browser-testing (5.x)
- https://laravel.com/docs/13.x/database-testing#available-assertions (`expectsDatabaseQueryCount`)
- https://laravel.com/docs/13.x/eloquent-relationships#preventing-lazy-loading
- https://github.com/larastan/larastan (3.x)
- https://viteplus.dev/guide/test (Vite+ test block, `vite-plus/test` imports)
- https://oxc.rs/docs/guide/usage/linter/js-plugins.html (Vue templates not supported)
- Spikes, 2026-11-16: `vp test run` on the kit copy (Vitest 5.0.5, passed); `npm ls @vue/compiler-sfc` (direct dependency of `vue`)
- G2 decision packet (G2-6, G2-13, G2-14, G2-16); `04-quality/G2-testability-review.md` (F-07, F-08, F-09, F-12, F-22); `02-architecture/reviews/G2-security-review.md` (A-02, A-15)
