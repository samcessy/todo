# R-002: Inertia monolith vs Sanctum API + standalone Vue SPA

**Status:** Draft · **Author:** architect agent · **Red team:** (pending) · **Date:** 2026-11-16

## Question
For the MVP, should the todo app be a Laravel + Inertia + Vue monolith built from the official Vue starter kit, or a Laravel JSON API (authenticated with Sanctum) plus a standalone Vue SPA?

## Constraints
- **D2** (`00-product/assumptions.md`): web only, no API in the MVP, Inertia monolith pending R-002 and ADR-0001. Revisit trigger: a mobile or third-party client is committed to. The planned answer is then a Sanctum `/api/v1` layer next to the web routes.
- **D1:** email and password auth with verification, reset and account deletion. The starter kit should provide it, so we write no custom auth code.
- **D3:** Laravel Cloud hosting, one deploy target.
- **D6:** solo project, one approver. Fewer moving parts means less to review.
- NFR-01 (ASVS L1), NFR-02 (IDOR tests), NFR-03 (no `v-html`), NFR-07 (coverage at least 85%, at least 90% on `app/Http` and `app/Policies`), NFR-08 (vue-tsc clean).
- "Public API" and "mobile app" are explicit non-goals (`vision.md`, `requirements.md` → Out of scope).
- SEO does not matter. Every todo page sits behind `auth` + `verified`. The only public pages are the auth screens and a welcome page, so neither option needs SSR for SEO.

## Options
| Option | Summary |
|---|---|
| **A. Inertia monolith (official Vue starter kit)** | One Laravel app. Controllers return `Inertia::render()` with props. Vue 3 + TS page components live in `resources/js/pages`. Session cookie auth through Fortify, with built-in CSRF. One repo, one build, one deploy. |
| **B. Sanctum API + standalone Vue SPA** | Laravel serves JSON under `/api/*` (`php artisan install:api`). A separate Vue app (Vite + Vue Router + state and fetch layer) uses Sanctum *SPA* (cookie) auth. That requires stateful-domain config, CORS with credentials, `/sanctum/csrf-cookie`, and a shared top-level domain. Two build artefacts, and possibly two repos and deploys. |
| A′. (variant) Inertia now, Sanctum `/api/v1` later | Option A, plus the D2 escape hatch: add `install:api` and token-authenticated `/api/v1` routes only when a mobile client is committed. Assessed under "future mobile path". |

## Evaluation criteria
Complexity, security surface (auth, session, CSRF), testability, deploy simplicity, future flexibility (mobile), maturity and churn, team familiarity, relevant framework features.

### Comparison
| Criterion | A. Inertia monolith | B. Sanctum API + SPA |
|---|---|---|
| **Complexity** | Classic controllers plus page components. No client router, no client store, no API client, no DTO layer. Inertia's stated purpose is to avoid "build[ing] an API … authentication and authorization for that API … client-side state management … a new Git repository … a more complicated deployment" [E1]. The starter kit ships auth pages, layouts, flash toasts and typed shared props already wired up [E8–E12]. | We write and maintain a JSON API, API Resources, a Vue Router config, auth guards on the client, a fetch/XSRF layer, 401/419 handling ("redirect the user to your SPA's login page") [E17], and our own auth screens (the starter kit is Inertia-based, so its pages do not apply). There is roughly twice the surface for each story. |
| **Auth, session and CSRF surface** | Same-origin session cookie. No CORS: Inertia's auth docs say "you don't need to set up CORS" [E2]. CSRF is automatic. Laravel sets `XSRF-TOKEN` and Inertia's built-in HTTP client sends `X-XSRF-TOKEN` on every request [E3]. No tokens are stored in the browser. Fortify supplies rate-limited login [E9]. | Sanctum SPA mode is still cookie and session based. It is not worse in principle [E15], but it adds configuration that can be got wrong: `stateful` domains, `statefulApi()`, `config/cors.php` with `supports_credentials`, the session cookie `domain`, the `/sanctum/csrf-cookie` bootstrap, and a shared top-level domain requirement [E16]. Each item is an ASVS review point. Misconfigured CORS with credentials is a classic vulnerability class (general knowledge, **UNVERIFIED** against an official source). |
| **Data exposure** | Every prop is serialised into the HTML or JSON page object and is visible client-side. The official warning: "all data returned from the controllers will be visible client-side" [E21][E22]. Mitigation: pass API Resources or explicit arrays, never raw models. The starter kit shares `auth.user` as the full `User` model [E10], so `$hidden` must stay correct. | Same risk through JSON responses. API Resources are the normal mitigation. Neutral. |
| **Testability (Pest)** | Endpoint tests use `assertInertia()` for component name, props, counts, `missing()` (useful for "never leak other users' todos"), `reloadOnly()` for partial reloads, `loadDeferredProps()`, `hasFlash()`, and `assertInertiaFlash()` on redirects [E13]. Validation is the plain Laravel `assertSessionHasErrors` on a redirect (standard Laravel behaviour, since errors are flashed to the session [E5]). IDOR tests (NFR-02) are ordinary `actingAs()->patch()->assertForbidden()`. Inertia tests do **not** execute Vue. JS behaviour needs Vitest or Pest browser tests [E13]. | JSON API tests (`getJson`, `assertJsonPath`, `Sanctum::actingAs`) [E18] are equally strong for the backend. **Plus** the SPA needs its own test layer for routing, guards and the API client, with contract drift between the two sides. More tests to reach the same AC coverage. |
| **Deploy simplicity** | One Laravel app with a Vite build. Asset versioning makes clients hard-reload on a version mismatch (409 + `X-Inertia-Location`) [E20]. One Laravel Cloud environment. | Either serve the SPA from Laravel (then most of B's benefits disappear) or deploy two artefacts with a CORS and cookie-domain setup on a shared TLD [E16]. Release ordering between API and SPA becomes a concern. |
| **SEO** | Not relevant (auth-only app). SSR is available but not needed [E9]. | Not relevant. |
| **Future mobile client** | **A′ path:** `php artisan install:api`, add `HasApiTokens`, a `/sanctum/token` endpoint, and `auth:sanctum` routes under `/api/v1` [E14][E19]. Policies, FormRequests and models are reused as they are. If we pass **API Resources as Inertia props** now (supported: "Responsable objects like API resources … are resolved" [E22]), the same resources serialise `/api/v1`. The added cost is a thin set of JSON controllers plus tests for each endpoint. Estimate: about 1 controller and 1 test file per resource, **UNVERIFIED** (no spike). The `auth:sanctum` guard also accepts session cookies on web routes [E19]. | The API already exists. A mobile client would mostly need token issuance [E19]. This is B's main advantage. |
| **Maturity and churn** | Inertia v3 is the current major and is what the starter kit uses [E8][E9]. It moves fast: `@inertiajs/vue3` went from 3.4.0 (2026-06-11) to 3.11.0 (2026-11-02), and the v3 upgrade guide lists many breaking changes (Axios removed, events renamed, `Inertia::lazy()` removed, testing traits removed) [E6]. v2 remains on the `legacy` dist-tag (2.3.29) [V1]. | Sanctum v4.4.0 and Vue Router are both very stable. But B is a bespoke assembly with no official starter kit, so we would own every integration decision. |
| **Team familiarity** | **UNVERIFIED.** No evidence about the owner's experience is recorded in `docs/00-product`. The `CLAUDE.md` stack already assumes the Vue starter kit, and Laravel Boost (D7) ships guidelines for it. | **UNVERIFIED.** |

### Inertia v3 features relevant to this app
| Feature | Relevance to stories | Source |
|---|---|---|
| `useForm` (typed generic, `processing`, `errors`, `reset()`, `recentlySuccessful`) | US-02 and US-04 forms. Errors are scoped to the form object, so no error bags are needed even with several forms per page | [E4][E5][E7] |
| `<Form>` component (name-based inputs, slot props `errors`, `processing`, `wasSuccessful`, `disable-while-processing` via the `inert` attribute, Wayfinder action objects, typed generics in v3) | Simpler create form. **Note:** `disable-while-processing` sets `inert`, which removes focus from the form. Check against US-02.AC6 (focus returns to title) | [E4][E6] |
| Partial reloads (`only`/`except`) | US-07 filter: reload only `todos` + `counts` when the filter changes. Applies only to the same page component | [E23] |
| Deferred props (`Inertia::defer`, `<Deferred>`) | Not needed at A1 load (≤500 todos). Avoid them, because they add a second request and complicate the NFR-05 query-count test | [E24] |
| Prefetching (`<Link prefetch>`, 30 s cache, cache tags) | Optional for filter links. Low value, and stale-cache risk after writes. Not recommended for MVP | [E25] |
| **Optimistic updates (new in v3)**: `router.optimistic()`, `useForm().optimistic()`, `<Form :optimistic>`. Automatic rollback on validation error, server error or interruption. Concurrent updates are tracked per prop | US-05 toggle feels instant. Use it for the toggle only, and only if latency is a measured problem. Keep the server as the source of truth | [E7] |
| `useHttp` (new in v3): non-navigation requests | Not needed (no JSON endpoints in MVP) | [E6][E26] |
| Flash data (`Inertia::flash()`, `page.flash`, `flash` event, not persisted in history) | Success toasts. The starter kit already listens to `flash` and shows `toast` via vue-sonner | [E11][E12] |
| History encryption (`inertia.history.encrypt`, `clearHistory()` on logout) | Stops the back button from showing another session's todos after logout on a shared device. Recommended to enable (requires HTTPS) | [E27] |
| 419 handling recipe (`respond()` → `back()->with(...)`) | Better UX when a CSRF token expires, instead of a modal. Note: the starter kit's `bootstrap/app.php` does not include it | [E3][E10] |

### Known limitations (Option A)
- Inertia endpoints are not a public API. Non-Inertia clients cannot reuse them, so a mobile client requires the A′ layer [E1][E19].
- Partial reloads only work within the same page component [E23].
- The data-exposure footgun: whole models passed as props are shipped to the browser [E21][E22].
- Backend tests do not exercise the Vue code. A11y AC (US-02.AC6, US-06.AC4, NFR-06) need Vitest or browser tests (scope of R-003) [E13].
- Fast release cadence. Pin minors in the lock file and let Dependabot propose updates [V1][V2].
- The Laravel adapter returns only the first error per field unless `$withAllErrors = true` [E5]. That is fine for our AC.

## Writes: redirect-back, flash and validation flow (recommended convention)
1. **Writes return redirects, not JSON.** `store`/`update`/`toggle`/`destroy` return `to_route('todos.index', ['filter' => …])` or `back()`. Inertia requires a proper redirect after non-GET requests. Official adapters convert redirects to **303** automatically, so PUT, PATCH and DELETE are followed by a GET [E28].
2. **Validation:** a FormRequest fails, Laravel redirects back and flashes errors to the session, and the Inertia middleware shares them as `page.props.errors`. Inertia "never receives 422 responses". It calls `onError` when `errors` is non-empty, otherwise `onSuccess` [E5]. Component state (typed input) is preserved automatically for non-GET requests, so no `old()` repopulation is needed [E5]. With `useForm`, errors are exposed as `form.errors.title`, which feeds the `aria-describedby` cross-cutting AC.
3. **Flash:** `Inertia::flash('toast', ['type' => 'success', 'message' => 'Todo added'])->back()`. It is delivered once on `page.flash` and is not replayed from history [E11]. The starter kit's `initializeFlashToast()` shows `flash.toast` [E12]. Tests: `assertInertiaFlash('toast.type', 'success')` on the redirect response [E13].
4. **US-02.AC6 (focus back to title):** in `useForm` `onSuccess`, call `form.reset('title')` and focus the input ref. Check by browser test. The reset API is per [E4]. The exact `reset(field)` signature was not re-read in full: **UNVERIFIED**, so confirm during T-xxx.
5. **Preserve scroll** on toggle and delete (`preserveScroll: true`) so the list does not jump [E4].

## TypeScript: typing page props (official approach)
- **Shared props, flash and error types** are set globally by augmenting `InertiaConfig` in `@inertiajs/core` (`sharedPageProps`, `flashDataType`, `errorValueType`). The file must be a module (`import`/`export {}`), and `tsconfig` must include `resources/js/**/*.d.ts` [E29]. The starter kit already does this in `resources/js/types/global.d.ts` (`sharedPageProps: { name, auth, sidebarOpen }`) [E10]. We should add `flashDataType: { toast?: FlashToast }`.
- **Page-specific props:** use `defineProps<{ todos: Todo[]; counts: Counts; filter: Filter }>()` in `<script setup lang="ts">`. Or use `usePage<{…}>()`, which merges with `sharedPageProps` [E29].
- **Forms:** `useForm<{ title: string; description: string | null; due_date: string | null }>()` gives typed `errors` keys [E29].
- **Generation:** Inertia's docs say the *next* (beta) version of Laravel Wayfinder may generate shared and page prop, FormRequest and model types [E29]. The starter kit uses Wayfinder 0.1.x for routes only [V3]. Recommendation: hand-write types in `resources/js/types/` now. Revisit when Wayfinder `next` is stable. Adding it needs dependency approval.

## Evidence
Versions were checked by command on 2026-11-16 (V-rows). Docs were fetched on 2026-11-16 (E-rows). Inertia docs were read from the v3 markdown sources listed in `https://inertiajs.com/docs/llms.txt`.

| # | Claim | Source (URL) | Version / date | Verified by command? |
|---|---|---|---|---|
| V1 | `@inertiajs/vue3` latest = **3.11.0** (2026-11-02). dist-tags `legacy: 2.3.29`, `beta: 3.0.0-beta.7`. 3.4.0 published 2026-06-11. `@inertiajs/core` 3.11.0 | `npm view @inertiajs/vue3 version dist-tags time` | 2026-11-16 | Yes |
| V2 | `inertiajs/inertia-laravel` latest stable **v3.7.0** (GitHub release 2026-11-02). 2.x line ends at v2.0.28 | `composer show -a inertiajs/inertia-laravel`; `gh api repos/inertiajs/inertia-laravel/releases` | 2026-11-16 | Yes |
| V3 | Vue starter kit `main` (commit 1959e1a, 2026-11-10) requires `inertiajs/inertia-laravel ^3.0`, `laravel/framework ^13.17`, `laravel/fortify ^1.37.2`, `laravel/wayfinder ^0.1.14`, PHP ^8.3. npm: `@inertiajs/vue3 ^3.0.0`, `@inertiajs/vite ^3.0.0`, `vue ^3.5.13`, `vue-tsc`, `vue-sonner`, `reka-ui`, Vite replaced by `@voidzero-dev/vite-plus-core@1.4.0` (`vp build`). Tests are **PHPUnit** classes by default (`phpunit/phpunit ^12.5.23`), with no Pest in `require-dev` | `gh api repos/laravel/vue-starter-kit/contents/{composer,package}.json` | main @ 2026-11-10 | Yes |
| V4 | `laravel/framework` latest v13.42.0. `laravel/sanctum` latest v4.4.0. `vue` 3.5.45. `laravel-vite-plugin` 3.2.1. `@laravel/vite-plugin-wayfinder` 0.1.11 | `composer show -a`, `npm view` | 2026-11-16 | Yes |
| E1 | Inertia's purpose: an SPA without building an API, separate auth, client state, extra repo or complex deploy | https://inertiajs.com/docs/v3/core-concepts/who-is-it-for | v3 | No (doc) |
| E2 | Inertia uses the framework's session auth. "you don't need to set up CORS" | https://inertiajs.com/docs/v3/security/authentication | v3 | No |
| E3 | Laravel CSRF is automatic. Built-in client sends `X-XSRF-TOKEN` from the `XSRF-TOKEN` cookie. Omit the `csrf-token` meta tag. 419 → `back()->with()` recipe via `$exceptions->respond()` | https://inertiajs.com/docs/v3/security/csrf-protection | v3 | No |
| E4 | `<Form>` component and `useForm`: slot props, `disable-while-processing` adds `inert`, Wayfinder `action`, `options.preserveScroll`, `optimistic` prop | https://inertiajs.com/docs/v3/the-basics/forms | v3 | No |
| E5 | Validation via redirect + session-flashed errors shared as `errors`. No 422s. `onError` vs `onSuccess`. State preserved on non-GET. First error only unless `$withAllErrors`. Error bags unnecessary with the form helper | https://inertiajs.com/docs/v3/the-basics/validation | v3 | No |
| E6 | v3 upgrade guide: requires PHP 8.2 / Laravel 11+. Axios removed (built-in XHR client). New `useHttp`, optimistic updates, `<Form>` generics, instant visits, `@inertiajs/vite`. Breaking: event renames, `Inertia::lazy()` removed, testing traits removed, ESM-only | https://inertiajs.com/docs/v3/getting-started/upgrade-guide | v3.0 | No |
| E7 | Optimistic updates: `router.optimistic()`, `useForm().optimistic()`, `<Form :optimistic>`. Auto-rollback on validation or server error or interruption. Concurrent tracking | https://inertiajs.com/docs/v3/the-basics/optimistic-updates | v3 | No |
| E8 | Vue starter kit "built with Inertia 3, Vue 3 Composition API, Tailwind, and shadcn-vue". Uses Wayfinder for type-safe routes | https://laravel.com/docs/13.x/starter-kits | 13.x | Partly (V3) |
| E9 | All starter kits use Fortify (login, register, reset, verification, confirm-password, 2FA routes). Login rate limiting is configurable. SSR is optional (`build:ssr`) | https://laravel.com/docs/13.x/starter-kits | 13.x | No |
| E10 | Starter kit `HandleInertiaRequests::share()` shares `auth.user = $request->user()`. `global.d.ts` augments `InertiaConfig.sharedPageProps`. `bootstrap/app.php` has no 419 handler | GitHub `laravel/vue-starter-kit` main: `app/Http/Middleware/HandleInertiaRequests.php`, `resources/js/types/global.d.ts`, `bootstrap/app.php` | 1959e1a | Yes (`gh api`) |
| E11 | `Inertia::flash()`, `page.flash`, `flash` event. Not persisted in history | https://inertiajs.com/docs/v3/data-props/flash-data | v3 | No |
| E12 | Starter kit `initializeFlashToast()` listens to `router.on('flash')` and shows `flash.toast` via vue-sonner | GitHub `laravel/vue-starter-kit` `resources/js/lib/flashToast.ts`, `resources/js/app.ts` | 1959e1a | Yes (`gh api`) |
| E13 | `assertInertia` (`component`, `has`, `where`, `missing`, `etc`), `inertiaProps()`, `reloadOnly`, `loadDeferredProps`, `hasFlash`, `assertInertiaFlash` on redirects. JS is tested with Vitest or E2E (Pest) | https://inertiajs.com/docs/v3/advanced/testing | v3 | No |
| E14 | Sanctum install via `php artisan install:api` | https://laravel.com/docs/13.x/sanctum#installation | 13.x | No |
| E15 | Sanctum SPA auth uses cookie sessions (CSRF, no tokens, XSS-resistant credentials). Do not use API tokens for a first-party SPA | https://laravel.com/docs/13.x/sanctum#spa-authentication | 13.x | No |
| E16 | SPA requirements: same top-level domain, `stateful` domains, `statefulApi()`, CORS `supports_credentials`, client `withCredentials`/`withXSRFToken`, session cookie domain, `/sanctum/csrf-cookie` first | https://laravel.com/docs/13.x/sanctum#spa-configuration | 13.x | No |
| E17 | On session expiry the SPA receives 401/419 and "should redirect the user to your SPA's login page" | https://laravel.com/docs/13.x/sanctum#spa-authenticating | 13.x | No |
| E18 | `Sanctum::actingAs($user, ['*'])` testing helper (Pest example given) | https://laravel.com/docs/13.x/sanctum#testing | 13.x | No |
| E19 | Mobile auth: `/sanctum/token` endpoint issues `createToken($device)->plainTextToken`. `auth:sanctum` guard checks the session cookie first, then the bearer token, so it can protect `web.php` and `api.php` routes | https://laravel.com/docs/13.x/sanctum#mobile-application-authentication | 13.x | No |
| E20 | Initial page object in `<script type="application/json">`. `X-Inertia`, `Vary: X-Inertia`. Asset-version mismatch → 409 + `X-Inertia-Location` | https://inertiajs.com/docs/v3/core-concepts/the-protocol | v3 | No |
| E21 | (same as E20) The page object is embedded in the HTML, so props are readable by the client | https://inertiajs.com/docs/v3/core-concepts/the-protocol | v3 | No |
| E22 | "all data returned from the controllers will be visible client-side". Models via `toArray()`. API Resources resolved via `toResponse()`. Enum component names | https://inertiajs.com/docs/v3/the-basics/responses | v3 | No |
| E23 | Partial reloads `only`/`except`. Same page component only | https://inertiajs.com/docs/v3/data-props/partial-reloads | v3 | No |
| E24 | Deferred props `Inertia::defer()`, `<Deferred>`, groups | https://inertiajs.com/docs/v3/data-props/deferred-props | v3 | No |
| E25 | Prefetch on hover (75 ms), default cache 30 s, `cache-for` | https://inertiajs.com/docs/v3/data-props/prefetching | v3 | No |
| E26 | `useHttp` for non-visit HTTP requests | https://inertiajs.com/docs/v3/the-basics/http-requests | v3 | No |
| E27 | History encryption: opt-in `inertia.history.encrypt`, `Inertia::clearHistory()`, needs `crypto.subtle` (HTTPS) | https://inertiajs.com/docs/v3/security/history-encryption | v3 | No |
| E28 | Non-GET → must redirect. Official adapters auto-convert to 303 | https://inertiajs.com/docs/v3/the-basics/redirects | v3 | No |
| E29 | TS: augment `InertiaConfig` (`sharedPageProps`, `flashDataType`, `errorValueType`). `usePage<T>()` merges with shared props. `useForm<T>()` typed errors. Wayfinder `next` (beta) may generate prop types | https://inertiajs.com/docs/v3/advanced/typescript | v3 | No |

**Spikes:** none were run. The scratchpad `r002-spike/` holds only the fetched starter kit manifests and the Inertia docs. No app was scaffolded, so runtime behaviour such as the 303 conversion and `assertInertia` under Pest is documented but **not executed**.

## Recommendation
**Option A: Laravel + Inertia v3 + Vue 3 (TypeScript) monolith from the official Vue starter kit.** This confirms D2. The conventions to record in ADR-0001:
1. All todo writes are Inertia visits that **redirect** (to `todos.index` with the current filter, or `back()`), relying on the adapter's automatic 303. Validation goes through FormRequests → session errors → `form.errors`. Success feedback uses `Inertia::flash('toast', …)`.
2. **Props are built from API Resources (or explicit arrays), never raw models.** Audit the shared `auth.user` against `$hidden`. This limits exposure [E22] and makes the future `/api/v1` cheap.
3. Enable **history encryption** and `clearHistory()` on logout and account deletion. Add the official **419 → back-with-flash** handler.
4. Type pages with `defineProps<T>()` and `useForm<T>()`. Extend the existing `InertiaConfig` augmentation with `flashDataType`.
5. Do not use deferred props or prefetching in the MVP. Optimistic UI only for the US-05 toggle, and only if latency calls for it.
6. Pest is not the starter kit default [V3]. Converting the kit's PHPUnit tests to Pest, or choosing Pest at `laravel new`, is for R-001 and R-003 to decide.

## Strongest argument against the recommendation
**Option A couples the HTTP layer to one client, and Inertia v3 is a young, fast-moving major.** If a mobile client is likely within the product's life, every controller will exist twice (an Inertia controller and a JSON controller), with two sets of tests and two places to forget an authorisation check. Option B's single API would avoid that. Inertia also shipped 8 minor releases between June and November 2026 [V1], and v3 removed APIs that v2 code relied on [E6]. The starter kit additionally replaces stock Vite with VoidZero's "vite-plus" build tool [V3], which is less "boring" than it looks. An Inertia backend test (`assertInertia`) also proves nothing about the Vue page actually working, so A needs a browser-test layer just as B needs SPA tests.

**Rebuttal:** mobile is an explicit non-goal and D2 has a revisit trigger. Policies, FormRequests and API Resources are shared, so the duplicated part is thin controllers. Churn is handled by lock files and dependency review. B's extra CORS, cookie-domain and client-auth surface would be paid now for a client that may never exist.

## What would change this recommendation
- A mobile or third-party client is **committed** within the MVP window (D2 trigger). Even then, A′ (adding `/api/v1` beside Inertia) is preferred over a rewrite, unless the web UI must be deployed and versioned independently of the backend.
- A requirement for offline support or a separately hosted frontend (CDN-only, different domain). Sanctum SPA mode requires a shared top-level domain [E16], so that would point to token auth and a true SPA.
- Inertia v3 shows a blocking defect or an unmaintained period, or the starter kit drops Vue.
- The owner reports strong existing SPA/API experience and no Inertia experience (team familiarity is currently unknown).

## Risks
| Risk | Likelihood | Impact | Mitigation | Owner |
|---|---|---|---|---|
| Over-exposure of model attributes through props (for example, the full `auth.user`) | Medium | High (ASVS, privacy) | Use API Resources for props. Add a `missing('…')` assertion for sensitive fields. Security review checklist item | Architect → Security agent |
| Inertia v3 minor-release churn breaks the build or behaviour | Medium | Medium | Lock files. Dependabot PRs gated by the full CI suite. Read the upgrade guide on each minor | Engineer, approved at G4 |
| `vite-plus` (VoidZero) build tool in the starter kit is novel | Low–Medium | Medium | Note in R-001. Fall back to standard Vite if issues appear (needs dependency approval) | R-001 author |
| Backend Inertia tests pass while Vue pages are broken or inaccessible | High (if unaddressed) | High (NFR-06, US-02.AC6, US-06.AC4) | Vitest component tests plus Pest browser tests for core journeys (R-003) | QA agent |
| Back button after logout reveals cached todo pages on shared devices | Low | Medium | Enable history encryption and `clearHistory()` on logout and delete [E27] | Engineer |
| 419 expiry shows the default error modal and loses user input | Medium | Low | Official `respond()` 419 → `back()->with()` handler [E3] | Engineer |
| `<Form disable-while-processing>` (`inert`) steals focus and breaks US-02.AC6 | Medium | Low | Use `useForm` for the create form, with explicit focus handling. Browser test for AC6 | Engineer / QA |
| Future `/api/v1` duplicates authorisation logic | Low (no mobile planned) | High if it happens | Keep authorisation in Policies and FormRequests only. IDOR tests cover both route sets when the API is added | Architect |
| The default starter kit uses PHPUnit, while the project mandates Pest | High | Low | Decide in R-001 and R-003 (installer Pest option or conversion) | R-001/R-003 authors |

## Red-team review (2026-11-16)

**Reviewer:** architect agent (red-team mode).

**Verdict: Stands with changes.** I could not refute Option A. Option B's extra CORS, cookie-domain and client-auth surface is not justified while mobile remains a non-goal. But the convention "props from API Resources" has an unstated data-shape consequence, and the churn mitigation relies on a Dependabot setup that doesn't exist.

| # | Severity | Finding | Evidence | Required change |
|---|---|---|---|---|
| 1 | Medium | **API Resources passed as props keep the JSON `data` wrapper**, and nothing in the tooling checks the PHP-to-TS prop shape. Inertia resolves a `Responsable` prop with `toResponse($request)->getData(true)`, so `TodoResource::collection($todos)` reaches Vue as `{ data: [...] }` (plus `links`/`meta` if paginated). R-002 says to hand-write TS types (§TypeScript). Wayfinder 0.1 types routes only, vue-tsc can't see PHP and PHPStan can't see Vue, so prop drift is caught by no gate | `vendor/inertiajs/inertia-laravel/src/PropsResolver.php` lines 523–528 (inertia-laravel 3.7.0 in the spike): `if ($value instanceof Responsable) { $response = $value->toResponse($this->request); … $value = $response->getData(true); }` | ADR-0001 must pick one: (a) `JsonResource::withoutWrapping()` globally (this also changes the future `/api/v1` envelope), (b) `->resolve()` when passing props, or (c) keep `data` and type it. Also require one **strict `assertInertia` shape test per page** (`has('todos.data.0', fn ($t) => $t->hasAll([...]))` without `etc()`) as the prop contract, so a field added or leaked in PHP fails a test |
| 2 | Medium | **The churn and pinning mitigation is not real yet.** R-002 says "Dependabot PRs gated by the full CI suite", but the kit's `dependabot.yml` covers only `github-actions`. The server and client adapters are versioned independently (`inertiajs/inertia-laravel` 3.7.0, `@inertiajs/vue3` 3.11.0), both with caret constraints (`^3.0`, `^3.0.0`). The client shipped 11 minors in 7 months. The kit's `composer setup` runs `npm install`, not `npm ci` | `r001-spike/.github/dependabot.yml`. `npm view @inertiajs/vue3 time`: 3.0.0 on 2026-03-25 … 3.11.0 on 2026-11-02. `r001-spike/package.json` and `composer.json` constraints | Record a pinning policy in ADR-0001: lockfiles are authoritative; `npm ci` and `composer install` in CI and on Cloud (R-004 already uses `npm ci`); Inertia server and client are updated **together in one grouped PR**, after reading the release notes, with the browser smoke suite green; no `composer update`/`npm update` outside such a PR (aligns with R-005 C8). Revisit trigger: Inertia 4.0, or any 3.x minor whose notes list a breaking change |
| 3 | Medium | **Agents will get wrong-major docs.** Boost Search Docs covers "Inertia 1.x, 2.x" only. Every Inertia fact in this report is docs-only ("No" in the Verified column), and no spike was run, although `r001-spike` existed | https://laravel.com/docs/13.x/boost § Documentation API table (fetched 2026-11-16). R-002 Evidence table | `vue-engineer.md` must require citing inertiajs.com `/docs/v3/` (not `search-docs`) for Inertia. T-001 adds a 10-minute check in the scaffold: a PATCH redirect gives 303, and `assertInertia` and `assertInertiaFlash` work under Pest 5 |
| 4 | Low | Recommendation 6 and the last Risks row ("Pest is not the starter kit default… decide in R-001 and R-003") are outdated. `laravel new --pest` installs Pest 5.3.6 (R-001 E10/E19), and the drift conversion works outside the agent env (R-001 red-team finding 1) | R-001 spike, red-team spike | Mark both as resolved by R-001 and R-003 |
| 5 | Low | History encryption needs `crypto.subtle`, which only exists in a secure context (E27). Herd sites on `http://*.test` are not secure contexts, so enabling encryption breaks local dev unless the site is served over HTTPS | E27 ("requires HTTPS") | T-001: serve the Herd site over HTTPS, or gate `inertia.history.encrypt` per environment. Pest browser tests on `127.0.0.1` count as secure (localhost). Whether the Pest browser server uses localhost is **UNVERIFIED** |
| 6 | Low | The shared `auth.user` is the raw model (this is a real deviation from Recommendation 2), but the kit's `#[Hidden]` covers `password`, `two_factor_secret`, `two_factor_recovery_codes` and `remember_token` | `r001-spike/app/Models/User.php` line 30, `HandleInertiaRequests::share()` | Keep it. Add a `missing('auth.user.two_factor_secret')`-style test, and record the exception in ADR-0001 |

**Strongest counter-argument checked:** "mobile later doubles controllers." That holds only if a mobile client is committed. D2 already has the trigger, and Policies and FormRequests are shared. Not refuted.
