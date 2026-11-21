```yaml
id: T-XXX
title:
type: feature | chore | bug | maint | research
milestone: M0 | M1 | M2 | M3 | M4
stories: []            # e.g. [US-02, US-04]
agents:                # in working order; with tests_first the qa-engineer comes first
  - qa-engineer        # laravel-engineer | vue-engineer | qa-engineer | devops-engineer | architect | orchestrator
depends_on: []         # tasks that must be merged to main first
context:               # docs the agents must read (and only these)
  - docs/02-architecture/contracts.md
scope:
  paths: []            # globs the PR may change (CI scope job); each agent is further limited by its role (scopes.json)
  forbidden: []
acceptance: []         # AC, threat or NFR IDs whose tests this task adds, e.g. US-05.AC1..AC5, T-01
tests_first: true      # QA commits failing tests from the ACs before any implementation commit
definition_of_done:
  - Global Definition of Done (docs/03-plan/roadmap.md §4)
human_approval_required: []   # decisions the owner must approve before or at G4 (dependency, auth change, contract change, exception)
owner_steps: []        # actions only the owner can take (GitHub settings, Cloud, .env.example, installer)
estimate: XS | S | M   # XS < 50, S < 200, M < 400 changed lines including tests; anything larger is split
status: draft | ready | in-progress | in-review | done | blocked
branch: task/T-XXX-short-name   # required: the CI scope check reads the task ID from the branch name
```

## Notes

## Questions
<!-- Agents write escalations here and stop. -->
