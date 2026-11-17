# Governance: Gates, Autonomy, Escalation

## Principle

AI drafts, deterministic tools verify, and humans decide.

## Approval gates

| Gate | Transition | Human approves | Evidence |
|---|---|---|---|
| G0 | Discovery → Research | Vision, MVP scope, stories and AC, NFRs, decisions in `assumptions.md` | `docs/00-product/*` |
| G1 | Research → Architecture | Technology recommendations, risk register | `docs/01-research/*` (sources cited, versions verified by command) |
| G2 | Architecture → Planning | Each ADR, data model, contracts, security model | `docs/02-architecture/*`, `docs/adr/*` |
| G3 | Planning → Build | Task breakdown, order, Definition of Done | `docs/03-plan/*`, `docs/04-quality/test-strategy.md` |
| G4 | Each PR → `main` | Code review and merge | Green CI, QA report, security report, handoff notes |
| G5 | Staging → Production | Release go/no-go | Staging smoke tests, changelog, rollback plan |
| G6 | After an incident or major change | Postmortem actions, process changes | `docs/05-operations/incidents/*` |

**Approver:** the project owner, as a single approver (decision D6 in `00-product/assumptions.md`).
The compensating control is that the AI QA and AI security reviews are mandatory inputs to G4 and G5.

**Identities (G1-1, G2-5):** agents push and open PRs as a separate low-privilege bot GitHub account,
using a classic PAT with only the `public_repo` scope (no `workflow` scope, 90-day expiry) and no SSH keys,
so the bot cannot change `.github/workflows/*`. *(Token type confirmed by the owner, 2026-11-17.)*
The owner reviews and merges as themselves. The owner's own `gh` login is never available in agent sessions.

**Production release (G1-2):** CI deploys staging. Production is deployed only by the owner, who triggers
the owner-held Laravel Cloud deploy hook with the staged commit SHA. The hook URL is never stored in GitHub,
in the repo, or in any agent-readable location.

## Autonomy matrix

| AI acts autonomously | AI proposes, human approves | AI never does |
|---|---|---|
| Write code and tests on a feature branch, within the task scope | Adding any composer or npm dependency | Read or edit `.env*` or production secrets |
| Run tests, linters, and static analysis | Schema changes after the baseline migration | Push or force-push to `main` |
| Refactor inside the task's files | Accepting or changing an ADR | Skip, weaken, or delete tests to get CI green |
| Draft docs, ADRs, and PR descriptions | Changes to auth, authorisation, or sessions | Lower coverage or PHPStan thresholds |
| Fix lint and type errors | Changes to `contracts.md` | Run `migrate:fresh` or `db:wipe` outside local or test environments |
| Open draft PRs | Merging a PR, deploying, rolling back | Deploy to production |
| Triage logs and propose fixes | Closing a security finding as "won't fix" | |

## Escalation rule

An agent that hits something outside its task, such as an ambiguous requirement, a test that seems wrong,
or a need for a new dependency or a file outside its scope, **stops** and writes the question under `## Questions`
in the task file. It does not improvise.

## Loop guardrails

- If a task fails the gates 3 times, it is escalated to the human. Repeated failure usually means the spec is wrong.
- PRs are limited to about 400 changed lines.
- Engineer agents never edit `tests/`. The QA agent never edits `app/` or `resources/js/` (except `*.spec.ts`).

## Exceptions

| ID | Rule excepted | Scope | Approved | Conditions |
|---|---|---|---|---|
| E-1 | "Never delete tests" | Delete `tests/Feature/DashboardTest.php` (replaced 1:1 by `IndexTest`: US-01.AC1 plus 200 for a verified user) and retarget 6 kit assertions from `dashboard` to `todos.index` | Owner, G2-8, 2026-11-17 | Done by the QA agent in one dedicated task with `human_approval_required: [E-1]`; reviewed at G4 |

## Enforcement

| Rule | How it is enforced | Strength |
|---|---|---|
| `.env` access, push to main, force push, destructive DB commands, `gh pr merge`/`secret` | `.claude/settings.json` deny and ask rules | Best-effort: Bash rules can be bypassed by unusual command forms or scripts. A sandbox is planned |
| Push to `main`, merge approval | GitHub branch protection: PR required, 1 approval, required checks, **no bypass** (free on the public repo; T-002). Bot identity (G1-1) | **Authoritative** |
| Workflow changes / secret exfiltration | Bot token without `workflow` scope + CI `workflow-lint` + `staging` Environment secret limited to `main` (G2-5) | **Authoritative** |
| Production deploy | Owner-held deploy hook (G1-2) | **Authoritative while custody holds**: agents never have the hook. Never paste it into an agent-visible shell (shell history, A-16) |
| Agent role scopes | PreToolUse `.claude/hooks/path-guard.sh` + `scopes.json` (routes on `agent_type`; protects `.claude/settings.json` and hooks from every agent, including the main session) | Best-effort for Bash writes; reliable for Edit/Write |
| Task scopes (`scope.paths`) | CI scope check: the PR's changed files must match the task's `scope.paths` (T-002) | **Authoritative** |
| Raw HTML in templates | CI `vue/compiler-sfc` check (ADR-0003) | **Authoritative** |
| PHP formatting | PostToolUse `.claude/hooks/pint-on-edit.sh` (not run for Bash edits) + CI `pint --test` | CI is authoritative |

Changes to `.claude/settings.json`, `.claude/hooks/*` and `scopes.json` are made by the owner only.

## Gate log

| Gate | Date | Approver | Outcome | Notes |
|---|---|---|---|---|
| G0 | 2026-11-16 | Project owner | Approved | Decisions D1–D8 accepted as recommended |
| G1 | 2026-11-16 | Project owner | Approved | All 11 decisions in docs/01-research/G1-decision-packet.md accepted as recommended |
