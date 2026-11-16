# R-004: Laravel Cloud for staging and production, with Forge + VPS as the fallback

**Status:** Draft · **Author:** architect agent · **Red team:** _pending_ · **Date:** 2026-11-16

## Question
Can Laravel Cloud host our staging and production environments (EU region, PostgreSQL, small budget) with a deploy flow where production goes out only after a human approves it? And is Forge + VPS still a workable fallback?

## Constraints
- **D3** Hosting: Laravel Cloud for staging and production. Fallback is Forge + VPS if cost or region rules Cloud out. (`00-product/assumptions.md`)
- **D4** PostgreSQL in staging and production. SQLite locally and in the fast suite. CI also runs on Postgres.
- **D6** One human approver (the project owner) for every gate, including G5 (Staging to Production).
- **D8** GDPR baseline: no PII in logs. This report decides the hosting region.
- **A1** Small load: under 1,000 users and under 500 todos per user.
- **A3** GitHub + GitHub Actions. The remote is created in T-002.
- **NFR-05** p95 under 300 ms. **NFR-10** `/up` health endpoint, structured logs without PII, error tracking with release tags.
- **Governance:** AI never deploys to production. Merge and deploy approval are enforced through GitHub branch protection and Environment required reviewers (T-002). Adding a dependency (for example `laravel/nightwatch`) needs human approval.
- Local toolchain (assumptions.md): PHP 8.4.25, Node 26.12.1.

## Options
| Option | Summary |
|---|---|
| **A. Laravel Cloud (Starter plan), EU Central (Frankfurt), Serverless Postgres. CI-gated deploy hooks** | Fully managed PaaS. One application with two environments (`staging` and `production`), each with its own scale-to-zero Postgres cluster. Push to deploy is **off**. GitHub Actions calls each environment's deploy hook with the exact commit SHA. The production hook URL is stored only as a secret of a GitHub Environment that requires the owner's approval. |
| A2. Option A on the Growth plan | Same topology, plus HTTP basic auth for staging and previews, 7-day logs, unlimited preview environments, and Pro compute. +$15/month base. |
| **B. Forge + Laravel VPS (or Hetzner/DigitalOcean)** | You manage the servers yourself, through Forge. Zero-downtime releases on one server, deploy hooks, and Postgres either on the server or as a Forge managed cluster (DigitalOcean). Flat Forge fee plus the VPS cost. |
| C. Laravel Vapor | Serverless on your own AWS account. Ruled out: Laravel itself positions it as needing AWS Console experience (laravel.com/cloud/pricing FAQ), and it adds an AWS account to the security surface. Not evaluated further. |

## Evaluation criteria
Complexity, security surface, testability, deploy simplicity, future flexibility, maturity ("boring technology" counts in an option's favour). Added for this ticket: **EU data residency**, **cost at A1 scale**, and **whether the deploy flow can enforce G5 technically**.

| Criterion | A. Cloud (Starter) | B. Forge + VPS |
|---|---|---|
| Ops burden | None: no OS, PHP, Nginx, or TLS work | OS patching, PHP upgrades, firewall, and backups are ours |
| EU region with managed Postgres | Yes: Frankfurt and London have Serverless Postgres. Ireland does not | Depends on the VPS provider. Laravel VPS regions are UNVERIFIED |
| Postgres backups | Point-in-time recovery, 0–30 days (Prod preset: 7) | Managed cluster: daily, 7 days, no PITR. On-server backups need the Forge **Business** plan ($39) |
| Deploy by exact SHA from CI | Yes (`commit_hash` on the deploy hook) | **No**: `forge_deploy_commit` is only a label. Forge deploys the branch head |
| Zero downtime | Yes, health-check gated, previous release keeps serving if checks fail | Yes (symlink releases, one server). No readiness gate |
| Rollback | Re-deploy an earlier SHA through the hook. A one-click dashboard rollback is UNVERIFIED | Last 4 releases are retained for rollback |
| Preview environments per PR | Yes (1 automation on Starter) | No |
| Cost at our scale | About $5–$35/month (see Evidence §Cost) | $12 Forge + VPS (UNVERIFIED) + backups |
| Lock-in | Moderate: plain Laravel, with Cloud-specific config limited to dashboard settings | Low |

## Evidence
All sources accessed **2026-11-16**. "Cmd" = verified by a command (curl of the official page or docs markdown, parsed in the scratchpad). No accounts were created.

### Plans and pricing (Laravel Cloud)
| Claim | Source (URL) | Version / date | Verified by command? |
|---|---|---|---|
| Starter $5/mo, Growth $20/mo, Business $200/mo, all "+ usage". Every plan includes $5/month in usage credits. The first month on Starter is free | https://laravel.com/cloud/pricing ; https://laravel.com/cloud/docs/pricing.md | 2026-11-16 | Cmd (curl + parse) |
| Starter: unlimited apps, environments, builds, and users. Preview environments for 1 app. Flex compute only (max 2 GiB, 1 replica). 1 managed queue per environment. 10 custom domains. **1-day** log and metrics retention. 10 deployments of history | https://laravel.com/cloud/pricing | 2026-11-16 | Cmd |
| Growth: Pro compute, autoscaling up to 10 replicas, unlimited previews, basic WAF, team roles, **7-day** logs, 50 deployments of history | https://laravel.com/cloud/pricing | 2026-11-16 | Cmd |
| Spending limits at org level, with alerts at 50/80/90/100%. Can pause compute when the limit is reached | https://laravel.com/cloud/pricing (FAQ) | 2026-11-16 | Cmd |
| **Frankfurt** compute rates: Flex 512 MiB $0.010417/h (cap $7/mo). Flex 1 GiB $0.020833/h (cap $14/mo). Flex 2 GiB $0.041667/h (cap $28/mo). US East (Ohio) Flex 512 MiB is $6/mo, so EU is about 15–17% dearer | Pricing-page embedded rate data, region `eu-central-1` | 2026-11-16 | Cmd (parsed JSON) |
| Serverless Postgres: **$0.106 per CU-hour** of awake compute and **$0.50 per GB-month** of storage. The page data shows one global Postgres rate, with no per-region entry | https://laravel.com/cloud/pricing (data); worked example at https://laravel.com/cloud/docs/pricing.md | 2026-11-16 | Cmd. Whether EU Postgres costs the same as US is **UNVERIFIED** |
| Postgres point-in-time recovery history is billed per GB-month. **The rate was not rendered in the docs: UNVERIFIED** | https://laravel.com/cloud/docs/pricing.md | 2026-11-16 | Cmd (value missing) |
| Compute is billed per second, capped at 28 days (672 h) per period. Bandwidth overage is $0.10/GB | https://laravel.com/cloud/docs/pricing.md | 2026-11-16 | Cmd |
| HTTP basic auth (to protect staging or previews) needs **Growth or higher** | https://laravel.com/cloud/docs/environments.md#http-basic-authentication | 2026-11-16 | Cmd |
| Log drains are **Enterprise only**: about $50/month per drain + $0.25/GB. S3 is the only destination | https://laravel.com/cloud/docs/services/log-drains/overview.md ; …/log-drains/pricing.md | 2026-11-16 | Cmd |

**Cost estimate (Frankfurt, Starter, derived from the rates above. An estimate, not a quote):**

| Scenario | Production | Staging | Usage | Bill (plan $5 + usage − $5 credit) |
|---|---|---|---|---|
| Low: prod awake 200 h, staging 30 h, both scale to zero; prod Flex 1 GiB, staging Flex 512 MiB, Postgres 0.25 CU, about 1.5 GB storage | $4.17 compute + $5.30 DB + $0.50 storage | $0.31 + $0.80 + $0.25 | ≈ $11.3 | **≈ $11/mo** |
| High: prod DB never sleeps (to protect NFR-05), prod compute awake all month | $14 (cap) + $19.08 DB (720 h × 0.25 CU) + $0.50 | ≈ $1.4 | ≈ $35 | **≈ $35/mo** |

Not included: PITR history storage (rate UNVERIFIED), bandwidth beyond the allowance (unlikely), and email sending.

### PostgreSQL on Cloud
| Claim | Source | Version / date | Cmd? |
|---|---|---|---|
| "Laravel Serverless Postgres" is powered by Neon: managed, autoscaling compute and storage. Supports **Postgres 17 and 18** (18 is the default) | https://laravel.com/cloud/docs/resources/databases/postgres.md | 2026-11-16 | Cmd |
| Postgres regions: us-east-1, us-east-2, **eu-central-1 (Frankfurt)**, **eu-west-2 (London)**, ap-southeast-1, ap-southeast-2. **Not in eu-west-1 (Ireland)**, ca-central-1, or ap-northeast-1. The cluster must be in the same region as the environment | same | 2026-11-16 | Cmd |
| Scale to zero after 60 s to 7 days with no queries, or turned off. Wakes "within a few hundred milliseconds". Any open connection keeps it awake. Storage and PITR history are billed while it sleeps | same | 2026-11-16 | Cmd |
| Compute units: 0.25 CU = 0.25 vCPU / 1 GB. Starter allows up to 1 CU, Growth 4 CU | same | 2026-11-16 | Cmd |
| PITR retention is 0–30 days (presets: Dev 1, Prod 7, Scale 14). A restore creates a **new** cluster, which you then attach and redeploy | same | 2026-11-16 | Cmd |
| A pgbouncer pooler endpoint (`-pooler` host suffix) is available. Attaching a database injects `DB_*` variables | same | 2026-11-16 | Cmd |
| Snapshot and restore are also in the API (`database-snapshots`, `database-restores`) | https://laravel.com/cloud/docs/llms.txt | 2026-11-16 | Cmd |

### Regions and compliance
| Claim | Source | Version / date | Cmd? |
|---|---|---|---|
| App regions: us-east-1, us-east-2, ca-central-1, **eu-central-1 Frankfurt**, **eu-west-1 Ireland**, **eu-west-2 London**, ap-southeast-1, ap-southeast-2, ap-northeast-1. **An application is deployed to only one region at a time** | https://laravel.com/cloud/docs/applications.md#regions | 2026-11-16 | WebFetch |
| Moving regions later is a documented migration (knowledge-base article), not a setting you can just change | https://laravel.com/cloud/docs/knowledge-base/migrate-to-different-region.md (title in docs index) | 2026-11-16 | Index only. Contents not read |
| Cloud runs on AWS Graviton EC2 (it is not serverless). The edge network is Cloudflare (DDoS protection, CDN, OWASP core ruleset) | https://laravel.com/cloud/docs/pricing.md ; https://laravel.com/cloud/docs/intro.md ; https://laravel.com/cloud/docs/compliance.md | 2026-11-16 | Cmd |
| SOC 2 Type 2 and ISO 27001. A Laravel DPA is published at trust.laravel.com (updated July 2024) | https://laravel.com/cloud/docs/compliance.md ; https://laravel.com/legal | 2026-11-16 | WebFetch |
| Subprocessor list, SCCs, and whether Cloudflare edge TLS termination stays inside the EU | trust.laravel.com (not reviewed) | — | **UNVERIFIED** |
| `*.laravel.cloud` domains get an `X-Robots-Tag: noindex, nofollow` header automatically | https://laravel.com/cloud/docs/domains.md | 2026-11-16 | Cmd |

### Environments, previews, secrets
| Claim | Source | Version / date | Cmd? |
|---|---|---|---|
| An application can have many environments. Each has its own compute, resources, branch, and deploy settings | https://laravel.com/cloud/docs/environments.md | 2026-11-16 | Cmd |
| Preview environments: one per PR (not from forks), auto-destroyed on merge or close. Default resources are new scale-to-zero compute and a **new isolated DB**. The Starter plan includes one preview automation. Since 13 Jul 2026, previews **do not inherit** the target environment's variables, so production secrets don't leak to them | https://laravel.com/cloud/docs/preview-environments.md | 2026-11-16 | Cmd |
| Environment variables are written to `.env` for Laravel apps. Changes need a redeploy. Custom variables override injected ones | https://laravel.com/cloud/docs/environments.md#environment-variables | 2026-11-16 | Cmd |
| Secrets Manager: org-level encrypted secrets linked to environments. Values are "write once, read never". Encrypted in the browser with the org's public key (KMS-protected). Masked in deploy logs. A rollback reuses the secret values of the old deployment | https://laravel.com/cloud/docs/secrets.md | 2026-11-16 | Cmd |
| Filesystem is ephemeral and per replica. Use the database or a cache for sessions and cache, and Object Storage for files | https://laravel.com/cloud/docs/environments.md#filesystem | 2026-11-16 | Cmd |
| PHP 8.2–8.5 supported. **8.5 is the default for new environments** (local Herd is 8.4.25). Node 22/24/26, default 26 | https://laravel.com/cloud/docs/runtimes.md | 2026-11-16 | Cmd |
| Laravel apps must be on the latest minor of their major, or the deploy fails ("…version is not supported") | https://laravel.com/cloud/docs/deployments.md#framework-or-runtime-version-not-supported | 2026-11-16 | Cmd |

### Deploy mechanics and CI gating
| Claim | Source | Version / date | Cmd? |
|---|---|---|---|
| Deploy pipeline: build an image, then run **build commands** (15 min timeout), then run **deploy commands** on the infrastructure "just before a new deployment goes live" (15 min timeout, filesystem changes don't persist), then switch over with zero downtime. The old release is terminated gracefully | https://laravel.com/cloud/docs/deployments.md ; https://laravel.com/cloud/docs/environments.md#build-and-deploy-commands | 2026-11-16 | Cmd |
| Recommended Laravel build: `composer install --no-dev && npm run build`. Run `php artisan optimize` in the build, **not** in deploy. Deploy commands should typically be migrations. Don't run `queue:restart`, `optimize:clear`, or `storage:link` | https://laravel.com/cloud/docs/environments.md | 2026-11-16 | Cmd |
| The API also exposes a `post_deploy_command` ("commands that run after the deployment goes live") | https://laravel.com/cloud/docs/api/deployments/initiate-deployment.md (EnvironmentResource schema) | 2026-11-16 | WebFetch |
| **Push to deploy is on by default** and can be turned off in Settings > Deployments | https://laravel.com/cloud/docs/deployments.md#push-to-deploy | 2026-11-16 | Cmd |
| **Deploy hook:** a refreshable secret URL. `POST …?commit_hash=<sha>` deploys that commit, which "must belong to the branch configured for the environment". The docs show a GitHub Actions example. No CI build is needed because Cloud builds | https://laravel.com/cloud/docs/deployments.md#deploy-hooks | 2026-11-16 | Cmd |
| API `POST /environments/{environment}/deployments` (bearer token, permission `deployment.create`) takes **no commit parameter**. CLI `cloud deploy` uses `LARAVEL_CLOUD_TOKEN` in CI and requires PHP 8.3+ | https://laravel.com/cloud/docs/api/deployments/initiate-deployment.md ; https://laravel.com/cloud/docs/api/cli.md | 2026-11-16 | WebFetch / Cmd |
| Whether the API token can be limited to one environment or to deploy-only | https://laravel.com/cloud/docs/api/authentication.md (not fully reviewed) | — | **UNVERIFIED** |
| Whether the deploy hook response returns a deployment ID that CI can poll | not documented | — | **UNVERIFIED** |
| One-click "rollback to previous deployment" in the dashboard. The secrets doc mentions rolling back to an earlier deployment, but no rollback procedure is documented | https://laravel.com/cloud/docs/secrets.md#deploying-changes | 2026-11-16 | **UNVERIFIED** |
| Anyone with dashboard access can click **Deploy** manually | https://laravel.com/cloud/docs/deployments.md#manual | 2026-11-16 | Cmd |
| GitHub: on private repos, required reviewers for Environments need **GitHub Enterprise**. Environments themselves need Pro, Team, or Enterprise. On public repos, every plan has them. (From prior knowledge: two docs.github.com fetches failed) | docs.github.com "Deployments and environments" | — | **UNVERIFIED (high impact)** |

### Health, logs, queues, scheduler, domains
| Claim | Source | Version / date | Cmd? |
|---|---|---|---|
| Startup, readiness, and liveness checks. On Laravel 11+, Cloud auto-detects `/up` from `bootstrap/app.php` and pre-fills startup and readiness. Liveness stays on the default. If a check fails, the deploy fails and **the previous release keeps serving**. Don't make liveness depend on the DB | https://laravel.com/cloud/docs/health-checks.md | 2026-11-16 | Cmd |
| Laravel `/up` returns 200 if the app booted without exceptions, otherwise 500. It dispatches `DiagnosingHealth` for extra checks. Docs banner: 12.x is "an old version", so **13.x is current** (exact version is R-001's job) | https://laravel.com/docs/12.x/deployment#the-health-route | 2026-11-16 | WebFetch |
| Logs: application, access, and scheduled-command logs, searchable in the dashboard. Retention by plan (Starter: 1 day) | https://laravel.com/cloud/docs/logs.md | 2026-11-16 | Cmd |
| Scale to zero: Flex only. Wakes in under 500 ms (legacy Flex sizes: 5–20 s). The env **wakes for Laravel scheduled tasks** and stays awake for the sleep timeout. Queued jobs don't wake it (use managed queues). Health checks don't keep it awake | https://laravel.com/cloud/docs/compute.md#scale-to-zero ; https://laravel.com/cloud/docs/health-checks.md | 2026-11-16 | Cmd |
| Enabling the Scheduler runs `schedule:run` every minute on the App cluster | https://laravel.com/cloud/docs/scheduled-tasks.md | 2026-11-16 | Cmd |
| Whether an enabled scheduler with nothing due still wakes the environment every minute (which would defeat scale to zero) | — | — | **UNVERIFIED** |
| Custom domains: automatic SSL issue and renewal, www redirect option, zero-gap pre-verification. Domain health monitoring since 27 Oct 2026. Edge rules (rate limit, WAF) apply **only to custom domains**, not `*.laravel.cloud` | https://laravel.com/cloud/docs/domains.md ; https://laravel.com/cloud/docs/changelog.md | 2026-11-16 | Cmd |
| Cloud removes `.git` at deploy. For Sentry releases, write `SENTRY_RELEASE` from `git log` into `.env` during the build | https://laravel.com/cloud/docs/knowledge-base/logs.md | 2026-11-16 | Cmd |

### Monitoring: Nightwatch vs Sentry
| Claim | Source | Version / date | Cmd? |
|---|---|---|---|
| Nightwatch is Laravel's hosted APM and error monitor. Events = requests, queries, jobs, mail, exceptions, logs, and so on | https://laravel.com/nightwatch/pricing | 2026-11-16 | WebFetch |
| Plans: **Free $0** (300k events, 14-day lookback; free projects pause after 30 days of app inactivity). **Pro $20** (7.5M, 30 days). Team $60. Business $300. Overage $0.50/100k on Free (hard spend caps available). Unlimited apps, environments, and seats | same | 2026-11-16 | WebFetch |
| Data region is chosen per application: **US, EU, or Australia** | https://nightwatch.laravel.com/docs/getting-started/start-guide | 2026-11-16 | WebFetch |
| Integration: `composer require laravel/nightwatch` (**new dependency, needs approval**) plus the agent `php artisan nightwatch:agent`. Cloud has one-click "Connect Nightwatch", which runs the agent and injects `NIGHTWATCH_TOKEN`, `NIGHTWATCH_REQUEST_SAMPLE_RATE=0.1`, and `LOG_STACK=laravel-cloud-socket,nightwatch` | https://laravel.com/cloud/docs/environments.md#nightwatch-integration ; https://nightwatch.laravel.com/docs/guides/cloud | 2026-11-16 | Cmd / WebFetch |
| Requirements: Laravel 10+, PHP 8.2+. Docs "strongly recommend" Laravel 10, 11, or 12. **Laravel 13 is not mentioned: UNVERIFIED** | https://nightwatch.laravel.com/docs/requirements.md | 2026-11-16 | WebFetch |
| Redaction runs in the package, before data is sent: request URLs, IPs, headers, payloads, SQL, exception messages, and more. Whether user email or name is captured by default is **UNVERIFIED** | https://nightwatch.laravel.com/docs/filtering.md ; pricing FAQ | 2026-11-16 | WebFetch |
| Nightwatch SOC 2 is "actively pursuing" (not yet certified) | https://laravel.com/nightwatch/pricing (FAQ) | 2026-11-16 | WebFetch |
| Nightwatch release or deploy tagging (needed by NFR-10 "release tags"): no docs page found | https://nightwatch.laravel.com/docs/llms.txt | 2026-11-16 | **UNVERIFIED** |
| Sentry: Laravel SDK, native releases, EU data region. Pricing and current plan limits **not fetched (outside the source list): UNVERIFIED** | — | — | UNVERIFIED |

### Forge fallback
| Claim | Source | Version / date | Cmd? |
|---|---|---|---|
| Forge: Hobby $12/mo (unlimited Laravel VPS, **1 external server**). Growth $19. Business $39. All include zero-downtime deployments | https://laravel.com/forge/pricing | 2026-11-16 | WebFetch |
| Laravel VPS: DigitalOcean-backed, billed hourly through Forge, mail ports blocked. **Sizes, prices, and regions are not shown in the docs: UNVERIFIED** | https://laravel.com/forge/docs/servers/laravel-vps | 2026-11-16 | WebFetch |
| Forge on-server database backups are **Business plan only**. Forge managed DB clusters (DigitalOcean): MySQL 8.4 and **PostgreSQL 17/18**, daily backups kept 7 days, **no PITR** | https://laravel.com/forge/docs/resources/database-backups.md ; https://laravel.com/forge/docs/resources/managed-databases.md | 2026-11-16 | WebFetch |
| Forge deploy hooks: GET or POST. `forge_deploy_commit` "does not affect which commit is deployed". Zero-downtime on **one server only**. Last 4 releases kept for rollback. The post-deploy health check only notifies; it doesn't gate traffic | https://laravel.com/forge/docs/sites/deployments | 2026-11-16 | WebFetch |

## Recommendation
**Option A: Laravel Cloud, Starter plan, region `eu-central-1` (Frankfurt), Serverless Postgres 18. Deploys are CI-gated through deploy hooks, and a GitHub Environment approval gates production.** D3 stands. Forge + VPS stays the documented fallback.

**Why Frankfurt:** it is in the EU and is one of only two European regions with Serverless Postgres. Ireland has app hosting but no Serverless Postgres, and London is outside the EU (it relies on a UK adequacy decision). The app and DB must share a region, and moving regions later is a migration.

### Topology
| | `staging` environment | `production` environment |
|---|---|---|
| Branch | `main` | `main` |
| Push to deploy | **Off** | **Off** |
| Deploy hook | On. URL in GitHub Environment `staging` secret | On. URL **only** in GitHub Environment `production` secret |
| Compute | Flex 512 MiB, scale to zero | Flex 1 GiB, scale to zero (revisit: see Risks, NFR-05) |
| PHP / Node | **Pin PHP 8.4** to match local Herd (or upgrade local to 8.5 in T-001). Node 26 | same |
| Database | Own Serverless Postgres 18 cluster, Dev preset (1-day PITR), 0.25 CU, scale to zero | Own cluster, **Prod preset (7-day PITR)**, 0.25–1 CU, scale to zero at first |
| Build command | `composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader && npm ci && npm run build && php artisan optimize` | same |
| Deploy command | `php artisan migrate --force` | same |
| Health checks | Startup and readiness: `/up`. Liveness: default | same |
| Sessions, cache, queue | `database` drivers, on Postgres. No Valkey. No queue worker, because the MVP has no queued jobs (verification and reset mail can be sent synchronously). Scheduler **off** | same |
| Domain | `*.laravel.cloud` (noindex) | Custom domain + automatic TLS (edge WAF rules apply only here) |
| Data | Synthetic seed data only. Never a copy of production | Real users |
| Monitoring | Nightwatch Free, EU region, own environment | Nightwatch Free, EU region, `NIGHTWATCH_REQUEST_SAMPLE_RATE=0.1`, redaction configured for D8 |
| Previews | **Not in the MVP** (see Risks). Revisit after T-002 | — |

### Deploy flow (G4 then G5)
1. A PR is opened. CI runs the gates on SQLite **and** Postgres (D4). Nothing is deployed.
2. The owner approves and merges the PR to `main` (G4, branch protection).
3. Workflow `deploy.yml` on `push: main` runs the CI gates again on the merge commit, then:
   - Job `staging` (`environment: staging`): `curl -fsS -X POST "$HOOK?commit_hash=$GITHUB_SHA"`. Then poll `https://<staging>/up` and run smoke tests until they pass or time out.
   - Job `production` (`needs: staging`, `environment: production`, **required reviewer = project owner**, deployment branch restricted to `main`): it pauses for approval. **The owner's approval is the G5 decision**, made with the staging smoke results, changelog, and rollback plan to hand. Only after approval does GitHub expose the production hook secret. The job then posts the **same `$GITHUB_SHA`**, so production gets exactly the commit that staging verified. It then polls production `/up`.
4. **Rollback:** a `workflow_dispatch` workflow takes a known-good SHA (it must be on `main`), runs in `environment: production` (so it also needs approval), and posts it to the hook. The code rolls back. **Migrations do not.** So migrations must be backward-compatible (expand, then contract), because Cloud runs them *before* the new release goes live while the old release is still serving. Data recovery is a PITR restore into a new cluster, then attach and redeploy.
5. **Who holds what:** agents get no Cloud account, no `LARAVEL_CLOUD_TOKEN`, and no hook URL. The only Cloud dashboard user is the owner (dashboard "Deploy" is a human action). Hooks are refreshed if they leak. The Cloud API token and CLI aren't used for deploys, because the hook is narrower: it can deploy only one environment, and only commits on its branch.

**Prerequisite:** the GitHub plan must support required reviewers on this repo (see the UNVERIFIED row). If it doesn't, either make the repo public (Environments with required reviewers are available on every plan), or use the fallback gate: a production job that runs only on `workflow_dispatch`, with `if: github.actor == '<owner>'`. That fallback is weaker, because it is not an approval record. T-002 must confirm this before setting up the workflow.

### Monitoring decision
Use **Nightwatch Free (EU region)** for errors and performance, subject to three conditions: approval of the `laravel/nightwatch` dependency, confirmation that it supports Laravel 13, and redaction config that meets D8 (IP, email, request payloads). Use Cloud logs for access and application logs. Sentry is the alternative **if** Nightwatch can't provide NFR-10 "release tags" (UNVERIFIED). Cloud's docs show the `SENTRY_RELEASE` build-step pattern for that case.

## Strongest argument against the recommendation
**Forge + a small EU VPS is more "boring", cheaper at steady state, and gives a rollback and backup story we fully control. Cloud's scale-to-zero economics work against our own NFR-05.** At A1 scale, a production app that is idle most of the day will often serve a first request that wakes both compute (under 500 ms median) and Neon (a few hundred ms). That breaks a p95 under 300 ms target unless we pay to keep the DB, and possibly compute, always on (about $35/month, against Forge Hobby $12 plus a VPS). Cloud's own gaps also count against it: one-click rollback is undocumented, there is no log export below Enterprise, logs are kept for 1 day on Starter, and staging can't be password-protected below Growth. Several of the guarantees we rely on (deploy hook semantics, plan features) belong to a fast-moving product whose changelog shows frequent change.

Counter: Forge can't deploy a pinned SHA (`forge_deploy_commit` is only a label), so "promote exactly what staging tested" is weaker. On-server backups need Forge Business ($39). Managed DO Postgres has no PITR. And OS patching becomes our job. For a solo owner, the ops burden outweighs the cost difference.

## What would change this recommendation
- The required-reviewer feature isn't available on our GitHub plan and the repo can't be public. Then evaluate the `workflow_dispatch` owner gate or a GitHub plan upgrade. Hosting is unaffected.
- Staging measurements show NFR-05 can't be met with scale to zero **and** the always-on cost (about $35/month) is unacceptable. Then reconsider Forge + VPS.
- A legal or contractual requirement for EU-only processing that the Laravel or Cloudflare subprocessor chain can't meet. Then use Forge on an EU provider.
- We need log retention or export beyond 1 day without Enterprise. Then add Growth (7 days) or ship logs through Nightwatch.
- We need previews with basic auth, or a private staging. Then use Growth (+$15/month).

## Risks
| Risk | Likelihood | Impact | Mitigation | Owner |
|---|---|---|---|---|
| GitHub required reviewers unavailable on a private repo with our plan (UNVERIFIED), so G5 isn't technically enforced | Medium | High | T-002 checks the plan first. Public repo, or plan upgrade, or the owner-only `workflow_dispatch` fallback. Record it in ADR-0006 | Owner / T-002 |
| Push to deploy left on by default, so an unreviewed path to production exists | Medium | High | Turn it off on both environments during setup. Add a deployment-checklist item to verify it (setting visible via API `uses_push_to_deploy`) | Owner |
| Cold starts (app + Neon) push p95 over 300 ms (NFR-05) | High | Medium | Measure NFR-05 warm and cold on staging. If needed, turn off scale to zero on the prod DB first (about $19/month), then on compute | Architect / QA |
| A migration runs before go-live. A failed health check leaves the old code on the new schema | Medium | High | Expand/contract migrations only. Migration review at G4. A PITR restore runbook in `05-operations` | Engineer / Owner |
| No documented one-click rollback (UNVERIFIED) | Medium | Medium | Hook redeploy of the last good SHA through the gated workflow. Test it once on staging before go-live | Owner |
| Deploy hook URL leaks, so anyone can deploy branch commits | Low | Medium | Store it only as a GitHub Environment secret. Refresh it on suspicion. A deploy only ships commits already on `main` | Owner |
| Staging is publicly reachable on Starter (no basic auth). Bots register and trigger verification email | Medium | Low | No real data on staging. Rate limiting. Mail to a sandbox or log mailer on staging. Upgrade to Growth if abused | Owner |
| PII in logs or monitoring (Cloud access logs hold client IPs. Nightwatch may capture user identifiers) breaks D8 | Medium | Medium | Nightwatch redaction config. Don't log request bodies. Accept that IPs are in 1-day Cloud access logs and document it in the privacy notice | Security / Architect |
| Subprocessors and international transfers (Cloudflare edge, Neon) not reviewed | Medium | Medium | Owner reviews the DPA and subprocessor list at trust.laravel.com before G5 | Owner |
| PHP version drift: Cloud defaults to 8.5, local is 8.4 | High | Low | Pin 8.4 in both environments, or move local to 8.5 in T-001. Decided in R-001 | Architect |
| Nightwatch not confirmed for Laravel 13, and a free project pauses after 30 days of inactivity | Medium | Low | Confirm before adding the dependency. Sentry fallback. Low traffic still counts as activity in prod | Architect |
| Pricing or features change (Cloud changelog shows frequent changes. Prices quoted as of 2026-11-16) | Medium | Low | Org spending limit (for example $40) with alerts. Re-check pricing at G5 | Owner |
| Lock-in to Cloud | Low | Low | Plain Laravel, no Cloud-specific code. Forge path documented above | Architect |

## Red-team review (2026-11-16)

**Reviewer:** architect agent (red-team mode).

**Verdict: Stands with changes. The hosting choice stands; the G5 enforcement design does not stand as written.** Laravel Cloud Starter in Frankfurt with Serverless Postgres is a sound choice. But the production gate depends on a GitHub feature that a private personal repo doesn't have. Even where that feature exists, it doesn't stop an agent that holds the owner's GitHub token.

| # | Severity | Finding | Evidence | Required change |
|---|---|---|---|---|
| 1 | High | **The UNVERIFIED high-impact row is confirmed: required reviewers are unavailable on private repos for Free, Pro and Team.** Wait timers, custom protection rules and admin-bypass settings have the same limit. On private repos, environment secrets need Pro, Team or Enterprise, and deployment-branch rules need Pro or Team. The docs' restriction names only Free, Pro and Team, which implies Enterprise is fine, but this is not stated explicitly | https://docs.github.com/en/actions/reference/workflows-and-actions/deployments-and-environments (fetched 2026-11-16): "If you are on a GitHub Free, GitHub Pro, or GitHub Team plan, required reviewers are only available for public repositories." Also: "environment secrets are only available in public repositories" (Free); "wait timers are only available for public repositories" | Replace the Prerequisite paragraph and the first Risks row with the decision in finding 3 |
| 2 | High | **Even with required reviewers (a public repo or Enterprise), G5 and G4 aren't enforced against agents** while agent sessions use the owner's GitHub credentials. (a) A required reviewer's token can approve a pending deployment through the REST API (`POST /repos/{owner}/{repo}/actions/runs/{run_id}/pending_deployments`; endpoint name from GitHub REST docs, **not fetched**). (b) The proposed fallback `if: github.actor == '<owner>'` is satisfied by an agent running `gh workflow run` as the owner. (c) For G4: "Pull request authors cannot approve their own pull requests." So if agents open PRs as the owner, a "1 approval" rule can never be met by the solo owner, and by default "the restrictions of a branch protection rule don't apply to people with admin permissions" | `gh auth status` in this agent session: logged in as the owner with scopes `'admin:public_key', 'gist', 'read:org', 'repo'`. https://docs.github.com/en/pull-requests/collaborating-with-pull-requests/reviewing-changes-in-pull-requests/approving-a-pull-request-with-required-reviews ; https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-protected-branches/about-protected-branches (both fetched 2026-11-16) | **Give agents a separate GitHub identity.** Options: a free bot account added as a collaborator with write access, or a fine-grained PAT or GitHub App limited to contents and pull-requests. Neither has admin, Actions-dispatch, environment or secrets rights. Keep the owner's `gh` token out of agent sessions. Enable "Do not allow bypassing the above settings". Add R-005 deny rules for `gh pr merge`, `gh api`, `gh workflow run` and `gh secret` as a backstop. This is a G1 decision for the owner |
| 3 | High | **The cheapest enforceable G5 option costs $0 and keeps the production hook out of GitHub entirely.** The owner keeps the production deploy-hook URL in a password manager. After reviewing the staging smoke results, the owner runs `curl -fsS -X POST "$PROD_HOOK?commit_hash=<SHA verified on staging>"` from their own terminal. Enforcement comes from **secret possession**: agents have no Cloud account (R-004 §Who holds what) and never see the URL. The audit trail is Cloud's deployment history plus the G5 row in the gate log. A dashboard "Deploy" click also works, but it deploys the branch head rather than a pinned SHA ("If you don't provide a commit hash, Laravel Cloud deploys the latest commit") | https://laravel.com/cloud/docs/deployments.md § Deploy hooks, § Manual (fetched 2026-11-16) | Make this the default G5 mechanism in ADR-0006. Alternatives, in order: a public repo with required reviewers ($0, needs finding 2, makes the code and docs public); GitHub Enterprise (paid, price **UNVERIFIED**, needs finding 2). Staging may stay CI-triggered: use a repo secret on Free, or an environment secret plus a `main`-only deployment branch on Pro. Update the governance Enforcement row "Merge and deploy approval" |
| 4 | Medium | **NFR-05 and cold starts: the cost arithmetic is right, but the sizing and the measurement aren't.** My recompute gives $11.33 (low) and $34.98 (high), matching the report. However: (a) wake time is a **median** of 500 ms (pricing page), so the p95 wake is unpublished, and Neon adds "a few hundred milliseconds", so a cold request almost certainly exceeds 300 ms. (b) An always-on production compute instance doesn't need 1 GiB at A1: 512 MiB gives about 17 PHP workers (`floor(512/30)`), which is about $7 in Frankfurt (the report's figure) instead of $14, so always-on costs about **$28/month**. (c) NFR-05 doesn't say whether platform wake counts, and the "staging measurement" uses a different configuration (512 MiB, Dev DB). (d) `NIGHTWATCH_REQUEST_SAMPLE_RATE=0.1` at A1 traffic gives too few samples to compute a p95 | https://laravel.com/cloud/pricing ("wake back up in a median of 500 ms"); https://laravel.com/cloud/docs/compute.md (worker formula, "under 500 milliseconds"); https://laravel.com/cloud/docs/resources/databases/postgres.md (fetched 2026-11-16) | The owner decides at G1 between: **(i)** NFR-05 = warm-request p95 of app-side duration, measured on a production-equivalent configuration with at least 200 requests at 100 todos per user, **plus** an explicit cold-start budget (for example, the first request after idle ≤ 1.5 s, reported separately); or **(ii)** keep the strict reading and pay about $28/month (512 MiB compute plus DB always on). Use a sample rate of 1.0 during measurement runs |
| 5 | Medium | **Missing: a transactional email provider.** D1 depends on verification and reset email. R-004 leaves "email sending" out of cost and proposes sending synchronously, but names no provider. A provider means a new account, a D8 subprocessor, SPF/DKIM on the custom domain, and a decision on whether staging delivers real mail | R-004 Cost "Not included", Topology "Sessions, cache, queue" row | Add an option table (for example SES, Postmark, Resend; prices **UNVERIFIED**) and an owner decision before G2. Use a log or sandbox mailer on staging |
| 6 | Low | **Nightwatch on Laravel 13 is resolved by package metadata**, although the docs page still recommends 10–12 | `composer show -a laravel/nightwatch v1.30.6` requires `laravel/framework ^10.0\|^11.0\|^12.0\|^13.0`. https://nightwatch.laravel.com/docs/requirements.md | Downgrade the row from UNVERIFIED to "supported by constraint; docs lag" |
| 7 | Low | The claim that apps must be on the latest minor "or the deploy fails" is overstated. The docs say you "should also use the latest minor" and list minimum minors only for Laravel 9–11 | https://laravel.com/cloud/docs/deployments.md § Framework or runtime version not supported | Reword. Keep a monthly `composer update laravel/framework` PR anyway |
| 8 | Low | Deploy flow step 3 re-runs every CI gate on the merge commit, which doubles Actions minutes (see R-003 red-team finding 7) | — | Re-run only fast gates on `main`, or rely on a required up-to-date branch check |

**Strongest argument against (re-checked):** Forge + VPS. It still doesn't refute Cloud. Finding 3's hook approach works the same on Forge, but Forge can't pin a SHA.
