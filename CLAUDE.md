# Todo App: Agent Guide

This is the reference implementation of our AI-first engineering framework.
**The docs in `docs/` are the source of truth.** Code follows the docs.

## Current phase

**Phase 2 (Architecture): G1 approved 2026-11-16. Waiting for G2.**
No application code exists, and none may be written until Gate G3 (plan) is approved.
The gate status is recorded in `docs/governance.md` → Gate log.

## Read first

| Need | File |
|---|---|
| Gates, autonomy, escalation | `docs/governance.md` |
| What we are building | `docs/00-product/` |
| Accepted decisions | `docs/adr/` (none yet) |
| Your assignment | `docs/03-plan/tasks/<ID>.md` |
| Doc formats | `docs/templates/` |

## Rules for every agent

1. **Work only from a task file.** If there is no approved task, write no code.
2. **Stay inside the task's `scope.paths`.** If you need anything outside them, stop and write a question in the task file under `## Questions`.
3. **Human approval is required** before you add a dependency, change the schema or `contracts.md`, change auth or authorisation, edit CI gates, or merge.
4. **Never** read or edit `.env*`, push to `main`, skip, weaken, or delete tests, or lower coverage or PHPStan thresholds.
5. **Framework facts come from documentation, not memory.** Use Laravel Boost `search-docs` or the official docs. Research reports cite the URL and version for every claim.
6. **"Done" means the deterministic gates pass** (commands below). Your own judgment that the work is finished does not count.
7. **If you change behaviour, update the docs in the same PR.**
8. **End every task with a handoff note** in `docs/03-plan/handoffs/<ID>-<agent>.md` (template in `docs/templates/handoff.md`).

## Commands (available once T-001 is done; names are the starter kit's own)

```bash
composer test          # pint check → phpstan → pest
composer lint          # pint (fix)          | composer lint:check  # pint --test
composer types:check   # phpstan (level 8)   | composer ci:check    # full local CI
npm run check          # Vite+ (oxlint + oxfmt) | npm run check:fix
npm run types:check    # vue-tsc --noEmit
npm run build          # production build
```

Coverage and mutation gates run in CI only (PCOV is not installed locally).
Test output may be in agent format (laravel/pao) inside agent sessions.

## Stack (approved at G1; details in ADR-0001 to 0006)

Laravel 13 + official Vue starter kit (Inertia 3, Vue 3.5, TypeScript, Tailwind 4, shadcn-vue,
Fortify incl. 2FA/passkeys, Wayfinder), Pest 5, Larastan 3 (level 8), Pint, Vite+ (Oxlint/Oxfmt),
Laravel Boost MCP. PHP 8.4, Node 26. Local environment: Laravel Herd. Hosting: Laravel Cloud (Frankfurt).

## Framework docs

Boost `search-docs` covers Laravel 13 but **not** Inertia 3.x, Pest 5.x or Vue:
use inertiajs.com, pestphp.com and vuejs.org for those.
