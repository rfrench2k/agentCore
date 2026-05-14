<?php
/**
 * AgentCore — Configuration Loader
 *
 * Merges defaults with local overrides, then derives any unset workspace paths
 * (skills, memory, status, memory_file, user_file, tools_file, soul_file,
 * telegram_instructions) from the final value of paths.project_root.
 *
 * Create config.local.php from config.local.php.example to customize.
 */

$config = require __DIR__ . '/config.defaults.php';

if (file_exists(__DIR__ . '/config.local.php')) {
    $localConfig = require __DIR__ . '/config.local.php';
    $config = array_replace_recursive($config, $localConfig);
}

// Derive workspace paths from the final project_root if the caller didn't set
// them explicitly. Done after the defaults+local merge so an override of
// project_root takes effect for every derived path.
$projectRoot = rtrim(str_replace('\\', '/', $config['paths']['project_root']), '/');
$derived = [
    'skills'                => $projectRoot . '/skills',
    'memory'                => $projectRoot . '/memory',
    'status'                => $projectRoot . '/STATUS.md',
    'memory_file'           => $projectRoot . '/MEMORY.md',
    'user_file'             => $projectRoot . '/USER.md',
    'tools_file'            => $projectRoot . '/TOOLS.md',
    'soul_file'             => $projectRoot . '/SOUL.md',
    'telegram_instructions' => $projectRoot . '/TELEGRAM_INSTRUCTIONS.md',
];
foreach ($derived as $key => $defaultPath) {
    if (!isset($config['paths'][$key]) || $config['paths'][$key] === '') {
        $config['paths'][$key] = $defaultPath;
    }
}

date_default_timezone_set($config['timezone']);

return $config;
