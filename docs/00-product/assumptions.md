# Assumptions and Phase 0 Decisions

**Status:** Approved (G0, 2026-11-16). Each decision has a revisit trigger. If the trigger fires, the decision is reopened.

## Decisions (answers to the Part G clarification questions)

| ID | Question | Recommendation | Rationale | Revisit trigger |
|---|---|---|---|---|
| D1 | Users and auth | **Multi-user with private lists.** Email and password with **email verification**, password reset, and **account deletion**. No social login | The starter kit provides all of this, so we build no custom auth code. Verification blocks spam accounts. Account deletion meets the basic GDPR erasure duty | Organisational SSO is needed, or there are sign-up abuse problems |
| D2 | Clients | **Web only. No API in the MVP.** Inertia monolith (to be confirmed in R-002 and ADR-0001) | Session auth with built-in CSRF, no token or CORS handling, one deploy. Lowest security surface | A mobile or third-party client is committed to. Then add a Sanctum `/api/v1` layer next to the web routes |
| D3 | Hosting | **Laravel Cloud** for staging and production. **Laravel Herd** locally (already installed) | First-party managed hosting: zero-downtime deploys, managed DB, autoscaling, no server administration. Herd matches local PHP to the target | Cost or region requirements rule it out. Fallback is Forge + VPS |
| D4 | Database | **PostgreSQL** in staging and production. **SQLite** for local development and the fast test suite. **CI also runs the suite on PostgreSQL** | Postgres has strict types and good managed options. SQLite keeps local tests fast. The Postgres CI job catches dialect differences | Dialect bugs keep slipping through. Then standardise on Postgres everywhere |
| D5 | Feature edges | **Due date: included** (optional field). **Priority, reordering, tags: out of scope** | Due dates add value for a nullable column and one validation rule. Reordering needs a `position` column, an endpoint, and a drag library, which is too much for the MVP | Users ask for it after the MVP |
| D6 | Team and approvals | **One human approver** (the project owner) for every gate | It's a solo project, and requiring two humans would stall it. The mandatory AI QA and AI security reviews act as extra checks | A second human joins. Then add CODEOWNERS and 2 approvals for G4 on `app/Policies`, auth, and `.github/` |
| D7 | Agent tooling | **Claude Code + Laravel Boost MCP**, with `CLAUDE.md` as the canonical guide. Boost can also generate `AGENTS.md`, so other tools can still be used | Boost gives version-matched docs search, schema inspection, and Laravel guidelines. That directly reduces API calls hallucinated from memory | The team adopts another agent runtime |
| D8 | Compliance | **GDPR baseline:** account deletion cascades to todos, no PII in logs, a minimal privacy notice. **Data export: backlog.** Hosting region decided in R-004 | Proportionate for a personal todo app with email addresses as the only PII | EU-resident users at scale, or a contractual requirement |

## Environment facts (checked by command on 2026-11-16)

| Tool | Version |
|---|---|
| PHP | 8.4.25 |
| Composer | 2.10.2 |
| Node / npm | 26.12.1 / 11.20.0 |
| Laravel Installer | 5.32.0 |
| Laravel Herd | 1.30.2 |
| git / gh | 2.56.0 / 2.103.0 |

The Laravel framework version and the starter kit's contents are **not assumed**. R-001 checks them.

## Remaining assumptions

- A1: The expected load is small: fewer than 1,000 users and fewer than 500 todos per user.
- A2: English UI only. No i18n in the MVP. Strings stay in templates, and extraction is backlog work.
- A3: The repo will be hosted on GitHub, with GitHub Actions for CI. A remote is created in T-002, and only with approval.
