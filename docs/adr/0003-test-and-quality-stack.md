# ADR-0003: Test and quality stack

**Status:** Proposed · **Date:** 2026-11-16 · **Approver:** project owner (pending, G2)

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
| Coverage | **PCOV, in CI only** (`shivammathur/setup-php` `coverage: pcov`). Gate: `--coverage --min=85` overall. For **at least 90% on `app/Http` and `app/Policies`**, a committed script of about 20 lines (`tests/coverage-dirs.php`) reads `--coverage-clover build/clover.xml` (Pest's `--min` is global only, R-003 E10). Local runs skip coverage: no driver is installed (R-003 red-team finding 4), and `CLAUDE.md` already says so |
| Mutation | `pest --mutate --parallel --min=70 --ignore-min-score-on-zero-mutations`, run over every class declared with `mutates()` in a test file (Policy, FormRequests, controllers, Resource, the `TodoFilter` enum). It runs on every PR. Revisit when the job takes more than 5 minutes: switch to diff-scoped `--class=` (R-003 §3). Any `@pest-mutate-ignore` is a reviewed exception, the same as weakening a test |
| N+1 (NFR-05, US-03.AC6) | (1) A query-count equality test: `GET /todos` with 1 todo and with 100 todos must give the same count. (2) An exact `expectsDatabaseQueryCount()` budget on the same endpoint, updated deliberately. (3) `Model::preventLazyLoading()` outside production (R-003 §5) |
| Static analysis | **Larastan 3 / PHPStan 2 at level 8**. Fix the kit's 10 level-8 errors in T-001, with **no baseline file**. Set `composer types:check` to `phpstan analyse --memory-limit=1G` (R-001 E23). Revisit trigger: try level 9 after the MVP (it needs 3 more fixes, R-003 red-team finding 5) |
| Style | **Pint** `laravel` preset (kit `pint.json`), via `composer lint` / `lint:check` |
| Postgres (D4) | CI job `pgsql`: `services: postgres:18` (matches Cloud, ADR-0006) with a health check, `extensions: pdo_pgsql`, and **every** DB variable set (`DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=testing DB_USERNAME=postgres DB_PASSWORD=postgres`). Otherwise `DB_DATABASE=:memory:` leaks in from `phpunit.xml` (R-003 red-team finding 3). One test asserts that the driver **and** the database name match `EXPECTED_DB_DRIVER` / `EXPECTED_DB_DATABASE` when those are set. Runs with `--parallel`. No coverage or mutation in this job |

### Browser, accessibility and frontend
| Concern | Tool / rule |
|---|---|
| Browser and accessibility tests | **`pestphp/pest-plugin-browser ^5.2` + npm `playwright`**. Both are new dependencies, and **accepting this ADR approves exactly these two**, plus `ext-sockets` in CI. Keyboard and focus ACs (US-02.AC6, US-05.AC5, US-06.AC4, US-07.AC4) are scripted. `assertNoAccessibilityIssues()` runs at **level 2 (moderate)** on every page, together with `assertNoJavaScriptErrors()` |
| Browser matrix | On every PR: **Chromium** (it also stands in for Edge), at desktop size and a mobile viewport. On pushes to `main` and nightly: **Firefox and WebKit** (the Safari engine, not Safari). This saves Actions minutes (R-003 red-team finding 7). A manual Safari check happens before G5 (R-003 red-team finding 9). Whether the plugin can set an exact 320 px viewport is **UNVERIFIED**. If it can't, T-001 records the closest device preset and NFR-09 is checked by hand |
| NFR-03 raw-HTML check (G1-4) | `scripts/check-raw-html.mjs`, about 40 lines, run by `npm run check:html` and in CI. It parses every `resources/js/**/*.vue` with **`@vue/compiler-sfc`**, which is a direct dependency of `vue` (`npm ls`: `vue@3.5.45 └── @vue/compiler-sfc@3.5.45`), so there is **no new dependency**. It walks the template AST and **fails on** `v-html`, and on any `v-bind`/`:` binding whose argument is `innerHTML` or `outerHTML`. It also scans `<script>` blocks and `resources/js/**/*.ts` for `innerHTML`, `outerHTML`, `insertAdjacentHTML` and `document.write`. **Allow-list:** `scripts/raw-html-allowlist.json` holds one entry, `{ "file": "resources/js/components/TwoFactorSetupModal.vue", "expr": "qrCodeSvg", "reason": "Fortify-generated QR SVG, no user input (G1-3)" }`. The allow-list is matched on **file + expression**, so a second `v-html` in the same file still fails. Changing the allow-list counts as a security change: it needs the owner's approval at G4. Known limitation: the object-spread form `v-bind="{ innerHTML: x }"` is caught only by the script-side grep, and only if it is written literally in the template. That is acceptable, and the security review checks for it |
| Frontend lint and format | The kit's **Vite+ `vp check`** (Oxfmt + Oxlint, `denyWarnings`, `typeAware`) through `npm run check` / `check:fix` |
| Type check | **vue-tsc** through `npm run types:check` |
| Frontend unit tests | **Pure TypeScript logic tests only, through `vp test run`. Vue component tests are deferred.** Spike, 2026-11-16, kit copy: a `*.test.ts` importing from `vite-plus/test` ran under bundled Vitest 5.0.5 with **no new dependency** ("Test Files 1 passed, Tests 2 passed"). Component tests would need `@vue/test-utils` plus `jsdom` or `happy-dom` (none installed), or a Playwright provider for `@vitest/browser`, so they are **deferred**. Pest browser tests cover user-visible component behaviour. A `test` npm script (`vp test run`) is added only once the first logic test exists, and the MVP may not need one (the overdue logic runs server-side, see data-model.md) |
| Dependency CVEs (NFR-04) | `composer audit` and `npm audit --audit-level=high` in CI |

### CI gates
Written into T-002 and `test-strategy.md`.

| Job (trigger) | Commands | Threshold |
|---|---|---|
| `lint` (PR) | `composer lint:check`, `composer types:check`, `npm run check`, `npm run check:html`, `npm run types:check`, `npm run build` | 0 diffs, 0 errors, 0 warnings |
| `test-sqlite` (PR) | `pest --parallel --coverage --min=85 --coverage-clover build/clover.xml`, then `php tests/coverage-dirs.php build/clover.xml app/Http=90 app/Policies=90` | 85 / 90 / 90 |
| `mutation` (PR) | `pest --mutate --parallel --min=70 --ignore-min-score-on-zero-mutations` | 70 |
| `test-pgsql` (PR) | `pest --parallel` with every `DB_*` variable set | green |
| `browser-chromium` (PR) | `pest --ci --parallel --trace tests/Browser`. Traces and screenshots are uploaded on failure | green, no accessibility issues at moderate or above |
| `browser-matrix` (`main` + nightly) | same, with `--browser firefox` and `--browser safari` | green |
| `audit` (PR + weekly) | `composer audit`, `npm audit --audit-level=high` | 0 high or critical |
| `scope` (PR) | Path-ownership check against the task's `scope.paths` (G1-11) | 0 files out of scope |

Every job runs PHP 8.4 and Node 26 and installs with `npm ci` and `composer install`. Actions are pinned by SHA, as in the kit's workflow.

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
- Every NFR from 02 to 09 has a deterministic CI gate.
- Only two new dependencies, both for browser tests. The rest comes with the kit or the platform.

**Negative**
- "Done" for coverage and mutation is **CI-only**. Agents can't reproduce those gates locally.
- Browser tests are the most likely source of flaky failures. Flakiness is escalated to the owner, never skipped (governance).
- WebKit on Linux is not real Safari.
- The custom raw-HTML script and the clover script are about 60 lines of our own tooling to maintain. They need their own small tests: a fixture `.vue` with `v-html` and `:innerHTML` must fail, and the allow-listed fixture must pass.

**Follow-up work**
- T-001: PHPStan level 8, Pest conversion check, `check-raw-html.mjs` and its fixtures.
- T-002: the CI workflow above, plus measuring Actions minutes per run.
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
