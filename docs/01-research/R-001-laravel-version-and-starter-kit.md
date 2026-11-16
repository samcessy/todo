# R-001: Laravel version, PHP requirement, support window, and the official Vue starter kit

**Status:** Draft · **Author:** architect agent · **Red team:** pending · **Date:** 2026-11-16

## Question
Which Laravel version and official Vue starter kit should T-001 scaffold, what does the kit actually ship, and how do we scaffold it into the existing (non-empty) repo?

## Constraints
- D1 (`00-product/assumptions.md`): email and password auth with email verification, password reset and account deletion. No social login. No custom auth code.
- D2: web only, Inertia monolith (R-002 confirms).
- D3: Herd locally, Laravel Cloud for hosting.
- D4: SQLite locally and in the fast test suite, PostgreSQL in staging, production and a CI job.
- D7: Claude Code plus Laravel Boost, with `CLAUDE.md` as the canonical guide.
- NFR-03 (`vue/no-v-html` as an ESLint error), NFR-07 (coverage), NFR-08 (PHPStan level 8 or higher, zero ESLint, Pint or vue-tsc errors).
- Local environment (checked on 2026-11-16): PHP 8.4.25, Composer 2.10.2, Node 26.12.1, npm 11.20.0, Laravel Installer 5.32.0, Herd 1.30.2.
- The repo root already contains `.git/`, `.claude/`, `.idea/`, `docs/`, `CLAUDE.md`, `README.md` and `.gitignore`.

## Options

**A. Framework version**

| Option | Summary |
|---|---|
| A1 Laravel 13.x (current, 13.42.0) | Current major. PHP 8.3 to 8.5. Bug fixes until Q3 2027, security fixes until 2028-03-17 |
| A2 Laravel 12.x | Bug fixes ended 2026-08-13. Security fixes only, until 2027-02-24. Ruled out |

**B. Scaffold method for the existing repo**

| Option | Summary |
|---|---|
| B1 `laravel new .` in the repo root | Not possible. The installer refuses a non-empty current directory, and `--force` is explicitly rejected for `.` (see Evidence E20 and E21) |
| B2 `laravel new <name> --force` pointed at the repo | Destructive. `--force` runs `rm -rf "<directory>"` first and would delete `docs/`, `.claude/` and `.git/`. **Never do this** |
| B3 Scaffold into a temporary sibling directory, then copy into the repo without overwriting anything (**recommended**) | Uses the official installer and its post-install hooks unchanged. The only real conflicts are `README.md` and `.gitignore`, which are merged by hand |
| B4 `composer create-project laravel/vue-starter-kit` into a temporary directory, then copy | Same result as B3, but it skips the installer's database, Pest and npm steps, so they would have to be repeated by hand. More steps for no gain |

## Evaluation criteria
Complexity, security surface, testability, deploy simplicity, future flexibility, maturity ("boring technology" counts in an option's favour). For B, the criteria are also: no risk to existing repo content, and reproducibility by a human or an agent.

## Evidence

Spike: `laravel new r001-spike --vue --pest --database=sqlite --npm --no-boost --no-interaction`, run in
`/private/tmp/claude-501/-Users-air-code/d432f7dd-7c24-4901-b8f3-881de9b0efe6/scratchpad/r001-spike`.
It finished with exit code 0 in about 2 minutes 3 seconds. Output: `{"success":true,"name":"r001-spike",...}`. The installer prints JSON when it detects an agent.

### Framework and support window

| # | Claim | Source (URL) | Version / date | Verified by command? |
|---|---|---|---|---|
| E1 | The latest stable framework release is **v13.42.0**. The newest 12.x release is v12.72.0. There is no 14.x release yet | Packagist via `composer show -a laravel/framework` | 2026-11-16 | Yes. Versions list: `dev-master, 13.x-dev, v13.42.0, v13.41.0 …, 12.x-dev, v12.72.0 …` |
| E2 | laravel/framework 13.x requires `php ^8.3` | `composer show -a laravel/framework` (requires section). Also https://laravel.com/docs/13.x/releases ("Laravel 13.x requires a minimum PHP version of 8.3") | 13.42.0 | Yes. Output: `requires php ^8.3` |
| E3 | Laravel 13: supported PHP versions 8.3 to 8.5. Released 2026-03-17. **Bug fixes until "Q3 2027". Security fixes until 2028-03-17** | https://laravel.com/docs/13.x/releases#support-policy | Laravel 13.x docs, fetched 2026-11-16 | No (doc). The bug-fix date is only given as a quarter |
| E4 | Laravel 12: PHP 8.2 to 8.5. Bug fixes until 2026-08-13 (already passed). Security fixes until 2027-02-24 | Same | Same | No (doc) |
| E5 | Policy: bug fixes for 18 months, security fixes for 2 years. A new major ships each year in about Q1, so Laravel 14 is expected around Q1 2027 | Same ("Versioning Scheme", "Support Policy") | Same | No (doc). The Laravel 14 timing is an inference from the stated cadence |
| E6 | The spike installed **Laravel Framework 13.42.0** | Spike | 2026-11-16 | Yes. `php artisan --version` gives `Laravel Framework 13.42.0` |
| E7 | Local PHP 8.4.25 is within the 13.x range (8.3 to 8.5). The spike CI workflow uses PHP 8.4 | E3, plus the spike's `.github/workflows/tests.yml` | — | Yes (file read) |

### What the Vue starter kit ships (spike, 2026-11-16)

| # | Claim | Source (URL) | Version / date | Verified by command? |
|---|---|---|---|---|
| E8 | `laravel new --vue` runs `composer create-project laravel/vue-starter-kit <dir> --stability=dev`. `--no-authentication` swaps in `laravel/blank-vue-starter-kit`. `--workos` and `--teams` select branches of the kit. The composer package name is `laravel/vue-starter-kit` | Installer source `~/.composer/vendor/laravel/installer/src/NewCommand.php` lines 521–543 and 1310–1328 (mirrors https://github.com/laravel/installer). Spike `composer.json` `"name": "laravel/vue-starter-kit"` | Installer 5.32.0 | Yes (source and file read) |
| E9 | Docs: the Vue kit is "built with Inertia 3, Vue 3 Composition API, Tailwind, and shadcn-vue" and uses TypeScript | https://laravel.com/docs/13.x/starter-kits#vue | 13.x docs | No (doc). Confirmed by the spike below |
| E10 | Installed backend packages: inertiajs/inertia-laravel **3.7.0**, laravel/fortify **1.42.0**, laravel/wayfinder 0.1.23, laravel/tinker 3.1.0, laravel/chisel 0.1.1. Dev packages: **pestphp/pest 5.3.6**, pest-plugin-laravel 5.0.1, pest-plugin-drift 5.0.0, **larastan/larastan 3.15.0**, laravel/pint 1.32.3, laravel/pail 1.3.0, laravel/pao 1.1.5 (agent-optimised test output), laravel/sail 1.70.0, mockery 1.6.15, collision 8.9.6, faker 1.24.1 | Spike | 2026-11-16 | Yes, `composer show --direct` |
| E11 | Installed frontend packages: **vue 3.5.45**, **@inertiajs/vue3 3.11.0**, @inertiajs/vite 3.11.0, **tailwindcss 4.3.5** (with @tailwindcss/vite 4.3.5), **reka-ui 2.11.0** (the primitives behind shadcn-vue), class-variance-authority, clsx, tailwind-merge, tw-animate-css, @lucide/vue 1.73.0, @vueuse/core 12.8.2, vue-sonner 2.0.9, vue-input-otp 0.3.2, @laravel/passkeys 0.2.0, laravel-vite-plugin 3.2.1, @laravel/vite-plugin-wayfinder 0.1.11, **typescript 5.9.3**, **vue-tsc 3.3.14**, **vite-plus 1.4.0**. `vite` is aliased to `@voidzero-dev/vite-plus-core@1.4.0` | Spike | 2026-11-16 | Yes, `npm ls --depth=0` |
| E12 | UI component library: shadcn-vue, style "new-york", base colour neutral, Lucide icons. 22 components are published under `resources/js/components/ui/`: alert, avatar, badge, breadcrumb, button, card, checkbox, collapsible, dialog, dropdown-menu, input, input-otp, label, navigation-menu, select, separator, sheet, sidebar, skeleton, sonner, spinner, tooltip | Spike `components.json` and directory listing | 2026-11-16 | Yes |
| E13 | Fortify features enabled in `config/fortify.php`: `registration()`, `resetPasswords()`, `emailVerification()`, `twoFactorAuthentication(['confirm'=>true,'confirmPassword'=>true])`, **`passkeys(['confirmPassword'=>true])`**. Login is rate-limited to 5 per minute per email and IP (`FortifyServiceProvider`). Emails are stored lowercase (`lowercase_usernames => true`). Home path is `/dashboard` | Spike config and provider. Docs: https://laravel.com/docs/13.x/starter-kits#authentication | Fortify 1.42.0 | Yes (file read) |
| E14 | **Email verification is on by default:** `App\Models\User implements MustVerifyEmail, PasskeyUser`, and `dashboard` sits behind `['auth','verified']`. Note: the docs FAQ still says to "uncomment" `MustVerifyEmail`, so the docs lag behind the kit | Spike `app/Models/User.php`, `routes/web.php`. Doc: https://laravel.com/docs/13.x/starter-kits#faqs | 2026-11-16 | Yes (file read). The doc and the code disagree, and the code wins |
| E15 | **Account deletion is included:** `DELETE settings/profile` (`profile.destroy`, `auth`+`verified`) requires the current password (`ProfileDeleteRequest`). The test `test_user_can_delete_their_account` asserts the user is logged out and the record is deleted | Spike `routes/settings.php`, `tests/Feature/Settings/ProfileUpdateTest.php` | 2026-11-16 | Yes (file read, and the test passes) |
| E16 | Other screens: profile update, security page (password change throttled to 6 per minute, 2FA management, passkey management, behind `RequirePassword`), appearance (light/dark). Pages: auth/Login, Register, ForgotPassword, ResetPassword, VerifyEmail, ConfirmPassword, TwoFactorChallenge, Dashboard, Welcome, settings/Profile, Security, Appearance | Spike `routes/settings.php`, `resources/js/pages/**` | 2026-11-16 | Yes |
| E17 | Migrations: users, cache, jobs, `create_passkeys_table`, `add_two_factor_columns_to_users_table` | Spike `database/migrations/` | 2026-11-16 | Yes |
| E18 | **Default database is SQLite:** `config/database.php` `'default' => env('DB_CONNECTION','sqlite')`. `post-create-project-cmd` touches `database/database.sqlite` and runs migrations. `phpunit.xml` uses `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:` | Spike | 2026-11-16 | Yes |
| E19 | **Testing:** with `--pest`, Pest 5.3.6 is installed and `tests/Pest.php` binds `TestCase` plus `RefreshDatabase` to `Feature`. The 14 shipped test files stay **PHPUnit class-style** (for example `class DashboardTest extends TestCase`), and Pest runs them. **Result: 42 tests, 42 passed, 147 assertions, about 2.8 s.** The installer's drift conversion (`pest --drift`) **failed silently**. Rerunning it gives `The [--drift] argument only accepts the directory to convert as argument.`, and `pest-plugin-drift` stays in `require-dev`. There are no frontend unit tests and **Vitest is not wired up** (no `test` npm script), although `vitest` is present in `node_modules/.bin` through vite-plus | Spike. Installer source lines 996–1021 | Pest 5.3.6, Installer 5.32.0 | Yes: `php artisan test` gives `{"tool":"pest","result":"passed","tests":42,"passed":42,"assertions":147}`. Drift rerun output recorded above |
| E20 | **Lint and format are not ESLint and Prettier.** The kit uses **Vite+ (`vp`)**, which bundles **Oxlint** and **Oxfmt** (configured under `lint:` and `fmt:` in `vite.config.ts`: `denyWarnings: true`, `typeAware: true`, 4-space indent, single quotes, Tailwind class sorting, `components/ui/*` and generated Wayfinder files ignored). There is no `.eslintrc`, `eslint.config.*` or `.prettierrc` | Spike `vite.config.ts`, `node_modules/.bin` (oxlint, oxfmt, vitest, vp). Tool description: https://viteplus.dev/guide/ ("bundles Vite, Vitest, Oxlint, Oxfmt, Rolldown, tsdown…"; "`vp check` runs format, lint, and type checks together") | vite-plus 1.4.0 | Yes. `npm run check` gives `pass: All 68 files are correctly formatted` and `pass: Found no warnings or lint errors in 62 files` |
| E21 | **npm scripts:** `build` (`vp build`), `build:ssr`, `dev` (`vp dev`), `check` (`vp check`), `check:fix`, `types:check` (`vue-tsc --noEmit`). There are no `lint`, `types`, `format` or `test` scripts. `.npmrc` sets `ignore-scripts=true`. A `pnpm-workspace.yaml` file is present | Spike `package.json`, `.npmrc` | 2026-11-16 | Yes. `npm run types:check` passes with no output. `npm run build` gives `✓ built in 1.53s` |
| E22 | **Composer scripts:** `setup` (install, copy .env, key:generate, migrate, npm install, npm run build), `dev` (`php artisan dev`), `lint` (`pint --parallel`), `lint:check` (`pint --parallel --test`), `types:check` (`phpstan analyse`), `test` (config:clear, **lint:check, types:check, then `php artisan test`**), `ci:check` (`npm run check`, `npm run types:check`, `@test`), plus the standard post-* hooks | Spike `composer.json` | 2026-11-16 | Yes (file read) |
| E23 | **PHPStan (Larastan) ships at `level: 7`**, covering app/, bootstrap/app.php, config/, database/ and routes/. NFR-08 needs level 8 or higher | Spike `phpstan.neon` | Larastan 3.15.0 | Yes. Under the local 128 MB PHP `memory_limit`, `composer test` **failed**: `PHPStan process crashed because it reached configured PHP memory limit: 128M`. `vendor/bin/phpstan analyse --memory-limit=1G` gives `{"result":"passed","errors":0}` |
| E24 | The kit ships `.github/workflows/tests.yml` (PHP 8.4, **Node 22**, `composer setup` then `composer ci:check`, actions pinned by SHA, `permissions: contents: read`) and `.github/dependabot.yml` | Spike | 2026-11-16 | Yes (file read) |
| E25 | **The kit's `.gitignore` ignores `/.claude`, `/CLAUDE.md`, `/AGENTS.md`, `/.mcp.json`, `/boost.json` and `/.idea`.** It also ignores the generated Wayfinder dirs `resources/js/actions`, `routes` and `wayfinder` | Spike `.gitignore` | 2026-11-16 | Yes. **This conflicts with our repo, where `.claude/` and `CLAUDE.md` are tracked source of truth** |
| E26 | The Inertia v3 Laravel adapter needs at least PHP 8.2 and Laravel 11. v3 is ESM-only with an ES2022 build target, and Axios is removed in favour of a built-in XHR client | https://inertiajs.com/docs/v3/getting-started/upgrade-guide | Inertia 3.0 docs | No (doc) |
| E27 | Wayfinder generates typed route helpers at build time. **Disabling a Fortify feature means removing its frontend route references, or the build fails** | https://laravel.com/docs/13.x/starter-kits#enabling-and-disabling-features | 13.x docs | No (doc) |

### Installer behaviour in a non-empty directory

| # | Claim | Source (URL) | Version / date | Verified by command? |
|---|---|---|---|---|
| E28 | `laravel new .`: `getInstallationDirectory('.')` returns `'.'`. Then `verifyApplicationDoesntExist('.')` throws `Application already exists!` because `is_dir('.')` is true and `'.' != getcwd()`. So **`laravel new .` refuses to run in an existing directory** | Installer source lines 1282–1287 and 1376–1383 | Installer 5.32.0 | Source read only. The command test in a spike dir could not be run (tool refusal). **Partly UNVERIFIED by execution** |
| E29 | `--force` with `.` throws `Cannot use --force option when using current directory for installation!`. `--force` with a named directory runs **`rm -rf "<directory>"`** before create-project | Installer source lines 514–516 and 552–559 | Installer 5.32.0 | Source read only |
| E30 | Installer 5.33.0 exists on Packagist. Local is 5.32.0 | `composer show -a laravel/installer` | 2026-11-16 | Yes. Whether 5.33.0 fixes the drift bug (E19) is **UNVERIFIED** |
| E31 | Starter kit docs flow: `laravel new my-app` (interactive), then `npm install && npm run build`, then `composer run dev` | https://laravel.com/docs/13.x/starter-kits#creating-an-application | 13.x docs | No (doc) |

## Recommendation

1. **Pin Laravel 13.x** (`laravel/framework ^13.17` as the kit declares it, resolved to 13.42.0) on **PHP 8.4** (local 8.4.25. Laravel Cloud's PHP version is checked in R-004). Bug fixes run until Q3 2027 and security fixes until 2028-03-17. **Plan the Laravel 14 upgrade for about Q2 2027** (revisit trigger: the Laravel 14 release).
2. **Use the official `laravel/vue-starter-kit`** with Fortify auth, Pest and SQLite. It covers **all of D1 with no custom auth code**: registration, email verification (on by default), password reset, account deletion with password confirmation, and login rate limit 5/min (US-01.AC4).
3. **Decide on two features the kit adds beyond D1: TOTP 2FA and passkeys.** Default recommendation: **keep them**. Removing them means deleting Fortify features, migrations, Vue components and Wayfinder references (E27), which is churn on security-sensitive code with no story behind it. They are opt-in per user, and the kit's own tests cover them. This needs explicit human approval at G1, because it widens the auth surface beyond D1. If they are kept, the security review (NFR-01) must cover them.
4. **Scaffold with option B3** (procedure below). Never use `--force` against the repo.
5. **Record these deviations from `CLAUDE.md` and the NFRs for ADR-0001 and R-003.** T-001 must not paper over them:
   - Frontend lint and format is **Oxlint and Oxfmt through Vite+**, not ESLint and Prettier. NFR-03 names the ESLint rule `vue/no-v-html`. Whether Oxlint has an equivalent rule is **UNVERIFIED**. R-003 must answer this: an Oxlint rule, an ESLint addition (new dependency, needs approval), or a CI grep guard.
   - Script names differ from `CLAUDE.md`. The real ones are `npm run check` (not `lint`) and `npm run types:check` (not `types`). There is no `npm run test` and no Vitest config. `composer test` already runs Pint, PHPStan and Pest. `CLAUDE.md` § Commands must be updated in T-001's PR (rule 7).
   - PHPStan ships at level 7. T-001 raises it to 8 (NFR-08). Raising a threshold is allowed.
   - PHPStan needs `--memory-limit` (for example 1G) in the `types:check` script. Otherwise `composer test` crashes under the local 128 MB limit (E23).
   - The shipped tests are PHPUnit class-style (drift failed). They run fine under Pest. Converting them is optional and goes to the QA agent. Remove the leftover `pestphp/pest-plugin-drift` dev dependency, or keep it for the conversion.
6. **Boost:** scaffold with `--no-boost`. Install Boost afterwards as its own step, once R-005 is approved, so it cannot overwrite our hand-written `CLAUDE.md` or `.claude/`.

### Recommended T-001 scaffold procedure

Prerequisites: G3 approved, T-001 task file exists, human present for the `.env` steps (agents may not touch `.env*`).

```bash
# 0. Clean state: the repo has only docs/config; commit or stash anything pending
cd ~/code/todo-app && git status        # must be clean

# 1. Scaffold OUTSIDE the repo (sibling temp dir), non-interactive, reproducible flags
cd ~/code
laravel new todo-app-scaffold --vue --pest --database=sqlite --npm --no-boost --no-interaction
#   (no --git/--github: the repo already exists; no --force ever)

# 2. Copy into the repo WITHOUT overwriting existing files, excluding machine-local artefacts
rsync -a --ignore-existing \
  --exclude '.git/' --exclude '.env' --exclude 'vendor/' --exclude 'node_modules/' \
  --exclude 'database/database.sqlite' --exclude 'public/build/' --exclude '.idea/' \
  todo-app-scaffold/ todo-app/
#   Expected collisions skipped by --ignore-existing: README.md, .gitignore (merge by hand, step 3)

# 3. Merge .gitignore: append the kit's entries to ours, but DELETE these kit lines:
#      /.claude  /CLAUDE.md  /AGENTS.md
#    (keep: .claude/settings.local.json, .env, .env.*, !.env.example, /vendor, /node_modules,
#     /public/build, /public/hot, Wayfinder generated dirs, /.phpunit.cache, etc.)
#    Decide /.mcp.json and /boost.json in R-005 (Boost may need them committed).
diff todo-app-scaffold/.gitignore todo-app/.gitignore
#    README.md: keep ours; optionally add a "Local setup" section pointing to `composer setup`.

# 4. Install and initialise inside the repo (HUMAN runs, because it creates/reads .env)
cd ~/code/todo-app
touch database/database.sqlite
composer setup          # composer install, .env from .env.example, key:generate, migrate, npm install, npm run build

# 5. Apply the agreed baseline edits (each one approved in T-001):
#    - phpstan.neon: level 7 -> 8; composer "types:check": "phpstan analyse --memory-limit=1G"
#    - CLAUDE.md "Commands": real script names (composer test, composer lint:check,
#      composer types:check, npm run check, npm run types:check, npm run build; vitest per R-003)
#    - remove pestphp/pest-plugin-drift (or keep it for the QA conversion task)

# 6. Verify the deterministic gates in the repo (record output in the handoff)
composer test           # pint --test + phpstan + pest -> expect 42 passed on a fresh kit
npm run check           # oxfmt + oxlint
npm run types:check     # vue-tsc
npm run build
composer audit && npm audit --audit-level=high   # NFR-04 baseline

# 7. Confirm nothing outside the kit changed, then remove the temp dir
git status && git diff --stat -- docs .claude CLAUDE.md   # must show no changes under docs/ and .claude/
rm -rf ~/code/todo-app-scaffold
```

Expected size: the kit adds hundreds of files, well over the roughly 400-line PR limit. Ask the human to grant T-001 a **one-time exception for the vendor scaffold commit**: commit 1 is the unmodified kit, and commit 2 holds the baseline edits from step 5. The diff stays reviewable and the provenance stays clear.

## Strongest argument against the recommendation

The kit is **much bigger and more opinionated than our MVP needs, and it is moving fast.** It brings 2FA, passkeys (WebAuthn, `@laravel/passkeys` 0.2.0, which is pre-1.0), Wayfinder (0.1.x, pre-1.0), Laravel Chisel (0.1.x), an appearance switcher, 22 UI components, and a pre-1.0-era toolchain swap (Vite+ 1.4 with `vite` aliased to `@voidzero-dev/vite-plus-core`, Oxlint and Oxfmt instead of ESLint and Prettier). Several of these are pre-1.0 packages, and the kit is installed from `--stability=dev` (`dev-main`). That means a larger attack surface (NFR-01, NFR-04), more code to own (the docs say "there is no need to update the starter kit itself", so we never get upstream fixes), and tooling that does not match our NFRs (ESLint `vue/no-v-html`, PHPStan 8) or `CLAUDE.md`. A minimal alternative, `laravel/laravel` plus Inertia plus Fortify headless, wired by hand, would give a smaller surface and boring ESLint tooling. The cost is writing the auth UI ourselves, which contradicts D1's "no custom auth code" and adds the risk of auth bugs written by us. On balance the kit still wins: auth is the riskiest code to write by hand, and the kit ships 42 passing tests for it. The objection is real, though. It is why the 2FA and passkeys decision and the linter decision are escalated rather than assumed.

## What would change this recommendation

- The human rejects 2FA and passkeys. T-001 then gets an explicit "strip features" step: remove the Fortify features, migrations, components and Wayfinder references, and change or remove the related tests (QA agent only).
- R-003 finds that Oxlint cannot enforce `vue/no-v-html`, and ESLint is not approved. Then we use a CI grep guard or reopen NFR-03's wording.
- Laravel Cloud (R-004) does not offer PHP 8.4, or forces 8.5. Laravel 13 supports 8.3 to 8.5, so only the CI PHP version changes.
- Laravel 14 is released before T-001 runs. Re-run this spike on 14.x before scaffolding.
- Installer 5.33.0 or later changes the flags or fixes drift. Re-run step 1 with `laravel --version` recorded.

## Risks

| Risk | Likelihood | Impact | Mitigation | Owner |
|---|---|---|---|---|
| The kit's `.gitignore` silently untracks `.claude/` and `CLAUDE.md` if it is copied over ours | High (if copied naively) | High (the agent guide and governance hooks are lost from the repo) | `rsync --ignore-existing`, hand-merge `.gitignore` (step 3), and the `git status` check in step 7 | T-001 engineer, reviewed at G4 |
| Someone runs `laravel new … --force` against the repo path, which runs `rm -rf` | Low | Critical (deletes docs, .claude, .git) | The procedure forbids `--force`. Scaffold only into a new sibling dir. Candidate deny rule in `.claude/settings.json` for `laravel new*--force` (R-005) | Human, R-005 |
| The kit comes from `dev-main` (`--stability=dev`), so content drifts between this spike and T-001 | Medium | Medium | Re-run the spike checks in T-001 and record `composer show --direct` and `npm ls --depth=0` in the handoff. The lock files pin versions after scaffolding | T-001 engineer |
| Lint stack mismatch (Oxlint and Oxfmt instead of ESLint and Prettier). NFR-03 is not enforceable as written | High | Medium (XSS guard missing) | R-003 decides. Until then, `grep -rn "v-html" resources/js` in CI | Architect (R-003) |
| PHPStan out-of-memory under the local 128 MB limit breaks `composer test` | High (seen) | Low | Add `--memory-limit=1G` to the `types:check` script | T-001 engineer |
| PHPStan level 7 to 8 surfaces errors in kit code | Medium | Low | Fix them in T-001's baseline commit, or add a baseline file (needs approval, because it amounts to a threshold change) | T-001 engineer |
| Extra auth surface (2FA, passkeys with pre-1.0 `@laravel/passkeys`) widens the threat model | Medium | Medium | Human decision at G1, plus a security review in the threat model (G2) | Human, architect |
| CI uses Node 22 (kit) while local uses Node 26: build or tool differences | Low | Low | Pin the Node version in CI to an LTS that Vite+ supports. Record it in ADR-0001 | T-002 |
| Shipped tests are PHPUnit-style while our standard is Pest. `pest-plugin-drift` is left behind | High (seen) | Low | Optional QA task to convert. Remove drift if unused | QA agent |
| Pre-1.0 packages (Wayfinder 0.1, Chisel 0.1, passkeys 0.2) introduce breaking minor releases | Medium | Low to medium | Lock files, Dependabot (shipped), and minor upgrades treated as reviewed changes | Engineer |
| Docs lag behind the kit (for example, the FAQ says to uncomment `MustVerifyEmail`, but it is already on) | Medium | Low | Treat the spike code as ground truth. Re-verify with Boost `search-docs` and the code | All agents |

## UNVERIFIED items
- Whether Oxlint (via vite-plus 1.4.0) offers a `vue/no-v-html` equivalent (the oxc rule docs fetch failed).
- `laravel new .` refusal and `--force` behaviour were verified by reading the installer 5.32.0 source, not by executing it.
- Whether installer 5.33.0 fixes the `pest --drift` failure.
- The Laravel 14 release timing (inferred from the "about Q1" cadence). The exact 13.x bug-fix end date is only "Q3 2027".
- Whether `composer setup` works on a fresh clone without first running `touch database/database.sqlite` (`migrate --force` on a missing SQLite file was not tested).
- Whether `vp test` (Vitest bundled in Vite+) works for Vue SFC tests without new dependencies. This is for R-003.
- The vite-plus licence and maturity (viteplus.dev states no licence on the guide page).

## Red-team review (2026-11-16)

**Reviewer:** architect agent (red-team mode). **Method:** independent re-check against official sources and commands. The spike was copied with `cp -cR` to `scratchpad/redteam/kit`, so the original `r001-spike` was not changed.

**Verdict: Stands with changes.** Laravel 13.x plus the official Vue starter kit is the right call, and B3 (scaffold in a sibling directory, then copy) is the right scaffold method. Three things must be corrected before G1: the diagnosis of the drift failure, the description of the `.gitignore` risk and how to detect it, and the claim about Dependabot. A new fact also links the 2FA decision to NFR-03 (finding 2).

| # | Severity | Finding | Evidence | Required change |
|---|---|---|---|---|
| 1 | High | **The drift failure (E19) is caused by the agent environment, not by Pest or the installer.** The installer was run from inside Claude Code. Agent-detection env vars switch on `laravel/pao` (via `laravel/agent-detector`), and with them set `pest --drift` rejects its arguments. When those vars are unset, the conversion works and the converted suite passes. | In `redteam/kit`: `php vendor/bin/pest --drift tests` gives `"The [--drift] argument only accepts the directory to convert as argument."`. `env -u CLAUDECODE -u CLAUDE_CODE_ENTRYPOINT -u AI_AGENT php vendor/bin/pest --drift tests` gives `The [tests] directory has been migrated to PEST with 12 files changed.` Then `grep -rln "^class " tests` gives 0, and `pest` gives **42 passed (147 assertions)**. `grep -rln AI_AGENT vendor/` finds `vendor/laravel/agent-detector/src/AgentDetector.php`. Installer 5.32.0 `NewCommand.php` lines 1001–1011 runs require drift, then `pest --drift`, then remove drift as one chain | Step 1 must be run **by the human in a plain terminal**, or the agent must run the conversion with the env vars unset, inside the scaffold dir, before copying. Commit 1 then holds the true installer output: Pest-style tests, with drift already removed by the installer. Delete the "optional QA conversion" item and the matching risk row. Note: each converted file gets a redundant `uses(RefreshDatabase::class)` that is already bound in `tests/Pest.php`. It is harmless, and the QA agent can clean it up later |
| 2 | High | **The kit itself uses `v-html`**, so NFR-03 as written (any rule or grep guard) fails on day one if 2FA is kept. The value is a server-generated QR SVG, not user content | `grep -rn "v-html" resources/js` gives `resources/js/components/TwoFactorSetupModal.vue:174: v-html="qrCodeSvg"`. The file is outside `components/ui`, so R-003's proposed exclusion doesn't cover it | Add to Recommendation 3: keeping 2FA means **one reviewed, allow-listed NFR-03 exception** for this file, with justification "Fortify-generated SVG, no user input". Otherwise remove 2FA. The human decides both together at G1 |
| 3 | Medium | **The `.gitignore` risk is described wrongly, and the step 7 check can't detect it.** `.gitignore` never untracks files that are already tracked. The real harm is that *new* files under `.claude/` (for example the planned `path-guard.sh`, `scopes.json` or Boost skills), and a regenerated `CLAUDE.md`, are silently left out of `git add`. `git status` doesn't show ignored files. Also: `.idea/` **is tracked** in our repo but ignored by the kit (`/.idea`), and the kit ships **no `README.md`**, so only `.gitignore` collides | `git ls-files` lists `.idea/misc.xml`, `.idea/php.xml` and others. `ls -A r001-spike` has no `README.md`. The kit `.gitignore` contains `/.idea`, `/.claude`, `/CLAUDE.md`, `/AGENTS.md`, `/.mcp.json` and `/boost.json` | Step 3: also delete `/.idea` (or decide to untrack `.idea/`), plus `/.mcp.json` and `/boost.json` per R-005. Keep our stricter `.env.*` + `!.env.example`. Step 7: add `git check-ignore -v CLAUDE.md .claude/hooks/x.sh .claude/skills/x/SKILL.md .idea/misc.xml .mcp.json boost.json`, which must print nothing. Correct the risk row (mechanism: new files ignored, not existing files untracked) |
| 4 | Medium | **E7/E24 (spike CI on PHP 8.4) contradict R-003 E22 (upstream CI on 8.3), and the source code backs R-003.** Upstream `main` @ `1959e1a` has `php-version: '8.3'`. Installer 5.32.0 never rewrites `php-version`; it only swaps `./vendor/bin/phpunit` for `./vendor/bin/pest` (line 1025). So the spike's `tests.yml` doesn't match a pure installer run, and the cause is unknown | `curl …/vue-starter-kit/main/.github/workflows/tests.yml \| diff - r001-spike/.github/workflows/tests.yml` gives `< php-version: '8.3'` / `> php-version: '8.4'`. `grep -rn "php-version\|8\.4" ~/.composer/vendor/laravel/installer/src` finds no match. File mtime: 11:07:57, the same as `composer.json` | Correct E7/E24 to say "upstream 8.3; must be raised to 8.4". Re-run the spike checks in T-001 from a clean install. Also raise `composer.json` `"php": "^8.3"` to `"^8.4"`, because Pest 5 requires ^8.4 and the lock already needs it |
| 5 | Medium | **"Dependabot (shipped)" is listed as a mitigation for pre-1.0 package churn, but the shipped config covers only `github-actions`** | `r001-spike/.github/dependabot.yml` has exactly one ecosystem: `package-ecosystem: "github-actions"` | T-002 (a CI change, so it needs approval) adds `composer` and `npm` ecosystems, with groups (`inertiajs/*` + `@inertiajs/*`; `vite-plus` + `vite` alias + Vitest coverage). Until then, correct the risk mitigations |
| 6 | Low | PHPStan level 8 has now been measured on the kit: **10 errors**, all `$request->user()` nullability in kit settings code. Level 9 gives 13 and level 10 gives 16 | `vendor/bin/phpstan analyse --level=8 --memory-limit=1G` → `errors:10` (ProfileController ×5, SecurityController ×4, ProfileUpdateRequest ×1) | Fix these in the T-001 baseline commit, with no baseline file. Because they touch auth/settings code, say in the T-001 approval that these mechanical null-safety edits are covered |
| 7 | Low | rsync also copies the generated `bootstrap/cache/{packages,services}.php` and the Wayfinder output dirs. They are gitignored and harmless, but stale | `find r001-spike/bootstrap/cache -type f` | Add `--exclude 'bootstrap/cache/*.php'` (optional hygiene) |
| 8 | Low | Local PHP has **no coverage driver**, so NFR-07 gates can't run locally (see R-003) | `php -m` shows intl, pdo_pgsql, pgsql and sockets, with no pcov and no xdebug. `memory_limit => 128M` | Note it in the T-001 handoff and in `CLAUDE.md` § Commands |

**Missing option (B):** none material. B3 remains the best choice once findings 1 and 3 are applied.
