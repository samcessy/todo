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

## Enforcement

| Rule | How it is enforced |
|---|---|
| `.env` access, push to main, destructive DB commands | `.claude/settings.json` deny and ask rules (enforced by the harness) |
| PHP formatting | PostToolUse hook `.claude/hooks/pint-on-edit.sh` |
| Agent path scopes | Agent instructions, plus the PR diff review at G4. **These are not technically enforced yet.** A path-guard hook is a candidate for T-001 |
| Merge and deploy approval | GitHub branch protection and Environment required reviewers (T-002) |

## Gate log

| Gate | Date | Approver | Outcome | Notes |
|---|---|---|---|---|
| G0 | 2026-11-16 | Project owner | Approved | Decisions D1–D8 accepted as recommended |
