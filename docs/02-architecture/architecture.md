# Architecture

**Status:** Draft for G2 · **Author:** architect agent · **Date:** 2026-11-16
**Decisions:** ADR-0001 to ADR-0006 (all Proposed). **Related:** `data-model.md`, `contracts.md`, `security.md`, `coding-standards.md`.

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
│ routes/web.php ─▶ middleware: web(session, CSRF, HandleInertiaRequests) ▸ auth ▸ verified ▸ can:…,todo ▸ throttle │
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
   - `web`: session start, CSRF check (a mismatch gives 419, handled in §2a), `HandleInertiaRequests`.
   - `auth`: a guest gets 302 to `/login` (US-01.AC1, US-05.AC4).
   - `verified`: an unverified user gets 302 to `/email/verify` (US-01.AC2).
   - `throttle:todo-writes`: over the limit gives 429.
   - Routes with `{todo}` also get **`can:update,todo` / `can:delete,todo`**. A non-owner gets **403**, before validation runs (US-04.AC3, US-05.AC3, US-06.AC3).
3. **FormRequest.** `StoreTodoRequest::rules()` comes from `TodoValidationRules`. If validation fails, Laravel redirects **back** (302) and flashes `errors` to the session. On the next GET, Inertia shares them as `page.props.errors`. Inertia never receives a 422 (R-002 E5). On the client, `form.errors.title` renders next to the field, linked by `aria-describedby`. Typed input is preserved.
4. **Controller.** `$request->user()->todos()->create($request->validated())` sets `user_id` from the relationship, never from input (US-02.AC5). Then:
   ```php
   Inertia::flash('toast', ['type' => 'success', 'message' => 'Todo added.']);
   return back(fallback: route('todos.index'));   // previous URL, so ?filter= is preserved (US-07.AC3)
   ```
5. **Redirect status.**
   - POST keeps **302**, which browsers already follow with a GET.
   - For **PUT, PATCH and DELETE**, the Inertia middleware rewrites 302 to **303** (`Middleware.php:178`, inertia-laravel 3.7.0), so the follow-up request is a GET (R-002 E28).
6. **Follow-up GET `/todos?filter=…`.** `TodoController@index` builds the props: `todos` (resolved resource array), `counts` and `filter`. It returns `Inertia::render('todos/Index', …)` as a JSON page object.
7. **Client.** Inertia swaps the props. The `flash` event fires once, and the kit's `initializeFlashToast()` shows a toast (R-002 E12); flash data is not replayed from history. In `onSuccess`, the form resets `title` and focuses the title input (US-02.AC6). Toggle and delete use `preserveScroll: true`.

### 2a. Expired CSRF token (419)
`bootstrap/app.php` gets the official recipe (R-002 E3; https://inertiajs.com/docs/v3/security/csrf-protection), adapted to the kit's flash mechanism. If `$response->getStatusCode() === 419`, the handler calls `Inertia::flash('toast', ['type' => 'warning', 'message' => 'The page expired, please try again.'])` and returns `back()`. The user sees a toast instead of the error modal.

## 3. Read path (US-03, US-07)
`GET /todos?filter=active` → `TodoFilter::fromQuery($request->query('filter'))` (an unknown value falls back to `All`, with no error, US-07.AC2). The controller then runs:
- **Query 1:** `$user->todos()->tap($filter->apply(...))->orderByDesc('created_at')->orderByDesc('id')->get()`.
- **Query 2:** `$user->todos()->toBase()->selectRaw('count(*) as total, count(completed_at) as completed')->first()`.

There are two todo queries, whatever the number of todos (US-03.AC6), plus the fixed session and user queries from middleware.

`is_overdue` is computed in `TodoResource` from `due_date < today()` and `completed_at === null` (FR-08). Doing it server-side makes it testable with `travelTo()`; see open question Q5.

Filter links are Inertia `<Link>`s to the same page component, using `only: ['todos', 'filter']` and `preserveState`. Counts don't depend on the filter, so they are not reloaded.

## 4. Boundaries
| Boundary | Rule |
|---|---|
| Browser ↔ server | Inertia only. Props are an explicit allow-list (Resource `toArray`). Shared props are limited to the kit's set plus `flash` (contracts.md §5) |
| Auth | Fortify and the kit own **all** authentication code. Todo code reads only `$request->user()` |
| Authorisation | `TodoPolicy` only, attached as route middleware (`->can()`). There are no inline `abort_if` ownership checks, and no `authorize()` logic in FormRequests (they return `true`, because the middleware has already run) |
| Validation | FormRequests only, sharing the `TodoValidationRules` trait |
| Persistence | Eloquent only. No `DB::` facade in controllers (arch test) |
| Tenancy | Every todo query starts from `$request->user()->todos()`. Route-model binding plus the policy covers `{todo}` routes |
| Agents ↔ production | Agents have no Cloud account and no hook URLs, and act as the bot GitHub identity (ADR-0006) |

## 5. Directory layout: what we add
Everything else is the unmodified kit (R-001 E12–E17), apart from the edits in ADR-0001.

```
app/
  Concerns/TodoValidationRules.php           # shared rules (matches kit's ProfileValidationRules)
  Enums/TodoFilter.php                       # All|Active|Completed, fromQuery(), apply()
  Http/Controllers/TodoController.php        # index, store, update, destroy
  Http/Controllers/TodoCompletionController.php  # update (sets/clears completed_at)
  Http/Requests/StoreTodoRequest.php
  Http/Requests/UpdateTodoRequest.php
  Http/Requests/UpdateTodoCompletionRequest.php
  Http/Resources/TodoResource.php
  Http/Middleware/SecurityHeaders.php        # X-Frame-Options, nosniff, Referrer-Policy (security.md T-13)
  Http/Responses/LogoutResponse.php          # Fortify LogoutResponse + Inertia::clearHistory() (T-07);
                                             #   bound in kit FortifyServiceProvider (auth change → owner approval)
  Models/Todo.php                            # + User::todos() hasMany (edit to kit User)
  Policies/TodoPolicy.php                    # update, delete (auto-discovered)
  Providers/AppServiceProvider.php           # edit: strictness flags, RateLimiter::for('todo-writes')
bootstrap/app.php                            # edit: 419 → back + toast; append SecurityHeaders to web group
app/Http/Controllers/Settings/ProfileController.php  # edit: Inertia::clearHistory() in destroy (T-07)
config/fortify.php                           # edit: home => '/todos'
config/inertia.php                           # edit: history.encrypt per env (security.md)
database/migrations/xxxx_create_todos_table.php
database/factories/TodoFactory.php
routes/web.php                               # edit: todo routes; dashboard route removed (Q1)
resources/js/
  pages/todos/Index.vue
  components/todos/{TodoCreateForm,TodoList,TodoItem,TodoEditDialog,TodoDeleteDialog,TodoFilterNav,TodoEmptyState}.vue
  types/todo.ts                              # + re-export from types/index.ts
  types/global.d.ts                          # edit: InertiaConfig.flashDataType
  components/AppSidebar.vue                  # edit: nav item "Todos" → todos.index
scripts/check-raw-html.mjs, scripts/raw-html-allowlist.json   # NFR-03 (ADR-0003)
tests/
  Feature/Todos/{IndexTest,StoreTest,UpdateTest,CompletionTest,DestroyTest,FilterTest,QueryCountTest,AuthorizationTest}.php
  Feature/Security/{SharedPropsTest,DevtoolsTest,DatabaseDriverTest}.php
  Arch/ArchTest.php
  Browser/{TodoJourneyTest,AccessibilityTest}.php
  coverage-dirs.php                          # per-directory clover threshold
  fixtures/raw-html/*.vue                    # fixtures for check-raw-html
```

## 6. Cross-cutting decisions (summary)
| Concern | Decision | Where |
|---|---|---|
| Write responses | Redirect back (303 for PUT, PATCH and DELETE) + `Inertia::flash('toast', …)` | §2, contracts.md |
| Completion | `completed_at` timestamp, idempotent "set state" endpoint | ADR-0005 |
| Listing | No pagination; 2 todo queries | ADR-0004 |
| Props | `TodoResource::collection(...)->resolve()`, one strict shape test per page | ADR-0002 |
| Strictness | `preventLazyLoading` and `preventSilentlyDiscardingAttributes` outside production | ADR-0002, ADR-0003 |
| Dates | Stored in UTC. `due_date` is a calendar `DATE` | data-model.md, Q5 |
| Optimistic UI | Not in the MVP. Revisit for the completion checkbox if warm p95 exceeds 150 ms (R-002 §Inertia v3) | — |
| Deferred props, prefetch, SSR | Not used (R-002 Recommendation 5). The kit ships SSR config; we don't deploy `build:ssr` | — |

## 7. Story → component trace (Must stories)
| Story | Server | Client |
|---|---|---|
| US-01 Account | Fortify + kit settings (unchanged), `fortify.home=/todos`, `auth`+`verified` on todo routes | kit auth and settings pages |
| US-02 Create | `todos.store`, `StoreTodoRequest` | `TodoCreateForm` |
| US-03 View | `todos.index`, `TodoResource`, counts query | `todos/Index`, `TodoList`, `TodoItem`, `TodoEmptyState` |
| US-04 Edit | `todos.update`, `UpdateTodoRequest`, `can:update,todo` | `TodoEditDialog` |
| US-05 Complete | `todos.completion.update`, `can:update,todo` | `TodoItem` checkbox |
| US-06 Delete | `todos.destroy`, `can:delete,todo` | `TodoDeleteDialog` (reka-ui Dialog) |
| US-07 Filter (Should) | `todos.index?filter=`, `TodoFilter` | `TodoFilterNav` (`aria-current`) |

The AC-level trace is in `contracts.md` §7.

## Open questions for G2
| # | Question | Recommendation | Why the owner must decide |
|---|---|---|---|
| Q1 | What happens to the kit's placeholder **Dashboard** page and the `dashboard` route? | **Remove both.** Set `fortify.home` to `/todos` and point the sidebar logo and nav at `todos.index`. The QA agent updates the kit tests that assert `route('dashboard')` (`DashboardTest`, `AuthenticationTest`, verification and registration tests). Those are kit test edits, and only the QA agent may touch them | It changes kit auth tests and a kit page (governance: auth-adjacent change) |
| Q2 | **GitHub plan and repo visibility.** Branch protection and rulesets on a *private* repo may need a paid plan. The GitHub docs pages fetched today don't say this plainly (**UNVERIFIED**). Without protection, G4 rests only on the bot having no merge rights | T-002 checks the plan first. If private-repo protection isn't available: use GitHub Pro (about $4/month, price UNVERIFIED), or make the repo public, or accept "the bot can't merge" (write access only, `gh pr merge` denied) as the G4 control | Cost and visibility decision |
| Q3 | **Error tracking for NFR-10** (release tags). Nightwatch Free (EU) needs the `laravel/nightwatch` dependency | Decide in a short ticket alongside R-006, before G5. It doesn't block G3 | New dependency and subprocessor (D8) |
| Q4 | **Per-user todo cap?** Without pagination (ADR-0004), there is no upper bound | No hard cap in the MVP. Rely on write rate limiting (120/min per user) and the ADR-0004 revisit trigger | Introducing a cap would be a product rule |
| Q5 | **Which "today" defines overdue (FR-08)?** The server is UTC, so a Berlin user sees an item become overdue 1–2 hours late | Accept server-side UTC for the MVP (deterministic and feature-testable). Revisit with a per-user timezone if users report it | Product behaviour versus simplicity |
| Q6 | **Approve the two browser-test dependencies** (`pestphp/pest-plugin-browser`, `playwright`) by accepting ADR-0003? | Yes. They are the only new dependencies in the design | Governance: every dependency needs approval |
| Q7 | **Write rate limit value:** `todo-writes` = 120 requests per minute per user | Accept | Small UX trade-off. An auth-adjacent setting |
