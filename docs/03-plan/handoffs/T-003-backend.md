# Handoff: T-003 (laravel-engineer)

## What changed
- `app/Http/Controllers/Settings/ProfileController.php`: `update()` and `destroy()` take `#[CurrentUser] User $user` instead of calling the nullable `$request->user()` (fixes lines 33, 35, 36, 39, 55).
- `app/Http/Controllers/Settings/SecurityController.php`: `edit()` and `update()` take `#[CurrentUser] User $user` (fixes lines 26, 46, 58). The passkey `created_at_diff` handles a null `created_at` explicitly (fixes line 34).
- `app/Http/Requests/Settings/ProfileUpdateRequest.php`: `rules(#[CurrentUser] User $user)` instead of `$this->user()->id` (fixes line 31).

## Decisions made inside the task scope
- **`#[CurrentUser]` contextual attribute** (Laravel 13 container docs). All these routes sit behind the `auth` middleware (`routes/settings.php`), so the user is never null at runtime. Typing the parameter as `App\Models\User` states that in the signature. If the route were ever left without `auth`, the container would pass `null` to a non-nullable parameter and throw a `TypeError`, so it fails loudly instead of failing quietly.
- **FormRequest:** `FormRequest::validationRules()` calls `rules()` through `$this->container->call([$this, 'rules'])` (checked in `vendor/laravel/framework/src/Illuminate/Foundation/Http/FormRequest.php:209`, framework v13.43.0). So method injection with `#[CurrentUser]` works in `rules()`, and the kit's `ProfileValidationRules` trait stays unchanged.
- **Passkey `created_at` null case:** the column comes from `timestamps()`, which is nullable, and `Laravel\Passkeys\Passkey` declares `@property Carbon|null $created_at`. So the type is real. Eloquent always sets the value on insert, so null only happens for rows written outside Eloquent. The Vue contract (`resources/js/types/auth.ts`, `Passkey.created_at_diff: string`) and `PasskeyItem.vue` (`Added {{ passkey.created_at_diff }}`) need a non-null string. The fallback is `__('on an unknown date')`, which renders as "Added on an unknown date". The prop contract does not change.
- `edit()` still calls `$request->ensureStateIsValid()` on the Fortify `TwoFactorAuthenticationRequest`, unchanged.
- No `@phpstan-ignore`, baseline, inline `@var`, `assert()` or casts were added, so the backend handoff has nothing to list under the task's ignore rule.

## Deviations from the spec
None. `config/boost.php:132` (the 11th level-8 error) is outside this agent's scope and is left to the orchestrator.

## Commands run and results
```
composer lint                                        →  pass (pint)
composer lint:check                                  →  pass
composer types:check                                 →  1 error, config/boost.php:132 only (out of scope); 0 errors in app/
APP_KEY=base64:<random> php artisan test             →  pass: 49 tests, 203 assertions (includes the 7 Arch tests)
```

Docs used (Laravel 13.x):
- https://laravel.com/docs/13.x/container#contextual-attributes: `#[CurrentUser]` injects the authenticated user into a route or class (example uses `->middleware('auth')`).
- https://laravel.com/docs/13.x/container#method-invocation-and-injection: container method injection, which `FormRequest` uses for `rules()`.
- https://laravel.com/docs/13.x/authentication#retrieving-the-authenticated-user: `$request->user()` / `Auth::user()` return null for guests (the source of the nullable type).

## Open questions / follow-ups
- `composer types:check` stays red until `config/boost.php:132` is fixed (orchestrator).
- QA verification: re-run the Arch suite (it is green in the full run above).
