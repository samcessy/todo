# Architecture

**Status:** Draft for G2 (revised 2026-11-17 to implement G2-1 to G2-16) · **Author:** architect agent · **Date:** 2026-11-16, revised 2026-11-17
**Decisions:** ADR-0001 to ADR-0006 (all Proposed; the owner accepts them after the blocker re-check). G2 decisions G2-1 to G2-16 (`G2-decision-packet.md`, approved 2026-11-17) are binding. **Related:** `data-model.md`, `contracts.md`, `security.md`, `coding-standards.md`.

## 1. Shape of the system
This is one Laravel 13 monolith built from the official Vue starter kit (ADR-0001), with no service layer (ADR-0002).

The browser talks only to same-origin web routes, using the Inertia protocol. There is no public API, no queue, no scheduler and no cache server.

```
                ┌────────────────────────── Browser (Vue 3.5 + TS, Inertia 3 client) ─────────────────────────┐
                │  pages/todos/Index.vue ── components/todos/* ── shadcn-vue ui/* ── Wayfinder route helpers  │
                │  useForm / router.patch / router.delete  ·  vue-sonner toast on page.flash.toast           │
                └───────────────▲──────────────────────────────────────────────┬─────────────────────────────┘
                     HTML (first visit) / JSON page object                     │ XHR with X-Inertia, X-XSRF-TOKEN
                     (props: todos, counts, filter, auth, errors, flash)       │ (same origin, session cookie)
┌──────────────────────────────────────── Laravel 13 app (PHP 8.4) ──────────▼─────────────────────────────────────┐
│ global SecurityHeaders ▸ web(session, CSRF, HandleInertiaRequests, ThrottleAuthEndpoints)                       │
│   ▸ auth ▸ auth.session ▸ verified ▸ throttle ▸ can:…,todo                                                      │
│                                                                                                                 │
│  Fortify (kit)            TodoController            TodoCompletionController      Settings controllers (kit)    │
│  login/register/reset/    index/store/update/       update                        profile / security / delete   │
│  verify/2FA/passkeys      destroy                                                                              │
│        │                        │  FormRequests (Store/Update/UpdateCompletion) ── Concerns\TodoValidationRules  │
│        │                        │  TodoPolicy (update, delete)   TodoFilter enum   TodoResource (->resolve())    │
│        ▼                        ▼                                                                               │
│      User  ───── hasMany ─────▶ Todo  (Eloquent; preventLazyLoading + preventSilentlyDiscardingAttributes)      │
└───────────────────────────────────────────────┬─────────────────────────────────────────────────────────────────┘
                                                 │ PDO
                         SQLite (local, fast tests)   ·   PostgreSQL 18 (CI job, staging, production — Laravel Cloud Frankfurt)
                         sessions + cache = database driver
```

## 2. Request lifecycle for a write (example: create a todo, US-02)
1. **Client.** `TodoCreateForm.vue` uses `useForm<TodoForm>({ title: '', description: null, due_date: null })` and calls `form.submit(store())`, where `store` is the Wayfinder helper for `todos.store`. Inertia sends `POST /todos` as an XHR with `X-Inertia: true` and the `X-XSRF-TOKEN` header taken from the `XSRF-TOKEN` cookie (R-002 E3).
2. **Middleware.**
   - Global `SecurityHeaders`: HSTS, basic CSP, `nosniff`, `X-Frame-Options`, `Referrer-Policy`; outside `local`/`testing` a plain-HTTP request gets 308 to `https` (security.md T-13, T-24).
   - `web`: session start, CSRF check via `PreventRequestForgery` (a mismatch gives 419, handled in §2a), `HandleInertiaRequests`, `ThrottleAuthEndpoints` (acts only on the Fortify routes listed in contracts §1.1).
   - `auth`: a guest is redirected to `/login` (US-01.AC1, US-05.AC4). `auth.session`: a session whose password hash is stale (password changed elsewhere) is logged out (security.md T-06).
   - `verified`: an unverified user is redirected to `/email/verify` (US-01.AC2).
   - The status of every redirect depends on the request type: see contracts §1.1.1.
   - `throttle:todo-writes`: over the limit gives 429.
   - Routes with `{todo}` also get **`can:update,todo` / `can:delete,todo`**. A non-owner gets **403**, before validation runs (US-04.AC3, US-05.AC3, US-06.AC3).
3. **FormRequest.** `StoreTodoRequest::rules()` comes from `TodoValidationRules`. If validation fails, Laravel redirects **back** (302) and flashes `errors` to the session. On the next GET, Inertia shares them as `page.props.errors`. Inertia never receives a 422 (R-002 E5). On the client, `form.errors.title` renders next to the field, linked by `aria-describedby`. Typed input is preserved.
4. **Controller.** `$request->user()->todos()->create($request->validated())` sets `user_id` from the relationship, never from input (US-02.AC5). Then:
   ```php
   Inertia::flash('toast', ['type' => 'success', 'message' => 'Todo added.']);
   return back(fallback: route('todos.index'));   // previous URL, so ?filter= is preserved (US-07.AC3)
   ```
5. **Redirect status (G2-6).**
   - POST keeps **302**, which browsers already follow with a GET.
   - For **PUT, PATCH and DELETE**, the Inertia middleware rewrites 302 to **303**, so the follow-up request is a GET (R-002 E28). It does this **only for requests that carry `X-Inertia: true`** (`Middleware.php:162` returns early, the rewrite is at `:179`, inertia-laravel 3.7.0). A plain HTTP client gets 302. The full table is contracts §1.1.1, and write tests send the header.
6. **Follow-up GET `/todos?filter=…`.** `TodoController@index` builds the props: `todos` (resolved resource array), `counts` and `filter`. It returns `Inertia::render('todos/Index', …)` as a JSON page object.
7. **Client.** Inertia swaps the props. The `flash` event fires once, and the kit's `initializeFlashToast()` shows a toast (R-002 E12); flash data is not replayed from history. In `onSuccess`, the form resets `title` and focuses the title input (US-02.AC6). Toggle and delete use `preserveScroll: true`.

### 2a. Expired CSRF token (419)
`bootstrap/app.php` gets the official recipe (R-002 E3; https://inertiajs.com/docs/v3/security/csrf-protection), adapted to the kit's flash mechanism. If `$response->getStatusCode() === 419`, the handler calls `Inertia::flash('toast', ['type' => 'warning', 'message' => 'The page expired, please try again.'])` and returns `back()`. The user sees a toast instead of the error modal.

## 3. Read path (US-03, US-07)
`GET /todos?filter=active` → `TodoFilter::fromQuery($request->query('filter'))` (an unknown value falls back to `All`, with no error, US-07.AC2). The controller then runs:
- **Query 1:** `$user->todos()->tap($filter->apply(...))->orderByDesc('created_at')->orderByDesc('id')->get()`.
- **Query 2:** `$user->todos()->toBase()->selectRaw('count(*) as total, count(completed_at) as completed')->first()`.

There are two todo queries, whatever the number of todos (US-03.AC6). In the testing environment with `actingAs()` that is the whole budget: **exactly 2 queries** (contracts §3; the T-001 spike confirms the number). In production the session and user lookups add a fixed number.

**Overdue is computed in the browser (G2-13).** The server sends only `due_date` (`YYYY-MM-DD`) and `completed_at`. `resources/js/lib/overdue.ts` exports the pure functions `localToday()` and `isOverdue(dueDate, completedAt, today)`, and `TodoItem` shows the "Overdue" badge from them (contracts §5.3). Reason: a server-side UTC "today" marks todos overdue up to a day late or early for users outside UTC, including the owner's region (UTC+3). The logic is unit-tested with `vp test`, with no new dependency (ADR-0003).

Filter links are Inertia `<Link>`s to the same page component, using `only: ['todos', 'filter']` and `preserveState`. Counts don't depend on the filter, so they are not reloaded.

## 4. Boundaries
| Boundary | Rule |
|---|---|
| Browser ↔ server | Inertia only, over HTTPS (HSTS, security.md T-13, T-24). Props are an explicit allow-list (Resource `toArray`). Shared props are limited to the kit's set plus `flash`, and `auth.user` is an explicit key list (contracts.md §4) |
| Auth | Fortify and the kit own **all** authentication code. Todo code reads only `$request->user()` |
| Authorisation | `TodoPolicy` only, attached as route middleware (`->can()`). There are no inline `abort_if` ownership checks, and no `authorize()` logic in FormRequests (they return `true`, because the middleware has already run) |
| Validation | FormRequests only, sharing the `TodoValidationRules` trait |
| Persistence | Eloquent only. No `DB::` facade in controllers (arch test) |
| Tenancy | Every todo query starts from `$request->user()->todos()`. Route-model binding plus the policy covers `{todo}` routes |
| Agents ↔ production | Agents have no Cloud account and no hook URLs, and act as the bot GitHub identity, whose token cannot write workflows (ADR-0006, security.md T-18) |
| Repository ↔ workflows | Public repo. Default `GITHUB_TOKEN` read-only, minimal per-workflow `permissions`, no `pull_request_target`, SHA-pinned actions, staging hook only in the `staging` environment limited to `main`. Checked by CI `workflow-lint` (security.md T-18) |

## 5. Directory layout: what we add
Everything else is the unmodified kit (R-001 E12–E17), apart from the edits listed here and in ADR-0001.

```
app/
  Concerns/TodoValidationRules.php           # shared rules (matches kit's ProfileValidationRules)
  Concerns/PasswordValidationRules.php       # kit file, edit: 72-byte maximum counted with strlen, also on currentPasswordRules() (security.md T-23)
  Enums/TodoFilter.php                       # All|Active|Completed, fromQuery(), apply()
  Http/Controllers/TodoController.php        # index, store, update, destroy
  Http/Controllers/TodoCompletionController.php  # update (sets/clears completed_at)
  Http/Requests/StoreTodoRequest.php
  Http/Requests/UpdateTodoRequest.php
  Http/Requests/UpdateTodoCompletionRequest.php
  Http/Resources/TodoResource.php            # 7 keys, no is_overdue (contracts §5.1)
  Http/Middleware/SecurityHeaders.php        # GLOBAL: HSTS, basic CSP, nosniff, XFO, Referrer-Policy,
                                             #   X-Powered-By removal, HTTP→HTTPS 308 (T-13, T-24)
  Http/Middleware/ThrottleAuthEndpoints.php  # web group: auth-public / auth-confirm limiters (T-08)
  Http/Middleware/HandleInertiaRequests.php  # kit file, edit: auth.user allow-list (T-04)
  Http/Responses/LogoutResponse.php          # Fortify LogoutResponse + Inertia::clearHistory() (T-07);
                                             #   bound in kit FortifyServiceProvider (auth change → owner approval)
  Models/Todo.php                            # + User::todos() hasMany (edit to kit User)
  Policies/TodoPolicy.php                    # update, delete via $todo->user()->is($user) (auto-discovered)
  Providers/AppServiceProvider.php           # edit: strictness flags; Password::min(12)->uncompromised();
                                             #   URL::forceHttps(); limiters todo-writes, auth-public, auth-confirm
bootstrap/app.php                            # edit: 419 → back + toast; global SecurityHeaders; web append
                                             #   ThrottleAuthEndpoints; trustProxies (Cloud); QueryException report()
app/Http/Controllers/Settings/ProfileController.php  # edit: Inertia::clearHistory() in destroy (T-07)
config/auth.php                              # edit: guards.web.remember = 43200 (T-06)
config/fortify.php                           # edit: home => '/todos'
config/inertia.php                           # edit: history.encrypt and ssr.enabled from env (T-07)
database/migrations/xxxx_create_todos_table.php
database/factories/TodoFactory.php
database/seeders/StagingSeeder.php           # env password, no factories, refuses production (T-25)
routes/web.php                               # edit: todo routes; dashboard route removed; auth.session
routes/settings.php                          # edit: auth.session on the kit's authenticated groups (T-06)
resources/js/
  pages/todos/Index.vue
  components/todos/{TodoCreateForm,TodoList,TodoItem,TodoEditDialog,TodoDeleteDialog,TodoFilterNav,TodoEmptyState}.vue
  lib/overdue.ts, lib/overdue.spec.ts        # isOverdue(), localToday() + vp test (G2-13); *.spec.ts is QA-owned (N-1)
  types/todo.ts                              # + re-export from types/index.ts
  types/global.d.ts                          # edit: InertiaConfig.flashDataType
  composables/useAppearance.ts:40, components/ui/sidebar/SidebarProvider.vue:34  # edit: `; Secure` on HTTPS (security.md W-1)
scripts/check-raw-html.mjs, scripts/raw-html-allowlist.json   # NFR-03 (ADR-0003)
scripts/check-workflows.mjs                  # workflow-lint (security.md T-18, ADR-0003)
.github/workflows/{ci.yml,deploy-staging.yml,nightly.yml}     # T-002 (owner-reviewed; bot cannot push these)
.github/dependabot.yml                       # composer, npm, github-actions; cooldown; Inertia group
tests/
  Feature/Todos/{IndexTest,StoreTest,UpdateTest,CompletionTest,DestroyTest,FilterTest,QueryCountTest,AuthorizationTest,RateLimitTest}.php
  Feature/Security/{SharedPropsTest,DevtoolsTest,DatabaseDriverTest,BoostConfigTest,HistoryTest,SecurityHeadersTest,HttpsRedirectTest,SessionCookieTest,SessionLifetimeTest,CsrfConfigTest,CsrfHandlerTest,PasswordPolicyTest,AuthRateLimitTest,QueryExceptionLoggingTest}.php
  Feature/Seeders/StagingSeederTest.php
  Feature/HealthTest.php
  Unit/TodoFilterTest.php
  Arch/ArchTest.php
  Browser/{TodoJourneyTest,AccessibilityTest,AuthJourneyTest,ResponsiveTest}.php
  coverage-dirs.php                          # per-directory clover threshold
  fixtures/raw-html/*.vue, fixtures/workflows/*.yml   # fixtures for the two CI scripts
```

### 5.1 Dashboard removal: complete file list (G2-8, G2-9)
`/todos` becomes the home page. Every place the kit references the Dashboard (found with `grep -rn dashboard resources/js app routes config tests` in the kit spike, QA review F-03):

| File | Change | What catches a miss |
|---|---|---|
| `routes/web.php` | Remove `Route::inertia('dashboard', 'Dashboard')->name('dashboard')` | contracts §1.2 assertion (`dashboard` route count = 0) |
| `config/fortify.php` | `'home' => '/todos'` | `US-01.AC3` tests |
| `resources/js/pages/Dashboard.vue` | Delete | `npm run build` (no importer left) |
| `resources/js/components/AppSidebar.vue` | Replace the `dashboard` import and both uses (nav item and logo link) with `index` from `@/routes/todos`; nav item label `Todos` | `npm run build`, `types:check` (Wayfinder no longer exports `dashboard`) |
| `resources/js/components/AppHeader.vue` | Same as `AppSidebar.vue` (nav item and logo link) | `npm run build`, `types:check` |
| `resources/js/pages/Welcome.vue` | The "Dashboard" link for logged-in visitors points to `index()` from `@/routes/todos`, text `Todos` | `npm run build`, `types:check` |
| `resources/js/components/PasskeyVerify.vue` | Line 33: replace the string fallback `'/dashboard'` in `router.visit(response.redirect ?? '/dashboard')` with `index().url` from `@/routes/todos` | **No tool catches this one** (a plain string). The task's handoff must show `grep -rn "dashboard" resources/js app routes config` returning nothing; QA adds a browser or feature check that passkey login lands on `/todos` when that is feasible (T-001 spike) |
| `tests/Feature/DashboardTest.php` | **Deleted** under the one-off owner exception (G2-8), replaced 1:1 by `IndexTest` `US-01.AC1` (guest → login) and a test that a verified user gets 200 with component `todos/Index` | QA verification; the exception is listed under `human_approval_required` in the task file |
| `tests/Feature/Auth/AuthenticationTest.php`, `RegistrationTest.php`, `EmailVerificationTest.php` (3 assertions), `VerificationNotificationTest.php` | Retarget the 6 assertions from `route('dashboard')` to `route('todos.index')` (QA only, same exception) | The suite fails until retargeted |

These changes go in one dedicated task (QA for the tests, engineer for the rest), separate from the todo features.

## 6. Cross-cutting decisions (summary)
| Concern | Decision | Where |
|---|---|---|
| Write responses | Redirect back (303 for PATCH and DELETE **on Inertia requests**, 302 otherwise) + `Inertia::flash('toast', …)` | §2, contracts §1.1.1 |
| Completion | `completed_at` timestamp, idempotent "set state" endpoint | ADR-0005 |
| Listing | No pagination, no per-user cap (G2-12); 2 todo queries | ADR-0004 |
| Props | `TodoResource::collection(...)->resolve()`, one strict shape test per page; `auth.user` key allow-list | ADR-0002, contracts §4 |
| Strictness | `preventLazyLoading` and `preventSilentlyDiscardingAttributes` outside production | ADR-0002, ADR-0003 |
| Dates | Timestamps stored in UTC. `due_date` is a calendar `DATE`. **Overdue is computed in the browser** from the user's local date (G2-13) | data-model.md, contracts §5.3 |
| Transport and headers | HTTPS enforced, HSTS 1 year, `__Host-` session cookie, basic CSP, global headers middleware (G2-1, A-11, A-12) | security.md T-06, T-13, T-24 |
| Passwords | `Password::min(12)->uncompromised()`, at most 72 bytes counted with `strlen` (G2-3), `BCRYPT_LIMIT=72` backstop | security.md T-23 |
| Abuse limits | `todo-writes` 120/min/user (G2-15); `auth-public` and `auth-confirm` on Fortify endpoints (G2-4, A-06) | contracts §1.1, security.md T-08 |
| Repository and CI | Public repo `github.com/samcessy/todo` (G2-10); bot token without workflow scope; read-only default `GITHUB_TOKEN`; branch protection on `main`; production only by the owner-held hook | ADR-0006, security.md T-16 to T-18 |
| Optimistic UI | Not in the MVP. Revisit for the completion checkbox if warm p95 exceeds 150 ms (R-002 §Inertia v3) | — |
| Deferred props, prefetch, SSR | Not used (R-002 Recommendation 5). `INERTIA_SSR_ENABLED=false` set explicitly (A-19) | security.md T-07 |

## 7. Story → component trace (Must stories)
| Story | Server | Client |
|---|---|---|
| US-01 Account | Fortify + kit settings (unchanged), `fortify.home=/todos`, `auth`+`verified` on todo routes | kit auth and settings pages |
| US-02 Create | `todos.store`, `StoreTodoRequest` | `TodoCreateForm` |
| US-03 View | `todos.index`, `TodoResource`, counts query | `todos/Index`, `TodoList`, `TodoItem`, `TodoEmptyState`, `lib/overdue.ts` |
| US-04 Edit | `todos.update`, `UpdateTodoRequest`, `can:update,todo` | `TodoEditDialog` |
| US-05 Complete | `todos.completion.update`, `can:update,todo` | `TodoItem` checkbox |
| US-06 Delete | `todos.destroy`, `can:delete,todo` | `TodoDeleteDialog` (reka-ui Dialog) |
| US-07 Filter (Should) | `todos.index?filter=`, `TodoFilter` | `TodoFilterNav` (`aria-current`) |

The AC-level trace, including `CC.AC1` to `CC.AC3`, is in `contracts.md` §7. The UI contract (accessible names, texts, focus targets) is `contracts.md` §6.1.

## 8. G2 decisions (recorded 2026-11-17)
The open questions Q1 to Q7 of the first draft were decided at G2. They are no longer open.

| Former Q | Decision | Where it is implemented |
|---|---|---|
| Q1 Dashboard | **G2-9:** remove the Dashboard page and route; `/todos` is home. **G2-8:** one-off owner exception to delete `DashboardTest` and retarget 6 kit assertions | §5.1, contracts §1.1, ADR-0001 |
| Q2 GitHub plan and visibility | **G2-10:** public repository `github.com/samcessy/todo`. Branch protection and environment secrets are free on public repos | ADR-0006, security.md T-15 to T-18 |
| Q3 Error tracking | **G2-11:** decided alongside R-006, before G5, with the redaction review of A-09 | ADR-0006 Observability, security.md T-12 |
| Q4 Per-user cap | **G2-12:** no cap; write rate limiting is the brake | ADR-0004, security.md T-08 |
| Q5 Overdue "today" | **G2-13:** computed in the browser from the user's local date (`lib/overdue.ts`, `vp test`) | §3, contracts §5.3, data-model.md, ADR-0003 |
| Q6 Browser-test dependencies | **G2-14:** `pestphp/pest-plugin-browser` and `playwright` approved by accepting ADR-0003 | ADR-0003 |
| Q7 Write rate limit | **G2-15:** `todo-writes` = 120 per minute per user | contracts §1.1 |

**Still open (not G2 questions):** R-006 mail provider and the error-tracking choice (both before G5); the T-001 spike items listed in ADR-0003 and contracts §1.1.1, §1.2, §3, plus security.md T-24 (the `HttpsRedirectTest` production switch, and Cloud's `/up` health check with the HTTPS redirect).

## 9. Review dispositions (G2-16)
Every security advisory (A-01 to A-19) and QA finding (F-01 to F-22) is folded into the docs, except the items in "Rejected review items" below. Blockers B-01 to B-05 are G2-1 to G2-5.

| Item | Where it is addressed |
|---|---|
| B-01 HSTS, HTTPS, `__Host-` | security.md T-06, T-13, T-24 |
| B-02 ASVS version | security.md header and §3 (ASVS 5.0.0 L1), waiver W-1 |
| B-03 Password composition | security.md T-23 |
| B-04 Public endpoint throttles | security.md T-08, contracts §1.1 |
| B-05 Workflow and token posture | security.md T-18, ADR-0006 |
| A-01 IDOR test generation, 404 case, policy comparison | security.md T-01, contracts §1.2 |
| A-02 / F-06 CSRF test | security.md T-05 |
| A-03 DevTools 404, `APP_ENV` | security.md T-11 |
| A-04 `auth.user` exact keys | security.md T-04, contracts §4, ADR-0002 |
| A-05 Remember-me 30 days, `auth.session` | security.md T-06, contracts §1.1 |
| A-06 Password-oracle throttles | security.md T-08 |
| A-07 PII in logs | security.md T-12 |
| A-08 No `log` mailer in production | security.md T-12, ADR-0006 (G5 hard item; the boot assertion is rejected, below) |
| A-09 Monitoring redaction | security.md T-12, ADR-0006 |
| A-10 Staging seeder | security.md T-25, data-model.md §4 |
| A-11 Basic CSP | security.md T-13 |
| A-12 Global headers, `X-Powered-By` | security.md T-13 |
| A-13 Proxies, `APP_URL`, passkey secret | security.md T-09, T-24, ADR-0006 |
| A-14 Shared staging domain | security.md T-05, T-06; resolved: `laravel.cloud` is on the Public Suffix List (security.md W-1, re-check 2026-11-17) |
| A-15 Supply chain, cooldown, SHA pins, `persist-credentials` | security.md T-14, T-18, ADR-0006 |
| A-16 Hook custody runbook | security.md T-17, ADR-0006 (governance wording: orchestrator) |
| A-17 Boost key names | security.md T-19 |
| A-18 `validated('completed')`, multibyte test | contracts §2, security.md T-02 |
| A-19 History encryption and SSR env | security.md T-07 |
| F-01 Status per request type | contracts §1.1.1 |
| F-02 UI contract | contracts §6.1 |
| F-03 Dashboard removal and test exception | §5.1 |
| F-04 `CC.AC1`–`CC.AC3` IDs | contracts §7, coding-standards §4 (the user-stories edit is the orchestrator's) |
| F-05 NFR-05 instrument | ADR-0006 §NFR-05 |
| F-07 Query budget | contracts §3, §3 above |
| F-08 Test suites per CI job | ADR-0003 |
| F-09 Browser plugin APIs | ADR-0003 T-001 spike list |
| F-10 Focus after delete, completion, edit, filter | contracts §6.1 focus targets |
| F-11 Empty state without autofocus | contracts §6, §6.1 (U-20, F-a, F-l) |
| F-12 Accessibility scan states | ADR-0003 |
| F-13 US-01.AC6 tests | contracts §7, security.md T-09 |
| F-14 Toggle vs desired state | contracts §1.1 notes |
| F-15 Timestamp format | contracts §5.1 |
| F-16 Partial update | contracts §2 |
| F-17 All link URL | contracts §3 |
| F-18 Boolean values | contracts §2 |
| F-19 Factory closures, time freezing | data-model.md §4, coding-standards §4 |
| F-20 Real 5-attempt test | security.md T-08, contracts §7 |
| F-21 UTC overdue guard | Superseded by G2-13 (no server-side "today"); the browser test uses `2000-01-01` as F-21 proposed |
| F-22 Coverage metric name | ADR-0003 |
| N-01 (architect, new) bcrypt 72-byte truncation, ASVS 6.2.8 | security.md T-23 (owner confirms at the re-check) |

### Rejected review items
| Item | Part rejected | Reason |
|---|---|---|
| G2-5 wording "fine-grained token" (from B-05) | The token **type** only | GitHub's docs (fetched 2026-11-17) state that fine-grained PATs cannot be used by a repository collaborator, and only classic PATs can write to a public repo the user does not own. The bot is a collaborator on `samcessy/todo`, so a fine-grained PAT cannot work. A classic PAT with only `public_repo` (no `workflow` scope) gives the decided effect: contents and PRs, no workflow writes, verified by a negative push test (security.md T-18). The alternatives (moving the repo to an organisation, or a GitHub App) would change the G2-10 URL or add a private key to manage. **The owner confirms this substitution at the re-check** |
| QA F-02 proposal: checkbox name `Mark "<title>" as active` for completed todos | The second, state-dependent name | A checkbox already announces its state ("checked"). A name that flips with the state reads as "Mark X as active, checked", which is ambiguous for screen-reader users, and it makes the test locator change after each toggle. contracts §6.1 U-09 uses one constant name, `Mark "<title>" complete`, with `checked` = completed |
| A-08 optional boot-time assertion that `MAIL_MAILER` ≠ `log` in production | The boot assertion | A boot-time exception takes the whole production site down on a configuration mistake, including `/up`, which then fails the Cloud health check mid-deploy. The hard G5 go/no-go item (ADR-0006) is checked by the owner before every production deploy, so the misconfiguration is caught without a runtime failure mode |
| B-05 item 4: "a ruleset restricting pushes to `.github/**`" ("if the plan allows it") | The ruleset | Push rulesets are available only for private and internal repositories (GitHub docs, fetched 2026-11-17). The repo is public (G2-10). The bot token's missing `workflow` scope is the control instead |
| F-21 guard test `config('app.timezone') === 'UTC'` | The guard test | Overdue no longer depends on the server timezone (G2-13). Timestamps are serialised with an explicit offset (contracts §5.1), so the guard would protect nothing that a test depends on |
