#!/bin/sh
# PostToolUse: format edited PHP files with Pint. Non-blocking; CI `pint --test` is the gate.
# No-op until Pint is installed (T-001). Files changed through Bash are not covered.
command -v jq >/dev/null || { echo "pint-on-edit: jq not found, skipping" >&2; exit 0; }
file=$(jq -r '.tool_input.file_path // empty')
case "$file" in
  *.php) ;;
  *) exit 0 ;;
esac
cd "$CLAUDE_PROJECT_DIR" || exit 0
[ -x vendor/bin/pint ] || exit 0
if ! out=$(vendor/bin/pint -q "$file" 2>&1); then
  echo "pint failed on $file: $out" >&2
  exit 2
fi
exit 0
