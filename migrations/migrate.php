<?php
/**
 * AgentCore — Database Migration Runner
 *
 * Creates the agentcore database and runs all migration SQL files.
 *
 * Usage:
 *   php migrate.php              Run all pending migrations
 *   php migrate.php --status     Show migration status
 *   php migrate.php --reset      Drop and recreate all tables (destructive!)
 */

require_once __DIR__ . '/../src/AgentCore.php';

$configPath = __DIR__ . '/../config/config.php';
if (!file_exists($configPath)) {
    fwrite(STDERR, "Error: config/config.php not found.\n");
    fwrite(STDERR, "Copy config/config.local.php.example to config/config.local.php and configure it.\n");
    exit(1);
}

$config = require $configPath;
$action = $argv[1] ?? 'run';

$dbConfig = $config['db'];

echo "AgentCore Migration Runner\n";
echo str_repeat('-', 40) . "\n";

// Connect to the target database (it must already exist)
try {
    $dsn = "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['name']};charset=utf8mb4";
    $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    echo "Connected to MySQL at {$dbConfig['host']}:{$dbConfig['port']}, database '{$dbConfig['name']}'\n";
} catch (PDOException $e) {
    fwrite(STDERR, "Error: Cannot connect to MySQL: {$e->getMessage()}\n");
    fwrite(STDERR, "Make sure the '{$dbConfig['name']}' database exists and the user has access.\n");
    exit(1);
}

if ($action === '--reset') {
    echo "\nWARNING: This will DROP the '{$dbConfig['name']}' database and all data.\n";
    echo "Type 'yes' to confirm: ";
    $confirm = trim(fgets(STDIN));
    if ($confirm !== 'yes') {
        echo "Aborted.\n";
        exit(0);
    }
    $pdo->exec("DROP DATABASE IF EXISTS `{$dbConfig['name']}`");
    echo "Database dropped.\n";
}

// Find and sort migration files
$migrationDir = __DIR__;
$files = glob($migrationDir . '/[0-9]*.sql');
sort($files);

if (empty($files)) {
    echo "No migration files found.\n";
    exit(0);
}

if ($action === '--status') {
    // Check if database exists
    $stmt = $pdo->query("SHOW DATABASES LIKE '{$dbConfig['name']}'");
    $exists = $stmt->rowCount() > 0;
    echo "\nDatabase '{$dbConfig['name']}': " . ($exists ? 'EXISTS' : 'NOT CREATED') . "\n";

    if ($exists) {
        $pdo->exec("USE `{$dbConfig['name']}`");
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        echo "Tables: " . (empty($tables) ? 'none' : implode(', ', $tables)) . "\n";
    }

    echo "\nMigration files:\n";
    foreach ($files as $file) {
        echo "  " . basename($file) . "\n";
    }
    exit(0);
}

// Run migrations
echo "\nRunning migrations...\n";

foreach ($files as $file) {
    $filename = basename($file);
    echo "  Running {$filename}... ";

    $sql = file_get_contents($file);

    // Split on semicolons, filtering empty statements
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        fn($s) => $s !== '' && !str_starts_with($s, '--')
    );

    foreach ($statements as $statement) {
        try {
            $pdo->exec($statement);
        } catch (PDOException $e) {
            // Ignore "database already exists" and "table already exists"
            if (!str_contains($e->getMessage(), 'already exists')) {
                echo "FAILED\n";
                fwrite(STDERR, "  Error: {$e->getMessage()}\n");
                fwrite(STDERR, "  SQL: " . substr($statement, 0, 100) . "...\n");
                exit(1);
            }
        }
    }

    echo "OK\n";
}

// Verify
$pdo->exec("USE `{$dbConfig['name']}`");
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "\nDone. Tables in '{$dbConfig['name']}': " . implode(', ', $tables) . "\n";
