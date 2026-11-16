```yaml
id: T-XXX
title:
type: feature | chore | bug | maint | research
stories: []            # e.g. [US-02, US-04]
agent:                 # laravel-engineer | vue-engineer | qa-engineer | devops-engineer | architect
depends_on: []
context:               # docs the agent must read (and only these)
  - docs/02-architecture/contracts.md
scope:
  paths: []            # files/dirs the agent may modify
  forbidden: []
acceptance: []         # AC IDs, e.g. US-05.AC1..AC5
tests_first: true      # QA writes failing tests from AC before implementation
definition_of_done:
  - All listed AC tests pass; full suite green
  - pint, phpstan, eslint, vue-tsc clean
  - Docs updated or N/A explained
  - Handoff note written
human_approval_required: []
estimate: XS | S | M   # anything larger must be split
status: draft | ready | in-progress | in-review | done | blocked
```

## Notes

## Questions
<!-- Agents write escalations here and stop. -->
