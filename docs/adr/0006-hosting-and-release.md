# ADR-0006: Hosting on Laravel Cloud (Frankfurt) and the release flow

**Status:** Proposed · **Date:** 2026-11-16, revised 2026-11-17 (G2-1, G2-5, G2-10, G2-11, G2-16) · **Approver:** project owner (pending, after the G2 blocker re-check)

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
- **Binding G2 decisions (2026-11-17):**
  - G2-10: the repository is **public**, `github.com/samcessy/todo`.
  - G2-5: the bot cannot change workflows; default `GITHUB_TOKEN` read-only; minimal per-workflow `permissions`; no `pull_request_target`.
  - G2-1: HTTPS enforced, HSTS, `__Host-` session cookie in staging and production.
  - G2-11: error tracking is decided alongside R-006, before G5.

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
| Domain | `*.laravel.cloud` (sent with `X-Robots-Tag: noindex`) | Custom domain + automatic TLS (edge WAF and rate-limit rules apply only to custom domains). `APP_URL` = the custom domain (A-13) |
| Security env (G2-1, A-03, A-13, A-14, A-19; security.md) | `APP_ENV=staging`, `APP_DEBUG=false`, `SESSION_COOKIE=__Host-todo_session`, `SESSION_SECURE_COOKIE=true`, `SESSION_DOMAIN` unset, `INERTIA_ENCRYPT_HISTORY=true`, `INERTIA_SSR_ENABLED=false`, `INERTIA_DEVTOOLS_ENABLED` unset, `PASSKEYS_USER_HANDLE_SECRET` set, `STAGING_DEMO_PASSWORD` set | same, with `APP_ENV=production` and **no** `STAGING_DEMO_PASSWORD` |
| Data | Synthetic seed data only, from `StagingSeeder` run once by the owner (data-model.md §4, security.md T-25). Never a copy of production | Real users. Seeding never runs |
| Previews | Not in the MVP | — |

### Repository, identities and workflow posture (G1-1, G2-5, G2-10)
**Repository.** Public, `github.com/samcessy/todo`, owned by the owner's personal account. Nothing secret is committed (security.md T-15). Secret scanning and push protection are on. Public visibility makes branch protection, environment secrets and deployment-branch rules free (https://docs.github.com/en/actions/reference/workflows-and-actions/deployments-and-environments, fetched 2026-11-17).

**Identities.**
- **Agents** commit, push and open PRs as a **bot GitHub account** (for example `todo-app-bot`), a repository collaborator with write access and no admin.
- The **owner** reviews, approves and merges as themselves. The owner's `gh` session is logged out of every agent terminal.
- `.claude/settings.json` adds `deny` rules for `gh pr merge*`, `gh secret*`, `gh repo edit*` and `gh api *pending_deployments*`, and `ask` rules for `gh api *` and `gh workflow run*` (R-005 red-team finding 4). These rules are a backstop. **Identity and token scope are the actual controls.**
- **Agents have no Laravel Cloud account, no `LARAVEL_CLOUD_TOKEN`, and no hook URL.** The owner is the only dashboard user.

**Bot token (G2-5, B-05).** The decision asked for a fine-grained token with contents and pull-request permissions and no Workflows permission. GitHub's documentation lists, as a current limitation, that fine-grained PATs cannot be used to contribute to repositories where the user is a repository collaborator, and that only classic PATs have write access to public repositories the user does not own (https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/managing-your-personal-access-tokens, fetched 2026-11-17). The bot is exactly such a collaborator. Therefore:
- The bot uses a **classic PAT with only the `public_repo` scope**. That scope gives read and write access to code, commit statuses and pull requests on public repositories the bot can access, which is only this one. It **lacks the `workflow` scope**, without which GitHub refuses pushes that add or change files under `.github/workflows/` (https://docs.github.com/en/apps/oauth-apps/building-oauth-apps/scopes-for-oauth-apps). No `repo`, `admin:*`, `delete_repo` or `workflow` scope. Expiry 90 days, renewed by the owner.
- The bot account has **no SSH keys**, because SSH pushes are not checked against token scopes. `gh` is logged in with `gh auth login --with-token`.
- The effect is the one G2-5 decided: contents and PRs, no workflow writes. **The owner confirms the token-type substitution at the re-check** (architecture.md §9, "Rejected review items"). If fine-grained PATs later support collaborators, switch to one with Contents: write, Pull requests: write, Metadata: read and no Workflows permission.
- Verification (T-002, recorded in the handoff; re-run before each G5 and after each rotation): `gh auth status` shows the bot with `Token scopes: 'public_repo'`; the bot's push of a branch adding `.github/workflows/probe.yml` is **rejected**; `gh api users/<bot>/keys` returns `[]`.

**Repository Actions settings (owner, T-002).** Default workflow permissions: **read repository contents**. "Allow GitHub Actions to create and approve pull requests": **off**. Fork PR workflows: "Require approval for all external contributors". Verified with `gh api repos/samcessy/todo/actions/permissions/workflow` (expect `default_workflow_permissions: "read"`, `can_approve_pull_request_reviews: false`).

**Workflow rules.** Every workflow declares top-level `permissions:` (`contents: read` or `{}`) and widens per job only where needed. Triggers are `pull_request`, `push` to `main`, `schedule` and `workflow_dispatch`, **never `pull_request_target` or `workflow_run`**. Every action is pinned to a full commit SHA (Dependabot updates the pins). `actions/checkout` uses `persist-credentials: false`. PR jobs reference no secrets; fork PRs receive no secrets and a read-only token in any case (https://docs.github.com/en/actions/security-for-github-actions/security-guides/using-secrets-in-github-actions). The CI job `workflow-lint` (`scripts/check-workflows.mjs`) enforces these rules (security.md T-18, ADR-0003).

**Branch protection on `main` (G2-10, security.md T-16).** Require a pull request; 1 approving review; dismiss stale approvals; require approval of the most recent reviewable push; required status checks (every PR job in ADR-0003) with branches up to date; require conversation resolution; **do not allow bypassing**, including for administrators; block force pushes and deletion. Verified by the owner with `gh api repos/samcessy/todo/branches/main/protection` at T-002 and before each G5, and by the bot's rejected direct push to `main`.

**Dependabot (G1-9, A-15).** Weekly for `composer`, `npm` and `github-actions`, each with a `cooldown` of at least 5 days (security updates exempt), and the Inertia group.

### Release flow (G4 → staging → G5)
1. **PR:** CI runs every ADR-0003 gate on SQLite and Postgres. Nothing deploys, and no PR job can read a secret.
2. **G4:** the owner approves and merges into `main` under the branch protection above.
3. **Staging (automatic):** workflow `deploy-staging.yml` runs `on: push: branches: [main]`. Only fast gates are re-run, because the PR gates already ran on an up-to-date branch (R-004 red-team finding 8). Its `deploy` job declares **`environment: staging`** and runs `curl -fsS -X POST "$STAGING_DEPLOY_HOOK?commit_hash=$GITHUB_SHA"`, polls `https://<staging>/up`, and runs the **staging smoke checks** (security.md §4): HTTP→HTTPS redirect without a loop, security headers and no `x-powered-by`, `__Host-todo_session` attributes, `/.git/HEAD` → 404, TLS versions, `https` verification links; it also prints the runner's public IP for the T-002 proxy check (A-13).
   - `STAGING_DEPLOY_HOOK` and `STAGING_DEMO_PASSWORD` are **environment secrets of the GitHub Environment `staging`**, whose deployment branches are limited to `main`. A workflow running for any other branch cannot read them (B-05). There is **no repository-level** deploy secret.
4. **G5 (owner-held hook, G1-2):** the **production deploy-hook URL exists only in the owner's password manager**. It is never stored in GitHub (there is no `production` GitHub Environment holding it; environment required reviewers are available on the public repo but are not used, because hook custody is the stronger control and keeps every production secret out of GitHub), CI, the repo or any agent session. After reviewing the staging smoke results, the changelog, the rollback plan and the **G5 go/no-go checklist** below, the owner runs this from their own terminal:
   ```bash
    curl -fsS -X POST "$(<password-manager CLI> read <prod hook item>)?commit_hash=<SHA verified on staging>"
   ```
   **Custody runbook (A-16):** read the URL inline from the password-manager CLI as above; never `export` it or write it to a file; the leading space plus `HIST_IGNORE_SPACE` keeps it out of shell history; use a terminal with no agent session; refresh the hook in Cloud if exposure is suspected. The control is **authoritative while custody holds**. The owner then records the G5 row in the governance gate log. The audit trail is Cloud's deployment history plus that row. The dashboard "Deploy" button is **not** used for production, because it deploys the branch head rather than the verified SHA.
5. **Rollback:** the owner posts the last known-good SHA (which must be on `main`) to the same hook. **Code rolls back, but migrations do not.**

### G5 go/no-go checklist (hard items; each records the command or screen used)
- R-006 implemented and **`MAIL_MAILER` ≠ `log` in production** (A-08).
- Error-tracking decision made with the redaction review (G2-11, A-09), or explicitly deferred by the owner.
- Production security env as in the Platform table: `APP_ENV=production`, `APP_DEBUG=false`, `__Host-todo_session`, secure cookie, `INERTIA_ENCRYPT_HISTORY=true`, `INERTIA_SSR_ENABLED=false`, `INERTIA_DEVTOOLS_ENABLED` unset, `PASSKEYS_USER_HANDLE_SECRET` set, `APP_URL` = custom domain (security.md T-06, T-07, T-11, T-24).
- `uses_push_to_deploy=false` on both environments; seeding has never run on production (T-17, T-25).
- Branch protection, Actions settings, bot token scopes and the `staging` environment re-verified (T-16, T-18).
- Full nonce-based CSP: implemented, or explicitly deferred by the owner with its revisit trigger (security.md T-13).
- `APP_KEY` rotation runbook with `previous_keys` and `PASSKEYS_USER_HANDLE_SECRET` written in `05-operations` (A-13).

### Migrations: expand/contract (required)
- Cloud runs `migrate --force` **before** the new release goes live, while the old release is still serving. A failed health check leaves the **old code on the new schema** (R-004 §Deploy mechanics, Risks).
- **Every migration after the baseline must therefore be backward-compatible with the previous release:**
  - add nullable columns or columns with defaults;
  - no `rename`, and no `drop` in the same release as the code change;
  - drop a column in a later release (the contract step), once no deployed code reads it.
- Any schema change after the baseline also needs owner approval (governance), and G4 review checks for expand/contract.
- Data recovery: restore PITR into a new cluster, attach it, then redeploy. The runbook goes in `05-operations` before G5.

### NFR-05: warm and cold (G1-5)
- **Warm:** p95 server response under **300 ms** for `GET /todos`. Measured on staging, configured like production (512 MiB, 0.25 CU), at **100 todos** for the demo user.
- **Warm instrument (F-05), no new dependency:** a post-deploy job in `deploy-staging.yml` (environment `staging`) logs in as the demo user with a cookie jar (fetch `XSRF-TOKEN`, `POST /login`, password from the `STAGING_DEMO_PASSWORD` environment secret), sends 10 discarded warm-up requests, then 200 sequential `curl -o /dev/null -w '%{time_starttransfer}\n' https://<staging>/todos` requests, and the same for `/up`. Pass: p95(`/todos`) − median(`/up`) < 300 ms (sort, take the 190th value). The subtraction approximates app time by removing the network round trip, and the report states the method. If the G2-11 monitoring tool is adopted, its p95 replaces this.
- **Cold:** the first request after both compute and DB have slept takes under **2 s**. A nightly scheduled job sends nothing to staging for longer than the Cloud idle timeout (value **UNVERIFIED**; T-002 reads it from Cloud docs), then one authenticated `GET /todos`, asserts `time_total` < 2 s and reports it separately from the p95.
- **Cost:** about $11/month with scale to zero. Switch production to always-on (about +$23/month) when there are real users, or when the cold budget is missed.

### Observability (NFR-10)
- `/up` is the health check.
- Cloud logs are kept for 1 day on Starter.
- Logs contain no PII (security.md T-12).
- Error tracking with release tags (Nightwatch Free EU, R-004 §Monitoring) needs a new dependency, so it is decided **alongside R-006, before G5** (G2-11). The decision must include the redaction review of security.md T-12 (user resolver limited to `id`, no request bodies or query bindings, EU region, DPA).

## Alternatives considered
| Alternative | Why it was rejected |
|---|---|
| Forge + VPS | Server ops become ours, it can't deploy a pinned SHA (`forge_deploy_commit` is only a label), and DigitalOcean managed Postgres has no PITR (R-004 §Forge) |
| GitHub Environment with required reviewers for production | Not available on private Free, Pro or Team repos. Even where it is available, a token holder can approve through the API (R-004 red-team findings 1–2) |
| `workflow_dispatch` with `if: github.actor == owner` | Satisfied by any session that holds the owner's token. It is not an approval record |
| Private repo on GitHub Pro (about $4/month) | Not chosen at G2-10. The owner chose a public repo: it is a reference implementation, and public repos get branch protection, environment secrets and deployment-branch rules free |
| GitHub Environment `production` with required reviewers instead of the owner-held hook | Now available (public repo), but it would put a production secret into GitHub, where a workflow or a token holder could reach it. The owner-held hook keeps G1-2 |
| Fine-grained PAT for the bot | Not usable by a repository collaborator (GitHub docs, fetched 2026-11-17); see "Bot token" |
| GitHub App for the bot | Fine-grained permissions without the collaborator limitation, but its private key would live on the agent machine, readable by agents, which is no better than a scoped classic PAT and adds token-minting code |
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
- T-002: bot account and classic `public_repo` token, repository Actions settings, branch protection, GitHub Environment `staging` (deployment branch `main`, two environment secrets), secret scanning and push protection, CI and staging workflows with `workflow-lint`, Dependabot with cooldowns, trusted proxies and the staging smoke checks.
- Owner:
  - Cloud setup: push-to-deploy off, PHP 8.4, org spending limit (for example $40 with alerts).
  - Review the DPA and subprocessors (trust.laravel.com) before G5.
  - R-006 (mail) and the monitoring decision before G5.

## Revisit triggers
- The warm p95 or the cold budget is missed on staging, or real users arrive. Switch production to always-on, DB first.
- The production hook leaks, or is suspected to have leaked. Refresh it immediately (Cloud supports refreshable hooks).
- The bot token is suspected to have leaked, or GitHub adds collaborator support to fine-grained PATs. Revoke or replace it.
- The repository becomes private. Re-check which protections and environment features the plan still provides.
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
- https://docs.github.com/en/actions/reference/workflows-and-actions/deployments-and-environments (required reviewers, environment secrets and deployment branches by plan; fetched 2026-11-17)
- https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/managing-your-personal-access-tokens (fine-grained PAT limitations; fetched 2026-11-17)
- https://docs.github.com/en/apps/oauth-apps/building-oauth-apps/scopes-for-oauth-apps (`public_repo`, `workflow` scopes; fetched 2026-11-17)
- https://docs.github.com/en/actions/security-for-github-actions/security-guides/using-secrets-in-github-actions (no secrets for fork-triggered workflows; fetched 2026-11-17)
- https://docs.github.com/en/actions/security-for-github-actions/security-guides/security-hardening-for-github-actions (`pull_request_target`, SHA pinning; fetched 2026-11-17)
- https://docs.github.com/en/repositories/managing-your-repositorys-settings-and-features/enabling-features-for-your-repository/managing-github-actions-settings-for-a-repository (workflow permissions, fork PR approval; fetched 2026-11-17)
- https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-rulesets/about-rulesets (push rulesets only for private and internal repositories; fetched 2026-11-17)
- `G2-decision-packet.md` (G2-1, G2-5, G2-10, G2-11), `reviews/G2-security-review.md` (B-01, B-05, A-08 to A-10, A-13, A-15, A-16, A-19), `04-quality/G2-testability-review.md` (F-05)
- https://laravel.com/docs/13.x/deployment#the-health-route
