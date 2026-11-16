# R-003: Evidence-based test and static-analysis stack

**Status:** Draft · **Author:** architect agent · **Red team:** (pending) · **Date:** 2026-11-16

## Question
Which test, coverage, mutation, browser/a11y and static-analysis tools (and which thresholds) let CI deterministically verify NFR-02, NFR-03, NFR-05, NFR-06, NFR-07, NFR-08 and NFR-09 on both SQLite and PostgreSQL?

## Constraints
- D4 (`00-product/assumptions.md`): SQLite locally and for the fast suite; **CI also runs the suite on PostgreSQL**.
- D3: Herd locally (PHP 8.4.25 recorded in assumptions), GitHub Actions for CI (A3).
- NFR-05 (fixed query count / no N+1), NFR-06 (WCAG 2.2 AA, browser + axe/Lighthouse), NFR-07 (coverage ≥85 % overall, ≥90 % on `app/Http` and `app/Policies`; mutation score ≥70 % on changed domain code), NFR-08 (PHPStan level ≥8; zero ESLint, Pint, vue-tsc errors), NFR-09 (Chrome, Firefox, Safari, Edge; 320 px).
- `requirements.md` Constraints: framework features first; every new dependency needs human approval.
- The starter kit's actual contents are R-001's subject. This report inspects the **upstream `laravel/vue-starter-kit` `main` branch at commit `1959e1a` (2026-11-10)** only as far as it affects testing tools.

## Key finding that changes the brief
The current official Vue starter kit does **not** ship ESLint or a standalone Vitest. Its frontend toolchain is **Vite+** (`vite-plus` 1.4.0: `vp check` = oxfmt + Oxlint + type check, `vp test` = bundled Vitest 5.0.5). It ships **PHPUnit 12** (the Laravel installer swaps it for Pest when you pick Pest), **Larastan at level 7**, and Pint. Its CI workflow pins **PHP 8.3** with `coverage: none`. As a result:
1. NFR-03's wording ("ESLint `vue/no-v-html`") and the `npm run lint # eslint` line in `CLAUDE.md` don't match the default toolchain. The human needs to decide how NFR-03 is enforced (see Recommendation §6).
2. Pest 5 requires PHP ^8.4, so the starter kit's CI PHP version has to be raised to 8.4.

## Options

| Area | Option | Summary |
|---|---|---|
| PHP test runner | A. **Pest 5** (installer default) | PHPUnit 13 underneath. Bundles the arch and mutate plugins. Optional browser plugin |
| | B. PHPUnit 12 (starter-kit default) | No built-in mutation, arch or browser testing |
| Coverage driver | A. **PCOV** in CI | Coverage only |
| | B. Xdebug (`XDEBUG_MODE=coverage`) | Also gives debugging. Needed locally if PCOV isn't available in Herd |
| Browser / E2E | A. **pestphp/pest-plugin-browser 5.x** | Playwright-driven, written in Pest, includes `assertNoAccessibilityIssues()` |
| | B. Laravel Dusk | ChromeDriver only, so it can't cover Firefox or Safari for NFR-09 |
| | C. Standalone `@playwright/test` + `@axe-core/playwright` | A second test language and runner, with app boot and DB seeding by hand |
| Frontend unit | A. **`vp test` (Vitest bundled in Vite+) + @vue/test-utils** | Configured in the `test` block of `vite.config.ts` |
| | B. Standalone `vitest` 5.1.0 package | Duplicates what Vite+ already bundles |
| Frontend lint for NFR-03 | A. Oxlint (`vp lint`) + **eslint-plugin-vue loaded as an Oxlint JS plugin** | Single linter, if template rules work through JS plugins (**UNVERIFIED**) |
| | B. Oxlint + a minimal ESLint 10 flat config that only enforces `vue/no-v-html: error` | Proven rule, but a second linter |
| | C. Oxlint + a CI `grep`/Pest arch-style check that fails on `v-html` in `resources/js` | No new dependency, but crude |
| N+1 guard | A. **Query-count equality test (1 vs 100 todos) + `Model::preventLazyLoading`** | Uses framework features only |
| | B. `expectsDatabaseQueryCount(n)` exact budget | Breaks when middleware or session queries change |
| | C. `Model::automaticallyEagerLoadRelationships()` | Hides N+1 instead of detecting it |
| Static analysis | **Larastan 3.15 / PHPStan 2.3** at level 8, 9, or 10 | |
| Postgres in CI | **GitHub Actions `services: postgres` + DB matrix** | |

## Evaluation criteria
Complexity, security surface, testability, deploy simplicity, future flexibility, maturity ("boring technology" counts in an option's favour). We also weigh **how close an option stays to the starter-kit default**, because each deviation is a dependency to approve and keep up to date.

## Evidence

Version commands were run on 2026-11-16. Packagist and npm release timestamps were read from `repo.packagist.org/p2/<pkg>.json` and `npm view <pkg> time`.

| # | Claim | Source (URL) | Version / date | Verified by command? |
|---|---|---|---|---|
| E1 | Pest's latest release is **v5.3.6** (2026-11-14). It requires `php ^8.4`, `phpunit/phpunit ^13.4.7`, `brianium/paratest ^7.29`, and `pestphp/pest-plugin-arch ^5.0` and `pestphp/pest-plugin-mutate ^5.0.2` as hard dependencies | https://packagist.org/packages/pestphp/pest | 5.3.6 | Yes: `composer show -a pestphp/pest` / `composer show -a pestphp/pest v5.3.6` |
| E2 | pest-plugin-laravel latest **v5.0.1**. pest-plugin-mutate **v5.0.2** (2026-08-10). pest-plugin-arch **v5.0.0** | packagist | as stated | Yes: `composer show -a` |
| E3 | pest-plugin-browser latest **v5.2.0** (2026-10-29). It requires `php ^8.4`, **`ext-sockets`**, `amphp/http-server ^3.4.6`, `amphp/websocket-client`, and `pestphp/pest ^5.2.1` | https://packagist.org/packages/pestphp/pest-plugin-browser | 5.2.0 | Yes: `composer show -a pestphp/pest-plugin-browser v5.2.0` |
| E4 | Browser plugin install: `composer require pestphp/pest-plugin-browser --dev`, `npm install playwright@latest`, `npx playwright install`. Add `tests/Browser/Screenshots` and `tests/Browser/Traces` to `.gitignore` | https://pestphp.com/docs/browser-testing | Pest 5.x docs branch | Docs only |
| E5 | Browser plugin CI recipe: setup-node, `npm ci`, `npx playwright install --with-deps`, `./vendor/bin/pest --ci --parallel --trace`, and on failure upload the Screenshots and Traces dirs | https://pestphp.com/docs/browser-testing | 5.x | Docs only |
| E6 | Browsers: Chrome by default, plus `--browser firefox` and `--browser safari` (also settable in `Pest.php`: `pest()->browser()->inFirefox()`). Devices: `->on()->mobile()`, `->on()->iPhone14Pro()`. Also `inDarkMode()` | https://pestphp.com/docs/browser-testing | 5.x | Docs only |
| E7 | `assertNoAccessibilityIssues()` fails on "serious" issues by default. Levels: 0 critical, 1 serious (default), 2 moderate, 3 minor. Also `assertNoSmoke()`, `assertNoJavaScriptErrors()`, `assertNoConsoleLogs()`, `assertScreenshotMatches()` | https://pestphp.com/docs/browser-testing | 5.x | Docs only. The page doesn't say the engine is axe-core: **UNVERIFIED** |
| E8 | The browser plugin is driven by Playwright (the docs install Playwright and use its trace viewer). The page never says "built on Playwright" in so many words. The amphp HTTP server dependency suggests the app is served in-process | https://pestphp.com/docs/browser-testing , E3 | 5.x | Playwright: docs. In-process server: **UNVERIFIED (inference)** |
| E9 | Mutation testing: `--mutate`, scoped per file with `covers(X::class)` or `mutates(X::class)`. `--everything` (pair it with `--covered-only`), `--class`, `--ignore`, `--min=<n>`, `--ignore-min-score-on-zero-mutations`, `--parallel`, `--id=<id>`, `--clear-cache`, `--no-cache`, `--profile`, `--bail`, `--stop-on-uncovered`, `--stop-on-untested`. Opt-out with `// @pest-mutate-ignore`. Needs Xdebug 3+ or PCOV. **No built-in "changed code only" option** | https://pestphp.com/docs/mutation-testing | 5.x | Docs only |
| E10 | Coverage: `--coverage`, `--min=<n>`, `--exactly=<n>`, `--only-covered`, plus clover, cobertura, html, text and xml reports. Needs Xdebug 3+ (with `XDEBUG_MODE=coverage`) or PCOV. Scope comes from `<source>` in `phpunit.xml`. `--min` is a **single global** threshold | https://pestphp.com/docs/test-coverage | 5.x | Docs only |
| E11 | Laravel `php artisan test --coverage --min=80.3` needs Xdebug or PCOV | https://laravel.com/docs/13.x/testing#reporting-test-coverage | 13.x | Docs only |
| E12 | Arch presets: `php`, `security`, `laravel`, `strict`, `relaxed`. The `laravel` preset checks conventions, for example controllers expose only the resource methods and carry a `Controller` suffix. `strict` requires strict types and final classes. The `php` preset needs `intl`. `ignoring()` excludes namespaces or classes | https://pestphp.com/docs/arch-testing | 5.x | Docs only |
| E13 | Parallel: `--parallel` (one process per core by default) or `--processes=N`. Sharding with `--shard=1/4` and `--update-shards` | https://pestphp.com/docs/optimizing-tests | 5.x | Docs only |
| E14 | Laravel parallel testing creates and migrates one test database per process, suffixed with a token (`your_db_test_1`, …). `--recreate-databases` rebuilds them. `ParallelTesting` hooks are available | https://laravel.com/docs/13.x/testing#running-tests-in-parallel | 13.x | Docs only |
| E15 | **`expectsDatabaseQueryCount(int)` exists.** It is called at the start of a test, and the test fails unless the total query count matches exactly | https://laravel.com/docs/13.x/database-testing#available-assertions | 13.x | Docs only (verified on the page) |
| E16 | `Model::preventLazyLoading(! $this->app->isProduction())` in `AppServiceProvider::boot` throws `LazyLoadingViolationException`. `handleLazyLoadingViolationUsing()` customises the behaviour. `Model::automaticallyEagerLoadRelationships()` and `withRelationshipAutoloading()` also exist | https://laravel.com/docs/13.x/eloquent-relationships#preventing-lazy-loading | 13.x | Docs only |
| E17 | Laravel framework latest **v13.42.0** | packagist | 13.42.0 | Yes: `composer show -a laravel/framework` |
| E18 | Larastan latest **v3.15.0** (2026-11-11). It requires `php ^8.2`, `illuminate/* ^11.44.2 \|\| ^12.4.1 \|\| ^13`, `phpstan/phpstan ^2.3.5`. PHPStan latest **2.3.6**. Larastan 4.x exists only as `4.x-dev` | https://github.com/larastan/larastan , packagist | 3.15.0 / 2.3.6 | Yes: `composer show -a larastan/larastan v3.15.0`, `composer show -a phpstan/phpstan` |
| E19 | Larastan setup: `composer require --dev "larastan/larastan:^3.0"`, `phpstan.neon` includes `vendor/larastan/larastan/extension.neon`. Level 10 is the highest. Baselines via `phpstan analyse --generate-baseline` | https://github.com/larastan/larastan | 3.x README | Docs only |
| E20 | The starter kit's `phpstan.neon` sets **level 7** over `app/`, `bootstrap/app.php`, `config/`, `database/`, `routes/`, with the Larastan and Carbon extensions. `composer.json` require-dev includes `larastan/larastan ^3.9`, `laravel/pint ^1.27`, `phpunit/phpunit ^12.5.23`. Scripts: `lint:check` = `pint --parallel --test`, `types:check` = `phpstan analyse`, `test` = config:clear + pint test + phpstan + `artisan test`, `ci:check` = `npm run check` + `npm run types:check` + `@test` | https://github.com/laravel/vue-starter-kit (main @ 1959e1a, 2026-11-10) | main | Yes: `curl raw.githubusercontent.com/.../composer.json`, `/phpstan.neon`, `gh api repos/laravel/vue-starter-kit/commits/main` |
| E21 | The starter kit's `package.json` has **no ESLint and no standalone Vitest**. Scripts: `check` = `vp check`, `types:check` = `vue-tsc --noEmit`. devDeps: `vite-plus 1.4.0`, `vite` aliased to `@voidzero-dev/vite-plus-core@1.4.0`, `vue-tsc ^3.3.13`, `typescript ^5.2.2`. `vite.config.ts` has a `lint` block (Oxlint: `denyWarnings: true`, `typeAware: true`) and an `fmt` block | https://github.com/laravel/vue-starter-kit (main @ 1959e1a) | main | Yes: `curl .../package.json`, `.../vite.config.ts` |
| E22 | The starter kit's CI (`.github/workflows/tests.yml`) uses PHP **8.3** with `coverage: none`, Node 22, then `composer setup` and `composer ci:check`. The kit's tests are PHPUnit (`tests/Feature/{Auth,Settings,...}`) | same repo | main | Yes: `curl .../tests.yml`, `gh api .../contents/tests` |
| E23 | The Laravel installer's `--pest` path (the interactive default is Pest) runs `composer remove phpunit/phpunit`, `composer require pestphp/pest pestphp/pest-plugin-laravel`, `pest --init`, and the drift plugin to convert tests. It also rewrites the kit's `tests.yml` to `./vendor/bin/pest` | https://github.com/laravel/installer/blob/master/src/NewCommand.php | master | Yes: `curl` + `grep` of the source |
| E24 | `vite-plus@1.4.0` bundles `vitest 5.0.5`, `oxlint =1.89.0`, `oxfmt =0.74.0`, `@vitest/browser 5.0.5`. Latest `vite-plus` is **1.6.0** | https://www.npmjs.com/package/vite-plus | 1.4.0 / 1.6.0 | Yes: `npm view vite-plus@1.4.0 dependencies`, `npm view vite-plus version` |
| E25 | Vite+: `vp check` runs format, lint and type checks. `vp test` runs Vitest. Test config goes in the `test` block of `vite.config.ts` ("We do not recommend using `vitest.config.ts` with Vite+"). APIs are imported from `vite-plus/test`. Coverage via `vp test run --coverage`. Lint is Oxlint configured under `lint` in `vite.config.ts`, and ESLint plugins can be loaded via `lint.jsPlugins` | https://viteplus.dev/guide/ , https://viteplus.dev/guide/test , https://viteplus.dev/guide/lint | fetched 2026-11-16 | Docs only |
| E26 | Vitest latest **5.1.0**: engines `node ^22.12.0 \|\| ^24 \|\| >=26`, peers `vite ^6.4 \|\| ^7 \|\| ^8`, `jsdom *`, `happy-dom *`, `@vitest/coverage-v8 5.1.0` (exact). `@vitest/coverage-v8` latest 5.1.0 | https://vitest.dev , npm | 5.1.0 | Yes: `npm view vitest@5.1.0 peerDependencies engines` |
| E27 | @vue/test-utils latest **2.6.0** (peer `vue 3.x`). jsdom latest 31.0.1, happy-dom latest 21.0.4 | https://test-utils.vuejs.org , npm | 2.6.0 | Yes: `npm view` |
| E28 | vue-tsc latest **3.3.14** (peer `typescript >=5.0.0`) | npm | 3.3.14 | Yes: `npm view` |
| E29 | ESLint latest **10.15.0**. eslint-plugin-vue latest **10.12.1** (peer `eslint ^8.57 \|\| ^9 \|\| ^10`, `vue-eslint-parser ^10.3`). typescript-eslint latest 8.72.2 | npm | as stated | Yes: `npm view` |
| E30 | `vue/no-v-html` (since eslint-plugin-vue v4.7.0) is part of `flat/recommended`. Its only option is `ignorePattern`. The page shows it configured as `"error"` but doesn't state the preset's own severity, so assume it must be set to `error` explicitly | https://eslint.vuejs.org/rules/no-v-html | 10.x site | Docs only. Preset severity **UNVERIFIED** |
| E31 | Oxlint has no documented native `vue/no-v-html`. Oxc's built-in Vue plugin is reported to cover only script-level rules. Whether an ESLint plugin loaded through `lint.jsPlugins` sees `<template>` is **UNVERIFIED** (the official plugins page could not be fetched) | https://oxc.rs/docs/guide/usage/linter/plugins.html (fetch failed); third-party repos only | oxlint 1.89 / 1.93 | **UNVERIFIED** |
| E32 | `axe-core` and `@axe-core/playwright` latest 4.14.0. `playwright` / `@playwright/test` latest **1.65.1** (node >=20). `vitest-axe` latest **0.1.0**, last modified 2025-01-22 (unmaintained pre-1.0) | npm | as stated | Yes: `npm view` |
| E33 | Pint latest **v1.32.3** (2026-11-11) | https://packagist.org/packages/laravel/pint | 1.32.3 | Yes: `composer show -a laravel/pint` |
| E34 | PCOV is faster than Xdebug for coverage-only runs | none official | | **UNVERIFIED** (widely held, but no official source found) |
| E35 | Herd: Xdebug is available (reportedly a Herd Pro feature), and PCOV would need manual installation into Herd's PHP | https://herd.laravel.com/docs (not fetched) | Herd 1.30.2 | **UNVERIFIED**. A local `php -m` check couldn't be run this session |
| E36 | `shivammathur/setup-php` accepts `coverage: pcov` / `xdebug` / `none` and `extensions:` (for example `pdo_pgsql, sockets, intl`) | https://github.com/shivammathur/setup-php (not fetched) | v2 | **UNVERIFIED** (input name `coverage` confirmed in the starter kit's workflow, E22) |
| E37 | GitHub Actions `services: postgres` with a health check is the standard way to run Postgres in CI, and Laravel picks up `DB_CONNECTION=pgsql`, `DB_HOST`, and so on from the environment | https://docs.github.com/en/actions/use-cases-and-examples/using-containerized-services/creating-postgresql-service-containers (not fetched) | | **UNVERIFIED** |
| E38 | Whether a CI-level `DB_CONNECTION` env var overrides the `<env name="DB_CONNECTION" value="sqlite"/>` in Laravel's `phpunit.xml` depends on PHPUnit's `force` attribute semantics | PHPUnit 13 docs (not fetched) | | **UNVERIFIED**. Use a dedicated config file to avoid relying on it |

Spike status: the planned scratchpad spike (create a starter-kit app, run Pest 5 `--mutate`, PHPStan level 8, and Oxlint with eslint-plugin-vue) **could not run**. The tool permission classifier repeatedly gave no verdict on shell commands late in the session. Every claim that depended on the spike is marked UNVERIFIED, and T-001 must run it (see "What would change this recommendation").

## Recommendation

### 1. PHP testing: Pest 5 (installer default)
- `pestphp/pest ^5.3` + `pestphp/pest-plugin-laravel ^5.0`, installed by `laravel new --pest` (E1, E2, E23). The arch and mutate plugins come bundled, so they add no extra dependency approvals.
- **Raise PHP in CI to 8.4.** Pest 5 requires `^8.4` (E1, E3) and the starter kit's CI uses 8.3 (E22). Local Herd is already 8.4.25.
- Run tests with `--parallel`. Laravel provisions one database per process (E13, E14).
- Arch tests: `arch()->preset()->php()`, `->security()`, `->laravel()`, plus `expect(['dd','dump','ray'])->not->toBeUsed()`. **Don't** use `strict`: starter-kit classes aren't final, and enforcing it would mean rewriting vendor-generated code (E12). Expect the `laravel` preset to need `ignoring()` for starter-kit or Fortify classes (**UNVERIFIED** until the spike runs).

### 2. Coverage: PCOV in CI, Xdebug optional locally
- CI: `setup-php` with `coverage: pcov` (E36, UNVERIFIED input semantics). Run coverage only in the SQLite job.
- Local (Herd): coverage is optional for developers. CI is the authoritative gate. Herd's Xdebug/PCOV availability is **UNVERIFIED** (E35).
- **Thresholds:**
  - Global: `pest --parallel --coverage --min=85` (E10, E11).
  - Per directory (≥90 % on `app/Http` and `app/Policies`): Pest's `--min` is global only (E10). Emit `--coverage-clover build/clover.xml` and fail CI with a ~20-line PHP script, committed under `tests/`, that sums covered and total statements for files under those two paths. This adds no new dependency. The alternative of a second run whose `phpunit.xml` `<source>` covers only those paths doubles the runtime.

### 3. Mutation testing: Pest `--mutate`, scoped by `covers()`/`mutates()`
- Each domain test file declares `mutates(TodoPolicy::class)`, `mutates(StoreTodoRequest::class)`, and so on (E9).
- CI (SQLite job, PCOV): `pest --mutate --parallel --min=70 --ignore-min-score-on-zero-mutations`.
- **"Changed domain code"**: Pest has no diff-based mode (E9). With an MVP this small (about 10 domain classes), run mutation on all classes declared via `mutates()` on every PR. The cache (E9) keeps re-runs cheap. Revisit trigger: the mutation job exceeds 5 minutes. Then compute changed classes from `git diff` and pass `--class=`.
- Treat `@pest-mutate-ignore` as a reviewed exception, the same as weakening a test (governance "never weaken tests").

### 4. Browser and accessibility: pest-plugin-browser 5.x
- `pestphp/pest-plugin-browser ^5.2` + npm `playwright` (1.65.1). Both are **new dependencies to approve**. The PHP side also needs `ext-sockets` (E3, E4).
- Covers NFR-06 and NFR-09: keyboard flows (US-02.AC6, US-05.AC5, US-06.AC4, US-07.AC4) as scripted browser tests, `assertNoAccessibilityIssues()` (default level 1 = serious; **recommend level 2, moderate**), and `assertNoJavaScriptErrors()` on every page (E7).
- Browser matrix in CI: chrome (which also stands in for Edge, both being Chromium), firefox, safari (Playwright WebKit). Viewports: `->on()->mobile()` plus desktop. A 320 px width needs an explicit viewport. Whether the API exposes an arbitrary width is **UNVERIFIED** (E6).
- Run the browser tests as a **separate CI job** (`pest --ci --parallel --trace tests/Browser`) with Playwright traces uploaded on failure (E5). Keep them out of the coverage and mutation runs.
- Not chosen:
  - `vitest-axe`: it's unmaintained (0.1.0, E32).
  - A standalone `@playwright/test` + `@axe-core/playwright`: it's a second runner.
  - Dusk: Chrome only, so it can't meet NFR-09.
  - Lighthouse: kept as a manual staging check (NFR-06 "or Lighthouse"), not a CI gate.

### 5. N+1 guard (NFR-05, US-03.AC6)
- Primary test: seed 1 todo, count the queries for `GET /todos` (via `DB::enableQueryLog()` / `count(DB::getQueryLog())`), seed up to 100, count again, and assert the counts are equal. This is literally AC6, and it doesn't break when unrelated middleware or session queries change.
- Secondary test: `expectsDatabaseQueryCount(n)` (E15) as a fixed budget on the list endpoint, accepting that it must be updated deliberately when the query set changes.
- Production code: `Model::preventLazyLoading(! $this->app->isProduction())` in `AppServiceProvider` (E16), so any lazy load throws during tests.
- **Don't** enable `automaticallyEagerLoadRelationships()`. It hides N+1 problems instead of exposing them, and makes the test above meaningless.

### 6. Static analysis and linting
- **Larastan ^3.15 / PHPStan ^2.3 at level 8** (E18, E19). The starter kit ships level 7 (E20), so level 8 (nullability checks) is the minimum NFR-08 allows and a one-step increase. No baseline for our own code. A baseline is allowed only for unmodified starter-kit files, and only if the spike shows level-8 errors there. Revisit trigger: after the MVP ships with zero baseline entries, try level 9.
- **Pint ^1.32** via the kit's existing `composer lint:check` (E20, E33).
- **vue-tsc ^3.3** via the kit's existing `npm run types:check` (E21, E28).
- **Frontend lint: keep the kit's Vite+/Oxlint (`vp check`)**. That is the framework default, and adding ESLint as the main linter would duplicate it (E21, E25). For NFR-03, in order of preference:
  1. Load `eslint-plugin-vue` (10.12.1) through Oxlint `lint.jsPlugins` with `'vue/no-v-html': 'error'`, **if the T-001 spike proves it reports on `<template>`** (E31, UNVERIFIED).
  2. Otherwise, a minimal ESLint 10 flat config (`eslint`, `eslint-plugin-vue`, `vue-eslint-parser`, `typescript-eslint`) that enables **only** `vue/no-v-html: error` and runs as `npm run lint:xss`.
  3. Under either option, add a zero-dependency CI guard: `! grep -rn "v-html" resources/js --include=*.vue` (excluding `components/ui` only if the kit uses it there and a reviewer accepts that use).
  - **Human decision needed:** reword NFR-03 to "lint rule `vue/no-v-html` (or equivalent) as an error", and update `CLAUDE.md`'s command list (`npm run lint` → `npm run check`, `npm run types` → `npm run types:check`, `npm run test` → `vp test run`). This report doesn't edit those files.

### 7. Frontend unit tests
- Use `vp test` with the Vitest that Vite+ bundles (5.0.5 in vite-plus 1.4.0, E24). Config goes in the `test` block of `vite.config.ts`, imports from `vite-plus/test` (E25). Add `@vue/test-utils ^2.6` and `happy-dom` or `jsdom` (E27). Both are new dependencies.
- Frontend coverage: `@vitest/coverage-v8` pins **exactly** its Vitest version (E26), which must match the Vitest bundled inside `vite-plus`. Pin both together, and treat frontend coverage as **informational**, since NFR-07 targets the backend only.
- Scope: unit-test only composables and logic-heavy components (filter state, overdue calculation). User-visible behaviour is covered by the browser tests.

### 8. PostgreSQL in CI (D4)
- Job matrix `db: [sqlite, pgsql]`. The `pgsql` job adds `services: postgres` (image major to match Laravel Cloud's, decided in R-004) with a health check, `extensions: pdo_pgsql`, and env `DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=testing DB_USERNAME=postgres DB_PASSWORD=postgres` (E37, UNVERIFIED).
- Run it with a **dedicated `phpunit.pgsql.xml`** (`--configuration`) rather than relying on env-var precedence over `phpunit.xml` (E38).
- The `pgsql` job runs the plain suite with `--parallel`. Laravel creates `testing_test_N` databases (E14), which the default `postgres` superuser is allowed to do. No coverage or mutation runs in this job.

### Recommended CI gates (to be written into T-002 and test-strategy.md)

| Gate | Command (sketch) | Threshold |
|---|---|---|
| Pint | `vendor/bin/pint --parallel --test` | 0 diffs |
| PHPStan | `vendor/bin/phpstan analyse` | level 8, no new baseline entries |
| Front check | `npm run check` (`vp check`) + NFR-03 rule | 0 errors, 0 warnings (`denyWarnings`) |
| vue-tsc | `npm run types:check` | 0 errors |
| Pest (SQLite) | `pest --parallel --coverage --min=85 --coverage-clover …` + dir check | 85 % global, 90 % `app/Http`, `app/Policies` |
| Mutation | `pest --mutate --parallel --min=70 --ignore-min-score-on-zero-mutations` | 70 % on `mutates()` classes |
| Pest (Postgres) | `pest --parallel -c phpunit.pgsql.xml` | green |
| Browser | `pest --ci --parallel --trace tests/Browser` (chrome, firefox, safari) | green, no a11y issues ≤ moderate |
| Vitest | `vp test run` | green |

**Achievability vs NFR-07/08:**
- 85 % global coverage is realistic, because the starter kit's own auth and settings tests already exercise most of `app/Http`.
- 90 % on `app/Http` and `app/Policies` is realistic for a CRUD resource.
- A 70 % mutation score is realistic on Policies and FormRequests. `rules()` arrays generate many "remove array item" mutants, and each validation AC (US-02.AC2–AC4) kills them.
- PHPStan level 8 is one step above the kit's default.

None of these targets needs lowering. **UNVERIFIED** until the T-001 spike measures the starter kit's baseline under level 8 and its coverage.

## Strongest argument against the recommendation
The stack has many moving parts: Pest + mutate + browser plugin + Playwright, Vite+/Oxlint plus a possible second linter just for one rule, two DB jobs, three browsers, and a custom clover script, all for a todo app with about 8 routes. The browser plugin is the youngest piece: 5.x, with its own amphp server, an undocumented a11y engine, and reliance on Playwright browser downloads in CI. It is the most likely source of flaky gates, and agents are banned from weakening tests. A leaner alternative would be Pest feature tests + PHPStan + Pint + `vp check`, with a manual Lighthouse/axe pass on staging for NFR-06 and NFR-09. It meets most NFRs with half the CI time and no extra npm dependencies, at the cost of keyboard and focus ACs (US-02.AC6, US-06.AC4) being verified only by hand.

## What would change this recommendation
- **The T-001 spike** shows that:
  - Oxlint JS plugins can't lint `<template>`. Fall back to option B or C for NFR-03.
  - Pest's browser plugin is flaky in CI (more than 1 spurious failure per 20 runs). Move browser tests to a non-blocking job and reconsider Playwright standalone.
  - The starter kit fails PHPStan level 8 heavily (more than 50 errors). Baseline the kit files and keep level 8 for new code.
- **R-001** pins a different starter kit (for example a version still using ESLint and standalone Vitest). Then use plain `eslint.config.js` with `vue/no-v-html: error` and `vitest`, and the NFR-03 conflict disappears.
- **R-004** picks a Postgres major. Pin the CI service image to it.
- The mutation job exceeds 5 minutes. Switch to diff-scoped `--class=`.

## Risks

| Risk | Likelihood | Impact | Mitigation | Owner |
|---|---|---|---|---|
| Pest 5 needs PHP 8.4, but the kit's CI pins 8.3 | High (certain) | CI fails at install | Set `php-version: '8.4'` in T-002. It matches Herd's 8.4.25 | DevOps / T-002 |
| NFR-03 tooling mismatch (no ESLint in the kit) | High (certain) | NFR-03 unenforced or tooling duplicated | Human decision on NFR-03 wording; spike for jsPlugins; grep guard as a backstop | Architect → owner (G1) |
| `@vitest/coverage-v8` version drift vs the Vitest bundled in vite-plus | Medium | Frontend coverage breaks on upgrade | Pin together; frontend coverage informational only | Engineer |
| Browser tests flaky in CI (Playwright downloads, WebKit on Linux) | Medium | Blocked merges, pressure to weaken tests | Separate job, `--trace`, artifact upload, retries via Playwright cache. Flakiness goes to the human, never to skipping | QA |
| `assertNoAccessibilityIssues()` engine and rules undocumented | Medium | False confidence for WCAG 2.2 AA | Pair it with explicit keyboard and focus tests per AC, plus a manual Lighthouse/axe check on staging before G5 | QA |
| Exact `expectsDatabaseQueryCount` brittle | Medium | Noisy failures after framework updates | Primary guard is the 1-vs-100 equality test | QA |
| PHPUnit `<env>` precedence makes the Postgres job silently run on SQLite | Medium | D4 silently unmet | Dedicated `phpunit.pgsql.xml`. Add one test asserting `DB::getDriverName() === env('EXPECTED_DB_DRIVER')` | DevOps |
| Mutation score gamed with `@pest-mutate-ignore` | Low | NFR-07 hollowed out | Treat any ignore as a reviewed exception at G4 | QA / reviewer |
| Coverage tooling unavailable in Herd | Medium | Developers can't reproduce gates locally | CI authoritative; document Xdebug enablement after verifying Herd docs | Engineer |
| Laravel `laravel` arch preset conflicts with Fortify or starter-kit classes | Medium | Red arch tests on day one | Scoped `ignoring()` for kit namespaces, reviewed once | Engineer |

## Red-team review (2026-11-16)

**Reviewer:** architect agent (red-team mode). I ran spikes in `scratchpad/redteam/kit`, a copy of the R-001 scaffold (Pest 5.3.6, vite-plus 1.4.0 / oxlint 1.89.0).

**Verdict: Stands with changes.** Pest 5, PCOV, Larastan level 8, a query-count equality test and a Postgres matrix are all sound. **§6 option 1 for NFR-03 is refuted** and must be replaced. Several UNVERIFIED items are now resolved below.

| # | Severity | Finding | Evidence | Required change |
|---|---|---|---|---|
| 1 | High | **Oxlint can't enforce `vue/no-v-html` today, natively or through JS plugins.** The bundled oxlint 1.89.0 config schema lists 46 `vue/*` rules, all script-level, and none of them is `no-v-html`. The oxc JS-plugins docs list "Custom file formats and parsers (e.g. Svelte, Vue, Angular)" as **not supported**, and JS plugins as "alpha". Also, `vue/no-v-html` and a `v-html` grep **both miss `:innerHTML="…"`**, which sets raw HTML just the same | `grep -o '"vue/[a-z-]*"' node_modules/oxlint/configuration_schema.json \| sort -u` gives 46 rules; `grep -c no-v-html` gives 0. `node_modules/.bin/oxlint --version` gives `1.89.0`. https://oxc.rs/docs/guide/usage/linter/js-plugins.html (fetched 2026-11-16) | Replace the §6 preference order with: **(1) a zero-new-dependency template check** built on `@vue/compiler-sfc` (already installed, transitively via `@vitejs/plugin-vue`) that fails on `v-html` and on `innerHTML`/`outerHTML` bindings. Spike: a 15-line `vhtml-check.mjs` flagged `TwoFactorSetupModal.vue:174 v-html="qrCodeSvg"` and the probe `:innerHTML="x"`, exit 1. Run it as an npm script in `npm run check` or CI. Relying on a transitive package is fragile, so either declare `@vue/compiler-sfc` explicitly (dependency approval, same version as `vue`) or pin it through the lock. **(2)** ESLint minimal config (4 new deps), only if (1) is rejected. **(3)** Add a script-side grep for `innerHTML`, `outerHTML` and `insertAdjacentHTML` in `resources/js/**/*.ts` and `*.vue`. NFR-03 wording: "a CI check forbids `v-html` and raw-HTML bindings in templates; exceptions are listed and reviewed" |
| 2 | High | **Every NFR-03 guard fails on the unmodified kit**: `TwoFactorSetupModal.vue` (outside `components/ui`) uses `v-html` for the Fortify QR SVG | `grep -rn "v-html" resources/js` in the spike | One reviewed allow-list entry if 2FA is kept (decided with R-001 Recommendation 3), otherwise removed with 2FA |
| 3 | Medium | **E38 resolved, with a trap.** A CI env var *does* override `<env name="DB_CONNECTION" value="sqlite"/>` in `phpunit.xml`. But `DB_DATABASE=:memory:` from `phpunit.xml` stays unless it is also set, so the Postgres job would connect to a database named `:memory:` | `DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5999 php vendor/bin/pest tests/Feature/DashboardTest.php` gives `Connection: pgsql, Host: 127.0.0.1, Port: 5999, Database: :memory:` | Set **all** `DB_*` in the pgsql job. `phpunit.pgsql.xml` becomes optional. Keep the driver-assert test, and also assert the database name |
| 4 | Medium | **E35 resolved: there is no coverage driver locally.** Agents can't run the `--coverage`/`--mutate` gates on this machine, so for NFR-07 "Done = gates pass" is CI-only. `CLAUDE.md` says `composer test # pest (parallel, coverage)`, but the kit's `composer test` runs Pint, PHPStan and then `php artisan test`, with no parallel run and no coverage | `php -m` (PHP 8.4.25): no `pcov`, no `xdebug`. R-001 E22 | Document "coverage and mutation are CI-authoritative" in `test-strategy.md` and `CLAUDE.md`, or have the human approve installing PCOV in Herd's PHP (method **UNVERIFIED**) |
| 5 | Medium | **The PHPStan level 8 baseline has been measured: 10 errors**, all `$request->user()` nullable (ProfileController ×5, SecurityController ×4, ProfileUpdateRequest ×1). Level 9 gives 13, level 10 gives 16. The ">50 errors" trigger is not hit | `vendor/bin/phpstan analyse --level=8 --memory-limit=1G` → `"errors":10` | Fix them in T-001. No baseline file is needed. Level 9 is 3 more fixes (FortifyServiceProvider, `config/fortify.php`), so consider it now rather than after the MVP |
| 6 | Medium | **This contradicts R-001 on the CI PHP version.** R-003 (8.3 upstream) is right. R-001's spike file was 8.4, by an unexplained route (see R-001 red-team finding 4). Also, `composer.json` declares `"php": "^8.3"` while Pest 5 needs `^8.4` | `curl …/main/.github/workflows/tests.yml` gives `php-version: '8.3'`. `r001-spike/composer.json` line 12 | Raise both the CI version and `composer.json` `php` to `^8.4` in T-001/T-002 |
| 7 | Medium | **Unstated cost: Actions minutes.** Per PR the gates are SQLite+coverage, mutation, pgsql, three browser jobs, Vitest and build, and R-004 re-runs CI on every merge to `main`. Private repos have a monthly minutes quota, and the figures (about 2,000 Free / 3,000 Pro) are **UNVERIFIED** (billing page not fetched) | Gate table above; R-004 deploy flow step 3 | T-002 measures the per-run minutes. Run the Firefox and WebKit jobs on `main` or nightly, not on every PR push, unless the quota allows it |
| 8 | Medium | **Stale ESLint/Vitest references outside the research folder.** NFR-03, NFR-08 ("Zero ESLint … errors"), `CLAUDE.md` Commands, `.claude/agents/devops-engineer.md` ("eslint", "vitest"), `.claude/agents/vue-engineer.md` (`npm run lint`, `npm run types`, `npm run test`), and `docs/templates/task.md` ("eslint … clean") | `grep -rn -i eslint .claude/agents CLAUDE.md docs/00-product docs/templates` | List all six files in the G1 decision for the human to update (agents may not edit them now) |
| 9 | Low | NFR-09 "Safari" through Playwright WebKit on Linux is an approximation, not Safari. "Edge" is covered by Chromium | E6 | Say "WebKit (Safari engine)" in the traceability evidence. Add a manual Safari check before G5 |
| 10 | Low | **Drift works when run outside the agent env** (R-001 red-team finding 1), so the shipped tests can be Pest-style from commit 1. That makes `covers()`/`mutates()` usable everywhere | Converted suite: 42 passed (147 assertions) | No action beyond R-001's change |

**Strongest argument against (re-checked):** "too many moving parts." This is partly upheld. Moving the extra browser engines off the PR path (finding 7) reduces flake pressure without weakening the gate set.
