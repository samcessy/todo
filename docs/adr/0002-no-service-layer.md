# ADR-0002: No service layer. Controllers + FormRequests + Policies + Eloquent, with API Resources for props

**Status:** Proposed · **Date:** 2026-11-16, revised 2026-11-17 (A-04, G2-13) · **Approver:** project owner (pending, after the G2 blocker re-check)

## Context
- `requirements.md` Constraints: "Use framework features (FormRequests, Policies, Eloquent) before adding custom layers or packages."
- The domain is one resource (`Todo`) with five operations: list, create, update, set completion and delete. There are no cross-aggregate transactions, no external calls and no queued work (A1, D5).
- R-002 Recommendation 2 says props are built from **API Resources, never raw models**, because "all data returned from the controllers will be visible client-side" (R-002 E22). The red team found that Inertia resolves a `Responsable` prop with `toResponse($request)->getData(true)`. A resource passed as-is therefore reaches Vue **with the `data` wrapper** (R-002 red-team finding 1).

## Decision
**Request handling**
- A request flows **route → middleware (`auth`, `verified`, `can:<ability>,todo`, throttle) → FormRequest (validation) → thin controller action → Eloquent → redirect (or `Inertia::render`)**.
- There are no Service, Repository, Action or DTO classes for todos.
- Authorisation lives **only** in `TodoPolicy`, and routes attach it with `->can()`.
- Validation lives **only** in FormRequests. A shared rules trait in `app/Concerns/` follows the kit's own `ProfileValidationRules` pattern.
- Ownership is set through the relationship: `$request->user()->todos()->create($request->validated())`.

**Props**
- Page props are built with `TodoResource`, and passed **resolved to plain arrays with `->resolve()`**:

  ```php
  'todos' => TodoResource::collection($todos)->resolve(),   // → [ {...}, {...} ]  (no "data" key)
  ```

**Why `->resolve()` and not `JsonResource::withoutWrapping()`** (both checked against Laravel 13 source and docs)
- `JsonResource::withoutWrapping()` is documented: https://laravel.com/docs/13.x/eloquent-resources#data-wrapping. It sets the **global static** `JsonResource::$wrap = null`. That would also silently change the envelope of any future `/api/v1` (D2 trigger). It also has exceptions: paginated collections stay wrapped, and the docs say "The `withoutWrapping` method only affects the outermost response."
- `JsonResource::resolve($request = null)` is a public method, at `Illuminate/Http/Resources/Json/JsonResource.php:120` in v13.42.0. It returns the filtered array. `ResourceCollection::toArray()` maps `resolve()` over each item. **The resource docs page does not document it** (grep of `laravel/docs` 13.x `eloquent-resources.md`). It is public API in source, and it is widely used.
- Spike, 2026-11-16, Laravel 13.42.0, scratchpad `res-spike.php`:
  - `R::collection($items)->toResponse($req)->getData(true)` gives `{"data":[{"id":1,"title":"a"},…]}`.
  - `R::collection($items)->resolve($req)` gives `[{"id":1,"title":"a"},…]`.
  - `(new R($item))->resolve($req)` gives `{"id":1,"title":"a"}`.
- `->resolve()` is local and explicit. It keeps the TypeScript types simple (`todos: Todo[]`) and leaves the API envelope untouched.
- Guard against misuse: every page has **one strict `assertInertia` shape test**, using `has('todos.0', fn ($t) => $t->hasAll([...]))` without `etc()`. This test is the prop contract (R-002 red-team finding 1). A forgotten `->resolve()`, an added field or a leaked field fails it.

**Shared `auth.user` (revised after security review A-04)**
- The first draft kept the kit's raw `User` model as a documented exception, filtered by `#[Hidden]`. That is a deny-list: any future column would leak with no failing test.
- `HandleInertiaRequests` now shares an **explicit key list**, `$request->user()?->only(['id', 'name', 'email', 'email_verified_at'])`, so the exception is removed. T-001 adds any key the kit pages need, by an owner-approved change to contracts.md §4.
- `SharedPropsTest` asserts the **exact key set** (security.md T-04).

**Derived values**
- `TodoResource` sends stored facts only. Overdue is not a prop: it depends on the user's local date, so the browser computes it (G2-13, contracts.md §5.3).

## Alternatives considered
| Alternative | Why it was rejected |
|---|---|
| Service/Action classes (`CreateTodo`, `CompleteTodo`) | Each would wrap a single Eloquent call. It is one more file per operation and one more mock seam, with no story or NFR behind it |
| Repository over Eloquent | It duplicates Eloquent's query builder. Swapping the database is already covered by D4 (SQLite and Postgres both run through Eloquent) |
| Pass models or `->toArray()` directly as props | They expose every non-hidden column, including `user_id` and timestamps, and future columns leak automatically (R-002 E22) |
| `JsonResource::withoutWrapping()` | It changes global state and the future API envelope (see above) |
| Keep the `data` wrapper and type it (`todos: { data: Todo[] }`) | It is noise in every component, and the pagination `meta`/`links` would leak into the type if pagination were ever added |
| `ProvidesInertiaProperty` on a custom class (Inertia v3) | It is valid, but it is an Inertia-specific abstraction. A Resource can be reused by an API. Revisit if per-prop context is needed |

## Consequences
**Positive**
- About 8 small PHP classes for the whole feature.
- Every rule has exactly one home: Policy for *who*, FormRequest for *what*, Resource for *what leaves the server*. That makes mutation testing targeted, with `mutates(TodoPolicy::class)` and similar.

**Negative**
- Controllers touch Eloquent directly, so business rules that grow (for example recurring todos) would bloat them. The revisit triggers cover this.
- `->resolve()` is undocumented on the resources page. It could change in a major release, and the shape test would catch that.

**Follow-up work**
- The arch test asserts that `App\Http\Controllers` does not use `Illuminate\Support\Facades\DB`. Eloquent only, so N+1 problems stay visible.
- T-001 also enables `Model::preventLazyLoading(! $this->app->isProduction())` and `Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction())` (https://laravel.com/docs/13.x/eloquent#configuring-eloquent-strictness).

## Revisit triggers
- An operation needs to touch more than one model in a transaction, call an external service, or be reused from a second entry point (console, queue, `/api/v1`). Extract that operation into an Action class, and only that one.
- A controller action exceeds about 25 lines, or a controller exceeds 5 public actions.
- An `/api/v1` is added. Re-evaluate the wrapper policy then; the API may want `data`.
- Laravel removes or renames `JsonResource::resolve()`.

## Sources
- R-002 Recommendation 2, red-team findings 1 and 6. `requirements.md` Constraints.
- https://laravel.com/docs/13.x/eloquent-resources#data-wrapping (13.x)
- https://inertiajs.com/docs/v3/the-basics/responses (v3): "Responsable objects like API resources and JSON responses are resolved through their `toResponse()` method"
- Source, inertia-laravel 3.7.0: `src/PropsResolver.php` (Responsable → `getData(true)`). Laravel 13.42.0: `JsonResource.php:120` (`resolve`) and `:282` (`withoutWrapping`); `ResourceCollection.php:100–107`
- https://laravel.com/docs/13.x/authorization (`->can('update', 'post')` route middleware)
- https://laravel.com/docs/13.x/validation#form-request-validation (13.x)
