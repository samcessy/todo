# ADR-0006: Hosting on Laravel Cloud (Frankfurt) and the release flow

**Status:** Proposed · **Date:** 2026-11-16 · **Approver:** project owner (pending, G2)

## Context
- D3 (Laravel Cloud, Forge as fallback), D4 (Postgres in staging and production), D6 (one approver), D8 (GDPR baseline). NFR-05 (as amended at G1-5) and NFR-10.
- **R-004** recommends Cloud Starter in `eu-central-1` with Serverless Postgres 18 and push-to-deploy off. **Its red team** showed that GitHub required reviewers are unavailable on private Free, Pro and Team repos. It also showed that an agent holding the owner's token could approve or merge anyway (R-004 red-team findings 1–3).
- **Binding G1 decisions:**
  - G1-1: bot account for agents.
  - G1-2: owner-held production hook.
  - G1-5: warm/cold split for NFR-05.
  - G1-6: PHP 8.4 / Node 26.
  - G1-8: log mailer until R-006.
  - G1-9: `npm ci`.

## Decision

### Platform and topology
| | `staging` | `production` |
|---|---|---|
| Plan / region | Laravel Cloud **Starter**, **`eu-central-1` (Frankfurt)** (in the EU, and has Serverless Postgres; R-004 §Recommendation) | same application, second environment |
| Branch / push-to-deploy | `main` / **off** | `main` / **off** (a deployment-checklist item verifies `uses_push_to_deploy = false`) |
| Compute | Flex 512 MiB, scale to zero | Flex **512 MiB**, scale to zero (about 17 PHP workers is enough at A1, R-004 red-team finding 4b) |
| Runtime | **PHP 8.4** pinned. Cloud defaults to 8.5 (R-004 §Environments). **Node 26**, or 24 if T-001 finds 26 is not LTS | same |
| Database | Own **Serverless Postgres 18** cluster, Dev preset (1-day PITR), 0.25 CU, scale to zero | Own cluster, **Prod preset (7-day PITR)**, 0.25–1 CU, scale to zero |
| Build command | `composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader && npm ci && npm run build && php artisan optimize` | same |
| Deploy command | `php artisan migrate --force` | same |
| Health checks | Startup and readiness: `/up`. Liveness: default (doesn't depend on the DB) | same |
| Session / cache / queue | `database` drivers on Postgres (Cloud's filesystem is ephemeral). No queue worker; verification and reset mail are sent synchronously. Scheduler **off** | same |
| Mail (G1-8) | **`log` mailer** | **`log` until R-006** picks an EU provider. **R-006 must be decided before G5**, because verification and reset mail don't reach real users with `log` |
| Domain | `*.laravel.cloud` (sent with `X-Robots-Tag: noindex`) | Custom domain + automatic TLS (edge WAF and rate-limit rules apply only to custom domains) |
| Data | Synthetic seed data only. Never a copy of production | Real users |
| Previews | Not in the MVP | — |

### Identities (G1-1)
- **Agents** commit, push and open PRs as a **bot GitHub account** (for example `todo-app-bot`) with **write** access and no admin.
- The **owner** reviews, approves and merges as themselves.
- The owner's `gh` session is logged out of every agent terminal.
- `.claude/settings.json` adds `deny` rules for `gh pr merge*`, `gh secret*`, `gh repo edit*` and `gh api *pending_deployments*`, and `ask` rules for `gh api *` and `gh workflow run*` (R-005 red-team finding 4). These rules are a backstop. **Identity separation is the actual control.**
- **Agents have no Laravel Cloud account, no `LARAVEL_CLOUD_TOKEN`, and no hook URL.** The owner is the only dashboard user.

### Release flow (G4 → staging → G5)
1. **PR:** CI runs every ADR-0003 gate on SQLite and Postgres. Nothing deploys.
2. **G4:** the owner approves and merges into `main`. Branch protection requires the PR, 1 approval and the required checks, and bypassing is not allowed. Availability depends on the plan: see open question Q2 in architecture.md.
3. **Staging (automatic):** workflow `deploy-staging.yml` runs `on: push: main`. Only fast gates are re-run, because the PR gates already ran on an up-to-date branch (R-004 red-team finding 8). The workflow then runs `curl -fsS -X POST "$STAGING_DEPLOY_HOOK?commit_hash=$GITHUB_SHA"`, polls `https://<staging>/up`, and runs the smoke checks.
   - `STAGING_DEPLOY_HOOK` is a **repository secret**. Environment secrets need a paid plan on private repos (R-004 red-team finding 1).
4. **G5 (owner-held hook, G1-2):** the **production deploy-hook URL exists only in the owner's password manager**. It is never stored in GitHub, CI, the repo or any agent session. After reviewing the staging smoke results, the changelog and the rollback plan, the owner runs this from their own terminal:
   ```bash
   curl -fsS -X POST "$PROD_HOOK?commit_hash=<SHA verified on staging>"
   ```
   The owner then records the G5 row in the governance gate log. The audit trail is Cloud's deployment history plus that row. The dashboard "Deploy" button is **not** used for production, because it deploys the branch head rather than the verified SHA.
5. **Rollback:** the owner posts the last known-good SHA (which must be on `main`) to the same hook. **Code rolls back, but migrations do not.**

### Migrations: expand/contract (required)
- Cloud runs `migrate --force` **before** the new release goes live, while the old release is still serving. A failed health check leaves the **old code on the new schema** (R-004 §Deploy mechanics, Risks).
- **Every migration after the baseline must therefore be backward-compatible with the previous release:**
  - add nullable columns or columns with defaults;
  - no `rename`, and no `drop` in the same release as the code change;
  - drop a column in a later release (the contract step), once no deployed code reads it.
- Any schema change after the baseline also needs owner approval (governance), and G4 review checks for expand/contract.
- Data recovery: restore PITR into a new cluster, attach it, then redeploy. The runbook goes in `05-operations` before G5.

### NFR-05: warm and cold (G1-5)
- **Warm:** p95 of app-side duration under **300 ms** for `GET /todos` and each write. Measured on staging, configured like production (512 MiB, 0.25 CU), with at least 200 requests at **100 todos per user**, the DB already awake, and any monitoring sample rate at 1.0 during the run.
- **Cold:** the first request after both compute and DB have slept takes under **2 s**. It is reported separately and is not part of the p95.
- **Cost:** about $11/month with scale to zero. Switch production to always-on (about +$23/month) when there are real users, or when the cold budget is missed.

### Observability (NFR-10)
- `/up` is the health check.
- Cloud logs are kept for 1 day on Starter.
- Logs contain no PII (security.md T-12).
- Error tracking with release tags (Nightwatch Free EU, R-004 §Monitoring) needs a new dependency, so it is a **separate decision before G5** (open question Q3).

## Alternatives considered
| Alternative | Why it was rejected |
|---|---|
| Forge + VPS | Server ops become ours, it can't deploy a pinned SHA (`forge_deploy_commit` is only a label), and DigitalOcean managed Postgres has no PITR (R-004 §Forge) |
| GitHub Environment with required reviewers for production | Not available on private Free, Pro or Team repos. Even where it is available, a token holder can approve through the API (R-004 red-team findings 1–2) |
| `workflow_dispatch` with `if: github.actor == owner` | Satisfied by any session that holds the owner's token. It is not an approval record |
| Public repo, to get required reviewers | It makes code and docs public. Still possible if the owner wants it, but not needed with an owner-held hook |
| Cloud API token / CLI in CI | Broader than a hook: the hook can deploy only one environment, and only commits on its branch |
| Always-on production now | About $34/month in total for a demo with no users (G1-5) |
| London / Ireland regions | London is outside the EU. Ireland has no Serverless Postgres (R-004) |

## Consequences
**Positive**
- G5 can be enforced at **$0**, because it rests on secret possession rather than GitHub plan features.
- Production gets exactly the SHA that staging verified.
- Zero-downtime deploys are gated on health checks.

**Negative**
- G5 depends on the owner's discipline: verifying the SHA and recording the gate.
- Starter keeps logs for 1 day, staging can't use basic auth (it is publicly reachable, with synthetic data only), and cold starts happen.
- Because Cloud migrates before switching releases, expand/contract is mandatory.

**Follow-up work**
- T-002: bot account, branch protection, CI and staging workflow, Dependabot.
- Owner:
  - Cloud setup: push-to-deploy off, PHP 8.4, org spending limit (for example $40 with alerts).
  - Review the DPA and subprocessors (trust.laravel.com) before G5.
  - R-006 (mail) and the monitoring decision before G5.

## Revisit triggers
- The warm p95 or the cold budget is missed on staging, or real users arrive. Switch production to always-on, DB first.
- The production hook leaks, or is suspected to have leaked. Refresh it immediately (Cloud supports refreshable hooks).
- A second human joins (D6). Add CODEOWNERS and 2 approvals, and reconsider GitHub Environments.
- Logs need to be kept longer than 1 day. Move to Growth (7 days).
- Cloud pricing or region changes, or EU-only processing becomes a contractual requirement. Re-evaluate Forge on an EU provider.

## Sources
- R-004 (§Topology, §Deploy flow, Risks, red-team findings 1–8). G1-1, G1-2, G1-5, G1-6, G1-8, G1-9.
- https://laravel.com/cloud/docs/deployments.md (deploy hooks, `commit_hash`, push-to-deploy, manual deploy)
- https://laravel.com/cloud/docs/resources/databases/postgres.md (Postgres 17/18, regions, PITR, scale to zero)
- https://laravel.com/cloud/docs/runtimes.md (PHP 8.2–8.5, default 8.5; Node 22/24/26)
- https://laravel.com/cloud/docs/environments.md (build and deploy commands, ephemeral filesystem)
- https://laravel.com/cloud/docs/health-checks.md
- https://laravel.com/cloud/pricing (Starter, Frankfurt rates, median wake 500 ms)
- https://docs.github.com/en/actions/reference/workflows-and-actions/deployments-and-environments (required-reviewer plan limits)
- https://laravel.com/docs/13.x/deployment#the-health-route
