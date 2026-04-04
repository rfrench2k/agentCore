<?php
/**
 * AgentCore — Default Configuration
 *
 * All settings can be overridden via environment variables or config.local.php.
 * Environment variables take precedence over defaults.
 * config.local.php takes precedence over everything.
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
        'skills'       => getenv('AGENTCORE_SKILLS_PATH')    ?: realpath(__DIR__ . '/../../skills') ?: __DIR__ . '/../../skills',
        'memory'       => getenv('AGENTCORE_MEMORY_PATH')    ?: realpath(__DIR__ . '/../../memory') ?: __DIR__ . '/../../memory',
        'status'       => getenv('AGENTCORE_STATUS_FILE')    ?: realpath(__DIR__ . '/../../STATUS.md') ?: __DIR__ . '/../../STATUS.md',
        'persona'      => getenv('AGENTCORE_PERSONA_FILE')   ?: realpath(__DIR__ . '/../../persona.md') ?: __DIR__ . '/../../persona.md',
        'claude_md'    => getenv('AGENTCORE_CLAUDE_MD')      ?: realpath(__DIR__ . '/../../CLAUDE.md') ?: __DIR__ . '/../../CLAUDE.md',
        'project_root' => getenv('AGENTCORE_PROJECT_ROOT')   ?: realpath(__DIR__ . '/../..') ?: __DIR__ . '/../..',
        'logs'         => getenv('AGENTCORE_LOG_DIR')        ?: __DIR__ . '/../logs',
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
    ],

    'timezone' => getenv('AGENTCORE_TIMEZONE') ?: 'UTC',
];
