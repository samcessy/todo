# Coding standards

**Status:** Draft for G2 (revised 2026-11-17 for G2-13, G2-16) · **Date:** 2026-11-16
Principle: **follow the starter kit's conventions.** Where the kit is silent, prefer the Laravel and Vue docs defaults. Every rule here is enforced by a tool or by a named G4 review item.

## 1. Commands (the kit's real script names, R-001 E21/E22)
| Command | What it runs | Gate |
|---|---|---|
| `composer setup` | install, `.env` from example, key, migrate, `npm install`, `npm run build` | First run only (human, because it touches `.env`) |
| `composer dev` | `php artisan dev` (server, Vite, logs) | — |
| `composer lint` | `pint --parallel` (fix) | — |
| `composer lint:check` | `pint --parallel --test` | CI |
| `composer types:check` | `phpstan analyse --memory-limit=1G` (level 8; the memory flag is added in T-001) | CI |
| `composer test` | config:clear → `lint:check` → `types:check` → `php artisan test` | local "done" check |
| `composer ci:check` | `npm run check` → `npm run types:check` → `@test` | full local CI (no coverage or mutation, ADR-0003) |
| `npm run build` | `vp build` | CI |
| `npm run dev` | `vp dev` | — |
| `npm run check` | `vp check` (Oxfmt + Oxlint, `denyWarnings`) | CI |
| `npm run check:fix` | `vp check --fix` | — |
| `npm run types:check` | `vue-tsc --noEmit` | CI |
| `npm run check:html` | `node scripts/check-raw-html.mjs` (added in T-001, ADR-0003) | CI |
| `npm run check:workflows` | `node scripts/check-workflows.mjs` (added in T-002, security.md T-18) | CI `workflow-lint` |
| `npm run test` | `vp test run` (pure TypeScript unit tests, first used by `lib/overdue.ts`, G2-13) | CI `js-unit` |

The CI-only gates are coverage (`--coverage --min=85` plus the per-directory script), mutation (`--mutate --min=70`), Postgres, browser tests and audits (ADR-0003).

## 2. PHP
| Topic | Rule | Enforced by |
|---|---|---|
| Style | **Pint, `laravel` preset** (kit `pint.json`). The PostToolUse hook formats each edited file | `composer lint:check` |
| `declare(strict_types=1)` | **Not used.** The kit doesn't use it, and the Pint `laravel` preset doesn't add it. Mixing strict and non-strict files is worse than either choice, and PHPStan level 8 gives the type safety we need. Revisit trigger: we move to PHPStan level 9 or higher, or adopt the Pest `strict` arch preset | Review (arch test `php` preset only) |
| `final` classes | **Not required**, which matches the kit (its classes aren't final, so the Pest `strict` preset is not used, ADR-0003) | — |
| Types | Every parameter, return and property is typed. Generics go in PHPDoc where Larastan needs them (`@return HasMany<Todo, $this>`). No `mixed` unless unavoidable. No `@phpstan-ignore` without a reason in the same line comment, and each one is a G4 review item. No baseline file | `composer types:check` (level 8) |
| Model style | Laravel 13 attributes, as in the kit's `User`: `#[Fillable([...])]`, `#[Hidden([...])]`, `#[Scope]`. Casts go in the `casts(): array` method | Review |
| Naming | Controllers: `<Model>Controller` with resource method names only (`index`, `store`, `update`, `destroy`). Sub-resources get their own controller (`TodoCompletionController@update`). FormRequests: `<Verb><Model>Request` (`StoreTodoRequest`, `UpdateTodoCompletionRequest`). Policies: `<Model>Policy`. Resources: `<Model>Resource`. Enums: singular, PascalCase cases (`TodoFilter::Active`). Shared validation traits go in `App\Concerns\<Model>ValidationRules` (the kit's pattern) | Pest arch `laravel` preset (suffixes, resource-only methods) |
| Controllers | Thin: get validated input, call Eloquent, then redirect or render. No `DB::` facade and no inline authorisation (`abort_if` ownership checks). Authorisation goes on the route through `->can()` | Arch test, review |
| Responses | Writes return `back(fallback: route('todos.index'))`, with `Inertia::flash('toast', ['type' => …, 'message' => …])`. Toast messages are short sentences ending with a full stop | `assertInertiaFlash` in tests |
| Dates | Use `now()` and `today()` (CarbonImmutable, via the kit's `Date::use`). Don't use `new DateTime`. Never compute a user-facing "today" on the server: overdue is a browser concern (G2-13) | Review |
| Debug calls | No `dd`, `dump`, `ray` or `var_dump` | Arch test |
| Lazy loading | Banned outside production (`Model::preventLazyLoading`) | Tests throw |

## 3. Vue and TypeScript (matching the kit)
| Topic | Rule | Enforced by |
|---|---|---|
| SFC style | `<script setup lang="ts">`, Composition API only. Order: `<script setup>`, then `<template>`. Props are typed with `defineProps<T>()` using types from `@/types` | `vp check`, `vue-tsc` |
| Formatting | Oxfmt as configured in `vite.config.ts`: 4-space indent, single quotes, Tailwind class sorting | `npm run check` |
| Files and names | Pages: `resources/js/pages/<area>/<Name>.vue` (lower-case folder, PascalCase file; the Inertia component name is `todos/Index`). Components: `resources/js/components/<area>/<PascalName>.vue`, prefixed with the domain (`TodoItem`). shadcn primitives in `components/ui/*` are vendor code: never edit them, add a wrapper instead | Review |
| Types | Use `export type` (not `interface`), matching the kit's `types/*.ts`. Domain types live in `resources/js/types/<domain>.ts` and are re-exported from `types/index.ts`. No `any`. Type the forms with `useForm<TodoForm>()` | `vue-tsc` (strict per kit tsconfig) |
| URLs | **Wayfinder helpers only** (`import { index, store } from '@/routes/todos'` or the controller actions under `@/actions/...`). No hard-coded paths. The generated folders are gitignored and rebuilt by Vite | Review. The build fails on a missing route |
| Raw HTML | Never use `v-html`, `:innerHTML` or `innerHTML=` (NFR-03). The allow-list is owner-approved | `npm run check:html` |
| Accessibility | Every control has an accessible name. Errors use `InputError` (kit) with an `id`, referenced by `aria-describedby` on the input. Dialogs use the kit's reka-ui `Dialog` (focus trap, Escape). Status is conveyed by text, not only by colour (US-03.AC5). Visible focus comes from the kit's Tailwind `focus-visible` styles | Browser tests: `assertNoAccessibilityIssues()` at level 2, plus the keyboard ACs |
| State | Server props are the source of truth. No client store (Pinia etc.). Local `ref`s are for UI-only state (dialog open, edit buffer) | Review |
| Inertia | Use `router.patch`/`router.delete` with `preserveScroll: true` for list actions. No deferred props, prefetch or optimistic UI in the MVP (architecture §6). Cite inertiajs.com `/docs/v3/` for Inertia APIs (Boost `search-docs` covers only 1.x and 2.x) | Review |

## 4. Tests
| Topic | Rule |
|---|---|
| Style | Pest functions (`it()`/`test()`), not PHPUnit classes. `uses(RefreshDatabase::class)` is already bound in `tests/Pest.php`, so don't repeat it |
| **Naming with AC IDs** | Every test that proves an acceptance criterion **starts with the AC ID**: `it('US-02.AC2 rejects an empty title', …)`. One AC can have several tests, and one test can name several ACs (`US-04.AC2 US-02.AC3 …`). The cross-cutting criteria are `CC.AC1` to `CC.AC3` (F-04). The traceability matrix is built by grepping `(US-\d{2}\|CC)\.AC\d+` in `tests/` and `resources/js/**/*.test.ts`. Non-AC tests name the NFR or threat (`NFR-02 …`, `T-04 …`) |
| Layout | `tests/Feature/Todos/<Action>Test.php`, `tests/Feature/Security/*`, `tests/Arch/ArchTest.php`, `tests/Browser/*`, `tests/Unit/*` (pure PHP logic only, for example `TodoFilter`) |
| Mutation scope | Each domain test file declares `mutates(<Class>::class)` for the class it proves (ADR-0003) |
| Data | Use factories and states (data-model.md §4). Use `actingAs($user)`. Freeze time with `travelTo()`/`freezeTime()` **before** creating models (F-19). Use explicit titles where order or text is asserted. No reliance on seeders |
| Inertia write tests | Send `X-Inertia: true` through the `inertia()` helper in `tests/Pest.php` and assert the exact status from contracts §1.1.1 (F-01) |
| Browser tests | Locate elements by role and accessible name from contracts §6.1, and assert focus by the ids listed there |
| Prop contracts | One strict `assertInertia` shape test per page, without `etc()` (ADR-0002) |
| Ownership | Only the QA agent edits `tests/` (governance). Engineers never edit tests. Tests are never skipped, weakened or deleted to get green |
| Frontend unit | `*.test.ts` next to the module (first one: `resources/js/lib/overdue.test.ts`), importing from `vite-plus/test`, for pure TS logic only, run by `npm run test` (`vp test run`). No component tests in the MVP (ADR-0003). Date tests build `Date`s with the local-time constructor so they pass in any CI timezone |

## 5. Git, branches, commits and PRs
| Topic | Rule |
|---|---|
| Branches | `task/T-0XX-short-slug` (for example `task/T-012-todo-create`). The CI `scope` job reads the task ID from the branch name (R-005 §B6). Docs-only branches: `docs/<slug>` |
| Commits | **Conventional Commits**: `feat(todos): …`, `fix(…)`, `test(…)`, `docs(…)`, `chore(deps): …`, `ci: …`, `refactor(…)`. The subject is imperative and at most 72 characters. The body references the task (`Refs: T-012`) and the AC IDs it satisfies. Agent commits end with the attribution trailer required by the harness |
| Author identity | Agents commit and push as the **bot account** (G1-1). The owner merges |
| PRs | One task per PR, about 400 changed lines at most (the scaffold commit is the one-time exception, G1-7). The PR body includes the task ID, the ACs covered, the gate output, and a link to the handoff note. Behaviour changes update the docs in the same PR (CLAUDE.md rule 7) |
| Merging | Squash-merge to `main` by the owner only, after green CI, plus the QA and security reports (G4). Never push to `main` directly |
| Lockfiles | Commit `composer.lock` and `package-lock.json`. Dependency changes happen only in approved PRs (Dependabot groups, ADR-0001) |
