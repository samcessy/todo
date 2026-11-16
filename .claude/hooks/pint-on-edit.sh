#!/bin/sh
# PostToolUse: format edited PHP files with Pint. No-op until Pint is installed (T-001).
file=$(jq -r '.tool_input.file_path // empty')
case "$file" in
  *.php)
    pint="$CLAUDE_PROJECT_DIR/vendor/bin/pint"
    [ -x "$pint" ] && "$pint" -q "$file"
    ;;
esac
exit 0
