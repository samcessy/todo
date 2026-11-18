# ADR-0001: Laravel 13 + official Vue starter kit (Inertia 3) as a monolith

**Status:** Accepted (G2, 2026-11-18) · **Date:** 2026-11-16, revised 2026-11-17 (G2-6, G2-8, G2-9) · **Approver:** project owner

## Context
- D1 needs registration, email verification, password reset and account deletion, with **no custom auth code**. D2 says web only, no public API.
- R-001 checked the current framework and the kit in a spike. Laravel **v13.42.0** is current. It needs PHP ^8.3, gets bug fixes until Q3 2027 and security fixes until 2028-03-17 (R-001 E1–E6). The official `laravel/vue-starter-kit` ships Inertia 3 (`inertiajs/inertia-laravel` 3.7.0, `@inertiajs/vue3` 3.11.0), Vue 3.5.45, TypeScript, Tailwind 4, shadcn-vue (reka-ui), Fortify 1.42 and Wayfinder 0.1.x (R-001 E10–E16).
- R-002 compared an Inertia monolith with a Sanctum API plus a standalone SPA. It recommends the monolith. The red team could not refute that, but found two required changes: the `data` wrapper on resources and the pinning policy (R-002 red-team findings 1 and 2).
- G1 decisions that bind this ADR:
  - **G1-3:** keep 2FA and passkeys, and allow-list the single QR `v-html`.
  - **G1-6:** PHP 8.4 and Node 26.
  - **G1-7:** the owner scaffolds in a plain terminal.
  - **G1-9:** weekly Dependabot, with the Inertia server and client packages grouped into one PR.

## Decision
We build a **single Laravel 13 application** from the **official Vue starter kit**: Inertia 3, Vue 3 Composition API, TypeScript, Tailwind 4, shadcn-vue, Fortify and Wayfinder. It is served from one deploy target, with same-origin session auth and Laravel's built-in CSRF protection.

**Todo pages and writes**
- Todo pages are Inertia page components under `resources/js/pages/todos/`.
- Every write is an Inertia visit to a web route. The route returns a **redirect**, and the adapter turns the redirect into a 303 for PUT, PATCH and DELETE **when the request carries `X-Inertia: true`**, which every Inertia visit does (R-002 §Writes; `Middleware.php:162`/`:179`). Plain HTTP clients get 302. The per-request-type table is contracts.md §1.1.1 (G2-6).
- There are no JSON endpoints and no `routes/api.php` in the MVP.

**Auth**
- We keep **all Fortify features the kit enables**: registration, password reset, email verification, TOTP 2FA (confirm plus password confirm) and passkeys (G1-3, amending D1).
- We write no auth code beyond these configuration edits:
  - Change `fortify.home` from `/dashboard` to `/todos` (US-01.AC3).
  - Remove the placeholder Dashboard page and route (G2-9). The complete file list, including `AppHeader.vue`, `Welcome.vue` and the `'/dashboard'` string fallback in `PasskeyVerify.vue`, is in architecture.md §5.1. Deleting the kit's `DashboardTest` is a one-off owner-approved exception (G2-8).
  - Add the 419 handler and history-encryption wiring (security.md T-05, T-07).
  - The security edits decided at G2: `Password::min(12)->uncompromised()` plus the 72-byte rule (G2-3), the `ThrottleAuthEndpoints` limiters (G2-4), `auth.session` and the 30-day remember-me (A-05), the `auth.user` allow-list (A-04), and the `__Host-` session cookie by environment (G2-1). Each is listed with its test in security.md.

**Runtime**
- PHP **8.4** everywhere. Raise the `composer.json` constraint from `"php": "^8.3"` to `"^8.4"`, because Pest 5 requires ^8.4 (R-003 E1, R-001 red-team finding 4).
- Node **26** locally, in CI and on Laravel Cloud. If T-001 finds that 26 is not an LTS release, use 24 LTS instead (G1-6).

**Inertia version-sync policy (G1-9, R-002 red-team finding 2)**
1. `composer.lock` and `package-lock.json` are authoritative. CI and Cloud install with `composer install` and `npm ci`, never `update`.
2. `inertiajs/inertia-laravel`, `@inertiajs/vue3` and `@inertiajs/vite` are upgraded **together in one grouped Dependabot PR** (weekly). The PR's author reads both sets of release notes, and the PR needs the full CI suite, including browser tests, before G4.
3. Nobody runs `composer update` or `npm update` outside such a PR. These commands are on the agents' `ask` list (R-005 C8).
4. **Inertia facts come from `inertiajs.com/docs/v3/`.** Boost `search-docs` covers only Inertia 1.x and 2.x (R-005 red-team finding 5).

## Alternatives considered
| Alternative | Why it was rejected |
|---|---|
| Sanctum API + standalone Vue SPA (R-002 option B) | It adds CORS, stateful-domain and cookie-domain config, a client router, store and auth guard, and two artefacts. That is about twice the surface for every story, for a mobile client that is a stated non-goal (R-002 §Comparison) |
| `laravel/laravel` + hand-wired Inertia + headless Fortify | The surface is smaller, but we would write the auth UI ourselves, which contradicts D1's "no custom auth code" (R-001 §Strongest argument) |
| Laravel 12.x | Bug-fix support ended on 2026-08-13 (R-001 E4) |
| Strip 2FA and passkeys from the kit | Rejected at G1 (G1-3). Removing them means churn on security-sensitive code, and it breaks Wayfinder references (R-001 E27) |
| `JsonResource::withoutWrapping()` globally | Covered in ADR-0002. We use `->resolve()` instead |

## Consequences
**Positive**
- All of D1 comes with the kit, together with its tests (42 passing in the spike).
- One deploy and one security perimeter. There is no CORS.
- Policies, FormRequests and API Resources can be reused if an `/api/v1` is ever added (D2 trigger).

**Negative**
- The kit is large and moves fast. It includes pre-1.0 packages: Wayfinder 0.1, `@laravel/passkeys` 0.2 and Chisel 0.1. It is installed from `dev-main`, and we own the copied code with no upstream fixes (R-001 §Strongest argument).
- The auth surface is wider than D1, because of 2FA and passkeys. security.md covers it.
- The kit's toolchain (Vite+/Oxlint, no ESLint) means NFR-03 is enforced by a custom check (ADR-0003, G1-4).
- The shared `auth.user` prop is narrowed from the raw `User` model to an explicit key list (ADR-0002, security review A-04), which is a small edit to the kit's `HandleInertiaRequests`, guarded by an exact-key-set test (security.md T-04).

**Follow-up work**
- T-001: scaffold using the R-001 B3 procedure, with the red-team fixes applied:
  - The owner runs `laravel new` in a plain terminal (G1-7).
  - Use `rsync --ignore-existing`, then hand-merge `.gitignore` (remove `/.claude`, `/CLAUDE.md`, `/AGENTS.md`, `/.idea`, `/.mcp.json`, `/boost.json`).
  - Run `git check-ignore` to confirm none of those paths is ignored.
- Commit 1 is the unmodified kit, as a one-time exception to the ~400-line PR limit. Commit 2 holds the baseline edits:
  - PHPStan level 8 (fix the 10 errors);
  - `--memory-limit=1G`;
  - PHP `^8.4`;
  - CI PHP 8.4 and Node 26.
- T-002: Dependabot for `composer`, `npm` and `github-actions`, with the Inertia group.

## Revisit triggers
- A mobile or third-party client is committed (D2). Add Sanctum `/api/v1` next to the web routes (R-002 A′).
- Inertia 4.0 is released, or a 3.x minor whose notes list a breaking change.
- Laravel 14 is released (expected about Q1 2027, R-001 E5). Plan the upgrade within 2 quarters.
- A pre-1.0 kit package (Wayfinder, passkeys) ships a breaking minor that blocks a security update.
- Wayfinder "next" becomes stable and can generate page-prop types (R-002 §TypeScript). That would replace the hand-written `types/todo.ts`.

## Sources
- R-001 (E1–E31, red-team findings 1–8). R-002 (§Comparison, §Writes, §TypeScript, red-team findings 1–6). G1-decision-packet G1-3, G1-6, G1-7, G1-9.
- https://laravel.com/docs/13.x/releases#support-policy (13.x)
- https://laravel.com/docs/13.x/starter-kits (13.x)
- https://inertiajs.com/docs/v3/getting-started/upgrade-guide (v3)
- https://inertiajs.com/docs/v3/the-basics/redirects (v3, 303 conversion). Also confirmed in source (3.7.0): `vendor/inertiajs/inertia-laravel/src/Middleware.php:162` returns early for requests without `X-Inertia`; `:179` converts 302 to 303 for PUT, PATCH, DELETE and QUERY
- https://inertiajs.com/docs/v3/security/authentication (v3, "you don't need to set up CORS")
