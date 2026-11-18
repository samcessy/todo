# Handoff: T-001 (orchestrator)

## What changed
- `90ad2fb`: the unmodified output of `laravel new todo-scaffold --vue --pest --database=sqlite --npm --no-boost --no-interaction` (Laravel Installer 5.32.0), copied with `rsync -a --ignore-existing --exclude .git --exclude .env`. The only file that already existed in the repo was `.gitignore`.
- `3493571`: `.gitignore` = the kit's file without `/.claude` and `/CLAUDE.md`, plus the project's `.env.*`, `!.env.example` and `.claude/settings.local.json` rules.
- `42b60a0`: `laravel/boost` v2.10.4 (dev); `config/boost.php` published and hardened; Boost guidelines block appended to `CLAUDE.md`; `CLAUDE.md` commands updated.

## Decisions made inside the task scope
- **Boost options:** `boost:install --guidelines --mcp` only. Agent skills were not installed (not part of G1-10). Boost also auto-selected Junie (detected from `.idea/`); its files (`.junie/`, `AGENTS.md`) were deleted, since D7 is Claude Code only. `.mcp.json` and `boost.json` stay gitignored (kit default), so each machine keeps its own MCP config.
- **Boost keys (security.md T-19, A-17), read from the installed source:**
  - `boost.tinker_tool_enabled`, read at `vendor/laravel/boost/src/Mcp/Tools/Tinker.php:29`, default `false`. Set to `false` explicitly.
  - `boost.rules.enabled`, read at `src/Mcp/Tools/RecordRule.php:32`, default **`true`**. Set to `false` (not env-overridable).
  - `boost.mcp.tools.exclude`, read at `src/Mcp/ToolRegistry.php:28`. Both tool classes listed as a second guard.
  - Verified after boot: `config('boost.rules.enabled') === false`, `config('boost.tinker_tool_enabled') === false`, exclude list has both classes. With rules off, Boost no longer writes the "Project Rules" section into `CLAUDE.md`.
- **Boost's `## Tinker` guideline** comes from its core guideline and cannot be excluded by name. `CLAUDE.md` now tells agents to ignore it (Tinker is denied by settings).
- **Kit CI kept.** The kit's `.github/workflows/tests.yml` already meets the T-18 posture (top-level `permissions: contents: read`, SHA-pinned actions, `persist-credentials: false`, no secrets). It runs `composer setup` and `composer ci:check` on Node 22 and gives basic CI until T-002 replaces it. The kit's `.github/dependabot.yml` covers only `github-actions`; T-002 extends it.

## Deviations from the spec
- **The installer was run by the orchestrator, not the owner** (owner's choice in this session, 2026-11-18). It ran from this session under `env -i` with only `HOME`, `USER`, `LANG`, `TERM` and a fixed `PATH`; inside that shell `env | grep -ciE '^(AI_AGENT|CLAUDE)'` printed `0`, so none of the agent variables R-001 flagged reached it.
- **`.env.example` not edited.** The owner step (add `BCRYPT_LIMIT=72`, `INERTIA_ENCRYPT_HISTORY=false`, `INERTIA_SSR_ENABLED=false`) is still open; agents cannot touch `.env*`.
- **Branch base.** The branch starts from `docs/phase-3` (PRs #1 and #2 not yet merged), so the PR is stacked on #2.

## Commands run and results
Kit baseline, run in `/Users/air/code/todo-scaffold` (identical to `90ad2fb`, with the installer's `.env`):
```
php artisan test                        →  pass: 42 tests, 147 assertions (all Pest style; output in laravel/pao JSON format)
composer lint:check                     →  pass
composer types:check (kit level 7)      →  FAIL: PHPStan worker hit the 128M memory limit (R-001 E23; T-003 adds --memory-limit=1G)
phpstan analyse --memory-limit=1G       →  pass at level 7, 0 errors
phpstan analyse --level=8 --memory-limit=1G →  10 errors (as R-001 predicted), all User|null or Carbon|null:
    Settings/ProfileController.php:33,35,36,39,55; Settings/SecurityController.php:26,34,46,58;
    Requests/Settings/ProfileUpdateRequest.php:31   → T-003
npm run check                           →  pass
npm run types:check                     →  pass
npm run build                           →  pass
```
In the repo after the Boost commit:
```
composer lint:check                     →  pass (after Pint imported the two Boost classes in config/boost.php)
php artisan test                        →  41 errors "No application encryption key has been specified": the repo has no .env yet (owner step below)
git check-ignore -v .env                →  .gitignore:15:.env  .env
git check-ignore .env.example           →  (not ignored; tracked)
```

### Versions (security.md T-14)
`php artisan --version`: Laravel Framework 13.43.0. Laravel Installer 5.32.0. Node v26.12.1.

`composer show --direct`:
```
fakerphp/faker 1.24.1
inertiajs/inertia-laravel 3.7.0
larastan/larastan 3.15.0
laravel/boost 2.10.4
laravel/chisel 0.1.1
laravel/fortify 1.43.0
laravel/framework 13.43.0
laravel/pail 1.3.0
laravel/pao 1.1.5
laravel/pint 1.32.3
laravel/sail 1.71.0
laravel/tinker 3.2.0
laravel/wayfinder 0.1.23
mockery/mockery 1.6.15
nunomaduro/collision 8.9.6
pestphp/pest 5.3.7
pestphp/pest-plugin-laravel 5.0.1
```
`npm ls --depth=0` (installed; the `UNMET OPTIONAL` Linux and Windows native binaries are platform-specific and expected on macOS):
```
@inertiajs/vite@3.11.0
@inertiajs/vue3@3.11.0
@laravel/multiplex@0.4.5
@laravel/passkeys@0.2.0
@laravel/vite-plugin-wayfinder@0.1.11
@lucide/vue@1.75.0
@tailwindcss/vite@4.3.5
@types/node@22.20.5
@vitejs/plugin-vue@6.0.11
@vueuse/core@12.8.2
class-variance-authority@0.7.1
clsx@2.1.1
concurrently@10.0.7
laravel-vite-plugin@3.2.1
reka-ui@2.11.0
tailwind-merge@3.7.0
tailwindcss@4.3.5
tw-animate-css@1.4.0
typescript@5.9.3
vite-plus@1.4.0
vite@npm:@voidzero-dev/vite-plus-core@1.4.0
vue-input-otp@0.3.2
vue-sonner@2.0.9
vue-tsc@3.3.14
vue@3.5.45
```
Drift since R-001 E10 (2026-11-16): framework 13.42.0 → 13.43.0, `laravel/fortify` 1.42.0 → 1.43.0, `pestphp/pest` 5.3.6 → 5.3.7, `laravel/tinker` 3.1.0 → 3.2.0, `laravel/sail` 1.70.0 → 1.71.0; `pestphp/pest-plugin-drift` is no longer installed. No package outside R-001's list.

## Open questions / follow-ups
1. **Owner:** create the local `.env`: `cp .env.example .env && php artisan key:generate` (or copy `../todo-scaffold/.env`), then add the three `.env.example` lines. Then `php artisan test` should give 42 passed.
2. **T-005:** re-verify the Fortify line references in the G2 docs (`fortify/routes/routes.php:64-83`, verified on 1.42.0) against 1.43.0.
3. **T-003:** the 10 level-8 errors above; `composer types:check` needs `--memory-limit=1G`.
4. **T-002:** replaces the kit's `tests.yml` (Node 22) with the ADR-0003 jobs on Node 26 and extends Dependabot to `composer` and `npm`.
5. `/Users/air/code/todo-scaffold` can be deleted once this PR is merged.
