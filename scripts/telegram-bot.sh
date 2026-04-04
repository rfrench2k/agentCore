#!/bin/bash
# AgentCore Telegram Bot — Linux wrapper with auto-restart
# Run via systemd, screen, or tmux

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

while true; do
    php "$SCRIPT_DIR/../src/TelegramBot.php" "$@"
    echo "Bot exited. Restarting in 5 seconds..."
    sleep 5
done
