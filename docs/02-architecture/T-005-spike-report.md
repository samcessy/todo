# T-005 Kit spike report: confirming the open G2 cells

**Task:** `docs/03-plan/tasks/T-005.md` · **Agent:** architect · **Date:** 2026-11-18 · **Branch:** `task/T-005-kit-spike`
**Status:** Draft. Contract changes below need owner approval (governance; T-005 `human_approval_required`).

## Setup

- **Spike copy:** `rsync` of the worktree, plus `vendor/` and `node_modules/` from the main checkout, into the session scratchpad (`…/scratchpad/t005-spike`). No code was added to the repository.
- **Versions** (read from `vendor/composer/installed.json` in the copy): laravel/framework v13.43.0, inertiajs/inertia-laravel v3.7.0, laravel/fortify v1.43.0, laravel/boost v2.10.4, laravel/passkeys v0.3.0, pestphp/pest v5.3.7. PHP 8.4.25 (64-bit, `PHP_INT_SIZE` 8). vite-plus 1.4.0 with bundled Vitest 5.0.5.
- **Run:** `APP_KEY="base64:$(openssl rand -base64 32)" php artisan test --compact tests/Feature/Spike/…`. No `.env` was used. Baseline before any spike change: `npm run build`, then `php artisan test` passed (42 tests, 147 assertions).
- **Spike-only code:**
  - the §1.3 routes with stub `TodoController` and `TodoCompletionController` (redirect back with `Inertia::flash('toast', …)`, `back(fallback: route('todos.index'))`);
  - a `todos` migration, `Todo` model and factory, `User::todos()`, and a `TodoPolicy` that allows the owner only;
  - the `todo-writes` limiter and `URL::forceHttps(! app()->environment(['local','testing']))` in `AppServiceProvider`;
  - the architecture §2a `respond()` 419 handler in `bootstrap/app.php`;
  - `auth.session` on the settings groups and `fortify.home = /todos`;
  - a stub route `spike/csrf` (POST, PATCH and DELETE) that throws `TokenMismatchException`;
  - a placeholder `pages/todos/Index.vue`, then `npm run build`;
  - environment switches `SPIKE_NO_DASHBOARD`, `SPIKE_REDIRECT_USERS` and `SPIKE_ALLOWLIST` for the before-and-after variants.
- **Final spike run:** 105 tests, 104 passed. The one failure is intended: the strict `auth.user` test fails against the unmodified kit share (item 4).

## Results

| # | Item | Command or test | Output (short) | Verdict |
|---|---|---|---|---|
| 1 | Status table (contracts §1.1.1) | `tests/Feature/Spike/StatusTableTest.php`: 84 requests. Each row × POST/PATCH/DELETE × three header sets: `inertia-full` (exactly what `@inertiajs/core` 3.11.0 sends: `X-Inertia`, `X-Inertia-Version` = manifest hash, `X-Requested-With`, `Accept: text/html, application/xhtml+xml`; `dist/index.js:3472-3480`), `inertia-min` (`X-Inertia` only) and `plain`. Results written to a JSONL file | See the table below. **Every cell matches except the 419 row:** Inertia PATCH and DELETE give **303**, not 302. The cause is a second, **global** rewrite middleware (`EnsureGetOnRedirect`). The "Why the rows differ" explanation in the contract was wrong. Unverified GET with `X-Inertia` and no version header gives **409** | **Corrected** (419 cell, explanation, test convention) |
| 2 | `auth.session` in `route:list --json` (§1.2) | `php artisan route:list --json --path=todos \| jq -S '[.[] \| {method, uri, name, action, middleware}] \| sort_by(.name)'`, then `diff` against the §1.2 block (also `jq -S`) | Prints the alias `"auth.session"`. `diff` output: empty (IDENTICAL) | **Confirmed** (block unchanged; the note is updated) |
| 3 | Query budget (§3) | `QueryBudgetTest.php`: `expectsDatabaseQueryCount(2)` under `actingAs()`. Filters: absent, `all`, `active`, `completed`, `foo`. Sizes: 1+1 and 100+100 todos. Also a query log, and a variant with a real `POST /login` session | 10 of 10 pass with **exactly 2** queries (the list query and `select count(*) as total, count(completed_at) as completed … limit 1`). The real-session variant adds `select * from "users" where "id" = ? limit 1` (3 queries) | **Confirmed** (clarifying note added) |
| 4 | Kit `auth.user` keys (T-04) | `grep -rnoE "user\??\.[a-zA-Z_]+" resources/js --include='*.vue' --include='*.ts'` (generated Wayfinder folders excluded). `SharedPropsTest.php` dumps `inertiaProps()` | **Pages read:** `name`, `email`, `email_verified_at` (`settings/Profile.vue:53,68,76`; `UserInfo.vue:26-35`; `AppHeader.vue:254-259`) and `avatar` (`UserInfo.vue:20,26`; `AppHeader.vue:252-253`; optional, no such column, initials fallback). `id` is not read. **Kit sends today:** `name`, `email`, `email_verified_at`, `two_factor_confirmed_at`, `updated_at`, `created_at`, `id`. The strict test fails on the kit ("Unexpected properties … `two_factor_confirmed_at`, `updated_at`, `created_at`") and passes with the allow-list | **Confirmed**: no key added. Notes added: three keys are over-exposed today, and the TS `User` type needs narrowing |
| 5 | Password-change session (T-06) | `PasswordSessionTest.php`: two real Fortify logins (A and B), `file` session driver, change the password in A | A: `/settings/profile` 200 and `/todos` 200. B: 302 to `/login`. Mechanism: `AuthenticateSession.php:70-74` re-stores the hash after the request | **Confirmed** (test recipe added) |
| 6 | Boost keys (T-19) | Source read: `Tinker.php:29`, `RecordRule.php:32`, `ToolRegistry.php:28,58-61`; `config/boost.php:34,48,52`. `BoostKeysTest.php` | 7 assertions pass: both flags false; exclude list contains both classes; `ToolRegistry::isToolAllowed()` false for both; `app(Tinker::class)->shouldRegister()` false. `RecordRule` cannot be built from the container (`RuleRepository` needs a `$directory`), so it is checked through the registry only | **Confirmed** (UNVERIFIED removed; assertion recorded) |
| 7 | `currentPasswordRules()` callers (T-23) | `grep -rn "currentPasswordRules" app resources routes` | `PasswordUpdateRequest.php:21` (`current_password`) and `ProfileDeleteRequest.php:21` (field **`password`**). Defined at `PasswordValidationRules.php:25`. `passwordRules()` is also used by `CreateNewUser:24` and `ResetUserPassword:22` | **Confirmed**, refined: the 73-byte test case is added for profile delete |
| 8 | `HttpsRedirectTest` mechanism (T-24, QA N-2) | `HttpsRedirectTest.php`: A = `detectEnvironment(fn () => 'production')` plus re-running `AppServiceProvider::boot()`; B = `$_ENV`/`$_SERVER`/`putenv` `APP_ENV=production` plus `refreshApplication()` | A passes (`route('todos.index')` and `url('/login')` are `https://…`). B gives `https://` too, but afterwards `Schema::hasTable('users')` is false: the fresh application loses the `:memory:` database | **Corrected**: A is chosen and B is rejected (snippet below) |
| 9 | Passkey landing (architecture §5.1) | `PasskeyLandingTest.php`: the real controller, guard and response, with only `VerifyPasskey` replaced | `passkey login: 200 {"redirect":"http://localhost/todos"}`, and `assertAuthenticatedAs($user)` passes. The redirect comes from `passkeys.redirect` = `Fortify::redirects('login')` → `fortify.home` (`FortifyServiceProvider.php:139`, `PasskeyLoginResponse.php:22-28`) | **Corrected**: a feature test is feasible. The grep stays the check for the client string |
| 10 | Vitest include (ADR-0003) | `resources/js/lib/spike.spec.ts` and `spike.test.ts` importing from `vite-plus/test`; `npx vp test run --reporter=verbose`; then only the `.spec.ts` | Both picked up ("Test Files 2 passed"), and then `.spec.ts` alone ("1 passed"). Default include `["**/*.{test,spec}.?(c\|m)[jt]s?(x)"]` (`node_modules/vitest/dist/chunks/defaults.B1FCaFfh.js:5`, Vitest 5.0.5). No `test` block in `vite.config.ts` | **Confirmed** (no config needed; snippet below) |
| 11 | Node 26 on Cloud (ADR-0006) | https://raw.githubusercontent.com/nodejs/Release/main/schedule.json, https://nodejs.org/en/about/previous-releases, https://laravel.com/cloud/docs/runtimes, `/base-images`, `/environments` (all fetched 2026-11-18) | Node 26: start 2026-05-05, **LTS 2026-10-28**, maintenance 2027-10-20, end 2029-04-30, so it is **Active LTS** today. Node 24: maintenance since 2026-10-20. Cloud runtimes: "Node.js 22, 24, and 26 (26 is the default for new environments)" for the JS runtimes. The PHP images "include PHP, Composer, Node.js, npm, and Yarn Classic", but the docs give neither the Node version nor a selector for PHP environments | **Confirmed** for LTS and for CI on Node 26. Node version in the Cloud **PHP** build image: **UNVERIFIED**. Check with `node --version` in the staging Commands console (T-002/T-025). If it is below 26, the build still works unless a Node-26-only feature is used |
| 12 | Dashboard references (architecture §5.1) | `grep -rn dashboard resources/js app routes config tests`, generated Wayfinder folders excluded (they are git-ignored, `.gitignore:10-12`) | Exactly the §5.1 files: `AppSidebar.vue:17,23,48`, `AppHeader.vue:38,59,149`, `PasskeyVerify.vue:33`, `Dashboard.vue`, `Welcome.vue:3,21`, `routes/web.php:8`, `config/fortify.php:76`, `DashboardTest.php`, and 6 assertions (`AuthenticationTest:22`, `RegistrationTest:24`, `EmailVerificationTest:45,90,105`, `VerificationNotificationTest:31`). **Missed by the grep:** `RedirectIfAuthenticated::defaultRedirectUri()` (`Illuminate/Auth/Middleware/RedirectIfAuthenticated.php:63-80`). Authenticated `GET /login`: with dashboard → `/dashboard`; without → `http://localhost` (Welcome); with `redirectUsersTo('/todos')` → `/todos` (also `/register`, `/forgot-password`, `/reset-password/x`, `/two-factor-challenge`) | **Corrected**: §5.1 gets a `bootstrap/app.php` row |
| 13 | pdo_pgsql aggregate type (contracts §5.2) | sqlite: `gettype()` of the raw aggregate in the stub index. pgsql: no local server (`psql`/`postgres` not installed, Docker daemon not running), so source reading: php-src `PHP-8.4` `ext/pdo_pgsql/pgsql_statement.c` and https://www.postgresql.org/docs/18/functions-aggregate.html (fetched 2026-11-18) | sqlite: `integer` for both. pgsql: `count(*)` → `bigint`; `pgsql_stmt_get_col` returns `INT8OID` as `ZVAL_LONG` when `SIZEOF_ZEND_LONG >= 8` (otherwise a string). Laravel `ATTR_STRINGIFY_FETCHES => false` (`Connectors/Connector.php:23`). So an **int** on 64-bit PHP | **Confirmed** from source (not run live). The `(int)` cast stays; the pgsql CI job is the live check |

### Item 1 in detail: measured status per cell

`inertia-full` and `inertia-min` gave identical statuses for every write. "—" means the case does not apply (no validation on DELETE, so the completion PATCH with `completed=yes` was used; POST has no `{todo}`).

| Outcome | Inertia POST | Inertia PATCH | Inertia DELETE | No header (POST / PATCH / DELETE) | Contract before | `Location`, flash |
|---|---|---|---|---|---|---|
| Success | 302 | 303 | 303 | 302 / 302 / 302 | same | `/todos?filter=active` (the `from` URL kept); `toast` success |
| Validation failure | 302 | 303 | — (completion PATCH: 303) | 302 / 302 / — (302) | same | the `from` URL |
| Guest | 302 | 303 | 303 | 302 / 302 / 302 | same | `/login` |
| Unverified | 302 | 303 | 303 | 302 / 302 / 302 | same | `/email/verify` |
| 419, stub route throwing `TokenMismatchException` | 302 | **303** | **303** | 302 / 302 / 302 | **302 / 302** | `/todos?filter=active`; `toast` = `{type: warning, message: "The page expired, please try again."}` |
| 419, real `PreventRequestForgery` (bypass off), no token | 302 | **303** | **303** | 302 / 302 / 302 | **302 / 302** | same as the stub; the todo was unchanged (count 1) |
| Not the owner | — | 403 | 403 | — / 403 / 403 | same | non-owner with `title=''`: 403, title unchanged |
| No such id | — | 404 | 404 | — / 404 / 404 | same | |
| Over the rate limit (limiter overridden to 1/min) | 429 | 429 | 429 | 429 / 429 / 429 | same | |
| `GET /todos` guest | 302 | | | 302 | same | `/login` (min header: 302) |
| `GET /todos` unverified | 302 (full headers) | | | 302 | same | **min header (no version): 409** |

**Why 303 on the 419 row.** `Inertia\ServiceProvider::registerRedirectMiddleware()` pushes `Inertia\Middleware\EnsureGetOnRedirect` onto the HTTP kernel as **global** middleware (`vendor/inertiajs/inertia-laravel/src/ServiceProvider.php:64,74-81`, push at `:78`). It rewrites any 302 to 303 for an Inertia PUT, PATCH or DELETE (`src/Middleware/EnsureGetOnRedirect.php:21-26`). Global middleware wraps the whole route pipeline, so its position relative to `PreventRequestForgery` and `HandleInertiaRequests` does not matter. The contract's explanation assumed only `HandleInertiaRequests` rewrites. The resolved middleware order for `todos.update` (in the contract) also shows that `auth`, `throttle`, `auth.session` and `SubstituteBindings` are sorted **before** `HandleInertiaRequests` by the kernel priority list (`Illuminate/Foundation/Http/Kernel.php:103-115`). So the old explanation was wrong for the guest row as well, even though that cell's value was right.

**Spike pitfall worth recording for QA.** The first run used `$this->call($method, …)`, and every write came back 302. `call()` does not merge `withHeaders()` (only the verb helpers do), so that run was header-free. The final run uses `get`/`post`/`patch`/`delete`. The contract's test convention now says so.

### Re-verified line references

| Reference (where cited) | Command | Output | Verdict |
|---|---|---|---|
| `fortify/routes/routes.php:64-83`, no throttle on register, forgot-password, reset-password (security.md T-08, G2-decision-packet) | `grep -n "password.email\|password.update'\|register.store\|throttle" vendor/laravel/fortify/routes/routes.php` (1.43.0) | `password.email` `:64-66`, `password.update` `:68-70`, `register.store` `:81-83`; no `throttle` in 60-84 (throttles only at `:45` login, `:95,:99` verification, `:143` 2FA, `:181` passkeys). `password.confirm.store` `:128-130` has none either | **Confirmed**, lines unchanged. The T-08 text now says 1.43.0 |
| `PreventRequestForgery.php:97-110`, unit-test bypass (security.md T-05; QA review cites `:97-103,130-132`) | `sed -n 85,115p` and `grep -n "function runningUnitTests"` | Bypass condition `:97-103` (`runningUnitTests()` at `:99`), pass-through `:104-108`, `throw new TokenMismatchException` at `:111`; `runningUnitTests()` defined at `:130-133` | **Corrected** to `:97-103` (`:99`, `:130-133`; throw `:111`). The old range was close but ended inside the pass-through branch |
| inertia-laravel `Middleware.php:162` early return / `:179` rewrite (contracts §1.1.1, architecture §2 step 5, ADR-0001, G2 packet) | `grep -n` on `Middleware.php` | `:162` `if (! $request->header(Header::INERTIA))`; `:178` condition, `:179` `setStatusCode(303)` | **Lines confirmed, but the claim is incomplete.** The global `EnsureGetOnRedirect` (`ServiceProvider.php:78`, `EnsureGetOnRedirect.php:21-26`) rewrites as well. Corrected in contracts §1.1.1. architecture §2 step 5, ADR-0001 and the G2 packet carry the same incomplete sentence (not edited here; see follow-ups) |
| `UrlGenerator.php:763` `forceHttps` (security.md T-24, cited for 13.42.0) | `grep -n "function forceHttps"` | `:763` on 13.43.0 | **Confirmed** |

## Snippets for the test strategy (QA copies them into `docs/04-quality/test-strategy.md`)

**Item 4: exact `auth.user` key set** (failed on the kit, passed with the allow-list):
```php
use Inertia\Testing\AssertableInertia as Assert;

test('auth.user has exactly the allow-listed keys', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('auth.user', fn (Assert $u) => $u
                ->where('id', $user->id)
                ->where('name', $user->name)
                ->where('email', $user->email)
                ->has('email_verified_at')));   // no etc(): an extra key fails
});
```

**Item 5: two real sessions** (passed):
```php
function freshRequestState(): void
{
    auth()->forgetGuards();
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');   // the guard holds this instance
}

// config(['session.driver' => 'file']);   array driver: every request shares one store
// login: $cookie = $this->post('/login', [...])->getCookie(config('session.cookie'), false)->getValue();
// later: freshRequestState(); $this->withUnencryptedCookie(config('session.cookie'), $cookie)->get(...);
```

**Item 8: `HttpsRedirectTest` URL part** (passed; GET requests only after the switch, because `runningUnitTests()` turns false):
```php
use App\Providers\AppServiceProvider;

test('generated URLs are https in production', function () {
    $this->app->detectEnvironment(fn () => 'production');
    (new AppServiceProvider($this->app))->boot();

    expect(route('todos.index'))->toStartWith('https://');
});
```

**Item 9: passkey landing** (passed; only the WebAuthn verification is replaced):
```php
$this->app->instance(VerifyPasskey::class, new class($passkey) extends VerifyPasskey {
    public function __construct(private Passkey $p) {}
    public function __invoke(PublicKeyCredential $c, PublicKeyCredentialRequestOptions $o, $user = null): Passkey { return $this->p; }
});
$this->getJson('/passkeys/login/options')->assertOk();
$this->postJson('/passkeys/login', ['credential' => $syntacticallyValidAssertion])
    ->assertOk()->assertJson(['redirect' => url('/todos')]);
// $syntacticallyValidAssertion: id/rawId/type 'public-key', response.clientDataJSON (base64url JSON with type
// 'webauthn.get'), authenticatorData (base64url: sha256(rpId) . chr(0x05) . pack('N', 1)), signature, userHandle null
```

**Item 10: frontend unit test file** (picked up by `vp test run` with no config):
```ts
// resources/js/lib/overdue.spec.ts
import { expect, test } from 'vite-plus/test';

test('due yesterday and active is overdue', () => {
    expect(isOverdue('2026-03-09', null, '2026-03-10')).toBe(true);
});
```

## Proposed doc corrections (applied in this branch, each marked "(T-005)")

| # | File and place | Change | Contract change? |
|---|---|---|---|
| C-1 | contracts.md §1.1.1 intro | Names both rewrite sites, including the global `EnsureGetOnRedirect` (`ServiceProvider.php:78`) | Yes |
| C-2 | contracts.md §1.1.1 table, 419 row | Inertia PATCH / DELETE: **302 → 303** | **Yes (status code)** |
| C-3 | contracts.md §1.1.1 "Why the rows differ" | Replaced: only the method and the header matter; real middleware order recorded; 403/404/429 unaffected | Yes |
| C-4 | contracts.md §1.1.1 GET note | Inertia GET without a matching `X-Inertia-Version` → 409 once a build manifest exists | Yes (adds a case) |
| C-5 | contracts.md §1.1.1 test convention | Use the verb helpers, not `call()`; `inertia()` only on writes (or add the version); the stub-route 419 test is equivalent to the real middleware | Yes |
| C-6 | contracts.md §1.1 "Kit routes changed" | New bullet: authenticated users on `guest` routes go to `/todos` via `$middleware->redirectUsersTo('/todos')` | **Yes, and an auth redirect change** |
| C-7 | contracts.md §1.2 note | `auth.session` prints as the alias; block confirmed identical | Yes (note only; block unchanged) |
| C-8 | contracts.md §3 query budget | Exactly 2 confirmed (10 cases); real-session login adds 1 | Yes (the number is confirmed, unblocking QA's exact test) |
| C-9 | contracts.md §4 `auth.user` row | No key added; the keys pages read and the keys sent today are listed; the kit TS `User` type is narrowed by the implementing task | Yes |
| C-10 | contracts.md §5.2 | pdo_pgsql UNVERIFIED → int on 64-bit PHP (source), sqlite int (measured) | Yes (note only) |
| C-11 | security.md T-04 | Grep result recorded; no key added | Security doc |
| C-12 | security.md T-05 | Line range `:97-110` → `:97-103` (`:99`, `:130-133`, throw `:111`); 419 status 303 on Inertia PATCH/DELETE; stub route equivalent | Security doc |
| C-13 | security.md T-06 | "T-001 spike: confirm…" → confirmed, with mechanism and test recipe | Security doc |
| C-14 | security.md T-08 | "Fortify 1.42" → 1.43.0, line numbers re-verified | Security doc |
| C-15 | security.md T-19 | Key names confirmed, UNVERIFIED removed, assertion and skip condition recorded | Security doc |
| C-16 | security.md T-23 | Callers named (`PasswordUpdateRequest:21`, `ProfileDeleteRequest:21` field `password`); 73-byte case added for profile delete | Security doc (adds one test case) |
| C-17 | security.md T-24 | Mechanism chosen (re-run provider boot); refresh rejected (loses the `:memory:` DB); `runningUnitTests()` caveat; `forceHttps` line re-verified on 13.43.0 | Security doc |
| C-18 | architecture.md §5.1 | Grep re-run confirmed; passkey row: feature test feasible; new row `bootstrap/app.php` (`redirectUsersTo('/todos')`) | File list changed |

## Owner approval needed

Governance requires owner approval for `contracts.md` changes, for auth changes and (T-005 task file) for any `security.md` correction. For the PR:

1. **C-2: 419 status on Inertia PATCH/DELETE is 303** (was 302). QA's status tests assert this exact value.
2. **C-1, C-3, C-4, C-5:** the rewritten §1.1.1 explanation, the 409 GET case and the test-convention additions.
3. **C-6 and C-18 (`bootstrap/app.php` row): `$middleware->redirectUsersTo('/todos')`.** This adds a kit edit and changes where an authenticated user lands on a guest route (`/` → `/todos`). It is an auth redirect change. The alternative is to accept `/` (Welcome, which links to Todos) and drop C-6.
4. **C-7, C-8, C-10:** notes that confirm the existing values. C-8 lets QA write `expectsDatabaseQueryCount(2)`.
5. **C-9:** the §4 row text. The key set is unchanged, but the row now asks the implementing task to narrow the kit's TypeScript `User` type.
6. **C-11 to C-17:** the security.md corrections. C-12 (line range, 419 status) and C-17 (test mechanism) change test instructions. C-16 adds one test case. The others replace UNVERIFIED or "T-001 confirms" text with results.

## Follow-ups (outside this task's files)

- **architecture.md §2 step 5, ADR-0001 (lines 20 and 86) and `G2-decision-packet.md` line 17** repeat "Middleware.php:162 returns early, the rewrite is at :179" without the global `EnsureGetOnRedirect`. The values they state (303 for Inertia PATCH/DELETE, 302 without the header) are still right. Only the mechanism is incomplete. The orchestrator aligns them, or leaves them as historical.
- **ADR-0006 runtime row:** Node 26 is Active LTS (since 2026-10-28), so "or 24 if T-001 finds 26 is not LTS" no longer applies. The Node version inside the Cloud PHP build image is UNVERIFIED: check `node --version` at staging setup (T-002/T-025).
- **QA (test-strategy.md, owned by QA):** copy the snippets above (items 4, 5, 8, 9, 10) and the `call()` pitfall.
- **Engineer, T-04 task:** narrow `resources/js/types/auth.ts` `User` to `id`, `name`, `email`, `email_verified_at`, `avatar?`.
- Related, already tracked: T-001 security review A-1 (`boost.browser_logs_watcher` default `true`). The T-19 assertion above can add it when T-023 sets it to `false`.

## Strongest argument against my recommendations

- **C-2 (303 for 419):** the contract could instead keep 302 by changing the handler, so the expired-token path stays unique. But 303 is what the adapter does on its own, and the browser follows a 303 with GET, which is the desired effect. Fighting the global middleware would mean removing a package default. Recording the measured value is the boring choice.
- **C-6 (`redirectUsersTo`):** it is one more kit edit and an auth-flow change for a cosmetic case. An authenticated user who opens `/login` already reaches the app through Welcome's "Todos" link. If the owner prefers the smallest kit diff, dropping C-6 is reasonable. The cost is that "`/todos` is home" then holds for login, registration and verification, but not for guest-route bounces.
- **Item 13** is verified from source, not by a live query. A PHP build with a 32-bit `zend_long` would return strings. That is not plausible on Cloud or CI, and the cast covers it anyway.
- **Item 9:** the feature test replaces `VerifyPasskey`, so it proves the redirect target and the login, not the WebAuthn verification. That part stays covered by the upstream package's own tests, not ours.
