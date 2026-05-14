#!/bin/bash
# AgentCore Scheduler — Linux/macOS cron wrapper
# Add to crontab: */5 * * * * /path/to/agentcore/scripts/scheduler.sh
#
# Required environment (set in your crontab line, in a sibling .agentcore.env
# file, or in your shell):
#   AGENTCORE_WORKSPACE  — workspace directory (so .env can be loaded from it)
#   AGENTCORE_PHP_BIN    — path to php binary (optional; defaults to "php")

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

if [ -f "$SCRIPT_DIR/.agentcore.env" ]; then
    set -a; source "$SCRIPT_DIR/.agentcore.env"; set +a
fi

if [ -n "${AGENTCORE_WORKSPACE:-}" ] && [ -f "$AGENTCORE_WORKSPACE/.env" ]; then
    set -a; source "$AGENTCORE_WORKSPACE/.env"; set +a
fi

PHP_BIN="${AGENTCORE_PHP_BIN:-php}"
"$PHP_BIN" "$SCRIPT_DIR/../src/Scheduler.php" "$@"
