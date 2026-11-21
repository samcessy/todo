# Documentation Index

These docs are the project's source of truth. Every change goes through a pull request, the same as code.

| Folder | Written in | Contents |
|---|---|---|
| `governance.md` | P0, then as needed | Gates, autonomy matrix, escalation, gate log |
| `00-product/` | P0 | Vision, requirements, user stories, assumptions and decisions |
| `01-research/` | P1 | `R-xxx` research reports and the risk register |
| `02-architecture/` | P2 | Architecture, data model, contracts, security, coding standards |
| `adr/` | P2 onward | Architectural Decision Records (`NNNN-title.md`) |
| `03-plan/` | P3 onward | Roadmap, `tasks/T-xxx.md`, `handoffs/`, tech-debt register |
| `04-quality/` | P3 onward | Test strategy and the traceability matrix (AC → tests) |
| `05-operations/` | P7 onward | Deployment, runbook, monitoring, `incidents/` |
| `templates/` | P0 | Templates for research, ADRs, tasks, and handoffs |

## Doc-drift rule

Every PR that changes routes, schema, or behaviour updates the matching doc in the same PR.
CI (the `lint` job, from T-025) compares `php artisan route:list --json` against `02-architecture/contracts.md` §1.2.
