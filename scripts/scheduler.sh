#!/bin/bash
# AgentCore Scheduler — Linux cron wrapper
# Add to crontab: */5 * * * * /path/to/agentcore/scripts/scheduler.sh

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
php "$SCRIPT_DIR/../src/Scheduler.php" "$@"
