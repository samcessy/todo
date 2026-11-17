# Contracts: routes, validation, props, UI

**Status:** Draft for G2 (revised 2026-11-17 after the G2 decisions) · **Date:** 2026-11-16, revised 2026-11-17
**Changing this file needs owner approval** (governance). Implementation must match it exactly. CI checks §1 against `php artisan route:list --json` (§1.2), and the browser tests check §6.1.

## 1. Routes

### 1.1 Route table (new todo routes)
Every route has the `web` middleware group (session, CSRF via `PreventRequestForgery`, `HandleInertiaRequests`) plus `auth`, `auth.session` and `verified` (`auth.session`: security.md T-06).

| Method | URI | Name | Controller@action | FormRequest | Extra middleware | Policy ability | Success | Failure |
|---|---|---|---|---|---|---|---|---|
| GET | `/todos` | `todos.index` | `TodoController@index` | — (query param `filter` parsed by `TodoFilter`) | — | — (query scoped to `$request->user()`) | 200 Inertia `todos/Index` (props §5.2) | guest → `/login`, unverified → `/email/verify` (statuses in §1.1.1) |
| POST | `/todos` | `todos.store` | `TodoController@store` | `StoreTodoRequest` | `throttle:todo-writes` | — (any verified user) | redirect back, flash `toast = {type:'success', message:'Todo added.'}` | redirect back with `errors`, 429 |
| PATCH | `/todos/{todo}` | `todos.update` | `TodoController@update` | `UpdateTodoRequest` | `throttle:todo-writes`, `can:update,todo` | `TodoPolicy@update` | redirect back, flash `toast = {type:'success', message:'Todo updated.'}` | 403 (not owner), 404 (no such id), redirect back with `errors`, 429 |
| PATCH | `/todos/{todo}/completion` | `todos.completion.update` | `TodoCompletionController@update` | `UpdateTodoCompletionRequest` | `throttle:todo-writes`, `can:update,todo` | `TodoPolicy@update` | redirect back, flash `toast = {type:'success', message:'Todo completed.'}` when `completed` is true (also when it was already completed), `'Todo marked as active.'` when false | 403, 404, redirect back with `errors`, 429 |
| DELETE | `/todos/{todo}` | `todos.destroy` | `TodoController@destroy` | — | `throttle:todo-writes`, `can:delete,todo` | `TodoPolicy@delete` | redirect back, flash `toast = {type:'success', message:'Todo deleted.'}` | 403, 404, 429 |

#### 1.1.1 Status code per request type (G2-6, F-01)
The Inertia middleware rewrites 302 to 303 for PUT, PATCH, DELETE (and QUERY) **only when the request carries `X-Inertia: true`**. Without the header it returns before the rewrite (`inertia-laravel/src/Middleware.php:162` returns early, the rewrite is at `:179`, v3.7.0; verified by the orchestrator and QA in the kit source).

| Outcome | Inertia request (`X-Inertia: true`), POST | Inertia request, PATCH / DELETE | Non-Inertia request (no header), any method |
|---|---|---|---|
| Success (redirect back) | **302** | **303** | **302** |
| Validation failure (redirect back with `errors`) | **302** | **303** | **302** |
| Guest (redirect to `/login`) | **302** | **303** | **302** |
| Unverified (redirect to `/email/verify`) | **302** | **303** | **302** |
| Expired CSRF token (419 handler → redirect back, architecture §2a) | **302** | **302** | **302** |
| Not the owner | 403 | 403 | 403 |
| No such id | 404 | 404 | 404 |
| Over the rate limit | 429 | 429 | 429 |

`GET /todos` as a guest or unverified user returns **302** in both cases (GET is never rewritten).

Why the rows differ: `auth`, `verified` and `can:` are route middleware, so their redirect passes back out through `HandleInertiaRequests` (the last entry of the `web` group) and is rewritten. `PreventRequestForgery` sits **outside** `HandleInertiaRequests` in the `web` group, so the 419 handler's redirect is never rewritten. The T-001 spike runs one request per row of this table against the kit and corrects any cell, with owner approval, before QA writes the status tests.

**Test convention:** feature tests for write routes send `X-Inertia: true` through one helper in `tests/Pest.php` (`inertia()` = `withHeaders(['X-Inertia' => 'true'])`) and assert the **exact** status from this table. Guest, 403, 404 and 429 tests may stay header-free (302 for the guest redirect).

**Notes**
- "Back" means `back(fallback: route('todos.index'))`. It returns to the previous URL, `/todos?filter=…`, so the filter survives (US-07.AC3). With no previous URL it goes to `todos.index`. Test: `->from('/todos?filter=active')` on the write, then `assertRedirect('/todos?filter=active')`.
- **Validation failures** are never 422 for Inertia visits. Laravel redirects back and Inertia exposes the errors as `page.props.errors` (R-002 E5).
- **Ordering:** authorisation (`can:` middleware) runs **before** validation. A non-owner therefore always gets 403, even with an invalid payload, and the todo is unchanged (US-04.AC3, US-05.AC3, US-06.AC3).
- **Route model binding** resolves `{todo}` by `id` across all users, then the policy denies. The 403 for another user's id is mandated by the ACs, and the existence oracle is an accepted, owner-visible decision (security.md T-01).
- **"Toggle" (F-14):** in the stories, "toggle" means the UI action. The endpoint takes the desired state `{completed: bool}` (ADR-0005). Authorisation tests cover both `completed: true` and `completed: false`. Re-sending `completed: true` keeps the original `completed_at` and flashes "Todo completed.".
- **Explicit routes, not `Route::resource`.** That keeps the method `PATCH` (not `PUT|PATCH`) and keeps unused actions out.

**Kit routes changed by this design (G2-8, G2-9)**
- `GET /dashboard` (`dashboard`) and `pages/Dashboard.vue` are **removed**. `fortify.home` becomes `/todos`, so `/todos` is the home after login, registration, verification and passkey login. The complete file list is in `architecture.md` §5.1.
- Fortify's `register.store`, `password.email`, `password.update`, `password.confirm.store` and the kit's `profile.destroy` get rate limits through `ThrottleAuthEndpoints` (security.md T-08). Their URIs, names and controllers are unchanged.
- All authenticated kit route groups get `auth.session` (security.md T-06).
- `GET /` (`home`, Welcome) stays public. `GET /up` is the health route.

**Named rate limiters** (in `AppServiceProvider::boot`; https://laravel.com/docs/13.x/routing#defining-rate-limiters)
```php
RateLimiter::for('todo-writes', fn (Request $request) => Limit::perMinute(120)->by((string) $request->user()?->id ?: $request->ip()));   // G2-15

RateLimiter::for('auth-public', fn (Request $request) => [                                              // G2-4, B-04
    Limit::perMinute(5)->by('ip:'.$request->ip()),
    Limit::perHour(20)->by('ip-hour:'.$request->ip()),
    Limit::perHour(5)->by('email:'.Str::lower((string) $request->input('email'))),
]);

RateLimiter::for('auth-confirm', fn (Request $request) => Limit::perMinute(6)->by('user:'.$request->user()?->id));   // A-06
```
`App\Http\Middleware\ThrottleAuthEndpoints` (appended to the `web` group) maps route names to limiters: `register.store`, `password.email`, `password.update` → `auth-public`; `password.confirm.store`, `profile.destroy` → `auth-confirm`. It delegates to the framework's `ThrottleRequests` middleware with the limiter name, so the 429 response and headers are the framework's.

### 1.2 Machine-checkable contract
CI (T-002) runs:
```bash
php artisan route:list --json --path=todos | jq -S '[.[] | {method, uri, name, action, middleware}] | sort_by(.name)'
```
It diffs the output against the block below.

**Verified by spike, 2026-11-16:** I added the `todos.index` and `todos.update` definitions from §1.3 to a copy of the kit (Laravel 13.42.0), then ran the command above. The output had exactly this shape: `"method": "GET|HEAD"` and `"PATCH"`; `"uri": "todos/{todo}"`; `"action": "App\\Http\\Controllers\\…@update"`; a `middleware` array in definition order. The `auth.session` entry is new after G2 and is confirmed by the T-001 spike (the alias may print as `auth.session` or as the class name; the block is updated to the printed form in that PR, with owner approval).

Changes to this block need owner approval and must not loosen the check.

```json
[
  {"method": "PATCH",    "uri": "todos/{todo}/completion", "name": "todos.completion.update", "action": "App\\Http\\Controllers\\TodoCompletionController@update", "middleware": ["web", "auth", "auth.session", "verified", "throttle:todo-writes", "can:update,todo"]},
  {"method": "DELETE",   "uri": "todos/{todo}",            "name": "todos.destroy",           "action": "App\\Http\\Controllers\\TodoController@destroy",          "middleware": ["web", "auth", "auth.session", "verified", "throttle:todo-writes", "can:delete,todo"]},
  {"method": "GET|HEAD", "uri": "todos",                   "name": "todos.index",             "action": "App\\Http\\Controllers\\TodoController@index",            "middleware": ["web", "auth", "auth.session", "verified"]},
  {"method": "POST",     "uri": "todos",                   "name": "todos.store",             "action": "App\\Http\\Controllers\\TodoController@store",            "middleware": ["web", "auth", "auth.session", "verified", "throttle:todo-writes"]},
  {"method": "PATCH",    "uri": "todos/{todo}",            "name": "todos.update",            "action": "App\\Http\\Controllers\\TodoController@update",           "middleware": ["web", "auth", "auth.session", "verified", "throttle:todo-writes", "can:update,todo"]}
]
```
Also asserted:
- `php artisan route:list --json | jq '[.[] | select(.name == "dashboard")] | length'` returns `0`.
- Every route whose URI contains `{todo}` has a `can:` middleware (a Pest test, security.md T-01).

### 1.3 Route definition (reference)
```php
Route::middleware(['auth', 'auth.session', 'verified'])->group(function () {
    Route::get('todos', [TodoController::class, 'index'])->name('todos.index');

    Route::middleware('throttle:todo-writes')->group(function () {
        Route::post('todos', [TodoController::class, 'store'])->name('todos.store');
        Route::patch('todos/{todo}', [TodoController::class, 'update'])->name('todos.update')->can('update', 'todo');
        Route::delete('todos/{todo}', [TodoController::class, 'destroy'])->name('todos.destroy')->can('delete', 'todo');
        Route::patch('todos/{todo}/completion', [TodoCompletionController::class, 'update'])
            ->name('todos.completion.update')->can('update', 'todo');
    });
});
```

## 2. Validation rules
These live in `App\Concerns\TodoValidationRules`. Store and Update use the same rules (US-04.AC2).

| Field | Rules | Notes / AC |
|---|---|---|
| `title` | `['required', 'string', 'max:255']` | Laravel's global `TrimStrings` and `ConvertEmptyStringsToNull` middleware turn whitespace-only input into `null`, so `required` fails. The message must be exactly **"The title field is required."** (Laravel default `validation.required`; US-02.AC2). `max:255` counts characters, covering US-02.AC3 |
| `description` | `['nullable', 'string', 'max:5000']` | FR-02 |
| `due_date` | `['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2099-12-31']` | US-02.AC4. The format matches `<input type="date">`. The bounds keep out values that the two drivers handle differently (for example year 0000), and the pgsql CI job verifies this. Past dates are allowed, because a todo can legitimately be overdue |
| `completed` (completion endpoint only) | `['required', 'boolean']` | US-05. The UI sends a JSON `true` or `false`. **Accepted (F-18):** `true`, `false`, `1`, `0`, `"1"`, `"0"`. **Rejected:** `"true"`, `"false"`, `"yes"`, `2`, `null`, missing. The controller reads `(bool) $request->validated('completed')`, never raw input (A-18) |

- **Partial update semantics for `PATCH /todos/{todo}` (F-16):** `title` is always required. An omitted `description` or `due_date` is absent from `validated()` and stays **unchanged**; an explicit `null` (or an empty string, converted to `null`) **clears** it. The edit dialog always sends all three fields.
- Any other input key (`user_id`, `completed_at`, `id`) is **not in `rules()`**. It is therefore absent from `validated()`, and is never mass-assigned (US-02.AC5). `preventSilentlyDiscardingAttributes` would surface a mistake in tests.
- `authorize()` returns `true` in every todo FormRequest. Authorisation is the route `can:` middleware (architecture §4).
- **Multibyte check (A-18):** the pgsql job runs `US-02.AC3` with a 255-character multibyte title (accepted) and a 256-character one (rejected), and a 5,001-character description (rejected).

## 3. Filter semantics (US-07)
| `?filter=` value | Meaning | SQL condition | `filter` prop |
|---|---|---|---|
| absent, `all` | everything | — | `'all'` |
| `active` | incomplete | `completed_at IS NULL` | `'active'` |
| `completed` | complete | `completed_at IS NOT NULL` | `'completed'` |
| anything else (`foo`, `ACTIVE`, an array `filter[]=x`, empty) | falls back to `all`, **with no error and no redirect** (US-07.AC2) | — | `'all'` |

- Implementation: `enum TodoFilter: string { case All = 'all'; case Active = 'active'; case Completed = 'completed'; }`, plus `fromQuery(mixed $v): self`, which does `is_string($v) ? self::tryFrom($v) ?? self::All : self::All`. Matching is case-sensitive.
- The list is always ordered `created_at DESC, id DESC` (US-03.AC2). `id` breaks ties for todos created in the same second.
- `counts` **ignore the filter**: they always cover all of the user's todos (US-03.AC4).
- The URL is the source of truth for the filter (US-07.AC3). **Link targets (F-17):** All → `/todos` (no query string), Active → `/todos?filter=active`, Completed → `/todos?filter=completed`. Links are Inertia `<Link>`s built with Wayfinder (`index()` and `index({ query: { filter } })`), with `only: ['todos', 'filter']`, `preserveState`, `preserveScroll`, and `aria-current="page"` on the active link (US-07.AC4).
- **Query budget (F-07, US-03.AC6):** with `actingAs()` and the testing environment (`SESSION_DRIVER=array`, `CACHE_STORE=array`), `GET /todos` (any filter) executes **exactly 2** queries: the list query and the count query (architecture §3). The T-001 spike confirms or corrects the number before G3, with owner approval for any change. Until then QA writes only the 1-vs-100 equality test.

## 4. Shared props used (from the kit's `HandleInertiaRequests`)
| Prop | Type | Source | Used for |
|---|---|---|---|
| `name` | `string` | `config('app.name')` | layout title |
| `auth.user` | `User` (kit type) | **explicit allow-list** `$request->user()?->only(['id', 'name', 'email', 'email_verified_at'])` (security.md T-04, A-04). T-001 adds any key the kit pages read, by an owner-approved change to this row | nav user menu, profile page |
| `sidebarOpen` | `boolean` | cookie | kit layout |
| `errors` | `Record<string, string>` | Inertia (session errors) | form field errors |
| `flash.toast` (page-level `flash`, not a prop) | `FlashToast` (`{ type: 'success'|'info'|'warning'|'error'; message: string }`, kit `types/ui.ts`) | `Inertia::flash('toast', …)` | toast through the kit's `initializeFlashToast()` |

**Contract test:** `SharedPropsTest` asserts the exact key set of `auth.user` (no `etc()`).

No new shared props are added. The only edit to `resources/js/types/global.d.ts`:
```ts
declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: { name: string; auth: Auth; sidebarOpen: boolean; [key: string]: unknown };
        flashDataType: { toast?: FlashToast };   // added (R-002 §TypeScript, E29)
    }
}
```

## 5. Page props: TypeScript (`resources/js/types/todo.ts`)
This uses `export type`, matching the kit's own `types/*.ts` convention (coding-standards.md). The file is re-exported from `types/index.ts`.

### 5.1 Types
```ts
export type TodoFilter = 'all' | 'active' | 'completed';

/** Wire shape produced by App\Http\Resources\TodoResource (resolved, no `data` wrapper — ADR-0002). */
export type Todo = {
    id: number;
    title: string;
    description: string | null;
    due_date: string | null;      // 'YYYY-MM-DD' (calendar date, no timezone)
    completed: boolean;
    completed_at: string | null;  // 'Y-m-d\TH:i:sP', UTC, e.g. '2026-03-10T12:00:00+00:00'
    created_at: string;           // same format
};
// There is no `is_overdue` field: overdue is computed in the browser from the user's local date (G2-13, §5.3).

export type TodoCounts = {
    total: number;
    active: number;
    completed: number;
};

/** Props of pages/todos/Index.vue (component name 'todos/Index'). */
export type TodosIndexProps = {
    todos: Todo[];
    counts: TodoCounts;
    filter: TodoFilter;
};

/** useForm<TodoForm>() payload for create and edit (field names = validation keys, §2). */
export type TodoForm = {
    title: string;
    description: string | null;
    due_date: string | null;
};

/** Payload for PATCH /todos/{todo}/completion. */
export type TodoCompletionForm = {
    completed: boolean;
};
```
**Timestamp format (F-15):** `completed_at` and `created_at` are produced with Carbon `toIso8601String()`, which gives `Y-m-d\TH:i:sP`, for example `2026-03-10T12:00:00+00:00` (no fractional seconds, `+00:00` rather than `Z`).

### 5.2 Server side of `todos/Index`
```php
return Inertia::render('todos/Index', [
    'todos'  => TodoResource::collection($todos)->resolve(),
    'counts' => ['total' => $total, 'active' => $total - $completed, 'completed' => $completed],   // ints
    'filter' => $filter->value,
]);
```
**Contract test:** one strict `assertInertia` per page. It uses `component('todos/Index')`, `has('todos', n)`, `has('todos.0', fn ($t) => $t->hasAll([...7 keys]))` **without `etc()`**, plus `where('counts.total', …)`, `where('filter', …)`, `missing('todos.0.user_id')` and `missing('todos.0.is_overdue')`. A field added on one side only then fails CI (R-002 red-team finding 1). Counts are cast to `(int)` explicitly, because the type of a raw aggregate can differ between PDO drivers (**UNVERIFIED** for pdo_pgsql on PHP 8.4). The `where('counts.total', 3)` strict comparison in the pgsql CI job catches any difference.

### 5.3 Overdue (FR-08, US-03.AC5, G2-13)
Overdue is computed **in the browser from the user's local calendar date**, by a pure function:

```ts
// resources/js/lib/overdue.ts
/** 'YYYY-MM-DD' of `now` in the browser's local timezone. */
export function localToday(now: Date = new Date()): string;

/** True when the todo has a due date before `today` and is not completed. All dates are 'YYYY-MM-DD'. */
export function isOverdue(dueDate: string | null, completedAt: string | null, today: string): boolean;
// = dueDate !== null && completedAt === null && dueDate < today   (lexical order of ISO dates = calendar order)
```
- `pages/todos/Index.vue` computes `today = localToday()` once per page render and passes it to `TodoItem`. A page left open across midnight updates on the next visit (accepted).
- **Unit tests** (`resources/js/lib/overdue.spec.ts`, QA-owned per coding-standards §4, `vp test run`, imports from `vite-plus/test`): due yesterday and active → true; due today → false; due tomorrow → false; due yesterday and completed → false; null due date → false; month and year boundaries (`2026-02-28` vs `2026-03-01`, `2025-12-31` vs `2026-01-01`); `localToday(new Date(2026, 2, 10, 0, 30))` → `'2026-03-10'` and `localToday(new Date(2026, 2, 9, 23, 59))` → `'2026-03-09'` (the local-time constructor makes these deterministic in any CI timezone).
- **Browser test** `US-03.AC5`: a todo due `2000-01-01` shows the text "Overdue"; one due `2099-12-31` and a completed one due `2000-01-01` do not. No time travel is needed.

## 6. Pages and components
| Page / component | Route(s) | Notes |
|---|---|---|
| `pages/todos/Index.vue` | `todos.index` | `defineProps<TodosIndexProps>()`. Uses `AppLayout`, breadcrumb "Todos". Computes `localToday()` (§5.3). **No autofocus on load** (F-11) |
| `components/todos/TodoCreateForm.vue` | `todos.store` | `useForm<TodoForm>`. On success: `reset()`, then focus the title input (US-02.AC6). Not `<Form disable-while-processing>`, because `inert` removes focus (R-002 risk row) |
| `components/todos/TodoList.vue` | — | `<ul>` of `TodoItem`s, in prop order |
| `components/todos/TodoItem.vue` | `todos.completion.update` | Labelled shadcn `Checkbox`, Space toggles (US-05.AC5), `router.patch(…, { completed: !todo.completed }, { preserveScroll: true })`. Shows the "Overdue" badge when `isOverdue(todo.due_date, todo.completed_at, today)` (§5.3) |
| `components/todos/TodoEditDialog.vue` | `todos.update` | reka-ui Dialog with `useForm<TodoForm>`, errors linked by `aria-describedby` |
| `components/todos/TodoDeleteDialog.vue` | `todos.destroy` | reka-ui AlertDialog: focus trap, Escape closes, focus returns to the trigger on cancel (US-06.AC4). Cancel sends nothing (US-06.AC2) |
| `components/todos/TodoFilterNav.vue` | `todos.index?filter=` | `<nav>` with three `<Link>`s and `aria-current="page"`. Each shows its count (US-03.AC4) |
| `components/todos/TodoEmptyState.vue` | — | Shown when `todos.length === 0`. Texts in §6.1. In the All view it has a button that moves focus to the title input (no autofocus, F-11) |

URLs come from **Wayfinder** helpers (`@/routes/todos`, `@/actions/App/Http/Controllers/...`), never from hard-coded strings (coding-standards.md).

### 6.1 UI contract (G2-7, F-02, F-10, F-11)
Browser tests locate elements by **role and accessible name** from this table and assert the visible texts exactly. **Changing this table is a contract change** (owner approval). `<title>` below stands for the todo's title, inserted literally (escaped by Vue). `<id>` is the todo's id. All strings are English (A2).

**Elements**
| # | Element | Role | Accessible name / visible text (exact) | Ids and attributes | AC |
|---|---|---|---|---|---|
| U-01 | Page heading | heading, level 1 | `Todos` | — | CC.AC1 |
| U-02 | Create form | form | `Add a todo` (via `aria-labelledby` on a visually hidden or visible `<h2>`) | `id="todo-create"` | US-02 |
| U-03 | Create title input | textbox | label `Title` | `id="todo-create-title"`, `required`, `maxlength="255"` | US-02.AC1, AC2, AC6 |
| U-04 | Create description input | textbox (multi-line) | label `Description` | `id="todo-create-description"` | US-02 |
| U-05 | Create due-date input | `<input type="date">` | label `Due date` | `id="todo-create-due_date"` | US-02.AC4 |
| U-06 | Create submit | button | `Add todo` | `type="submit"` | US-02.AC1 |
| U-07 | Field error (any form) | — (text) | the server's message, e.g. `The title field is required.` | `id="<form>-<field>-error"`, e.g. `todo-create-title-error`, `todo-edit-due_date-error`. While an error exists the input has `aria-invalid="true"` and `aria-describedby` containing that id | US-02.AC2-AC4, US-04.AC2, CC.AC2 |
| U-08 | Todo list | list | `Todo list` (`aria-label`) | `<ul>`, one `<li>` per todo | US-03.AC1, AC2 |
| U-09 | Completion checkbox | checkbox | `Mark "<title>" complete`, the **same name in both states**; `checked` = completed | `id="todo-<id>-completed"` | US-05.AC1, AC2, AC5 |
| U-10 | Due date text | — (text) | `Due <YYYY-MM-DD>`, e.g. `Due 2026-03-10`. Absent when there is no due date | — | US-03 |
| U-11 | Overdue badge | — (text) | `Overdue` (visible text, not colour only) | rendered only when `isOverdue(...)` is true | US-03.AC5 |
| U-12 | Edit trigger | button | `Edit "<title>"` (icon button with `aria-label`) | `id="todo-<id>-edit"` | US-04 |
| U-13 | Delete trigger | button | `Delete "<title>"` (icon button with `aria-label`) | `id="todo-<id>-delete"` | US-06 |
| U-14 | Edit dialog | dialog | title `Edit todo` | inputs labelled `Title`, `Description`, `Due date` with ids `todo-edit-title`, `todo-edit-description`, `todo-edit-due_date`; initial focus on `todo-edit-title` | US-04.AC1, AC2 |
| U-15 | Edit dialog buttons | button | `Save`, `Cancel` | `Save` is `type="submit"` | US-04 |
| U-16 | Delete dialog | alertdialog | title `Delete todo?`; description `"<title>" will be permanently deleted.` | initial focus on `Cancel` | US-06.AC1, AC2, AC4 |
| U-17 | Delete dialog buttons | button | `Delete`, `Cancel` | — | US-06.AC1, AC2 |
| U-18 | Filter navigation | navigation | `Filter todos` (`aria-label`) | — | US-07 |
| U-19 | Filter links | link | `All (<total>)`, `Active (<active>)`, `Completed (<completed>)`, e.g. `Active (3)`. Format: `<Label> (<n>)`, always shown, `0` included | hrefs per §3; `aria-current="page"` only on the current filter | US-03.AC4, US-07.AC1, AC3, AC4 |
| U-20 | Empty state, All view, no todos | — (text) + button | text `No todos yet.` and `Add your first todo to get started.`; button `Add your first todo` | the button moves focus to `#todo-create-title` | US-03.AC3 |
| U-21 | Empty state, Active view | — (text) | `No active todos.` | no button | US-03.AC3, US-07.AC1 |
| U-22 | Empty state, Completed view | — (text) | `No completed todos.` | no button | US-03.AC3, US-07.AC1 |
| U-23 | Toast | status (kit vue-sonner) | the toast texts in §1.1 | — | US-02 to US-06 |

**Focus targets** (where keyboard focus is after each action; tests assert `document.activeElement` by id)
| # | After | Focus moves to | AC |
|---|---|---|---|
| F-a | Page load (any filter) | nothing is focused by script (no autofocus) | F-11, CC.AC1 |
| F-b | Create succeeds | `#todo-create-title` (form reset) | US-02.AC6 |
| F-c | Create fails validation | the first field with an error, in the order title, description, due date | CC.AC2 |
| F-d | Edit saved | the dialog closes; `#todo-<id>-edit` of the edited todo | US-04, F-10 |
| F-e | Edit cancelled (button or Escape) | `#todo-<id>-edit` of that todo | US-04 |
| F-f | Edit fails validation | the dialog stays open; the first field with an error | US-04.AC2, CC.AC2 |
| F-g | Delete cancelled (button or Escape) | `#todo-<id>-delete` of that todo | US-06.AC2, AC4 |
| F-h | Delete confirmed | the **next** item's checkbox (`#todo-<next>-completed`); if none, the **previous** item's checkbox; if the list is now empty, `#todo-create-title` | US-06.AC4, F-10 |
| F-i | Completion toggled and the item is still in the list (All view) | stays on `#todo-<id>-completed` | US-05.AC5 |
| F-j | Completion toggled and the item leaves the list (Active or Completed view) | the same rule as F-h | US-05.AC5, F-10 |
| F-k | Filter link activated | stays on the activated link, which now has `aria-current="page"` | US-07.AC4, F-10 |
| F-l | Empty-state button activated | `#todo-create-title` | US-03.AC3 |

Implementation note: F-b, F-d, F-h and F-j run in the visit's `onSuccess` callback after `nextTick()`, and look elements up by the ids above.

## 7. Story → route/page traceability (Must + US-07 + cross-cutting)
`docs/04-quality/traceability-matrix.md` is the source of truth for AC → test; this table is the design-side view.

| AC | Route(s) | Page / component | Verified by (test file → test name prefix) |
|---|---|---|---|
| US-01.AC1 | `todos.index` (`auth`) | — | `Feature/Todos/IndexTest` → `US-01.AC1` (replaces the kit's `DashboardTest` guest case, G2-8) |
| US-01.AC2 | `todos.index` (`verified`) | kit `auth/VerifyEmail` | `Feature/Todos/IndexTest` → `US-01.AC2` |
| US-01.AC3 | Fortify `login.store`, `fortify.home=/todos` | kit `auth/Login`, `PasskeyVerify` | `Feature/Auth/AuthenticationTest` (retargeted to `todos.index`) → `US-01.AC3` |
| US-01.AC4 | Fortify `login.store` (5/min limiter) | kit `auth/Login` | `Feature/Auth/AuthenticationTest` → `US-01.AC4` (real 5-attempt test plus the kit test) |
| US-01.AC5 | kit `profile.destroy` + FK cascade | kit `settings/Profile` | `Feature/Settings/ProfileUpdateTest` → `US-01.AC5` (own todos gone, another user's remain; NFR-11) |
| US-01.AC6 | Fortify `two-factor.login.store` | kit `auth/TwoFactorChallenge` | `Feature/Auth/TwoFactorChallengeTest` → `US-01.AC6` (valid TOTP, invalid code, recovery code once; F-13) |
| US-02.AC1 | `todos.store` → `todos.index` | `TodoCreateForm`, `TodoList` | `Feature/Todos/StoreTest` + `Browser/TodoJourneyTest` → `US-02.AC1` |
| US-02.AC2–AC4 | `todos.store` | `TodoCreateForm` (U-03 to U-07) | `Feature/Todos/StoreTest` → `US-02.AC2`…`AC4` |
| US-02.AC5 | `todos.store` | — | `Feature/Todos/StoreTest` → `US-02.AC5` |
| US-02.AC6 | — | `TodoCreateForm` (F-b) | `Browser/TodoJourneyTest` → `US-02.AC6` |
| US-03.AC1 | `todos.index` | `TodoList` | `Feature/Todos/IndexTest` → `US-03.AC1` (`missing` other users' ids) |
| US-03.AC2 | `todos.index` | `TodoList` | `Feature/Todos/IndexTest` → `US-03.AC2` |
| US-03.AC3 | `todos.index` | `TodoEmptyState` (U-20 to U-22, F-l) | `Feature/Todos/IndexTest` (empty `todos`) + `Browser/TodoJourneyTest` → `US-03.AC3` |
| US-03.AC4 | `todos.index` (`counts`) | `TodoFilterNav` (U-19) | `Feature/Todos/IndexTest` + `Browser/TodoJourneyTest` → `US-03.AC4` |
| US-03.AC5 | `todos.index` (`due_date`, `completed_at`) | `TodoItem` (U-11), `lib/overdue.ts` | `resources/js/lib/overdue.spec.ts` (`vp test`) + `Browser/TodoJourneyTest` → `US-03.AC5` |
| US-03.AC6 | `todos.index` | — | `Feature/Todos/QueryCountTest` → `US-03.AC6` |
| US-04.AC1–AC2 | `todos.update` | `TodoEditDialog` (U-14, U-15) | `Feature/Todos/UpdateTest` → `US-04.AC1`, `US-04.AC2` |
| US-04.AC3 | `todos.update` | — | `Feature/Todos/AuthorizationTest` → `US-04.AC3` |
| US-05.AC1–AC2 | `todos.completion.update` | `TodoItem` | `Feature/Todos/CompletionTest` → `US-05.AC1`, `US-05.AC2` |
| US-05.AC3 | `todos.completion.update` | — | `Feature/Todos/AuthorizationTest` → `US-05.AC3` |
| US-05.AC4 | `todos.completion.update` (`auth`) | — | `Feature/Todos/AuthorizationTest` → `US-05.AC4` |
| US-05.AC5 | — | `TodoItem` (U-09, F-i, F-j) | `Browser/TodoJourneyTest` → `US-05.AC5` |
| US-06.AC1, AC3 | `todos.destroy` | `TodoDeleteDialog` | `Feature/Todos/DestroyTest`, `AuthorizationTest` → `US-06.AC1`, `US-06.AC3` |
| US-06.AC2, AC4 | — | `TodoDeleteDialog` (U-16, U-17, F-g, F-h) | `Browser/TodoJourneyTest` → `US-06.AC2`, `US-06.AC4` |
| US-07.AC1–AC3 | `todos.index?filter=` | `TodoFilterNav` | `Feature/Todos/FilterTest` + `Unit/TodoFilterTest` → `US-07.AC1`…`AC3`; `Browser/TodoJourneyTest` (URL after click) → `US-07.AC3` |
| US-07.AC4 | — | `TodoFilterNav` (U-19, F-k) | `Browser/TodoJourneyTest` → `US-07.AC4` |
| **CC.AC1** (every interactive control has an accessible name and visible focus) | all | §6.1 names | `Browser/AccessibilityTest` → `CC.AC1` (`assertNoAccessibilityIssues()` over the state list in ADR-0003, plus the focus-visible probe) |
| **CC.AC2** (validation errors next to the field, linked by `aria-describedby`) | `todos.store`, `todos.update` | U-07, F-c, F-f | `Browser/TodoJourneyTest` → `CC.AC2` (the input's `aria-describedby` resolves to an element containing the error text) |
| **CC.AC3** (no user content rendered as raw HTML) | — | all | `npm run check:html` fixture tests + `Browser/TodoJourneyTest` → `CC.AC3` (literal `<img src=x onerror=alert(1)>` title) |

**Scope of CC.AC1–CC.AC3 (QA re-check N-3).** All three apply to the todo forms **and** to the kit's auth and settings forms (login, register, forgot/reset password, confirm password, two-factor challenge, profile, security/password, delete account). How they are verified differs: the todo forms get the per-field tests in the rows above (U-07, F-c, F-f). The kit forms get **one browser accessibility pass per page**, `assertNoAccessibilityIssues()` in `Browser/AccessibilityTest` over the kit pages in ADR-0003's state list, plus the CC.AC3 raw-HTML check, which scans every `.vue` file. No per-field `aria-describedby` tests are written for kit forms. Owner-visible scope note: axe does not check that an error is linked by `aria-describedby`, so for kit forms that part of CC.AC2 is not asserted (the kit's `InputError` linkage is UNVERIFIED; T-001 records the baseline, and a gap is fixed or owner-approved like any other kit accessibility finding).
