#!/bin/bash
# PreToolUse: enforce role-level write scopes from scopes.json (design: R-005 §2, red-team finding 3).
# Exit 0 = allow, exit 2 = block (stderr goes to Claude), JSON on stdout = ask.
# Best-effort for Bash; the CI scope check (T-002) is the authoritative control.

deny() { echo "path-guard: $1" >&2; exit 2; }
ask() {
  jq -n --arg r "path-guard: $1" \
    '{hookSpecificOutput: {hookEventName: "PreToolUse", permissionDecision: "ask", permissionDecisionReason: $r}}'
  exit 0
}

command -v jq >/dev/null || deny "jq not found; cannot evaluate scope"
input=$(cat)
echo "$input" | jq -e . >/dev/null 2>&1 || deny "cannot parse hook input"

root=$(cd "${CLAUDE_PROJECT_DIR:-.}" && pwd -P)
scopes="$root/.claude/hooks/scopes.json"
[ -f "$scopes" ] || deny "scopes.json missing"

role=$(echo "$input" | jq -r '.agent_type // ""')
tool=$(echo "$input" | jq -r '.tool_name // ""')
mode=$(echo "$input" | jq -r '.permission_mode // ""')

# Print the repo-relative path for $1 (resolving symlinks), or "OUTSIDE:<path>".
relpath() {
  local p="$1" dir base
  case "$p" in /*) ;; *) p="$root/$p" ;; esac
  dir=$(dirname "$p"); base=$(basename "$p")
  while [ ! -d "$dir" ]; do base="$(basename "$dir")/$base"; dir=$(dirname "$dir"); done
  p="$(cd "$dir" && pwd -P)/$base"
  case "$p" in
    "$root"/*) echo "${p#"$root"/}" ;;
    *) echo "OUTSIDE:$p" ;;
  esac
}

# Succeeds if $1 matches any glob in the JSON array at jq path $2.
matches() {
  local rel="$1" pat
  while IFS= read -r pat; do
    [ -n "$pat" ] && [[ "$rel" == $pat ]] && return 0
  done < <(jq -r "($2 // [])[]" "$scopes")
  return 1
}

check_path() {
  local rel
  rel=$(relpath "$1")
  matches "$rel" '.global_deny' && deny "'$rel' is protected for every agent"
  if [ -z "$role" ]; then
    case "$rel" in OUTSIDE:*) return 0 ;; esac
    matches "$rel" '.main.deny' && deny "'$rel' is denied for the main session"
    case "$mode" in
      auto|bypassPermissions|acceptEdits)
        matches "$rel" '.main.ask_in_autonomous_modes' && ask "'$rel' needs confirmation in $mode mode" ;;
    esac
    return 0
  fi
  case "$rel" in OUTSIDE:*) deny "$role may not write outside the repo (${rel#OUTSIDE:})" ;; esac
  matches "$rel" ".roles[\"$role\"].deny" && deny "'$rel' is outside the $role scope"
  matches "$rel" ".roles[\"$role\"].allow" || deny "'$rel' is outside the $role scope (allowed: $(jq -c ".roles[\"$role\"].allow" "$scopes"))"
}

if [ -n "$role" ] && ! jq -e --arg r "$role" '.roles | has($r)' "$scopes" >/dev/null; then
  deny "unknown agent type '$role'; add it to scopes.json"
fi

case "$tool" in
  Edit|Write|MultiEdit|NotebookEdit)
    check_path "$(echo "$input" | jq -r '.tool_input.file_path // .tool_input.notebook_path // ""')"
    ;;
  Bash)
    [ -z "$role" ] && exit 0
    cmd=$(echo "$input" | jq -r '.tool_input.command // ""')
    if [[ "$cmd" =~ (php\ -r|node\ -e|python3?\ -c|bash\ -c|sh\ -c|eval\ ) ]] && [ "$role" != "devops-engineer" ]; then
      ask "inline interpreter in $role command; write scope cannot be verified"
    fi
    # Redirect targets and tee arguments (best effort).
    while IFS= read -r target; do
      [ -n "$target" ] && [ "$target" != "/dev/null" ] && check_path "$target"
    done < <(echo "$cmd" | grep -oE '(>>?|tee( -a)?) *[^ ;&|<>]+' | sed -E 's/^(>>?|tee( -a)?) *//')
    ;;
  mcp__laravel-boost__*)
    case "$tool" in *record-rule*|*tinker*) deny "$tool is disabled by governance (G1-10)" ;; esac
    ;;
esac
exit 0
