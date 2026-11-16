# Security model

**Status:** Draft for G2 · **Date:** 2026-11-16 · **Target:** OWASP ASVS Level 1 (NFR-01), GDPR baseline (D8)
Inputs: R-001 to R-005 with their red-team reviews, G1-1 to G1-11, ADR-0001 to ADR-0006.

## 1. Assets and actors
| Asset | Why it matters |
|---|---|
| Todo content (free text, may contain personal data) | Confidentiality between users (NFR-02, Goal 2) |
| Accounts: email, password hash, 2FA secret, recovery codes, passkeys | Account takeover |
| Session cookie, CSRF token | Impersonation |
| `APP_KEY`, DB credentials, deploy hooks | Full compromise or an unreviewed release |
| Repo `main` and CI gates | The integrity of the process (governance) |

| Actor | Capability |
|---|---|
| Anonymous internet user | Reaches public routes on staging and production (Starter has no basic auth, ADR-0006) |
| Authenticated user | A valid session and their own todos. Might try to reach other users' data |
| AI agent (Claude Code subagents and main thread) | Shell in the repo, bot GitHub identity (G1-1). Could be confused, prompt-injected or over-eager |
| Owner | All rights. Holds the production hook (G1-2) |

## 2. Threat model
"Verified by" names the deterministic control: a test, a CI check or a configuration assertion. "Review" means a G4/G5 checklist item, used only where no automated check is practical.

### Application
| # | Threat | Control | Verified by |
|---|---|---|---|
| T-01 | **IDOR**: reading, updating, completing or deleting another user's todo by changing `{todo}` (NFR-02) | Every `{todo}` route has `can:update,todo` or `can:delete,todo` → `TodoPolicy` (`$user->id === $todo->user_id`). The index query always starts from `$request->user()->todos()`. Note: the 403 (an AC requirement) does reveal that an id exists. We accept this, because ids reveal nothing but existence | `Feature/Todos/AuthorizationTest`: for **every** `{todo}` route, user B gets 403 and the DB row is unchanged (US-04.AC3, US-05.AC3, US-06.AC3). `IndexTest` uses `missing` for other users' ids (US-03.AC1). The `contracts.md` §1.2 route-list diff asserts that the `can:` middleware is present. Mutation testing on `TodoPolicy` |
| T-02 | **Mass assignment**: injecting `user_id`, `completed_at` or `id` | `#[Fillable(['title','description','due_date'])]`. Only `$request->validated()` is passed to Eloquent. `user_id` comes from the relationship. `preventSilentlyDiscardingAttributes` outside production | `StoreTest`: US-02.AC5 (a posted `user_id` is ignored). `UpdateTest`: a posted `user_id` or `completed_at` is ignored |
| T-03 | **XSS** through todo title or description | Vue `{{ }}` escaping. NFR-03: `scripts/check-raw-html.mjs` (built on `@vue/compiler-sfc`) fails on `v-html`, `:innerHTML` and `:outerHTML` in templates, and on `innerHTML`, `outerHTML`, `insertAdjacentHTML` and `document.write` in scripts (G1-4). **The allow-list has exactly one entry:** `TwoFactorSetupModal.vue` / `qrCodeSvg` (a server-generated Fortify SVG with no user input, G1-3), matched on file plus expression. User content is never put into `href`/`src` bindings | CI `npm run check:html`, with fixture tests (a `v-html` fixture fails, `:innerHTML` fails, the allow-listed fixture passes). An allow-list change needs owner approval at G4. Browser test: a title of `<img src=x onerror=alert(1)>` renders as literal text and `assertNoJavaScriptErrors()` passes |
| T-04 | **Over-exposure through Inertia props** (all props are visible to the client, R-002 E22) | `TodoResource` allow-list with `->resolve()` (ADR-0002). `auth.user` stays as the raw model, but `#[Hidden]` covers `password`, `two_factor_secret`, `two_factor_recovery_codes` and `remember_token` (a documented exception) | Strict shape test per page, with no `etc()`, plus `missing('todos.0.user_id')`. `Feature/Security/SharedPropsTest`: `missing('auth.user.password')`, `…two_factor_secret`, `…two_factor_recovery_codes`, `…remember_token` |
| T-05 | **CSRF** | Laravel's `web` group verifies the token. Inertia's client sends `X-XSRF-TOKEN` automatically (R-002 E3). No route is excluded from CSRF. **419 handler:** `bootstrap/app.php` `respond()` turns a 419 into `back()` plus a warning toast (architecture §2a) | Feature test: a POST without a token returns a redirect back with the flash toast, and no todo is created. The `VerifyCsrfToken` exclusion list stays empty (arch or config assertion) |
| T-06 | **Session hijack or fixation** | Fortify regenerates the session on login and invalidates it on logout (`AuthenticatedSessionController::destroy`). The cookie is `HttpOnly` and `SameSite=Lax` (kit `config/session.php`). **`SESSION_SECURE_COOKIE=true` on staging and production.** Session driver is `database`. `SESSION_ENCRYPT` stays false: the payload holds no secrets and sits in our own DB. Revisit if session data grows | Kit auth tests (login, logout). Deployment checklist: `SESSION_SECURE_COOKIE=true`, `APP_DEBUG=false`, `APP_ENV=production` (Review, G5) |
| T-07 | **History leakage**: the back button shows the previous user's todos on a shared device after logout or account deletion | **Inertia history encryption** (`INERTIA_ENCRYPT_HISTORY=true`, config `inertia.history.encrypt`). `Inertia::clearHistory()` on logout (bind a custom Fortify `LogoutResponse` that calls it *after* the session is invalidated, so the flag lands in the new session) and on account deletion (kit `ProfileController@destroy`). **HTTPS caveat:** encryption uses `crypto.subtle`, which exists only in a secure context (R-002 E27, R-002 red-team finding 5). Cloud environments are HTTPS. **Locally, run `herd secure`** so the site is served at `https://todo-app.test`; otherwise keep `INERTIA_ENCRYPT_HISTORY=false` locally. Browser tests run against a local server. Whether the Pest browser server uses `localhost` (which counts as secure) is **UNVERIFIED**, and T-001 checks it | Feature test: the logout response and the follow-up page carry the clear-history flag (`clearHistory: true` in the page object). Feature test with the config on: the page object has `encryptHistory: true`. Browser test: log out, go back, and the todo titles are not visible |
| T-08 | **Brute force, enumeration and abuse** | Fortify login limiter: 5 per minute per email and IP (US-01.AC4). Fortify `two-factor` limiter. Kit password update limited to 6 per minute. Verification resend uses Fortify's throttle. **Todo writes: `throttle:todo-writes`, 120 per minute per user** (Q7). Registration has no limiter in the kit (UNVERIFIED that Fortify adds none by default); staging holds only synthetic data. Revisit if bots appear | Kit `AuthenticationTest` (rate limit), tagged `US-01.AC4`. Feature test: request 121 within a minute returns 429 |
| T-09 | **2FA and passkey weaknesses** (kept at G1-3; `@laravel/passkeys` is 0.2, pre-1.0) | Kit Fortify config: 2FA `confirm` + `confirmPassword`, passkeys `confirmPassword`. Secrets and recovery codes are `#[Hidden]` and encrypted by Fortify. Passkey package updates are grouped and reviewed (ADR-0001 policy) | The kit's `TwoFactorChallengeTest` and `SecurityTest` stay green (never weakened). T-04 test. Security reviewer checklist item at G4 for any auth diff |
| T-10 | **Incomplete erasure** (D8, NFR-11) | FK `ON DELETE CASCADE` (data-model.md). Kit deletion requires the current password | `ProfileUpdateTest`, tagged `US-01.AC5`: after deletion, `todos` count for that user = 0. Runs on SQLite **and** Postgres |
| T-11 | **Error or debug information disclosure** | `APP_DEBUG=false` on staging and production. Inertia DevTools endpoints (`_inertia/devtools/*`, shipped in inertia-laravel 3.7) are allowed only when `app()->environment('local')`, or for a configured gate, which is unset. Recording is local only (`vendor/.../DevTools/Http/Authorize.php`) | `Feature/Security/DevtoolsTest`: in the `testing` environment, `GET /_inertia/devtools/entries` returns 403. Deployment checklist: `APP_DEBUG=false` |
| T-12 | **PII in logs** (D8, NFR-10) | No logging of request bodies, todo content or email addresses. The default exception context includes no payload. Cloud access logs hold client IPs for 1 day, which the privacy notice states. Monitoring redaction is decided with Q3 | Arch test: `App\Http` does not use `Illuminate\Support\Facades\Log` (any future need is an explicit, reviewed exception). Review at G4 |
| T-13 | **Clickjacking and MIME sniffing** | A small `SecurityHeaders` web middleware (about 10 lines, no package): `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`. **CSP is deferred:** Vite dev and the inline Inertia page JSON need nonce work. Revisit before real users arrive | Feature test asserts the three headers on `GET /todos` and `GET /login` |

### Platform and supply chain
| # | Threat | Control | Verified by |
|---|---|---|---|
| T-14 | **Vulnerable dependencies** (NFR-04) | Lock files are authoritative. `composer install` / `npm ci`. The kit's `.npmrc` has `ignore-scripts=true`. Weekly Dependabot for composer, npm and actions, with the Inertia group (G1-9). The kit came from `dev-main`, so `composer show --direct` and `npm ls --depth=0` are recorded at T-001 | CI `composer audit` and `npm audit --audit-level=high` on every PR and weekly |
| T-15 | **Secrets in the repo** | `.env*` is gitignored (except `.env.example`, which has no values). Cloud environment variables and the Secrets Manager (write once, read never) hold production secrets. No production secret ever exists locally | `git check-ignore .env` at T-001. Secret scanning in GitHub (free for public repos; plan-dependent for private, UNVERIFIED). Review |

### Agent-related (governance)
| # | Threat | Control | Verified by |
|---|---|---|---|
| T-16 | **Agent acts with the owner's authority** (approves or merges its own PR, edits branch protection, triggers workflows), R-004 red-team finding 2 | **A bot GitHub account** with write access and no admin, for every agent push and PR (G1-1). The owner's `gh` session is logged out of agent terminals. `.claude/settings.json` denies `gh pr merge*`, `gh secret*`, `gh repo edit*` and `gh api *pending_deployments*`, and asks for `gh api *` and `gh workflow run*`. Branch protection on `main` requires a PR, 1 approval and no bypass (subject to Q2) | `gh auth status` in an agent session shows the bot (T-002 checklist). The GitHub PR author is the bot and the approver is the owner (G4 evidence) |
| T-17 | **Unreviewed production deploy** | The production deploy hook is **owner-held**: password manager only, never in GitHub, CI or any agent environment (G1-2). Push-to-deploy is off. Agents have no Cloud account or token | Deployment checklist: `uses_push_to_deploy=false` on both environments. `grep -ri "laravel.cloud/.*hook" .github docs` finds nothing (a CI check in T-002). The G5 gate-log row is the record |
| T-18 | **Staging hook misuse**: a repository secret readable by workflows on any branch the bot can push | Impact is limited: the hook deploys only commits already on `main`, and only to staging (synthetic data). Changes to `.github/**` need owner approval, and the CI scope check fails a PR that touches `.github/**` outside a devops task. Refresh the hook if a leak is suspected | CI `scope` job (G1-11). G4 diff review of any `.github/**` change |
| T-19 | **Boost MCP tools bypass controls**: Tinker runs arbitrary PHP (it could read `.env` or the DB), and `record-rule` writes `.ai/rules/*` outside an agent's scope | `config/boost.php`: Tinker tool **disabled** (the default; never set `tinker_tool_enabled`), and `rules.enabled => false` (G1-10). Settings deny `php artisan tinker*`, `config:show*` and `env*` (R-005 C5) | Config assertion test: `config('boost.tinker_tool_enabled')` is falsy and `config('boost.rules.enabled')` is false (runs only where Boost is installed, i.e. dev). Re-check `src/Mcp/Tools/` on every Boost upgrade (review) |
| T-20 | **Agent edits outside its task scope** (for example an engineer edits `tests/`, or edits through Bash to dodge the hook) | Path-guard PreToolUse hook (best effort, R-005 B2). **Authoritative: the CI `scope` job** compares the PR diff with the task's `scope.paths` (G1-11) | CI `scope` job is a required check |
| T-21 | **Agent reads `.env` or secrets** | Deny rules with `**/.env*` anchors (R-005 C4/C5). No production secrets exist locally. Planned sandbox `denyRead` | Review of `.claude/settings.json` in its hardening PR (G1-11) |
| T-22 | **Prompt injection through repo content or dependencies** (for example a malicious README in `vendor/`) | Agents act only from task files. Dependency changes need approval. Agents never hold deploy or merge capability (T-16, T-17), so the worst outcome is a bad PR, which G4 catches | G4 review plus the deterministic gates |

## 3. ASVS L1 coverage notes
- **Covered by the kit and Fortify:** V2 authentication (password policy `Password::defaults()` in production: min 12, mixed case, symbols, `uncompromised()`), V3 session management, V4 access control (plus T-01).
- **Covered here:** V5 validation (contracts §2), output encoding (T-03), V7 logging without PII (T-12), V14 config (T-11, T-13, T-14).
- **Gaps accepted for the MVP:** no CSP (T-13), no registration rate limit (T-08), IP addresses in 1-day Cloud logs (T-12). Each has a revisit trigger above.

## 4. Security tests the QA agent must create
These are summarised for `04-quality/test-strategy.md`.
- `AuthorizationTest` covers every `{todo}` route × {guest → login, other user → 403 and unchanged}.
- `SharedPropsTest`, `DevtoolsTest`, the security-headers test, the CSRF/419 test, the history-flag test and the rate-limit test.
- `check-raw-html` fixture tests.
- Browser: the XSS literal-render test and the back-after-logout test.
