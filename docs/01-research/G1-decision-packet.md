# G1 Decision Packet

**Status:** Waiting for the owner's decision · **Prepared:** 2026-11-16 by the orchestrator
**Inputs:** R-001 to R-005 (each with a red-team review appended)

## Research outcome

| Report | Recommendation | Red-team verdict |
|---|---|---|
| R-001 | Laravel 13.42 (PHP ^8.3, security support until 2028-03-17) with the official Vue starter kit: Inertia 3, Vue 3.5, TS, Tailwind 4, shadcn-vue, Fortify, Wayfinder | Stands with changes |
| R-002 | Inertia monolith (confirms D2). Redirect-back writes. Typed props through `InertiaConfig` | Stands with changes |
| R-003 | Pest 5 (arch, mutate, browser plugins), PCOV in CI, Larastan level 8, coverage 85/90, mutation score 70 | Stands with changes |
| R-004 | Laravel Cloud Starter in Frankfurt with Serverless Postgres. Push-to-deploy off, deploys triggered by hook | Hosting stands. **The G5 mechanism as written does not stand** |
| R-005 | Boost 2.10.4 (appends to CLAUDE.md, doesn't overwrite it). Path-guard hook is possible but only a best-effort control. 19 corrections to settings | Stands with changes |

## Orchestrator verification (commands run 2026-11-16)

| Claim | Result |
|---|---|
| The kit installs Laravel v13.42.0 | ✅ `composer show` in the spike |
| The kit uses Vite+ (`vp`) and has no ESLint. npm scripts are `build`, `check`, `check:fix`, `types:check` | ✅ spike `package.json` |
| The kit's PHPStan is at level 7 | ✅ `phpstan.neon` |
| The kit's `.gitignore` ignores `/.claude`, `/CLAUDE.md`, `/AGENTS.md` | ✅ lines 32, 41, 42 |
| The kit uses `v-html` (2FA QR code) | ✅ `TwoFactorSetupModal.vue:174` |
| Local PHP has no coverage driver (pcov or xdebug) | ✅ `php -m` |
| This session's `gh` token belongs to the owner and has `repo` scope | ✅ `gh auth status`. **An agent could approve or merge as the owner** |

## Decisions required

| # | Decision | Recommendation | Why |
|---|---|---|---|
| G1-1 | **Agent GitHub identity** | Create a free **bot GitHub account** (for example `todo-app-bot`) with write access but no admin. Agents push and open PRs as the bot. The owner reviews and merges as themselves. Log the owner's `gh` session out of agent terminals. Add `ask` rules for `gh pr merge`, `gh api`, `gh workflow`, `gh secret` | GitHub doesn't let PR authors approve their own PRs, so the bot as author makes G4 approval possible. It also stops agents acting with the owner's authority |
| G1-2 | **G5 mechanism** | **Owner-held production deploy hook.** The hook URL lives only in the owner's password manager and is never stored in GitHub. The owner runs `curl -X POST "$HOOK?commit_hash=<staged SHA>"`. CI deploys staging automatically. Cost $0 | GitHub's required reviewers aren't available on private repos for Free, Pro, or Team plans. The hook stays enforceable because agents never see it |
| G1-3 | **2FA and passkeys** (beyond D1) | **Keep both.** Amend D1. Allow-list the single QR `v-html` (Fortify generates that SVG on the server, so it's not user content) | Removing them means editing security-sensitive auth code, which is riskier than keeping them |
| G1-4 | **NFR-03 enforcement** | Reword to "user content is never rendered as raw HTML". Enforce it with a ~40-line CI check using `vue/compiler-sfc` (already shipped with `vue`, so no new dependency) that fails on `v-html` and `:innerHTML` outside an explicit allow-list | Oxlint has no rule for this, and ESLint would miss `:innerHTML`. The red team's spike caught both |
| G1-5 | **NFR-05 and cold starts** | Redefine NFR-05 as warm p95 under 300 ms, plus a documented cold-start budget (first request after sleep under 2 s). Keep scale-to-zero (about $11/month total). Move to always-on production (+about $23/month) when there are real users | A demo app doesn't justify paying to stay always-on. Waking from sleep takes a median of 500 ms or more, so the old NFR could never be met with scale-to-zero |
| G1-6 | **Runtime versions** | PHP **8.4** everywhere (`composer.json` `^8.4`, CI, Cloud). Node **26** (local 26.12.1; T-001 checks that it's LTS, otherwise 24 LTS) | Pest 5 requires PHP 8.4. Cloud defaults to 8.5, and the local machine has 8.4.25 |
| G1-7 | **Who scaffolds** | **The owner runs `laravel new` in a plain terminal** (outside Claude Code) into a sibling folder. An agent then does the merge in T-001. **One-time exception to the ~400-line PR limit** for a separate, unmodified kit commit | Claude Code's agent-detection environment variables break the conversion of the kit's tests to Pest. The kit commit is generated code, so reviewing it line by line wouldn't add much |
| G1-8 | **Transactional email** | Use the `log` mailer locally and on staging. Choose a production provider (for example Postmark or Resend, EU region) through a short research ticket **R-006 before G5**. This doesn't block G2 or G3 | D1's email verification and password reset depend on it, but only in production |
| G1-9 | **Dependency updates** | Weekly Dependabot for `composer`, `npm`, and `github-actions`. Group `inertiajs/inertia-laravel` and `@inertiajs/vue3` into one PR. Install with `npm ci` in CI | Inertia 3 releases often, and the server and client packages need to stay in step |
| G1-10 | **Committed tooling files** | Commit `CLAUDE.md`, `.mcp.json`, `boost.json`. Turn Boost's `record-rule` and Tinker tools off | They give every agent and every clone the same setup. `record-rule` would let agents write outside their scope |
| G1-11 | **Agent settings hardening** | Apply R-005 corrections C1 to C19 plus the red team's `gh` rules in one reviewed PR. Add the path-guard hook as a best-effort guard. **The authoritative check is a CI job that fails if a PR touches files outside its task's `scope.paths`** | Several current rules are wrong (for example `migrate:*` doesn't match `migrate:fresh`) or easy to get around |

## Documents updated after G1 (orchestrator, one docs PR)

The NFR-03, NFR-05, and NFR-08 wording. The D1 amendment. The Commands section of `CLAUDE.md` (real script names). The `vue-engineer`, `devops-engineer`, `security-reviewer` (+Write), and `architect` (+Edit) agent files. The governance Enforcement table. The task template. ADR drafts 0001 to 0006 for G2.

## Remaining UNVERIFIED items (carried into the risk register)

How the spike's CI file came to use PHP 8.4. The REST endpoint for pending deployments (not needed if G1-2 is approved). Laravel Cloud's p95 wake time and Frankfurt compute prices. GitHub Actions minute quota for the CI matrix. Installing PCOV under Herd. Whether the Pest browser plugin can set a 320 px viewport.
