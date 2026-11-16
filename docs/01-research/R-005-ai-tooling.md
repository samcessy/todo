# R-005: Laravel Boost, Claude Code subagents, skills and hooks. Can agent path scopes be technically enforced?

**Status:** Draft · **Author:** architect · **Red team:** (pending) · **Date:** 2026-11-16

## Question
How should we set up Laravel Boost and Claude Code (subagents, skills, permissions, hooks) for this project, and can each agent's path scope be technically enforced instead of only being written in its instructions?

## Constraints
- D7 (`00-product/assumptions.md`): Claude Code + Laravel Boost MCP, with `CLAUDE.md` as the canonical guide. Boost may also generate `AGENTS.md`.
- D6: there is one human approver, so the controls must work without a second reviewer.
- `governance.md` → Enforcement: agent path scopes are "not technically enforced yet". A path-guard hook is a candidate for T-001.
- CLAUDE.md rules 2 (stay inside `scope.paths`), 3 (adding a dependency needs approval) and 4 (never touch `.env*`).
- Nothing is installed yet. Boost is added in T-001, which needs dependency approval.

## Options

### A. Laravel Boost and guideline files
| Option | Summary |
|---|---|
| A1 | `boost:install` with Claude Code selected. Boost writes its block **into our existing `CLAUDE.md`**, and our hand-written rules stay outside that block. Commit `CLAUDE.md`. |
| A2 | Set `boost.agents.claude_code.guidelines_path` to a separate file (for example `.ai/boost-guidelines.md`), and have `CLAUDE.md` reference it. Our `CLAUDE.md` is then never machine-edited. |
| A3 | Use the Boost MCP server only, without guidelines or skills. |

### B. Enforcing path scopes
| Option | Summary |
|---|---|
| B1 | Instructions only (status quo). |
| B2 | One **project-level PreToolUse path-guard hook** that routes on `agent_type` and checks `file_path` against a role-to-glob map. |
| B3 | Per-agent `hooks:` in each subagent's frontmatter. |
| B4 | Per-agent `tools` / `disallowedTools` restrictions (for example, no Write for reviewers). |
| B5 | `isolation: worktree` for implementer subagents. |
| B6 | **CI path-ownership check** on each PR: the diff must stay inside the task's `scope.paths`. Later, CODEOWNERS (D6 revisit). |
| B7 | OS sandbox (`sandbox.*`) for Bash file writes. |

## Evaluation criteria
Complexity, security surface, testability, deploy simplicity, future flexibility, maturity ("boring technology" counts in an option's favour). For enforcement we add one more: **can it be bypassed by an agent that is trying, or merely confused?**

## Evidence

Abbreviations:
- **CC-hooks** = https://code.claude.com/docs/en/hooks
- **CC-perm** = https://code.claude.com/docs/en/permissions
- **CC-sub** = https://code.claude.com/docs/en/sub-agents
- **CC-skills** = https://code.claude.com/docs/en/skills
- **LB-13** = https://laravel.com/framework/docs/13.x/boost

All were fetched 2026-11-16. The Claude Code docs pages carry no page-level version, but they cite features up to v2.1.314.
**Boost-src** = the `laravel/boost` tag v2.10.4 (commit 9cd5e7d, 2026-10-30), cloned into the scratchpad.

### Versions (verified by command, 2026-11-16)
| Claim | Source | Version / date | Verified by command? |
|---|---|---|---|
| Latest laravel/boost is **v2.10.4**. It requires `php ^8.2`, `illuminate/* ^11.45.3\|^12.41.1\|^13.0`, `laravel/mcp ^0.7.1\|…\|^1.0`, `laravel/roster ^1.0` | Packagist via `composer show -a laravel/boost` | v2.10.4 | Yes |
| Boost guidelines cover Laravel core and 10.x–13.x. The Search Docs API covers Laravel 10.x–13.x, **Inertia 1.x–2.x only**, Pest 3.x–4.x and Tailwind 3.x–4.x. **Vue is not covered** | LB-13 | Laravel 13.x docs | n/a |
| Installed Claude Code is **2.1.308**. Latest on npm is **2.1.325** | `claude --version`, `npm view @anthropic-ai/claude-code version` | 2026-11-16 | Yes |
| The local mirror `claude-ecosystem/official/anthropics__claude-code` is at CHANGELOG **2.1.294** (commit 71cddde, 2026-10-08). It is **stale by about 31 releases**, so it was used only to cross-check | `git log -1`, CHANGELOG head | 2026-10-08 | Yes |
| Laravel 13.x is the current docs version. The 12.x Boost page shows an "old version" banner | https://laravel.com/docs/12.x/boost | 2026-11-16 | n/a (R-001 owns framework versions) |

### Laravel Boost
| Claim | Source | Version / date | Verified by command? |
|---|---|---|---|
| Install with `composer require laravel/boost --dev`, then `php artisan boost:install`. The installer asks which agents and features (guidelines, skills, MCP) to set up | LB-13 §Installation | 13.x docs | No (install happens in T-001) |
| **Existing `CLAUDE.md` is preserved.** "If a guideline file such as `CLAUDE.md` or `AGENTS.md` already exists, Boost only replaces the content inside its `<laravel-boost-guidelines>` block" | LB-13 §Installation | 13.x docs | Source read (next row) |
| On the first install, Boost **appends** `\n\n===\n\n<laravel-boost-guidelines>…</laravel-boost-guidelines>` to the end of the file. Later runs replace the block in place, using a file lock | Boost-src `src/Install/GuidelineWriter.php` lines 55–76 | v2.10.4 | Source read |
| For Claude Code, guidelines go to `CLAUDE.md` if it exists, **otherwise to `AGENTS.md`**. Both paths can be overridden with config `boost.agents.claude_code.guidelines_path`. MCP config goes to `.mcp.json` (file strategy). Skills go to `.claude/skills` | Boost-src `src/Install/Agents/ClaudeCode.php` | v2.10.4 | Source read |
| The docs suggest **gitignoring** `.mcp.json`, the guideline files (`CLAUDE.md`, `AGENTS.md`) and `boost.json`, because they are regenerated | LB-13 §Installation | 13.x docs | n/a |
| Keep resources updated with `php artisan boost:update`. It can run automatically from a Composer `post-update-cmd` (`@php artisan boost:update --ansi`). `--no-discover` updates only resources that are already published | LB-13 §Keeping Boost Resources Updated | 13.x docs | No |
| The documented MCP tools are: Application Info, Browser Logs, Database Connections, Database Query, Database Schema, Get Absolute URL, Last Error, Read Log Entries, **Record Rule**, Search Docs. In source, Application Info is an MCP *resource*, not a tool | LB-13 §Available MCP Tools; Boost-src `src/Mcp/Boost.php` | v2.10.4 | Source read |
| A **Tinker** tool exists in source (it executes arbitrary PHP) but is **missing from the docs table**. It is registered only when `config('boost.tinker_tool_enabled')` is true, and the default is false | Boost-src `src/Mcp/Tools/Tinker.php` lines 27–30 | v2.10.4 | Source read |
| There is **no route-list or artisan-command tool** in v2.10.4 (the tool directory lists exactly the 10 classes above). Use `php artisan route:list`, which is already allowed in settings | Boost-src `src/Mcp/Tools/` | v2.10.4 | `ls` |
| Database Query accepts only SELECT, SHOW, EXPLAIN, DESCRIBE, WITH…SELECT, VALUES and TABLE, after a lexical filter. It runs inside a database read-only transaction that is always rolled back | Boost-src `src/Mcp/Tools/DatabaseQuery.php` | v2.10.4 | Source read |
| Tools can be removed with config `boost.mcp.tools.exclude` (a list of class names) and added with `include` | Boost-src `src/Mcp/Boost.php` `filterPrimitives()` | v2.10.4 | Source read |
| **Project rules**: the `record-rule` tool writes Markdown into `.ai/rules/` and regenerates `.ai/rules/index.md`. It is **on by default**. Disable it with `BOOST_RULES_ENABLED=false` (config `boost.rules.enabled`) | LB-13 §Project Rules; Boost-src `config/boost.php` | v2.10.4 | Source read |
| Custom guidelines go in `.ai/guidelines/*.md` or `*.blade.php`, and the same path overrides a built-in guideline. Custom skills go in `.ai/skills/<name>/SKILL.md` and are synced on `boost:update` | LB-13 §AI Guidelines, §Custom Skills | 13.x docs | No |
| Skills installed for our likely stack: `inertia-vue-development`, `pest-testing`, `tailwindcss-development`, `wayfinder-development` (if the starter kit uses Wayfinder) and `infer-conventions` (always) | LB-13 §Available Skills | 13.x docs | No (depends on the R-001 composer.json) |
| The manual Claude Code registration is `claude mcp add -s local -t stdio laravel-boost php artisan boost:mcp` | LB-13 §Set Up Your Agents | 13.x docs | No |

### Claude Code: subagents and skills
| Claim | Source | Version / date | Verified by command? |
|---|---|---|---|
| Subagent frontmatter requires only `name` and `description`. Optional fields: `tools` (comma string or YAML list; omitted means all tools are inherited), `disallowedTools`, `model` (`sonnet`, `opus`, `haiku`, `fable`, a full model ID, or `inherit`), `permissionMode`, `maxTurns`, `skills`, `mcpServers`, `hooks`, `memory`, `background`, `omitClaudeMd`, `effort`, `isolation: worktree`, `color`, `initialPrompt`. Unknown fields are silently ignored | CC-sub | docs cite ≤ v2.1.296 | Spike: a `name/description/tools/model` agent loaded and ran (see the spike rows below) |
| `permissionMode` in a subagent is **ignored** when the parent runs in `bypassPermissions`, `acceptEdits` or auto mode | CC-sub | — | No |
| Frontmatter `hooks` run only while that subagent is running. **Project-level frontmatter hooks need workspace trust, and a `-p` session doesn't count as trust** | CC-sub; CC-hooks; CC-perm (trust table) | — | No |
| `Agent(type)` in `tools` restricts spawning only for a `claude --agent` main thread. In a subagent definition the type list is ignored | CC-sub | — | No |
| Skills live in `.claude/skills/<name>/SKILL.md`, and every frontmatter field is optional. Fields include `name`, `description`, `when_to_use`, `allowed-tools`, `disallowed-tools`, `disable-model-invocation`, `user-invocable`, `context: fork`, `agent`, `model`, `effort`, `hooks`, `paths` and `arguments`. `.claude/commands/*.md` still works, and a skill wins on a name clash. Subagents can preload skills with `skills:` | CC-skills | docs cite ≤ v2.1.309 | No |

### Claude Code: permissions
| Claim | Source | Version / date | Verified by command? |
|---|---|---|---|
| Rules are evaluated deny, then ask, then allow. The first match wins, and specificity is ignored | CC-perm §Manage permissions | — | No |
| `:*` is a legacy-equivalent trailing wildcard: "`Bash(ls:*)` matches the same commands as `Bash(ls *)`". It is recognised **only at the end** of a pattern | CC-perm §Wildcard patterns | — | No (spike attempted, blocked by a tool outage) |
| **The space before a trailing `*` is significant**: `Bash(ls *)` does not match `lsof`, but `Bash(ls*)` does. So **`Bash(php artisan migrate:*)` equals `Bash(php artisan migrate *)` and does NOT match `php artisan migrate:fresh`, `:rollback`, `:reset` or `:refresh`** | CC-perm §Wildcard patterns | — | Derived from the docs; see UNVERIFIED |
| Compound commands are split on `&&`, `\|\|`, `;`, `\|`, `&` and newlines. Deny and ask rules apply if **any** subcommand matches | CC-perm §Compound commands | — | No |
| A Bash rule "isn't a security boundary around the program". For example, `Bash(git push *)` does not stop `git -C . push …` or `git -c … push` | CC-perm §What a Bash rule doesn't match | — | No |
| `Write(path)` rules **never match**. Use `Edit(path)`, which covers Write, NotebookEdit and MultiEdit | CC-perm §Write(path)… rules don't match | — | No |
| Path anchors: `//abs`, `~/home`, `/relative-to-settings-file`, and `path` or `./path` relative to the cwd. A bare filename such as `.env` matches at any depth. A `!` negation in the same deny list carves out exceptions | CC-perm §How path patterns match | — | No |
| Read and Edit deny rules also apply to recognised Bash file commands (`cat`, `head`, `tail`, `sed`, `tee`) and to redirection targets. They do **not** apply to `grep -r` or to scripts that open files themselves. Use the sandbox for OS-level enforcement | CC-perm §Read and Edit | — | No |
| `permissions.blockWritesOutsideWorkingDirectories` needs v2.1.314 or later | CC-perm §Working directories | v2.1.314 | Installed version is 2.1.308, so this needs an upgrade |
| `allow` entries in an **untrusted** project's `.claude/settings.json` are ignored, but its hooks still run | Spike stderr: "Ignoring 2 permissions.allow entries … this workspace has not been trusted" | 2.1.308 | **Yes (spike)** |

### Claude Code: hooks
| Claim | Source | Version / date | Verified by command? |
|---|---|---|---|
| There are 33 events, including PreToolUse, PostToolUse, PermissionRequest, SubagentStart, SubagentStop, Stop, SessionStart, InstructionsLoaded, FileChanged, WorktreeCreate and others | CC-hooks | — | No |
| Common stdin fields: `session_id`, `prompt_id`, `transcript_path`, `cwd`, `scratchpad_dir`, `permission_mode`, `effort`, `hook_event_name`. **Inside a subagent there are also `agent_id` and `agent_type`**. The subagent's type takes precedence over `--agent` | CC-hooks; CHANGELOG ("Added `agent_id` … and `agent_type` … to hook events") | — | **Yes (spike)** |
| For Write, `tool_input` has `file_path` (absolute) and `content`. For Edit: `file_path`, `old_string`, `new_string`, `replace_all`. For Bash: `command`, `description`, `timeout`, `run_in_background`. `~` and relative paths are expanded **before** hooks run | CC-hooks §PreToolUse | — | Spike confirmed `file_path` is absolute |
| Exit 0 applies any JSON output. **Exit 2 blocks** PreToolUse and sends stderr to Claude, even if the hook's JSON says allow. **Any other exit code, including 1, is non-blocking** | CC-hooks §Exit codes | — | **Yes (spike, exit 2)** |
| JSON alternative: `{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"allow\|deny\|ask\|defer","permissionDecisionReason":"…"}}`. Precedence is deny > defer > ask > allow. A hook's `allow` **never overrides** deny or ask rules | CC-hooks; CC-perm §Precedence among rules, hooks, and mods | — | No |
| Hooks fail open on timeout or error, unless `onFailure: "block"` is set (v2.1.295+). The default timeout is 600 s | CC-hooks | v2.1.295 | No |
| The `if` filter on a hook is best-effort: "use the permission system rather than a hook to enforce a hard allow or deny" | CC-hooks | — | No |
| A PostToolUse `Edit\|Write` hook does not run when Bash or another process rewrites a file | CC-hooks | — | No |
| `$CLAUDE_PROJECT_DIR` stays at the session root after entering a worktree. `cwd` follows Claude | CC-hooks | — | No |

### Spike results (scratchpad `r005-spike/hookproj`, Claude Code 2.1.308, `claude -p --model haiku`)
| Test | Observed |
|---|---|
| PreToolUse logger: the main session writes `main.txt`, then the `scribe` subagent writes `sub.txt` | Main session: `"agent_id":null,"agent_type":null`. Subagent: `"agent_id":"a19d330896b31d121","agent_type":"scribe"` |
| Guard hook: when `agent_type=="scribe"` and `file_path` is not under `$CLAUDE_PROJECT_DIR/allowed/`, the hook prints the reason to stderr and exits 2 | The Write to `blocked.txt` was **blocked**, and the subagent saw the stderr reason. The Write to `allowed/ok.txt` **succeeded** |
| The same subagent ran `echo z > bash-bypass.txt` through Bash | It **succeeded**. The guard only checks Write and Edit, which confirms the Bash bypass |

## Recommendation

### 1. Laravel Boost (T-001, after dependency approval): option A1 with three hardening settings
1. Run `composer require laravel/boost --dev`, then `php artisan boost:install`. Select Claude Code, with guidelines, skills and MCP. Record the installed version in the T-001 handoff (v2.10.4 as of today).
2. **Keep our hand-written `CLAUDE.md`, and commit it.** Boost appends its `<laravel-boost-guidelines>` block below a `===` separator, and later `boost:update` runs rewrite only that block. Ignore Boost's advice to gitignore `CLAUDE.md`, because it is our canonical guide (D7). Add one line to `CLAUDE.md` above the block: "Do not edit inside `<laravel-boost-guidelines>`. It is regenerated by `boost:update`." Commit `.mcp.json` and `boost.json` too, so every session and the red team see the same tool set.
   - A2 (a separate guidelines file) is the fallback if Boost diffs in `CLAUDE.md` become noisy in PRs.
3. Hardening, committed in `config/boost.php`:
   - Keep Tinker **disabled**. This is already the default; do not set `tinker_tool_enabled`. Tinker runs arbitrary PHP, which bypasses every path, `.env` and permission control.
   - Set `rules.enabled => false` until G3. The `record-rule` tool lets any agent write `.ai/rules/*` via MCP, outside its scope, without passing a file-tool hook. That conflicts with "docs are the source of truth". Revisit once project rules have an owner, for example the architect.
4. Update with `php artisan boost:update --no-discover` from a Composer `post-update-cmd`. Discovering new packages already needs a dependency approval, so the rerun is a deliberate act.
5. Put our own project skills, if any, in `.ai/skills/<name>/SKILL.md`. That is Boost's source of truth, and it syncs them to `.claude/skills/`. Use the Claude Code skill format above.
6. Tell the frontend agents that `search-docs` does **not** cover Vue, and does not cover Inertia 3.x if R-001 finds the starter kit on v3. For those, they must cite inertiajs.com and vuejs.org.

### 2. Path scopes: B2 + B4 + B6 as layers; B3, B5 and B7 not now
- **B2: a project-level PreToolUse path-guard is feasible** and has been demonstrated. `agent_type` reliably identifies the subagent. Put it in `.claude/settings.json`, not in agent frontmatter (B3), for three reasons:
  - one policy file to review;
  - it works in `-p` and untrusted sessions, where frontmatter hooks do not run;
  - it is not affected by `permissionMode` being overridden.
- **B4:** align each agent's `tools` with its scope (see the corrections below).
- **B6: a CI path-ownership check is the authoritative control.** The hook is a fast local guard that Bash can bypass. The CI job cannot be bypassed by the agent. CODEOWNERS follows when D6's trigger fires.
- Do not use B5 (`isolation: worktree`) for scope. It isolates concurrent agents from each other but does not restrict paths. Consider it later for parallel implementers.
- B7 (sandbox) is session-wide. A per-agent sandbox write allowlist was not found in the docs. It is worth adding at the session level later, to stop Bash writes outside the repo and reads of `.env` by scripts.

Path-guard design (pseudocode only; T-001 implements it):

```text
# .claude/settings.json
PreToolUse matcher "Edit|Write|NotebookEdit|MultiEdit|Bash|mcp__laravel-boost__.*"
  -> command "$CLAUDE_PROJECT_DIR/.claude/hooks/path-guard.sh", onFailure: "block", timeout: 10

# .claude/hooks/scopes.json   (role-level scopes; reviewed like code)
architect:          allow [docs/01-research/**, docs/02-architecture/**, docs/adr/**]
laravel-engineer:   allow [app/**, routes/**, database/**, config/**, bootstrap/**, lang/**,
                           docs/03-plan/handoffs/*-backend.md, docs/03-plan/tasks/*.md]
                    deny  [tests/**, .github/**, .claude/**, .env*]
vue-engineer:       allow [resources/js/**, resources/css/**, docs/03-plan/handoffs/*-frontend.md,
                           docs/03-plan/tasks/*.md]   deny [**/*.spec.ts, tests/**]
qa-engineer:        allow [tests/**, resources/js/**/*.spec.ts, docs/04-quality/traceability-matrix.md,
                           docs/03-plan/handoffs/*-qa.md, docs/03-plan/tasks/*.md]
devops-engineer:    allow [.github/**, docs/05-operations/**, docs/03-plan/handoffs/*-devops.md,
                           docs/03-plan/tasks/*.md]   (composer.json/package.json only via ask)
security-reviewer:  allow [docs/03-plan/handoffs/*-security.md]
<no agent_type>     (main session, human present) -> allow, rely on permission rules
<unknown agent_type> -> deny (fail closed for new or misspelt agents)
global deny for every role: .claude/**, .env*, vendor/**, node_modules/**

# path-guard.sh
read stdin JSON; if jq is missing or the JSON is invalid -> stderr "path-guard: cannot parse", exit 2
role  = .agent_type or ""
tool  = .tool_name
if role == "": exit 0
if role not in scopes: deny("unknown agent type")
if tool in {Edit, Write, NotebookEdit, MultiEdit}:
    p = realpath(.tool_input.file_path // .tool_input.notebook_path)   # resolves symlinks
    if p is not under $CLAUDE_PROJECT_DIR: deny
    rel = p relative to $CLAUDE_PROJECT_DIR
    if matches(global_deny or role.deny, rel) or not matches(role.allow, rel): deny(rel, role.allow)
    exit 0
if tool == Bash:   # BEST EFFORT ONLY: the CI check is the real control
    cmd = .tool_input.command
    for each write target found in cmd (> / >> redirects, tee, sed -i, cp/mv/rm/touch/mkdir args,
         git checkout|restore -- <paths>):
        apply the same path check; deny on a violation
    if cmd matches (php -r|node -e|python -c|bash -c|sh -c|eval) and role != devops-engineer:
        ask("inline interpreter: cannot verify the write scope")
    exit 0
if tool starts with "mcp__laravel-boost__":
    if tool ends with record-rule or tinker: deny unless role == "architect"
    exit 0
deny(msg): print msg to stderr; exit 2     # or JSON permissionDecision "deny"
```

Task-level scopes (`scope.paths` in each `docs/03-plan/tasks/<ID>.md`) are **not** enforced in the hook. The hook cannot know which task a given `agent_id` is working on, and a shared "active task" file races when agents run in parallel. Enforce task-level scope in CI (B6):

```text
on pull_request:
  task_id = branch name (task/T-0XX-*)  -> fail if missing
  scope   = YAML scope.paths in docs/03-plan/tasks/<task_id>.md
  changed = git diff --name-only origin/main...HEAD
  fail if any changed file matches none of the scope globs (allowlist: the handoff and task file for <task_id>)
  fail if the PR touches tests/** and app/**|resources/js/** (not *.spec.ts) in the same commit authored by an engineer agent (optional)
```

### 3. Concrete corrections to `.claude/settings.json`, the hook and the agent files
These are recommendations only. Nothing under `.claude/` has been modified.

| # | File / rule | Problem | Correction |
|---|---|---|---|
| C1 | `ask: Bash(php artisan migrate:*)` | `:*` equals ` *`, so `migrate:fresh`, `migrate:rollback`, `migrate:reset` and `migrate:refresh` are **not covered**. They fall to Manual-mode prompting in practice, but they are not *listed* | `Bash(php artisan migrate*)` (no space), plus a separate entry `Bash(php artisan db:seed*)` |
| C2 | `deny: Bash(php artisan db:wipe:*)` | Works, but in the legacy form. `migrate:fresh` is in the governance "never" list but has no rule | `Bash(php artisan db:wipe*)`. Add `Bash(php artisan migrate:fresh*)` to **ask**, not deny, because local and test use is allowed |
| C3 | `deny: Write(./.env*)` | `Write(path)` rules never match (dead rule) | Remove it. `Edit(...)` already covers Write |
| C4 | `deny: Read(./.env)`, `Read(./.env.*)`, `Edit(./.env*)` | `./` anchors to the cwd, so these miss `.env` files in nested or worktree checkouts and after a `cd` | `Read(**/.env)`, `Read(**/.env.*)`, `Edit(**/.env)`, `Edit(**/.env.*)`. Decide on `.env.example`: T-001 likely needs it, so either carve it out with `Read(!**/.env.example)` and `Edit(!**/.env.example)` in the same deny list, or keep it blocked and have the human edit it |
| C5 | `.env` protection in general | Read deny does not stop `grep -r`, `php -r`, `php artisan tinker`, `php artisan config:show` or Boost Tinker | Add to deny: `Bash(php artisan tinker*)`, `Bash(php artisan config:show*)`, `Bash(php artisan env*)`. Keep Boost Tinker disabled. Plan a sandbox `denyRead` for `.env*` (key name **UNVERIFIED**, check in T-001) |
| C6 | `deny: Bash(git push origin main:*)` | Misses `git push`, `git push -u origin main`, `git push origin HEAD:main` and `git -C . push`. The docs say Bash rules are not a security boundary | Replace with `Bash(git push *main*)` (it also blocks `maintenance*` branches, which is acceptable). Keep `Bash(git push *)` in ask. **Real control: branch protection (T-002).** Update the governance Enforcement table to say so |
| C7 | `deny: Bash(git push --force:*)`, `Bash(git push -f:*)` | Miss flags placed after the refspec (`git push origin x --force`) and `--force-with-lease` | `Bash(git push *--force*)`, `Bash(git push * -f*)`, `Bash(git push -f*)` |
| C8 | Missing **ask** entries | Dependency or lockfile changes and destructive git commands bypass the ask list | Add `Bash(npm i *)`, `Bash(npm add *)`, `Bash(npm update*)`, `Bash(composer update*)`, `Bash(npx *)`, `Bash(git reset --hard*)`, `Bash(git clean*)`, `Bash(git checkout -- *)`, `Bash(git rebase*)` |
| C9 | Style | `:*` is a legacy-equivalent form, and the dialog writes the space form | Optionally normalise all rules to `Bash(cmd *)`. It is functionally the same, so this is not required |
| C10 | Missing hooks | Path scopes are unenforced | Add the PreToolUse `path-guard` (design above) with `"onFailure": "block"`. It requires v2.1.295+, and 2.1.308 is installed |
| C11 | Optional hardening | — | After upgrading to v2.1.314+, add `"permissions": {"blockWritesOutsideWorkingDirectories": true}` |
| C12 | `pint-on-edit.sh`: depends on `jq` | If `jq` is missing, `file` is empty and the hook silently does nothing | Check with `command -v jq`, otherwise print to stderr and exit 0. Formatting stays non-blocking, and CI `pint --test` is the gate |
| C13 | `pint-on-edit.sh`: cwd | Pint resolves `pint.json` relative to the cwd (**UNVERIFIED**). The hook doesn't `cd` | Add `cd "$CLAUDE_PROJECT_DIR" \|\| exit 0` before running pint |
| C14 | `pint-on-edit.sh`: errors swallowed | Pint failures, such as a syntax error, are invisible to Claude | Capture pint's exit status. If it is non-zero, print its output to stderr and `exit 2`. For PostToolUse, exit 2 only shows stderr to Claude, which is the documented warning path |
| C15 | `pint-on-edit.sh`: Bash edits | Files changed by Bash aren't formatted (documented) | Accept this. CI `pint --test` covers it. Note it in governance |
| C16 | `security-reviewer.md` | Its tools are `Read, Grep, Glob, Bash`, yet it must "Write it to docs/03-plan/handoffs/<ID>-security.md", and it is told "never modify files". The only way it can comply is a Bash write, which is the unguarded path | Add `Write` and let the path-guard limit it to `docs/03-plan/handoffs/*-security.md`. Or have the orchestrator write the file from the returned findings |
| C17 | `architect.md` | No `Edit` tool, but red-team mode must "append a `## Red-team review` section". The only options are a full-file rewrite with Write or a Bash append | Add `Edit`. The guard keeps it inside the docs paths |
| C18 | All agents | No `model` field, so they inherit the session model. This is acceptable | Optional: `model: inherit` explicitly, to make it clear the omission is deliberate |
| C19 | `governance.md` Enforcement table (out of scope here, for a human to edit) | It says `.env`, push-to-main and destructive DB commands are "enforced by the harness". The docs say Bash rules are best-effort and Read deny doesn't cover scripts | Reword: "harness deny rules (best-effort) + branch protection (T-002) + sandbox (planned)" |

## Strongest argument against the recommendation
The path-guard hook may be **security theatre with a maintenance cost**. Every implementer has Bash, and the spike showed `echo z > file` sails past a Write/Edit guard. Bash parsing in a shell hook is best-effort by Anthropic's own wording, and `php -r`, `node -e` and composer scripts all write files. A motivated or confused agent will hit the guard, get a stderr message, and may then "work around it" through Bash, which is worse than an honest scope violation that shows up plainly in the diff. Meanwhile `scopes.json` duplicates what the task files and CI already express, and drifts. For a solo, ~400-line-PR project, the CI path check plus human review at G4 might be enough, and the hook could be skipped entirely.

Our counter: the hook catches the common, honest case (the Edit or Write tool) at the moment it happens, with a clear message, which reduces failed CI loops. Bash workarounds remain visible in the diff and are caught by B6. We therefore keep the hook, but explicitly label it a convenience guard, not a security control.

Against Boost: it adds a dev dependency that edits our canonical `CLAUDE.md` and installs an MCP server with database and log access. Its documented tool list was **already inaccurate** (Tinker is undocumented), and `search-docs` does not cover our frontend stack (Vue, and possibly Inertia 3). Version-matched Laravel docs and the Pest and Inertia guidelines are still the main hallucination reducer D7 relies on, so we keep it with the hardening above.

## What would change this recommendation
- Claude Code adds per-agent permission rules or a per-agent sandbox. That would replace most of the hook.
- Boost adds a route-list or Tinker-by-default tool, or `record-rule` gets an approval flow. Re-run the hardening review on each `boost:update` that changes `src/Mcp/Tools/`.
- R-001 finds the starter kit on Inertia 3.x while Boost's docs API is still on 2.x. Frontend agents would then not rely on `search-docs` at all.
- A second human joins (D6). Add CODEOWNERS on `.claude/**`, `.github/**` and `app/Policies/**`.
- The hook produces frequent false positives (more than 2 legitimate denials per task). Narrow it to deny lists only.

## Risks
| Risk | Likelihood | Impact | Mitigation | Owner |
|---|---|---|---|---|
| Agent writes outside its scope via Bash and bypasses the hook | Medium | Medium | CI path-ownership check (B6), G4 diff review, best-effort Bash parsing in the guard | devops-engineer (T-002) |
| Hook bug or missing `jq` silently allows everything (fail-open) | Medium | Medium | `onFailure: "block"`, exit 2 on parse errors, a unit test for the guard script in T-001 (pipe sample JSON in) | devops-engineer (T-001) |
| Hook bug blocks legitimate work | Medium | Low | Clear stderr messages, the main session (human) is exempt, scopes reviewed at G3 | architect |
| `boost:update` rewrites the `CLAUDE.md` block and produces noisy PR diffs, or a human edits inside the block and loses work | Medium | Low | Don't-edit note above the block. Switch to A2 if noise exceeds 1 PR per month | project owner |
| Boost MCP tool set changes on upgrade (for example Tinker enabled, a new write-capable tool) | Low | High | `config/boost.php` excludes, a version pin via composer.lock, re-check `src/Mcp/Tools/` on each Boost bump | security-reviewer |
| `record-rule` lets agents create unreviewed rules | Medium (if enabled) | Medium | Disabled until G3. Afterwards, only the architect may call it (guard), with rules reviewed in PRs | architect |
| `search-docs` lacks Vue and Inertia 3, so agents fall back to memory | Medium | Medium | Vue agent instructions require citing inertiajs.com and vuejs.org | vue-engineer |
| Boost installed in production builds (Browser Logs routes) | Low | Medium | Install as a `--dev` dependency. Laravel Cloud build uses `composer install --no-dev` (check in R-004) | devops-engineer |
| `.env` read by scripts (`grep -r`, `php -r`, tinker) despite deny rules | Medium | High | C5 deny entries, Tinker disabled, sandbox `denyRead` planned, no production secrets locally | devops-engineer |
| Claude Code behaviour changes (fast release cadence: 2.1.308 installed, 2.1.325 latest) | High | Low–Medium | Pin a minimum version in CLAUDE.md. Re-run the hook spike after upgrades | project owner |

## UNVERIFIED (to be checked in T-001)
- `:*` and `migrate:*` matching (C1) comes from the docs text only. A live permission spike was attempted but blocked by a tool outage. Test in T-001 with `--disallowedTools "Bash(echo migrate:*)"` against `echo migrate:fresh`.
- Sandbox key names for denying reads of `.env*` (C5) were not fetched.
- Whether Pint resolves `pint.json` from the cwd or from the file path (C13).
- Exact Claude Code MCP tool names for Boost (assumed `mcp__laravel-boost__<kebab-name>`, for example `record-rule` or `search-docs`). Confirm with `/mcp` after install.
- Whether `boost:update` deletes skills in `.claude/skills/` that it did not create.
- Whether a frontmatter hook in agent A fires for agent B's calls when both run in parallel. This is avoided by choosing a settings-level hook.
- The PreToolUse field name for NotebookEdit (`notebook_path`) was not in the fetched excerpt.

## Red-team review (2026-11-16)

**Reviewer:** architect agent (red-team mode).

**Verdict: Stands with changes.** The two headline claims are confirmed against current docs: the permission-matching semantics, and `agent_type` in hook input. The layered B2+B4+B6 approach is sound. The gaps are the main-session exemption, uncovered `gh` commands, allow rules that won't match the real script names, and the Boost doc-coverage gap for Pest 5.

| # | Severity | Finding | Evidence | Required change |
|---|---|---|---|---|
| 1 | — (confirmed) | **C1 is correct.** `Bash(php artisan migrate:*)` ≡ `Bash(php artisan migrate *)`, which **does not** match `php artisan migrate:fresh` | https://code.claude.com/docs/en/permissions (fetched 2026-11-16): "The `:*` suffix is an equivalent way to write a trailing wildcard, so `Bash(ls:*)` matches the same commands as `Bash(ls *)`"; "The space before a trailing `*` is part of the rule. `Bash(ls *)` requires a space after `ls`, so `lsof` doesn't match." Also: "A `*` at the end, with a space before it, also matches the bare command" | Mark the C1 UNVERIFIED item as verified by the docs. A live test in T-001 is still worthwhile |
| 2 | Medium | **Missed consequence of the same rule: the existing allow list won't match the kit's real commands.** `Bash(npm run types:*)` doesn't match `npm run types:check`. `npm run lint:*` and `npm run test:*` target scripts that don't exist. `npm run check`, `composer lint:check` and `composer types:check` aren't allowed | `.claude/settings.json` allow list; R-001 E21/E22 script names | In T-001, rewrite the allow list to the real script names (`Bash(npm run check)`, `Bash(npm run types:check)`, `Bash(composer lint:check)`, `Bash(composer types:check)`, …), together with the `CLAUDE.md` Commands update |
| 3 | Medium | **`agent_type` is confirmed, but the guard's "no agent_type = human present → allow" premise is false.** The docs say `agent_type` is "Present when the session uses `--agent` or the hook fires inside a subagent". So a plain main thread has none, and the main thread is itself an AI agent in auto or headless mode. It could edit `tests/**` and `app/**` together and get no guard at all. Separately, "unknown agent_type → deny" also blocks built-in types (`general-purpose`, `Explore`, `Plan`, forks) | https://code.claude.com/docs/en/hooks § `agent_id` and `agent_type` (fetched 2026-11-16): "Hooks from settings files … also run inside subagents … the input carries the `agent_id` and `agent_type`" | For the main thread, apply at least the global deny list (`.claude/**`, `.env*`, `.github/**`), plus "ask" on `tests/**` when `permission_mode` is auto or bypass. List the built-in agent types explicitly in `scopes.json` (read-only types allowed; `general-purpose` denied for writes, or scoped) |
| 4 | High | **GitHub CLI paths around G4 and G5 aren't covered.** This session has the owner's `gh` token with `repo` scope. `gh pr merge`, `gh api …` (approve pending deployments, edit branch protection), `gh workflow run` and `gh secret set` are not in any ask or deny list, and C6/C7 only harden `git push` | `gh auth status`: scopes `'admin:public_key', 'gist', 'read:org', 'repo'`. See R-004 red-team finding 2 | Add deny: `Bash(gh pr merge*)`, `Bash(gh secret*)`, `Bash(gh repo edit*)`, `Bash(gh api *pending_deployments*)`. Add ask: `Bash(gh api *)`, `Bash(gh workflow run*)`, `Bash(gh pr review*)`. The **real control** is a separate low-privilege agent identity (R-004 red-team finding 2). Update governance accordingly |
| 5 | Medium | **Boost doc coverage misses Pest 5 as well as Inertia 3.** Search Docs covers "Pest 3.x, 4.x" and "Inertia 1.x, 2.x". The guidelines table covers "Pest core, 3.x, 4.x" but does include Inertia Vue 3.x. The project runs Pest 5.3.6, so the `pest-testing` skill and the guidelines may teach Pest 4 APIs | https://laravel.com/docs/13.x/boost § Available AI Guidelines, § Documentation API (fetched 2026-11-16) | Extend Recommendation 1.6: QA agents cite pestphp.com (5.x docs) for Pest. Restate the Inertia point precisely: guidelines yes (3.x), search-docs no |
| 6 | Medium | **`laravel/pao` changes tool behaviour inside agent sessions.** It is how `pest --drift` broke in R-001. Agents' Pest, PHPStan and Paratest output differs from a human's or CI's (JSON in agent mode) | R-001 red-team finding 1. `composer show laravel/pao`: "Agent-optimized output for PHP testing tools", v1.1.5 | Add a risk row: hooks or scripts that parse test output must handle both formats, and any installer or conversion command that misbehaves under agent detection is run by the human or with the detection env vars unset |
| 7 | Low | The global deny `.claude/**` in `scopes.json` conflicts with Boost syncing skills to `.claude/skills` (Recommendation 1.5) when Boost runs from an agent's Bash | Boost-src `ClaudeCode.php` (skills → `.claude/skills`) | `boost:update` is a human or main-thread action only. Say so in T-001 |

**Strongest argument against (re-checked):** "the hook is theatre." This is partly upheld. Without finding 3, the most capable actor (the orchestrating main thread) is unguarded. CI path-ownership (B6) stays the authoritative control.
