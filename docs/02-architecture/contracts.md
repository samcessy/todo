# Contracts: routes, validation, props

**Status:** Draft for G2 · **Date:** 2026-11-16
**Changing this file needs owner approval** (governance). Implementation must match it exactly, and CI checks §1 against `php artisan route:list --json` (§1.2).

## 1. Routes

### 1.1 Route table (new todo routes)
Every route has the `web` middleware group (CSRF, session, `HandleInertiaRequests`) plus `auth` and `verified`.

| Method | URI | Name | Controller@action | FormRequest | Extra middleware | Policy ability | Success response | Failure responses |
|---|---|---|---|---|---|---|---|---|
| GET | `/todos` | `todos.index` | `TodoController@index` | — (query param `filter` parsed by `TodoFilter`) | — | — (query scoped to `$request->user()`) | 200 Inertia `todos/Index` (props §5.2) | 302 → `/login` (guest), 302 → `/email/verify` (unverified) |
| POST | `/todos` | `todos.store` | `TodoController@store` | `StoreTodoRequest` | `throttle:todo-writes` | — (any verified user) | **302** back, flash `toast = {type:'success', message:'Todo added.'}` | 302 back with `errors` (validation), 429 |
| PATCH | `/todos/{todo}` | `todos.update` | `TodoController@update` | `UpdateTodoRequest` | `throttle:todo-writes`, `can:update,todo` | `TodoPolicy@update` | **303** back, flash `toast = {type:'success', message:'Todo updated.'}` | 403 (not owner), 404 (no such id), 302/303 back with `errors`, 429 |
| PATCH | `/todos/{todo}/completion` | `todos.completion.update` | `TodoCompletionController@update` | `UpdateTodoCompletionRequest` | `throttle:todo-writes`, `can:update,todo` | `TodoPolicy@update` | **303** back, flash `toast = {type:'success', message:'Todo completed.'}` or `'Todo marked as active.'` | 403, 404, 303 back with `errors`, 429 |
| DELETE | `/todos/{todo}` | `todos.destroy` | `TodoController@destroy` | — | `throttle:todo-writes`, `can:delete,todo` | `TodoPolicy@delete` | **303** back, flash `toast = {type:'success', message:'Todo deleted.'}` | 403, 404, 429 |

**Notes**
- "Back" means `back(fallback: route('todos.index'))`. It returns to the previous URL, `/todos?filter=…`, so the filter survives (US-07.AC3). With no previous URL it goes to `todos.index`.
- The Inertia middleware converts 302 to **303** for PUT, PATCH and DELETE. POST stays 302. Both are followed by a GET (ADR-0001; `Middleware.php:178`).
- **Validation failures** are never 422 for Inertia visits. Laravel redirects back and Inertia exposes the errors as `page.props.errors` (R-002 E5).
- **Ordering:** authorisation (`can:` middleware) runs **before** validation. A non-owner therefore always gets 403, even with an invalid payload, and the todo is unchanged (US-04.AC3, US-05.AC3, US-06.AC3).
- **Route model binding** resolves `{todo}` by `id` across all users, then the policy denies. The 403 for another user's id is mandated by the ACs (see security.md T-01 on enumeration).
- **Explicit routes, not `Route::resource`.** That keeps the method `PATCH` (not `PUT|PATCH`) and keeps unused actions out.

**Kit routes changed by this design**
- `GET /dashboard` (`dashboard`) is **removed**, and `fortify.home` becomes `/todos` (open question Q1).
- Every Fortify and settings route is unchanged (R-001 E13–E16).
- `GET /` (`home`, Welcome) stays public.
- `GET /up` is the health route.

**Named rate limiter** (in `AppServiceProvider::boot`)
```php
RateLimiter::for('todo-writes', fn (Request $request) => Limit::perMinute(120)->by((string) $request->user()?->id ?: $request->ip()));
```
This follows https://laravel.com/docs/13.x/routing#defining-rate-limiters. The value is open question Q7.

### 1.2 Machine-checkable contract
CI (T-002) runs:
```bash
php artisan route:list --json --path=todos | jq -S '[.[] | {method, uri, name, action, middleware}] | sort_by(.name)'
```
It diffs the output against the block below.

**Verified by spike, 2026-11-16:** I added the `todos.index` and `todos.update` definitions from §1.3 to a copy of the kit (Laravel 13.42.0), then ran the command above. The output had exactly this shape:
- `"method": "GET|HEAD"` and `"PATCH"`;
- `"uri": "todos/{todo}"`;
- `"action": "App\\Http\\Controllers\\…@update"`;
- `"middleware": ["web","auth","verified","throttle:todo-writes","can:update,todo"]`.

Changes to this block need owner approval and must not loosen the check.

```json
[
  {"method": "PATCH",    "uri": "todos/{todo}/completion", "name": "todos.completion.update", "action": "App\\Http\\Controllers\\TodoCompletionController@update", "middleware": ["web", "auth", "verified", "throttle:todo-writes", "can:update,todo"]},
  {"method": "DELETE",   "uri": "todos/{todo}",            "name": "todos.destroy",           "action": "App\\Http\\Controllers\\TodoController@destroy",          "middleware": ["web", "auth", "verified", "throttle:todo-writes", "can:delete,todo"]},
  {"method": "GET|HEAD", "uri": "todos",                   "name": "todos.index",             "action": "App\\Http\\Controllers\\TodoController@index",            "middleware": ["web", "auth", "verified"]},
  {"method": "POST",     "uri": "todos",                   "name": "todos.store",             "action": "App\\Http\\Controllers\\TodoController@store",            "middleware": ["web", "auth", "verified", "throttle:todo-writes"]},
  {"method": "PATCH",    "uri": "todos/{todo}",            "name": "todos.update",            "action": "App\\Http\\Controllers\\TodoController@update",           "middleware": ["web", "auth", "verified", "throttle:todo-writes", "can:update,todo"]}
]
```
Also asserted: `php artisan route:list --json | jq '[.[] | select(.name == "dashboard")] | length'` returns `0`.

### 1.3 Route definition (reference)
```php
Route::middleware(['auth', 'verified'])->group(function () {
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
These live in `App\Concerns\TodoValidationRules`. Store and Update use the same rules (US-04.AC2), because the edit form always sends all three fields.

| Field | Rules | Notes / AC |
|---|---|---|
| `title` | `['required', 'string', 'max:255']` | Laravel's global `TrimStrings` and `ConvertEmptyStringsToNull` middleware turn whitespace-only input into `null`, so `required` fails. The message must be exactly **"The title field is required."** (Laravel default `validation.required`; US-02.AC2). `max:255` covers US-02.AC3 |
| `description` | `['nullable', 'string', 'max:5000']` | FR-02 |
| `due_date` | `['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2099-12-31']` | US-02.AC4. The format matches `<input type="date">`. The bounds keep out values that the two drivers handle differently (for example year 0000), and the pgsql CI job verifies this. Past dates are allowed, because a todo can legitimately be overdue |
| `completed` (completion endpoint only) | `['required', 'boolean']` | US-05. The UI sends a JSON `true` or `false` |

- Any other input key (`user_id`, `completed_at`, `id`) is **not in `rules()`**. It is therefore absent from `validated()`, and is never mass-assigned (US-02.AC5). `preventSilentlyDiscardingAttributes` would surface a mistake in tests.
- `authorize()` returns `true` in every todo FormRequest. Authorisation is the route `can:` middleware (architecture §4).

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
- The URL is the source of truth for the filter (US-07.AC3). Filter links are `<Link :href="index({ query: { filter } })">` with `only: ['todos', 'filter']`, `preserveState`, `preserveScroll`, and `aria-current="page"` on the active link (US-07.AC4).

## 4. Shared props used (from the kit's `HandleInertiaRequests`)
| Prop | Type | Source | Used for |
|---|---|---|---|
| `name` | `string` | `config('app.name')` | layout title |
| `auth.user` | `User` (kit type) | raw model filtered by `#[Hidden]`, a documented exception (ADR-0002) | nav user menu |
| `sidebarOpen` | `boolean` | cookie | kit layout |
| `errors` | `Record<string, string>` | Inertia (session errors) | form field errors |
| `flash.toast` (page-level `flash`, not a prop) | `FlashToast` (`{ type: 'success'|'info'|'warning'|'error'; message: string }`, kit `types/ui.ts`) | `Inertia::flash('toast', …)` | toast through the kit's `initializeFlashToast()` |

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
    due_date: string | null;      // 'YYYY-MM-DD'
    completed: boolean;
    completed_at: string | null;  // ISO 8601, UTC
    is_overdue: boolean;          // server-computed: due_date < today (UTC) && !completed (FR-08, Q5)
    created_at: string;           // ISO 8601, UTC
};

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

### 5.2 Server side of `todos/Index`
```php
return Inertia::render('todos/Index', [
    'todos'  => TodoResource::collection($todos)->resolve(),
    'counts' => ['total' => $total, 'active' => $total - $completed, 'completed' => $completed],   // ints
    'filter' => $filter->value,
]);
```
**Contract test:** one strict `assertInertia` per page. It uses `component('todos/Index')`, `has('todos', n)`, `has('todos.0', fn ($t) => $t->hasAll([...8 keys]))` **without `etc()`**, plus `where('counts.total', …)`, `where('filter', …)`, and `missing('todos.0.user_id')`. A field added on one side only then fails CI (R-002 red-team finding 1). Counts are cast to `(int)` explicitly, because the type of a raw aggregate can differ between PDO drivers (**UNVERIFIED** for pdo_pgsql on PHP 8.4). The `where('counts.total', 3)` strict comparison in the pgsql CI job catches any difference.

## 6. Pages and components
| Page / component | Route(s) | Notes |
|---|---|---|
| `pages/todos/Index.vue` | `todos.index` | `defineProps<TodosIndexProps>()`. Uses `AppLayout`, breadcrumb "Todos" |
| `components/todos/TodoCreateForm.vue` | `todos.store` | `useForm<TodoForm>`. On success: `reset()`, then focus the title input (US-02.AC6). Not `<Form disable-while-processing>`, because `inert` removes focus (R-002 risk row) |
| `components/todos/TodoItem.vue` | `todos.completion.update` | Labelled shadcn `Checkbox` ("Mark "<title>" complete"), Space toggles (US-05.AC5), `router.patch(…, { completed: !todo.completed }, { preserveScroll: true })`. The overdue badge has the text "Overdue" (US-03.AC5) |
| `components/todos/TodoEditDialog.vue` | `todos.update` | reka-ui Dialog with `useForm<TodoForm>`, errors linked by `aria-describedby` |
| `components/todos/TodoDeleteDialog.vue` | `todos.destroy` | reka-ui Dialog: focus trap, Escape closes, focus returns to the trigger (US-06.AC4). Cancel sends nothing (US-06.AC2) |
| `components/todos/TodoFilterNav.vue` | `todos.index?filter=` | `<nav aria-label="Filter todos">` with three `<Link>`s and `aria-current="page"`. Each shows its count (US-03.AC4) |
| `components/todos/TodoEmptyState.vue` | — | Shown when `todos.length === 0`. Invites the user to add a todo and focuses the title field (US-03.AC3). With a filter active, it says "No active/completed todos" |

URLs come from **Wayfinder** helpers (`@/routes/todos`, `@/actions/App/Http/Controllers/...`), never from hard-coded strings (coding-standards.md).

## 7. Story → route/page traceability (Must + US-07)
| AC | Route(s) | Page / component | Verified by (test file → test name prefix) |
|---|---|---|---|
| US-01.AC1 | `todos.index` (`auth`) | — | `Feature/Todos/IndexTest` → `US-01.AC1` |
| US-01.AC2 | `todos.index` (`verified`) | kit `auth/VerifyEmail` | `Feature/Todos/IndexTest` → `US-01.AC2` |
| US-01.AC3 | Fortify `login.store`, `fortify.home=/todos` | kit `auth/Login` | `Feature/Auth/AuthenticationTest` (QA-updated) → `US-01.AC3` |
| US-01.AC4 | Fortify `login.store` (5/min limiter) | kit `auth/Login` | `Feature/Auth/AuthenticationTest` → `US-01.AC4` |
| US-01.AC5 | kit `profile.destroy` + FK cascade | kit `settings/Profile` | `Feature/Settings/ProfileUpdateTest` → `US-01.AC5` (asserts todos are gone too; NFR-11) |
| US-02.AC1 | `todos.store` → `todos.index` | `TodoCreateForm`, `TodoList` | `Feature/Todos/StoreTest` → `US-02.AC1` |
| US-02.AC2–AC4 | `todos.store` | `TodoCreateForm` | `Feature/Todos/StoreTest` → `US-02.AC2`…`AC4` |
| US-02.AC5 | `todos.store` | — | `Feature/Todos/StoreTest` → `US-02.AC5` |
| US-02.AC6 | — | `TodoCreateForm` | `Browser/TodoJourneyTest` → `US-02.AC6` |
| US-03.AC1 | `todos.index` | `TodoList` | `Feature/Todos/IndexTest` → `US-03.AC1` (`missing` other users' ids) |
| US-03.AC2 | `todos.index` | `TodoList` | `Feature/Todos/IndexTest` → `US-03.AC2` |
| US-03.AC3 | `todos.index` | `TodoEmptyState` | `Feature/Todos/IndexTest` (empty `todos`) + `Browser/TodoJourneyTest` → `US-03.AC3` |
| US-03.AC4 | `todos.index` (`counts`) | `TodoFilterNav` | `Feature/Todos/IndexTest` → `US-03.AC4` |
| US-03.AC5 | `todos.index` (`is_overdue`) | `TodoItem` | `Feature/Todos/IndexTest` (with `travelTo`) + `Browser` text check → `US-03.AC5` |
| US-03.AC6 | `todos.index` | — | `Feature/Todos/QueryCountTest` → `US-03.AC6` |
| US-04.AC1–AC2 | `todos.update` | `TodoEditDialog` | `Feature/Todos/UpdateTest` → `US-04.AC1`, `US-04.AC2` |
| US-04.AC3 | `todos.update` | — | `Feature/Todos/AuthorizationTest` → `US-04.AC3` |
| US-05.AC1–AC2 | `todos.completion.update` | `TodoItem` | `Feature/Todos/CompletionTest` → `US-05.AC1`, `US-05.AC2` |
| US-05.AC3 | `todos.completion.update` | — | `Feature/Todos/AuthorizationTest` → `US-05.AC3` |
| US-05.AC4 | `todos.completion.update` (`auth`) | — | `Feature/Todos/AuthorizationTest` → `US-05.AC4` |
| US-05.AC5 | — | `TodoItem` | `Browser/TodoJourneyTest` → `US-05.AC5` |
| US-06.AC1, AC3 | `todos.destroy` | `TodoDeleteDialog` | `Feature/Todos/DestroyTest`, `AuthorizationTest` → `US-06.AC1`, `US-06.AC3` |
| US-06.AC2, AC4 | — | `TodoDeleteDialog` | `Browser/TodoJourneyTest` → `US-06.AC2`, `US-06.AC4` |
| US-07.AC1–AC3 | `todos.index?filter=` | `TodoFilterNav` | `Feature/Todos/FilterTest` → `US-07.AC1`…`AC3` |
| US-07.AC4 | — | `TodoFilterNav` | `Browser/TodoJourneyTest` → `US-07.AC4` |
