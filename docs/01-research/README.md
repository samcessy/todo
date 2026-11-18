# Research Queue (Phase 1, starts after G0)

| ID | Question | Feeds | Status |
|---|---|---|---|
| R-001 | Current Laravel and PHP versions, support windows, contents of the official Vue starter kit (Inertia, TS, auth features, test setup) | ADR-0001, T-001 | Approved (G1) |
| R-002 | Inertia monolith vs Sanctum API + standalone SPA, given D2 | ADR-0001 | Approved (G1) |
| R-003 | Test stack: Pest (with browser testing and mutation testing), Vitest, coverage tooling. Running CI on PostgreSQL alongside SQLite | ADR-0003 | Approved (G1) |
| R-004 | Laravel Cloud: environments, Postgres, region, cost, deploy hooks, preview environments. Forge as the fallback | ADR-0006 | Approved (G1) |
| R-005 | Laravel Boost features (MCP tools, guidelines), Claude Code subagents, skills, hooks. Can agent path scopes be technically enforced? | Agent setup, T-001 | Approved (G1) |
| R-006 | Production transactional email provider (EU region, e.g. Postmark or Resend): deliverability, pricing, Laravel mailer support | ADR-0006 amendment, before G5 | Not started |

Method: official sources only (laravel.com/docs, inertiajs.com, vuejs.org, pestphp.com, GitHub releases).
Each report uses `docs/templates/research.md` and gets an adversarial red-team pass before G1.
