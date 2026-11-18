# Security review: T-003 and T-004

Reviewer: security-reviewer (read-only). Date: 2026-11-18.
Baseline: `origin/task/T-001-scaffold`. Framework in `vendor/`: Laravel 13.43.0, Pest 5.3.7, `@vue/compiler-sfc` 3.5.45.
No working-tree changes were made. The bypass probes ran against a copy of `check-raw-html.mjs` in the session scratchpad, with `node_modules` symlinked in. Nothing under the repo was written.

---

## T-003: static-analysis and arch baseline (`origin/task/T-003-static-analysis`)

### Scope and commands run
- `git diff --stat` and `git diff origin/task/T-001-scaffold...origin/task/T-003-static-analysis -- app config composer.json phpstan.neon phpunit.xml tests`
- `git show <branch>:routes/settings.php`, `app/Concerns/ProfileValidationRules.php`, `app/Http/Requests/Settings/ProfileUpdateRequest.php`, `app/Http/Middleware/HandleInertiaRequests.php`, `config/boost.php`, the task file and both handoffs
- Framework source (read-only): `Container/Attributes/CurrentUser.php` and `Authenticated.php`, `Container/BoundMethod.php::addDependencyForCallParameter`, `Foundation/Http/FormRequest.php:105,174,207-209,350`, `Auth/AuthManager.php:61,206-213,249`, `Auth/AuthServiceProvider.php:88-90`, `config/hashing.php:34`
- Pest presets: `vendor/pestphp/pest/src/ArchPresets/{Php,Security}.php`
- `security.md` rows T-03, T-04, T-12, T-18, T-19

### Analysis
**`#[CurrentUser]` behaviour and authorisation.** Behaviour and authorisation are unchanged.
- `CurrentUser extends Authenticated`. `Authenticated::resolve()` calls `$container->make('auth')->userResolver()` with `guard = null`. `$request->user()` goes through `AuthServiceProvider:88-90`, which calls the same `auth->userResolver()`. Both resolve the same guard: the default guard, or the guard that the `auth` middleware set through `AuthManager::shouldUse()` (line 212 rebinds the resolver). The guard caches the user, so both return the same `User` instance.
- Every changed action still sits behind `auth`. `profile.update` uses `auth`. `profile.destroy`, `security.edit` (plus `RequirePassword`) and `user-password.update` (plus `throttle:6,1`) use `auth, verified`. The middleware runs before the controller is invoked, so `auth` still turns a guest away with a redirect before any injection happens.
- If a route ever lost `auth`, the resolver would return `null`, and `BoundMethod::addDependencyForCallParameter` would pass it on unchecked. PHP would then throw a `TypeError` on the non-nullable `User $user`, which is a 500. It fails closed in the same way as the old `$request->user()->...` ("call to a member function on null"). There is no silent null path.
- `destroy()`: `$user` is resolved before `Auth::logout()`, as before.

**`FormRequest::rules()` injection.** This is correct in 13.43. `validationRules()` (FormRequest.php:209) calls `$this->container->call([$this, 'rules'])`, so contextual attributes resolve. `passesAuthorization()` runs before the validator is built, so the order is unchanged. Nothing else calls `->rules()` directly (`git grep` on `app`, `routes` and `tests`).

**Unique-email rule.** Unchanged. `profileRules($user->id)` becomes `Rule::unique(User::class)->ignore($userId)`, with the same id as the old `$this->user()->id`.

**Arch suite (T-12).** `ArchTest.php:38-40` bans `Illuminate\Support\Facades\Log`, the global `Log` alias, `logger` and `info` across `App`, as `security.md` T-12 specifies. The QA handoff's inverted-rule sanity check showed that function detection works on namespaced code (`now`). The listed names are therefore enforced. Other logging routes are not covered; see A-1.

**Weakening.** None found. The PHPStan level goes from 7 to 8 with no baseline and no ignores. The memory flag only affects resources. `BCRYPT_LIMIT=72` matches the framework's `hashing.bcrypt.limit` key and tightens tests (T-23).

**`config/boost.php`.** The default is the same list as before. Only the `BOOST_BROWSER_LOG_LEVELS` override is removed. Boost is a dev tool and T-19's controls (`tinker_tool_enabled => false`, `rules.enabled => false`) are untouched, so the security posture does not change.

### Findings

| ID | Severity | File:line | Evidence | Recommended fix | Owning task |
|---|---|---|---|---|---|
| A-1 | ADVISORY | `tests/Arch/ArchTest.php:38-40` | The T-12 rule bans only `Illuminate\Support\Facades\Log`, `Log`, `logger` and `info`. Logging still gets through via constructor injection of `Psr\Log\LoggerInterface` (a common Laravel idiom an agent could write without meaning to), `Illuminate\Log\LogManager` / `Illuminate\Log\Logger`, the `logs()` helper, and `error_log()` / `syslog()`. None of these are in the Pest `php` or `security` presets. `app('log')` and `resolve('log')` cannot be caught statically. The rule meets the T-12 spec as written. | Extend the list to `['Illuminate\Support\Facades\Log', 'Log', 'logger', 'info', 'logs', 'Illuminate\Log', 'Psr\Log', 'error_log', 'syslog']` (namespace prefixes match in Pest `toUse`). Re-run the Arch suite, which should have no hits today. Add "no `app('log')` / `resolve('log')`" to the review checklist. Optionally update the T-12 wording in `security.md` (owner-visible). | T-003 (or a follow-up QA task) |

Out-of-scope observation (not in this diff, not a T-003 finding): on the T-001 baseline, `app/Http/Middleware/HandleInertiaRequests.php:42` still shares the raw `'user' => $request->user()`. `security.md` T-04 (A-04) requires the allow-list `?->only(['id', 'name', 'email', 'email_verified_at'])` plus `Feature/Security/SharedPropsTest`. The orchestrator should confirm which task owns that change. The table puts it at T-001.

### Verdict T-003: **Approve with advisories**

---

## T-004: raw-HTML check (`origin/task/T-004-raw-html-check`)

### Scope and commands run
- `git diff --stat` and the diff of `package.json` and `CLAUDE.md` against T-001
- `git show <branch>:scripts/check-raw-html.mjs` (read in full), `scripts/raw-html-allowlist.json`, the task file, the devops handoff, the ADR-0003 NFR-03 row, `.gitignore`, `tsconfig.json`, `vite.config.ts`, `.github/workflows/tests.yml` (Node 22)
- `git ls-tree -r --name-only <branch> resources/js` (all files are `.vue`/`.ts` today)
- Scope globs across all task files (`git show <branch>:docs/03-plan/tasks/*.md`)
- Framework source: `@vue/runtime-dom` `patchProp` (line 744, `.`/`^` prefixes) and `@vue/compiler-core` `.camel` transform (line 6284)
- **Bypass probes:** 18 scratch fixtures run through the script's own `--fixtures` mode, each expected to fail. Results are below.

### Probe results (copy of the branch script)
| Probe | Gate result | Real sink at runtime? |
|---|---|---|
| `v-html.foo="x"` | fail (caught) | yes |
| `.innerHTML="x"` (prop shorthand) | fail (caught) | yes |
| `v-bind:INNERHTML="x"` | fail (caught) | n/a |
| `<component :is v-html>` | fail (caught) | yes |
| `h('div', { innerHTML })` in `<script setup>` | fail (caught) | yes |
| `v-html="qrCodeSvg"` on another element in the allow-listed file | pass (allowed) | by design (file + expr) |
| `:inner-html.prop="x"` | pass | **no**: sets the meaningless DOM prop `inner-html` |
| `:inner-html.camel="x"` | pass | **no**: camelize gives `innerHtml`, which is set as an attribute |
| `:inner-h-t-m-l.camel="x"` | not flagged (arg not normalised) | **yes**: camelize gives `innerHTML` |
| `:[k]="x"` / `:['innerHTML']="y"` (dynamic arg) | pass | **yes** if the arg evaluates to `innerHTML` |
| `<iframe :srcdoc="x">` | pass | **yes**: a srcdoc iframe without `sandbox` is same-origin with the app |
| `createContextualFragment(x)` in `.ts` | pass | **yes**: scripts in the fragment execute when it is inserted |
| `el.setHTMLUnsafe(x)` in `.ts` | pass | **yes** |
| `DOMParser.parseFromString` in `.ts` | pass | only if nodes are later adopted (then `onerror` handlers fire) |
| `{{ (el.innerHTML = x) }}` interpolation | pass | yes, but absurd |
| `<template src="./evil.html">` | pass (no AST, skipped) | yes (plugin-vue supports `src`) |
| `<template lang="pug">` | pass (no AST, skipped) | only if `pug` is installed (it is not, so the build fails) |
| `el.innerHTML` in a `.js` / `.tsx` file | fails in fixture mode, **skipped in repo mode** | **yes** |

### Analysis
- **Allow-list strictness:** good. A `v-html` is allowed only when `a.file === path && a.expr === exp.trim()`, using exact repo-relative paths. Any other expression, any other file, and any `:innerHTML`/`outerHTML` in the allow-listed file still fail. Other directives' expressions are still token-scanned.
- **CI safety:** good. The script imports only `node:fs`, `node:path` and `@vue/compiler-sfc`. It has no network access, no `eval`/`Function`, and no `child_process`. It only reads files, and prints at most a 100-character snippet of offending source. SFC parse errors fail closed. `import.meta.dirname` needs Node ≥ 20.11, and CI uses Node 22.
- **ADR known limitation:** the object-spread `v-bind="{ innerHTML: x }"` is caught, because every directive expression is token-scanned. That is better than the ADR requires.

### Findings

| ID | Severity | File:line | Evidence | Recommended fix | Owning task |
|---|---|---|---|---|---|
| B-1 | **BLOCKER** | `scripts/check-raw-html.mjs:87` | Repo mode keeps only `/\.(vue\|ts)$/`. `tsconfig.json` sets `"allowJs": true` and explicitly includes `resources/js/**/*.tsx`, with `jsx: preserve` and `jsxImportSource: vue`, so `.js`, `.tsx`, `.jsx`, `.mts` and `.mjs` files under `resources/js` are first-class sources that Vite bundles. The gate skips them silently. A future agent writing a helper in `.js`, or a render component in `.tsx` (`<div innerHTML={todo.title} />`, `el.innerHTML = ...`), passes `check:html`. The probe confirmed that the same content fails when scanned, so only the extension filter lets it through. The ADR row only names `*.ts`, but the gate is the sole control for T-03/NFR-03 and must fail closed. | Scan `/\.(vue\|[cm]?[jt]sx?)$/`. Better: treat every file under `resources/js` (outside the generated dirs) as in scope, and **fail** on any extension that is not known safe (e.g. images, `.css`), so new source types cannot be skipped silently. Add `.js` and `.tsx` fixtures, run in repo mode (or make fixture mode apply the same extension filter so this path is tested). | T-004 |
| A-2 | ADVISORY | `scripts/check-raw-html.mjs:15-16` | The token list omits other HTML-parsing sinks: `setHTMLUnsafe`, `Document.parseHTMLUnsafe`, `createContextualFragment`, `srcdoc`, and `DOMParser` (borderline). All pass today (probes). They are less likely to be written by accident than `innerHTML`, but equivalent once written. | Add `setHTMLUnsafe\|parseHTMLUnsafe\|createContextualFragment\|srcdoc\|DOMParser` to `tokens`. Also flag `v-bind` with arg `srcdoc`. Add fixtures. Update ADR-0003 / `security.md` T-03 wording (owner-visible). | T-004 (doc wording: architect) |
| A-3 | ADVISORY | `scripts/check-raw-html.mjs:41,52` | Only static args are checked, and the comparison does not apply Vue's `.camel` transform. `:inner-h-t-m-l.camel="x"` compiles to the `innerHTML` prop. `:['innerHTML']="x"` (dynamic arg) is never scanned, because only `p.exp` is token-scanned and `p.arg` is not. Both forms are contrived. `:inner-html(.prop\|.camel)` alone is harmless (verified in `runtime-dom` `patchProp` and the compiler-core camelize step). | Normalise the arg with `arg.replace(/-/g, '')` before `/^(inner\|outer)html$/i`. Token-scan `p.arg.content` when `!p.arg.isStatic`, or fail on any dynamic `v-bind` arg (the kit has none). | T-004 |
| A-4 | ADVISORY | `scripts/check-raw-html.mjs:60` | When `descriptor.template.ast` is absent (`<template src="...">` or a non-HTML `lang`), the template is skipped silently. The probes passed. An external `.html` template under `resources/js` is not scanned at all (see B-1). Interpolation (`{{ }}`) contents are not token-scanned either. | Fail when `descriptor.template` exists without an `ast`, or has a `src`/`lang` attribute. Optionally token-scan interpolation nodes (`node.type === 5`). | T-004 |
| A-5 | ADVISORY | `scripts/check-raw-html.mjs:14` | `resources/js/{actions,routes,wayfinder}/` are skipped by path. These directories are gitignored, so a hand-written file there would only reach CI through `git add -f`. The risk is low, but the skip cannot be seen in review. | Skip only files that are not tracked. For example, in CI, assert that `git ls-files resources/js/actions resources/js/routes resources/js/wayfinder` is empty, or skip a path only when `git check-ignore` confirms it. | T-004 (or T-002 CI job) |
| A-6 | ADVISORY | `security.md` T-18 (8); `docs/03-plan/tasks/*` | The CI-gate exclusion list covers `scripts/check-*.mjs` and `scripts/*allowlist*.json` but not `tests/fixtures/raw-html/**` (or the T-002 fixture dirs). Task globs are recursive (T-003's `app/*` covers `app/Http/...`), so a future engineer task scoped `tests/*` could edit `expected.json` and flip a fixture's expectation. That only hides a regression in the (protected) script. It cannot whitelist real code. No current engineer task has that scope (T-005 forbids `tests/*`). | Add `tests/fixtures/raw-html/**`, `tests/fixtures/workflows/**`, `tests/fixtures/scope/**` and `tests/fixtures/clover/**` to the T-18 (8) list, and to `check-scope.mjs`'s owner-only set when T-002 lands. | architect / owner (security.md); T-002 |

### Verdict T-004: **Changes required** (B-1). A-2 to A-6 can follow in the same change or later.
