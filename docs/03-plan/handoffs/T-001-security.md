# Security review: T-001 (Scaffold import and Boost)

**Reviewer:** security-reviewer agent · **Date:** 2026-11-18 · **Branch:** `task/T-001-scaffold` (PR #3) · **Diff base:** `595d95d..task/T-001-scaffold` (298 files, +28441/-3)
**Against:** `.claude/agents/security-reviewer.md` checklist, `docs/02-architecture/security.md` (T-14, T-15, T-18, T-19, T-21, T-22), ADR-0006 workflow posture.

**Verdict: Approve with advisories.** No blocker. Nothing in the import is unsafe to merge; the advisories below are hardening items for later tasks and one owner decision (picomatch).

## 1. Scope reviewed

| Commit | Content | Depth |
|---|---|---|
| `90ad2fb` | Unmodified Laravel Vue starter-kit installer output (upstream) | Threat-model items only: CI, Dependabot, `.npmrc`, raw HTML, routes, secrets, logging |
| `3493571` | `.gitignore` merge | Full |
| `42b60a0` | `laravel/boost` v2.10.4 (dev), `config/boost.php`, CLAUDE.md Boost block | Full, including the Boost source under `vendor/laravel/boost/src` |
| `f700ad6`, `d121eae` | Docs | Checked for secrets and hook URLs only |

## 2. Commands run and results

```
git log --oneline 595d95d..task/T-001-scaffold       → 5 commits as described above
git diff 595d95d..task/T-001-scaffold -- config/boost.php .gitignore composer.json  → reviewed in full

# .gitignore
git check-ignore -v .env .env.local .env.testing .env.production auth.json \
  database/database.sqlite .claude/settings.local.json .mcp.json boost.json storage/oauth-private.key
                                                      → all ignored (.gitignore:15/46/24/39/41/48/8, database/.gitignore:1 *.sqlite*)
git check-ignore .env.example                         → not ignored; tracked
git ls-files | grep -iE '\.env|sqlite|auth\.json|\.key$|\.pem$|settings\.local|\.mcp\.json|boost\.json'
                                                      → only .env.example
.env.example secret-named keys                        → APP_KEY=, AWS_*= empty; REDIS/MAIL_PASSWORD=null; no values

# Secrets in tracked files (excluding lock files)
git grep -nIE "base64:[A-Za-z0-9+/]{20,}|gh[pousr]_…|github_pat_|BEGIN … PRIVATE KEY|AKIA…|xox[baprs]-|sk-…|hooks\.slack|laravel\.cloud/.*hook|/deploy/hooks?/|api_key=…"
                                                      → nothing

# config/boost.php loads without Boost installed (production, composer install --no-dev + php artisan optimize)
php (stub env(), spl_autoload_register that prints on any autoload) require config/boost.php
                                                      → no autoload triggered; exclude = ["Laravel\Boost\Mcp\Tools\Tinker","…\RecordRule"],
                                                        rules.enabled=false, tinker_tool_enabled=false
                                                        (`use` + `::class` are compile-time strings; config:cache is safe without Boost)

# Boost route registration by environment (no .env present; env passed inline)
php artisan route:list --path=_boost                  → no routes (defaults to production, debug off)
APP_ENV=local php artisan route:list --path=_boost    → POST _boost/browser-logs  boost.browser-logs
APP_ENV=staging APP_DEBUG=true php artisan route:list --path=_boost → POST _boost/browser-logs (registered)
php artisan route:list --json                         → 44 routes; unthrottled POSTs: register, forgot-password, reset-password,
                                                        user/confirm-password, DELETE settings/profile (known, T-021);
                                                        PUT storage/{path} and GET storage/{path} with no middleware (A-7)

# Raw HTML and logging
git grep -nE 'v-html|innerHTML|\{!!' -- resources app  → resources/js/components/TwoFactorSetupModal.vue:174 v-html="qrCodeSvg" (ADR-0003 allow-list) only
git grep -nE 'Log::|logger\(' -- app routes           → nothing

# Dependencies
composer audit                                        → No security vulnerability advisories found (exit 0)
composer audit --locked --no-dev                      → No security vulnerability advisories found
composer.lock                                         → laravel/boost, laravel/mcp, laravel/roster only in packages-dev
npm audit --audit-level=high                          → exit 1: 17 high, 0 critical
npm audit --json                                      → 17 high, every one via a single advisory source 1247710 =
                                                        GHSA-fc5q-39xc-vjpf (picomatch <=4.0.7, ReDoS via repeated extglobs, CVSS 7.5)
npm audit --omit=dev --json                           → 9 high (laravel-vite-plugin, @inertiajs/vite, tailwindcss chain are in "dependencies")
npm ls picomatch --all                                → picomatch@4.0.7 via tinyglobby (@inertiajs/vite), fdir, vitest (vite-plus),
                                                        @vue/language-core (vite-plus-core); picomatch@2.3.2 via vite-plugin-full-reload (laravel-vite-plugin)
npm view picomatch dist-tags                          → latest 4.0.7: no patched release exists on any line
grep -rl -iE 'picomatch|extglob' public/build         → nothing (assets, manifest.json, fonts-manifest.json only)
grep extglob patterns in vite.config.ts, resources/css/app.css → none (plain globs only)
```

Boost source read: `src/BoostServiceProvider.php`, `src/Mcp/Boost.php`, `src/Mcp/ToolRegistry.php`, `src/Mcp/ToolExecutor.php`, `src/Console/ExecuteToolCommand.php`, `src/Mcp/Tools/Tinker.php`, `src/Mcp/Tools/RecordRule.php`, `src/Mcp/Tools/DatabaseQuery.php`. Also read: `.github/workflows/tests.yml`, `.github/dependabot.yml`, `.npmrc`, `composer.json` scripts, `package.json`, `vite.config.ts`, `.claude/settings.json` deny list and hook matcher, local untracked `.mcp.json` / `boost.json` (no secrets, `php artisan boost:mcp` over stdio).

## 3. Verified as correct (no finding)

- **Tinker and record-rule are unreachable over MCP.** Two independent guards: `shouldRegister()` reads `boost.tinker_tool_enabled` (`Tinker.php:29`) and `boost.rules.enabled` (`RecordRule.php:32`), both hard-coded `false` (`config/boost.php:34,48`, not env-overridable); and `filterPrimitives()` drops both classes from the server's tool list via `boost.mcp.tools.exclude` (`Mcp/Boost.php:143-151`, `config/boost.php:52-55`). `ToolExecutor::execute()` also refuses them (`ToolExecutor.php:19`, through `ToolRegistry::isToolAllowed`). The path-guard hook additionally denies `mcp__laravel-boost__*tinker*|*record-rule*` (`.claude/hooks/path-guard.sh:84-85`).
- **`config/boost.php` does not break production.** The `use` imports and `::class` constants never autoload (verified above), so `composer install --no-dev && php artisan optimize` (ADR-0006 build) caches the file with harmless values; `package:discover` does not register `BoostServiceProvider` without the package.
- **`.gitignore`:** `.env`, `.env.*`, `auth.json`, `*.sqlite*`, `/storage/*.key`, `.claude/settings.local.json`, `.mcp.json`, `boost.json` ignored; `.env.example` tracked with no values (T-15).
- **`.github/workflows/tests.yml` meets T-18 (3):** top-level `permissions: contents: read` (line 9-10); triggers only `push` to `main` and `pull_request` (3-7); all three actions pinned to 40-hex SHAs (18, 23, 30); checkout `persist-credentials: false` (20); no `secrets.*`. `composer setup` in CI copies `.env.example` to an ephemeral `.env`, generates a throwaway key and migrates SQLite: no secret involved.
- **`.npmrc`:** `ignore-scripts=true` (T-14).
- **Boost web surface is local-only on Cloud today:** `shouldRun()` requires `APP_ENV=local` or `app.debug === true` (`BoostServiceProvider.php:200-216`), and Cloud builds with `--no-dev` and `APP_DEBUG=false` (ADR-0006 lines 31, 37, 80). The MCP server is `Mcp::local` (stdio), not an HTTP route.
- **Raw HTML:** only the allow-listed 2FA QR `v-html`.
- No committed secret, key, token, or deploy-hook URL.

## 4. Findings

| ID | Severity | Location | Evidence | Recommended fix | Fix in |
|---|---|---|---|---|---|
| A-1 | ADVISORY | `config/boost.php:119`; `vendor/laravel/boost/src/BoostServiceProvider.php:73-78, 105-147`; `CLAUDE.md:111` | With `browser_logs_watcher` on (default `true`), Boost registers `POST /_boost/browser-logs` **without auth and with CSRF disabled** (`->withoutMiddleware(VerifyCsrfToken::class)`) and injects a console-capturing script into every `web` response. The endpoint writes request content to `storage/logs/browser.log`, and the `browser-logs` MCP tool feeds that file to agents (CLAUDE.md tells agents to use it). Any website the developer visits can `sendBeacon` a `text/plain` POST to the local Herd host, which needs no CORS preflight, and plant text in agent context: a cross-site prompt-injection path (T-22). Console capture can also copy todo content or emails into a log file (T-12, local only). | Set `'browser_logs_watcher' => false` (hard-coded, as with `rules.enabled`) in `config/boost.php`, and add it to the T-19 config assertion test. Drop or annotate the `browser-logs` line in the project note of CLAUDE.md. | T-023 (owns T-19) |
| A-2 | ADVISORY | `vendor/laravel/boost/src/BoostServiceProvider.php:211` | Boost turns on in **any** environment where `app.debug === true`, not only `local`. Verified: `APP_ENV=staging APP_DEBUG=true php artisan route:list --path=_boost` lists `POST _boost/browser-logs`. Staging and production are safe only while both hold: `--no-dev` in the build command and `APP_DEBUG=false` (ADR-0006). If either slips, the anonymous log-write endpoint from A-1 and the script injection go public. | Add to the staging smoke: `curl -s -o /dev/null -w '%{http_code}' -X POST https://<staging>/_boost/browser-logs` → `404` or `405`; the route-contract check fails on any `_boost/*` route. A-1's fix also removes the route. | T-025 |
| A-3 | ADVISORY | `vendor/laravel/boost/src/Console/ExecuteToolCommand.php:16-32`; `src/Mcp/ToolRegistry.php:28-35`; `.claude/settings.json:50` | The hidden command `php artisan boost:execute-tool <class> <base64-json>` runs a tool directly. Its only guard is `ToolRegistry::isToolAllowed()`, which checks `boost.mcp.tools.exclude` and **ignores** `shouldRegister()`. So the exclude list, which the handoff calls a "second guard", is the **only** guard on this path. Without it, an agent could run arbitrary PHP through Bash with `boost:execute-tool 'Laravel\Boost\Mcp\Tools\Tinker' …`, bypassing the `Bash(php artisan tinker*)` deny. It is blocked today because the exclude list is set. | The T-19 test must assert `ToolRegistry::isToolAllowed(Tinker::class) === false` and the same for `RecordRule::class`, in addition to the two flags. Owner adds `Bash(php artisan boost:execute-tool*)` to the deny list in `.claude/settings.json` (`.claude/*` is owner-only). | T-023 (test); owner (settings) |
| A-4 | ADVISORY | `CLAUDE.md:131`, `CLAUDE.md:99` (Boost block) | Boost's guideline tells agents to "Read configuration values … `php artisan config:show`", which contradicts security.md T-19 (settings deny `config:show*`). The project note at line 47-50 overrides only `## Tinker`. Line 99 ("only create documentation files if explicitly requested") conflicts with rules 7 and 8 (update docs, write handoffs). Agents get conflicting instructions; the deny rule still holds. | Extend the project note: "`config:show` is denied: read `config/*.php` instead. Rules 7 and 8 take precedence over the Boost block." Keep the note outside `<laravel-boost-guidelines>` so `boost:update` does not overwrite it. | T-002 (already edits the CLAUDE.md command list) |
| A-5 | ADVISORY | `.github/workflows/tests.yml:35`; `composer.json` `scripts.setup` | Kit CI runs `composer setup`, which runs `npm install`, not `npm ci`. T-14 says lock files are authoritative, and `npm install` can re-resolve and rewrite `package-lock.json` in CI. Low risk: lockfile present, `ignore-scripts=true`, no secrets in the job. | T-002's replacement workflow uses `composer install` and `npm ci` directly, not `composer setup`. | T-002 |
| A-6 | ADVISORY | `config/filesystems.php:36` (`'serve' => true`); `vendor/laravel/framework/src/Illuminate/Filesystem/ReceiveFile.php:28-47` | The kit's local disk registers `GET storage/{path}` and `PUT storage/{path}` with **no middleware**. Both are guarded only by a signed URL (`hasValidRelativeSignature()` plus `upload=1` for PUT). The app does not use them, they are not in security.md, and they widen the route surface: a leaked `APP_KEY` would allow arbitrary writes to `storage/app/private`. | Set `'serve' => false` on the local disk (or record the routes as accepted in security.md), and have the route-contract check list them. | T-019 (decide), T-025 (route contract) |
| A-7 | ADVISORY (owner decision) | `package-lock.json`; `package.json` `dependencies` | `npm audit --audit-level=high` fails: 17 high, all from **one** advisory, GHSA-fc5q-39xc-vjpf (picomatch ≤4.0.7, ReDoS through repeated extglobs, CVSS 7.5). No patched release exists (latest is 4.0.7). The `--omit=dev` audit also fails (9 high) because the kit lists `laravel-vite-plugin` and `@inertiajs/vite` under `dependencies`. See section 5. | Owner chooses option A or B (section 5). Independently of that: move build-only packages (`laravel-vite-plugin`, `@inertiajs/vite`, `tailwindcss`, `tw-animate-css`) to `devDependencies` so the `--omit=dev` audit reflects what reaches users. That is a dependency change and needs approval. | Owner (decision); T-002 (audit job, dependency move) |

### Known and already tracked (no new finding)
- No throttle on `register`, `forgot-password`, `reset-password`, `user/confirm-password`, `DELETE settings/profile`: tracked in **T-021** (security.md T-08 `auth-public` / `auth-confirm`). Note that security.md T-10 says profile deletion "is throttled (T-08)"; that holds only once T-021 lands.
- Kit password rule (`Password::defaults`) still in use: tracked in **T-020** (T-23).
- Dependabot covers only `github-actions`; CI on Node 22: tracked in **T-002**.
- `.env.example` owner lines (`BCRYPT_LIMIT`, `INERTIA_ENCRYPT_HISTORY`, `INERTIA_SSR_ENABLED`) still open: owner step in T-001 itself, tracked in the orchestrator handoff.
- Fortify line references in the G2 docs need re-checking for 1.43.0: tracked in **T-005**.

## 5. picomatch GHSA-fc5q-39xc-vjpf: independent assessment

**Root cause confirmed.** One advisory, two installed versions: `picomatch@4.0.7`, reached through `tinyglobby` (`@inertiajs/vite`), `fdir`, `vitest` (`vite-plus`) and `@vue/language-core` (`vite-plus-core`, `vue-tsc`); and `picomatch@2.3.2`, reached through `vite-plugin-full-reload` (`laravel-vite-plugin`). The other 15 "vulnerable" packages are parents only. npm's suggested fixes (`vite-plus@0.1.24`, `laravel-vite-plugin` downgrade) are major downgrades and not usable.

**Exposure for this project:**
- **Production runtime:** none. Production is PHP. Node runs only in the Cloud build (`npm ci && npm run build`), and SSR is off (`INERTIA_SSR_ENABLED=false`, ADR-0006), so no Node process serves requests.
- **Browser bundle:** none. `public/build` contains no picomatch or extglob code. The "dependencies" placement is a packaging artefact: these packages are Vite plugins that run at build time.
- **Build, CI and dev:** the ReDoS needs a pattern with repeated extglobs. All patterns here come from the repo (`vite.config.ts`, `@source` in `app.css`, plugin defaults) and contain no extglobs. Vite's dev-server `fs.deny` matching uses default patterns, also without extglobs. The worst case is a contributor who commits a crafted pattern, which is reviewable and would only slow their own CI job.

**Severity for this project: Low**, despite the generic CVSS 7.5 rating.

**On the exception (owner decides):** I consider **option A acceptable**, under these conditions:
1. The allow-list is keyed on the **advisory ID** (`GHSA-fc5q-39xc-vjpf`), not on a package name. Any other high or critical advisory, including a new one on picomatch, still fails.
2. Each entry records an expiry of at most 30 days, the reason (this section) and the approver. An expired entry fails the job, so renewal is a fresh owner decision.
3. The allow-list file and the audit script are CI-gate files and stay owner-only (security.md T-18 (8)). They go in `scripts/*allowlist*.json`, which is already excluded from engineer scopes.
4. Exception **E-2** in `governance.md` links this report. The `composer audit` side stays strict.

Option B would leave the required `audit` check (T-16) red on every PR, which trains everyone to ignore it, or would force it out of the required set. I consider that worse than a narrow, expiring exception. With either option, the T-14 escalation clock was met: the advisory was escalated to the owner on 2026-11-18 in the T-001 orchestrator handoff.

## 6. Checklist (security-reviewer.md)

| Item | Result |
|---|---|
| Authorisation / `{todo}` Policy | N/A (no todo routes yet) |
| Mass assignment | N/A (no new models) |
| Validation | Kit FormRequests only, unchanged upstream |
| XSS | Pass: only the allow-listed QR `v-html` |
| Agent/CI surface | Pass, with advisories A-1, A-3, A-4 |
| CSRF/session | Kit: pass. Boost adds a CSRF-exempt local route (A-1, A-2) |
| Secrets | Pass |
| Logging (PII) | Pass in `app/`. Boost browser log, local only (A-1) |
| Dependencies | composer: pass. npm: 1 high advisory without a fix (A-7, owner decision) |
| Tests (guest + cross-user) | N/A (no new endpoints of ours) |
