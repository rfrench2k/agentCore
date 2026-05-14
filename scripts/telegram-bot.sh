#!/bin/bash
# AgentCore Telegram Bot — Linux/macOS long-running launcher
# The bot loops forever; on crash it relaunches after 5 seconds.
#
# Required environment (set in a sibling .agentcore.env file or your shell):
#   AGENTCORE_WORKSPACE  — workspace directory
#   AGENTCORE_PHP_BIN    — path to php binary (optional; defaults to "php")

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

if [ -f "$SCRIPT_DIR/.agentcore.env" ]; then
    set -a; source "$SCRIPT_DIR/.agentcore.env"; set +a
fi

if [ -n "${AGENTCORE_WORKSPACE:-}" ] && [ -f "$AGENTCORE_WORKSPACE/.env" ]; then
    set -a; source "$AGENTCORE_WORKSPACE/.env"; set +a
fi

PHP_BIN="${AGENTCORE_PHP_BIN:-php}"

while true; do
    "$PHP_BIN" "$SCRIPT_DIR/../src/TelegramBot.php" "$@" || true
    echo "Bot exited. Restarting in 5 seconds..."
    sleep 5
done
