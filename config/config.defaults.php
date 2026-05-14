<?php
/**
 * AgentCore — Default Configuration
 *
 * Settings can be overridden via environment variables or config.local.php.
 * Environment variables override the defaults set here; config.local.php overrides
 * both.
 *
 * The workspace pattern: paths.project_root anchors a directory containing your
 * personal skills, memory, and context files (USER.md, SOUL.md, MEMORY.md, TOOLS.md,
 * STATUS.md, TELEGRAM_INSTRUCTIONS.md). The config loader (config.php) derives
 * paths.skills, paths.memory, paths.user_file, paths.soul_file, etc. from the
 * final value of paths.project_root after all overrides are applied. Most users
 * only need to set paths.project_root in their config.local.php.
 */

return [
    'db' => [
        'host' => getenv('AGENTCORE_DB_HOST') ?: '127.0.0.1',
        'port' => (int)(getenv('AGENTCORE_DB_PORT') ?: 3306),
        'user' => getenv('AGENTCORE_DB_USER') ?: '',
        'pass' => getenv('AGENTCORE_DB_PASS') ?: '',
        'name' => getenv('AGENTCORE_DB_NAME') ?: 'agentcore',
    ],

    'paths' => [
        // Anchor for derived workspace paths. Defaults to the AgentCore checkout
        // (one level above this config/ directory). Override in config.local.php
        // to point at a separate workspace directory.
        'project_root' => getenv('AGENTCORE_PROJECT_ROOT') ?: realpath(__DIR__ . '/..') ?: __DIR__ . '/..',

        // Framework-internal log directory. Stays inside the AgentCore checkout
        // regardless of project_root so framework logs don't pollute the workspace.
        'logs'         => getenv('AGENTCORE_LOG_DIR') ?: __DIR__ . '/../logs',

        // The following keys are derived from project_root by config.php if not set:
        //   skills, memory, status, memory_file, user_file, tools_file, soul_file,
        //   telegram_instructions.
        // Set any of them explicitly here or in config.local.php to override the
        // derived default.
    ],

    'claude' => [
        'binary'        => getenv('AGENTCORE_CLAUDE_BIN')     ?: 'claude',
        'default_model' => getenv('AGENTCORE_DEFAULT_MODEL')  ?: 'sonnet',
        'mysql_client'  => getenv('AGENTCORE_MYSQL_CLIENT')   ?: 'mysql',
    ],

    'telegram' => [
        'bot_token'        => getenv('AGENTCORE_TELEGRAM_TOKEN')    ?: '',
        'allowed_chat_ids' => getenv('AGENTCORE_TELEGRAM_CHAT_IDS') ?: '',
        'session_timeout'  => (int)(getenv('AGENTCORE_TELEGRAM_SESSION_TIMEOUT') ?: 1800),
        // Heading the bot uses when injecting USER.md into its system prompt.
        // e.g. "About You" or "About <operator-name>". Generic by default.
        'user_heading'     => getenv('AGENTCORE_USER_HEADING')      ?: 'About You',
    ],

    // Optional: write per-run token/cost data to an external app database after
    // every scheduled or telegram-triggered skill run. Disabled by default — opt
    // in by setting enabled=true in config.local.php and providing the connection
    // details. The target table must already exist with columns matching the
    // INSERT in Scheduler::writeUsageLog().
    'usage_log' => [
        'enabled' => filter_var(getenv('AGENTCORE_USAGE_LOG_ENABLED') ?: 'false', FILTER_VALIDATE_BOOL),
        'host'    => getenv('AGENTCORE_USAGE_LOG_HOST')  ?: null,  // falls back to db.host
        'port'    => getenv('AGENTCORE_USAGE_LOG_PORT')  ?: null,  // falls back to db.port
        'user'    => getenv('AGENTCORE_USAGE_LOG_USER')  ?: null,  // falls back to db.user
        'pass'    => getenv('AGENTCORE_USAGE_LOG_PASS')  ?: null,  // falls back to db.pass
        'db'      => getenv('AGENTCORE_USAGE_LOG_DB')    ?: '',
        'table'   => getenv('AGENTCORE_USAGE_LOG_TABLE') ?: 'usage_log',
    ],

    // Web UI access control. 'local' = REMOTE_ADDR must be 127.0.0.1, ::1, or
    // 192.168.*. 'token' = require ?token=<shared-secret> or an
    // X-AgentCore-Token header. 'open' = no auth (only safe if the web/
    // directory is reverse-proxied behind your own auth layer — never expose
    // 'open' directly to the internet).
    'web' => [
        'auth_mode' => getenv('AGENTCORE_WEB_AUTH_MODE') ?: 'local',
        'token'     => getenv('AGENTCORE_WEB_TOKEN')     ?: '',
    ],

    'timezone' => getenv('AGENTCORE_TIMEZONE') ?: 'UTC',
];
