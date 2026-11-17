# G2 Decision Packet

**Status:** Decisions approved 2026-11-17 (all as recommended; G2-10 = public repo, github.com/samcessy/todo). Gate recorded after the blocker re-check · **Prepared:** 2026-11-16 by the orchestrator
**Inputs:** ADR-0001 to 0006 (Proposed), `docs/02-architecture/*`, `reviews/G2-security-review.md`, `docs/04-quality/G2-testability-review.md`, `docs/04-quality/traceability-matrix.md`

## Review outcome

| Review | Verdict | Must fix before G2 |
|---|---|---|
| Security (OWASP ASVS L1) | Approve with changes | 5 BLOCKERs, 19 advisories |
| QA testability | Testable with changes | 3 High findings, 19 others. 37 of 37 AC IDs mapped to planned tests |

## Orchestrator verification (kit source, 2026-11-16)

| Claim | Result |
|---|---|
| Inertia converts 302 to 303 only for requests with the `X-Inertia` header | ✅ `inertia-laravel/src/Middleware.php:162` returns early, before the 303 conversion at `:179` |
| Fortify does not rate-limit `POST /register`, `/forgot-password`, `/reset-password` | ✅ `fortify/routes/routes.php:64-83`: no throttle. Only login, 2FA, passkeys, and verification have one |
| The kit's production password rule uses composition rules | ✅ `app/Providers/AppServiceProvider.php:40-47` (`mixedCase`, `letters`, `numbers`, `symbols`) |

## Decisions required

### Security blockers

| # | Finding | Recommendation |
|---|---|---|
| G2-1 | **B-01:** no HSTS, no HTTPS enforcement, no `__Host-` cookie prefix | Adopt all three in production. A global `SecurityHeaders` middleware with HSTS (1 year, no preload), `URL::forceHttps()` in production, and the session cookie named `__Host-todo_session` in production. Add a test for each |
| G2-2 | **B-02:** ASVS version not pinned | **Pin OWASP ASVS 5.0.0 Level 1** and remap `security.md` §3. Under 5.0, CSP is L2, so deferring a full CSP is allowed. Ship a basic CSP that needs no nonces anyway (A-11) |
| G2-3 | **B-03:** password composition rules break ASVS L1 | Change the production rule to `Password::min(12)->uncompromised()`. This is a small, reviewed edit to kit code |
| G2-4 | **B-04:** no throttle on public endpoints that send email | Add app-level rate limiters (per IP and per email) on register, forgot-password, and reset-password, each with a test. Required before G5 |
| G2-5 | **B-05:** a workflow edit could leak the staging hook or misuse `GITHUB_TOKEN` | Give the bot a fine-grained token with **no Workflows permission**, so it cannot push `.github/workflows` changes. Make the repo's default `GITHUB_TOKEN` read-only. Every workflow declares minimal `permissions`. Never use `pull_request_target` |

### Testability (High)

| # | Finding | Recommendation |
|---|---|---|
| G2-6 | **F-01:** 303 only happens with the `X-Inertia` header | Fix `contracts.md` §1.1. State the status code per request type. Write tests send `X-Inertia: true` |
| G2-7 | **F-02:** no UI contract (labels, texts, accessible names, focus targets) | Add a "UI contract" table to `contracts.md` §6.1, including focus after delete and filter changes (F-10) |
| G2-8 | **F-03:** removing the Dashboard means deleting the kit's `DashboardTest` | **Grant a one-off, owner-approved exception** to the "never delete tests" rule. It covers deleting `DashboardTest` and retargeting 6 kit assertions to `todos.index`, in a dedicated task. Complete the file list in `architecture.md` §5 (`AppHeader.vue`, `Welcome.vue`, `PasskeyVerify.vue`) |

### Open questions from the architect

| # | Question | Recommendation |
|---|---|---|
| G2-9 | Q1: remove the kit's Dashboard? | **Yes.** `/todos` becomes home (covered by the exception in G2-8) |
| G2-10 | Q2: GitHub plan and visibility | **Owner's choice, see below.** Recommended: a **public repo**. It's a reference implementation, and public repos get branch protection and Environment required reviewers free. No secrets live in the repo. Alternative: private repo on GitHub Pro (about $4/month; T-002 confirms which protections that unlocks) |
| G2-11 | Q3: error tracking | Decide alongside R-006, before G5 |
| G2-12 | Q4: a cap on todos per user? | No cap. Rely on rate limiting |
| G2-13 | Q5: overdue calculated in UTC | **Calculate overdue in the browser using the user's local date** (a pure `isOverdue(dueDate, today)` function, unit-tested with `vp test`). UTC would show it hours late for users outside UTC, including the owner's region (UTC+3) |
| G2-14 | Q6: browser-test dependencies (`pestphp/pest-plugin-browser`, `playwright`) | Approve them, by accepting ADR-0003 |
| G2-15 | Q7: write rate limit of 120/min per user | Approve |

### Advisories

| # | Recommendation |
|---|---|
| G2-16 | The architect folds all 19 security advisories and 19 QA findings into the docs. Any it rejects is listed with a reason. Notable ones: the CSRF test as planned can't fail (A-02/F-06), so replace it with a check of the middleware configuration. The DevTools test should expect 404. `auth.user` gets an exact-key-set test. A password change ends other sessions. The staging seeder must not create `password` accounts. The exact query budget is stated. Browser tests run as their own CI job |

## After approval

1. The architect revises the Phase 2 docs (G2-1 to G2-16).
2. The security and QA reviewers **re-check only the blockers and High findings**.
3. If both pass, the orchestrator records G2. ADRs move to Accepted (approver: owner), and Phase 3 (task breakdown) starts.

## Owner confirmations (2026-11-17)

| Item | Decision |
|---|---|
| Bot token type (G2-5 substitution) | **Confirmed:** classic PAT with only `public_repo`, no `workflow` scope, 90-day expiry, no SSH keys on the bot account |
| N-01 bcrypt truncation | **Confirmed:** passwords are limited to 72 bytes (ASVS 5.0.0 6.2.8) |
| W-1 cookie-prefix waiver | **Confirmed:** ASVS 3.3.1 waived for `XSRF-TOKEN`, `remember_web_*`, `appearance`, `sidebar_state` |

If the re-check contradicts any of these, it comes back to the owner before G2 is recorded.
