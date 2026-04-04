<?php
/**
 * AgentCore — Configuration Loader
 *
 * Merges defaults with local overrides.
 * Create config.local.php from config.local.php.example to customize.
 */

$config = require __DIR__ . '/config.defaults.php';

if (file_exists(__DIR__ . '/config.local.php')) {
    $localConfig = require __DIR__ . '/config.local.php';
    $config = array_replace_recursive($config, $localConfig);
}

date_default_timezone_set($config['timezone']);

return $config;
