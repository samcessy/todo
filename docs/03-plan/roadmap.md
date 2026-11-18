# Roadmap

**Status:** Approved (G3, 2026-11-18) · **Author:** orchestrator · **Date:** 2026-11-18
**Inputs:** `00-product/*` (G0), ADR-0001 to 0006 and `02-architecture/*` (G2, 2026-11-18), `04-quality/traceability-matrix.md`, `governance.md`.
**Companion docs:** `tasks/T-001.md` to `tasks/T-026.md`, `04-quality/test-strategy.md`, `G3-decision-packet.md`.

## 1. Milestones

| Milestone | Goal | Tasks | Exit criterion |
|---|---|---|---|
| **M0 Foundation** | The kit is in the repo, every PR gate exists and is required on `main` | T-001 to T-006 | T-002 merged; `gh api repos/samcessy/todo/branches/main/protection` lists every required check |
| **M1 Todo server** | All five todo routes match `contracts.md` §1 and pass their feature tests | T-007 to T-012 | Route list equals contracts §1.2; US-01 to US-07 server ACs green |
| **M2 Todo UI** | The full journey works by mouse and keyboard and passes the accessibility scan | T-013 to T-018 | Every browser row in the traceability matrix green |
| **M3 Hardening** | Every ASVS 5.0.0 L1 control in `security.md` that lives in code is in place | T-019 to T-023 | Every T-xx feature-test row green |
| **M4 Staging and release readiness** | Staging deploys from `main` with smoke checks; G5 checklist can be run | T-024 to T-026, R-006, error-tracking decision | G5 go/no-go checklist (ADR-0006) complete |

M1 and M3 can run in parallel once M0 is done. M2 starts as soon as the server task it builds on is merged (see §3).

## 2. Task list

| ID | Title | Agents (in order) | Depends on | Est. | Adds tests for |
|---|---|---|---|---|---|
| T-001 | Scaffold import and Boost | owner, orchestrator | — | (scaffold exception, G1-7) | — (kit tests stay green) |
| T-002 | PR CI pipeline and repository hardening | qa, devops, owner | T-001, T-003, T-004, T-006 | M | NFR-04, NFR-07, NFR-08, NFR-11 driver test, T-14, T-15, T-18, T-20 |
| T-003 | Static-analysis and arch baseline | qa, laravel, orchestrator | T-001 | S | T-12 arch, NFR-08 arch |
| T-004 | Raw-HTML template check | qa, devops | T-001 | S | CC.AC3 (CI), T-03, NFR-03 |
| T-005 | Kit spike: confirm the open G2 cells | architect | T-001 | S (docs) | — (corrects contracts and security cells) |
| T-006 | Browser-test harness and kit accessibility baseline | orchestrator, qa | T-001 | S | CC.AC1 (kit pages) |
| T-007 | Todo schema, model and strictness flags | qa, laravel | T-002 | S | US-01.AC5, NFR-11 |
| T-008 | Todos home, read path and Dashboard removal (E-1) | qa, laravel, vue | T-005, T-007 | M | US-01.AC1–AC3, US-03.AC1–AC4, AC6, T-04 (page shape), FR-01 |
| T-009 | Filter on the server | qa, laravel | T-008 | S | US-07.AC1–AC3 (server) |
| T-010 | Create todo (server) and write rate limit | qa, laravel | T-008 | S | US-02.AC1–AC5, T-08 (todo writes) |
| T-011 | Update todo, TodoPolicy and the IDOR suite | qa, laravel | T-010 | M | US-04.AC1–AC3, T-01, T-02 |
| T-012 | Completion and delete (server) | qa, laravel | T-011 | S | US-05.AC1–AC4, US-06.AC1, AC3 |
| T-013 | Overdue logic | qa, vue, devops | T-002 | XS | US-03.AC5 (unit) |
| T-014 | List view UI | qa, vue | T-006, T-012, T-013 | M | US-03.AC3, AC5, US-05.AC5, CC.AC3 (browser) |
| T-015 | Filter navigation UI | qa, vue | T-009, T-014 | S | US-03.AC4, US-07.AC1, AC3, AC4 (browser) |
| T-016 | Create form UI | qa, vue | T-010, T-014 | S | US-02.AC1, AC6, CC.AC2 (create) |
| T-017 | Edit and delete dialogs UI | qa, vue | T-012, T-016 | M | US-04.AC1, US-06.AC1, AC2, AC4, CC.AC2 (edit) |
| T-018 | Accessibility and responsive sweep | qa, vue | T-015, T-017 | S | CC.AC1 (todo states), NFR-09 |
| T-019 | Transport, headers and cookies | qa, laravel, vue | T-002 | S | T-06 (cookie), T-13, T-24 |
| T-020 | Password policy | qa, laravel, owner | T-002 | S | T-23 |
| T-021 | Auth rate limits and two-factor tests | qa, laravel | T-020 | S | T-08 (auth), US-01.AC4, US-01.AC6 |
| T-022 | Sessions, history and DevTools | qa, laravel | T-008, T-021 | S | T-06 (lifetime), T-07, T-11 |
| T-023 | Errors, logging, shared props and health | qa, laravel | T-019 | S | T-04 (shared props), T-05, T-12, T-19, NFR-10 |
| T-024 | Staging seeder | qa, laravel | T-007 | XS | T-25 |
| T-025 | Route-contract check, staging deploy and smoke | laravel, devops, owner | T-012, T-019, T-022, T-024 | M | T-01 (route list), staging smoke (T-06, T-13, T-24), NFR-05 |
| T-026 | Operations runbooks | devops | T-025 | S (docs) | — |

Research and decisions outside the task list, both **before G5**: **R-006** (mail provider, architect + red team) and the **error-tracking decision** (G2-11, with the redaction review of security.md T-12). If either adds a dependency or code, it becomes a new task (T-027 onward).

## 3. Order and critical path

```
T-001 ─┬─ T-003 ─┐
       ├─ T-004 ─┼─ T-002 ─┬─ T-007 ─ T-008 ─┬─ T-009 ────────────────┐
       ├─ T-006 ─┘         │        (T-005)  ├─ T-010 ─ T-011 ─ T-012 ─┼─ T-014 ─┬─ T-015 ─┐
       └─ T-005 ───────────┤                 │                          │         ├─ T-016 ─ T-017 ─ T-018
                           ├─ T-013 ─────────┼──────────────────────────┘         │
                           ├─ T-019 ─ T-023  │
                           ├─ T-020 ─ T-021 ─ T-022 (also needs T-008)
                           └─ T-024 (after T-007)          T-012 + T-019 + T-022 + T-024 ─ T-025 ─ T-026
```

- **Critical path:** T-001 → T-003/T-004/T-006 → T-002 → T-007 → T-008 → T-010 → T-011 → T-012 → T-014 → T-016 → T-017 → T-018. That is 13 PRs in sequence.
- **CI before features (G3-2).** No feature task (T-007 onward) starts until T-002 is merged and its checks are required on `main`. M0 tasks before T-002 are reviewed with the gate output recorded in their handoffs.
- **Shared files.** `app/Providers/AppServiceProvider.php` is edited by T-007, T-010, T-019, T-020 and T-021; `bootstrap/app.php` by T-019, T-021, T-023 and T-025. Branch protection requires branches to be up to date, so the second PR rebases. Two tasks that touch the same file are not in review at the same time.
- **WIP limit:** at most 2 tasks in progress and 2 in review, so the owner's review queue stays short.

## 4. Global Definition of Done (every task)

A task is done only when **all** of these hold. The handoff note records the evidence.

1. **Tests first (when `tests_first: true`).** The branch's first commit is the QA agent's failing tests. The handoff for QA shows them failing for the right reason (missing behaviour, not a syntax or setup error).
2. **Acceptance tests pass.** Every ID in `acceptance` has at least one test whose name starts with that ID, and all of them pass.
3. **Every required CI check is green** on the PR (from T-002 on). Before T-002, the handoff records the local output of `composer test`, `npm run check`, `npm run types:check` and `npm run build`.
4. **Scope.** The PR changes only `scope.paths`, the task file and its handoff notes. The CI `scope` job enforces it from T-002 on.
5. **No weakened gates.** No test is skipped, weakened or deleted (except under an approved exception such as E-1); no threshold is lowered; no `@phpstan-ignore` or `@pest-mutate-ignore` without a same-line reason, each listed in the handoff.
6. **Docs.** Any change of behaviour, routes, schema or contract is reflected in the matching doc in the same PR (CLAUDE.md rule 7). Contract changes have the owner's approval recorded in the task file.
7. **Traceability.** The QA agent updates `04-quality/traceability-matrix.md`: rows for this task move from Planned to Passing, with the real test names.
8. **Handoffs.** Each agent writes `docs/03-plan/handoffs/T-XXX-<role>.md` (template `docs/templates/handoff.md`). Tasks that touch `app/`, `routes/`, `config/`, `bootstrap/`, `.github/` or auth also get a security review, `T-XXX-security.md`, with no open BLOCKER.
9. **PR.** Opened by the bot as a draft, marked ready when 1 to 8 hold. The body lists the task ID, ACs, gate output and handoff links. About 400 changed lines at most.
10. **Owner approval (G4).** The owner approves and squash-merges. Items in `human_approval_required` are confirmed in the PR before merge.

## 5. How a task runs

1. The orchestrator sets `status: ready` once its dependencies are merged and creates the branch `task/T-XXX-<slug>` from `main`.
2. **QA (tests-first mode)** reads only the task file, `user-stories.md`, `contracts.md` and `test-strategy.md`. It writes the failing tests and commits them as `test(...): ... (red)`.
3. **The implementing agents** work in the order listed in `agents`. Each commits separately, stays inside its role scope (`.claude/hooks/scopes.json`) and the task's `scope.paths`, and stops with a question in the task file if something is outside them.
4. **QA (verification mode)** runs the full suite, checks the diff for weakened tests and updates the traceability matrix.
5. **Security reviewer** for tasks named in §4.8.
6. The bot opens or updates the PR. Three failed gate runs on the same problem escalate to the owner (governance loop guardrails).

## 6. Owner steps by milestone

| When | Step | Why |
|---|---|---|
| Now | Merge PR #1, turn on branch protection on `main` (PR + 1 approval, no bypass), restart Claude Code from `todo-app` | Guard and agents load; `main` is protected before any code |
| T-001 | Run the Laravel installer in a plain terminal; approve the Boost install | The installer needs an environment without agent variables (R-001 red-team finding 1) |
| T-001 | Edit `.env.example` (`BCRYPT_LIMIT=72`, `INERTIA_ENCRYPT_HISTORY=false`, `INERTIA_SSR_ENABLED=false`) | `.env*` is denied to every agent |
| G3 (if G3-4 accepted) | Add the role-scope entries in `scopes.json` | Owner-only file |
| T-002 | Create the bot account and its classic `public_repo` token; repository Actions settings; secret scanning; branch-protection required checks; run the verification commands | security.md T-16, T-18; ADR-0006 |
| T-020 | Approve the password-rule change (auth change) | governance |
| T-025 | Laravel Cloud app and environments (push-to-deploy off, PHP 8.4, env vars), GitHub Environment `staging` with its two secrets, run `StagingSeeder` once | ADR-0006 |
| Before G5 | R-006 decision, error-tracking decision, DPA review, manual Safari and screen-reader smoke | ADR-0006 G5 checklist |

## 7. Where the G2 documents' task references land

The G2 documents were written before the breakdown and refer to "T-001" and "T-002" for work that is now split. They are not edited; this table is the mapping.

| G2 reference | Now done in |
|---|---|
| "T-001": scaffold, Boost, `composer show --direct` / `npm ls --depth=0`, `git check-ignore .env` | T-001 |
| "T-001": PHPStan level 8 fixes, arch-test ignores, `types:check` memory flag | T-003 |
| "T-001": `check-raw-html.mjs` and its fixtures | T-004 |
| "T-001 spike": status table (contracts §1.1.1), `auth.session` in the route list (§1.2), query budget (§3), kit `auth.user` keys (T-04), password-change session (T-06), Boost key names (T-19), `currentPasswordRules()` callers (T-23), `HttpsRedirectTest` mechanism (T-24, N-2), passkey-landing feasibility, Node 26 LTS on Cloud | T-005 |
| "T-001 spike": browser-plugin items (ADR-0003 F-09 list), kit accessibility baseline, kit `InputError` linkage (CC.AC2 scope) | T-006 |
| "T-001": `; Secure` on the two UI cookies (W-1) | T-019 |
| "T-002": CI workflow, `check-workflows.mjs`, Dependabot, bot, Actions settings, branch protection | T-002 |
| "T-002": route-list contract job | T-025 (needs all five routes) |
| "T-002": staging workflow, trusted proxies, staging smoke, Cloud `/up` check, Cloud idle timeout | T-025 |
