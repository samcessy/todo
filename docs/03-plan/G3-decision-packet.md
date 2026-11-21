# G3 Decision Packet

**Status:** Approved 2026-11-18 (all 12 decisions as recommended, including G3-4) · **Prepared:** 2026-11-18 by the orchestrator
**Inputs:** `roadmap.md`, `tasks/T-001.md` to `tasks/T-026.md`, `04-quality/test-strategy.md`, `templates/task.md` (revised), G2 documents (accepted 2026-11-18).

## What G3 approves

Gate G3 (Planning → Build) approves the task breakdown, the order and the Definition of Done (`governance.md`). After approval, T-001 becomes `ready` and no other code task starts until its dependencies are merged.

## Mechanical check (2026-11-18)

A script over the task files, the user stories and the traceability matrix confirmed:

| Check | Result |
|---|---|
| Every AC ID (34 story ACs + CC.AC1–CC.AC3) is in some task's `acceptance` | 37 / 37 |
| Every T-xx, NFR-xx and FR-xx row of the matrix is in some task's `acceptance` | All except **NFR-06**, which is a manual G5 check by design (test-strategy §10) |
| Every test file named in the matrix lies inside some task's `scope.paths` | Yes |
| Every test file is in the test-strategy §9 map | Yes |
| Every `depends_on` exists, and the dependency graph has no cycle | Yes (26 tasks) |

## Decisions required

| # | Decision | Recommendation |
|---|---|---|
| G3-1 | **Task breakdown and order** | Approve the 26 tasks in 5 milestones (`roadmap.md` §1–§3). Critical path: 13 PRs in sequence; M1 and M3 run in parallel after M0 |
| G3-2 | **CI before features** | No feature task (T-007 onward) starts until T-002 is merged and its checks are required on `main`. T-002 waits for T-003, T-004 and T-006, so every required check passes on day one. The 4 M0 tasks before it carry their local gate output in the handoffs |
| G3-3 | **One task, one branch, one PR, several agents** | QA's failing tests are the PR's first commit; the implementing agents follow in the order listed in `agents`. Tests-first is then visible in the PR history, and the scope check sees one task per PR |
| G3-4 | **Role scopes for build files** (owner edit of `.claude/hooks/scopes.json`) | Add `scripts/*`, `composer.json`, `package.json` and `phpstan.neon` to `devops-engineer`, and `phpunit.xml` to `qa-engineer`. Today no role may edit them, so the orchestrator (main session) would have to, which mixes coordination with implementation. Dependency installs (lock files) stay with the orchestrator behind the existing ask rules. **Alternative:** leave `scopes.json` as is; the orchestrator does these edits |
| G3-5 | **`js-unit` before the first frontend test** | `npm run test` = `vp test run --passWithNoTests` from T-002 until T-013 removes the flag. Without it the required `js-unit` check fails on an empty suite. Removing the flag is a tightening and is part of T-013's Definition of Done |
| G3-6 | **The scope check reads the task file from `main`** | `check-scope.mjs` takes `scope.paths` from the base branch, so a PR cannot widen its own scope. Consequences: task files are merged with this G3 PR before work starts, and a scope change is a separate docs PR that the owner approves |
| G3-7 | **Global Definition of Done** | Approve `roadmap.md` §4, including the security-review trigger: any task touching `app/`, `routes/`, `config/`, `bootstrap/`, `.github/` or auth gets a `T-XXX-security.md` with no open BLOCKER |
| G3-8 | **WIP limit** | At most 2 tasks in progress and 2 in review. Tasks that edit the same shared file (`AppServiceProvider.php`, `bootstrap/app.php`) are not in review at the same time |
| G3-9 | **Coverage gate on kit-only code** | If the kit alone is under 85 % (or a directory under 90 %) at T-002, QA adds tests for kit code in T-002, or the task stops and escalates. The threshold is never lowered |
| G3-10 | **Route-contract check placement** | The route-list diff against contracts §1.2 becomes a step of the required `lint` job in T-025, when all five routes exist. No new required check. Until then, T-012's handoff carries the same diff run by hand |
| G3-11 | **G2 document references** | The G2 docs say "T-001" or "T-002" for work now split across T-001 to T-006 and T-025. They stay unedited; `roadmap.md` §7 is the mapping |
| G3-12 | **Before G5, outside the task list** | R-006 (mail provider) and the error-tracking decision (G2-11) run as research with a red team. If either needs a dependency or code, it becomes T-027 onward through a docs PR |

## Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| T-002 exceeds about 400 lines (workflows plus three scripts plus fixtures) | Medium | Low | Split into T-002 (scripts and fixtures) and a new T-027 (workflows), through a docs PR, before work starts |
| Kit accessibility findings in `components/ui/*` (vendor code) at T-006 | Medium | Medium | Escalate each to the owner: wrap, fix upstream, or approve as a kit exception (like W-1) |
| T-005 corrects a contract cell (status, query budget, `auth.user` keys) | Medium | Low | The correction is an owner-approved contract change in T-005, before any test depends on it |
| Browser-plugin APIs missing (focus, keys, 320 px) | Low | Medium | ADR-0003: a missing API becomes a documented manual check with the owner's approval, never a silent gap |
| The owner's review queue becomes the bottleneck (26 PRs, single approver D6) | Medium | Medium | WIP limit (G3-8); small PRs; QA and security handoffs written for fast review |
| Laravel Cloud facts still UNVERIFIED (idle timeout, edge HTTPS redirect, trusted proxies) | Medium | Low | All checked on staging in T-025, before G5 |

## Owner steps after approval

1. Merge PR #1 (Phase 2), then this PR (Phase 3).
2. If G3-4 is accepted: add the five entries to `.claude/hooks/scopes.json`.
3. Restart Claude Code from `/Users/air/code/todo-app` so the guard, the agents and the settings load.
4. Run the T-001 installer step (the task file has the exact command).
