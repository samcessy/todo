# Todo App: Agent Guide

This is the reference implementation of our AI-first engineering framework.
**The docs in `docs/` are the source of truth.** Code follows the docs.

## Current phase

**Phase 1 (Research): G0 approved 2026-11-16. Waiting for G1.**
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

## Commands (available once T-001 is done)

```bash
composer test          # pest (parallel, coverage)
vendor/bin/pint --test # PHP style
vendor/bin/phpstan     # static analysis
npm run lint           # eslint
npm run types          # vue-tsc --noEmit
npm run test           # vitest
npm run build          # vite production build
```

## Stack (proposed; pinned by R-001 and ADR-0001)

Laravel (current major) + official Vue starter kit (Inertia, Vue 3, TypeScript, Tailwind),
Pest, Vitest, Larastan, Pint, Laravel Boost MCP. Local environment: Laravel Herd. Hosting: Laravel Cloud.
