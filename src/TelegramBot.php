<?php
/**
 * AgentCore — Telegram Bot
 *
 * Long-running process that receives Telegram messages and routes them
 * to skill execution or conversational Claude sessions.
 *
 * Usage:
 *   php TelegramBot.php            Start the bot (long-running)
 *   php TelegramBot.php --test     Send a test message and exit
 */

require_once __DIR__ . '/AgentCore.php';
require_once __DIR__ . '/SkillRunner.php';
require_once __DIR__ . '/CronExpression.php';
require_once __DIR__ . '/Scheduler.php';
require_once __DIR__ . '/TelegramApi.php';
require_once __DIR__ . '/Logger.php';

class TelegramBot
{
    private AgentCore $core;
    private PDO $db;
    private TelegramApi $api;
    private Scheduler $scheduler;
    private SkillRunner $runner;
    private Logger $logger;
    private array $allowedChatIds;
    private int $sessionTimeout;
    private int $lastUpdateId = 0;
    private int $errorBackoff = 1;

    public function __construct(?AgentCore $core = null)
    {
        $this->core = $core ?: AgentCore::getInstance();
        $this->db = $this->core->db();
        $this->logger = new Logger(null, 'telegram-bot');

        $token = $this->core->config('telegram.bot_token');
        if (!$token) {
            throw new RuntimeException('Telegram bot token not configured');
        }

        $this->api = new TelegramApi($token);
        $this->scheduler = new Scheduler($this->core);
        $this->runner = new SkillRunner($this->core);
        $this->sessionTimeout = $this->core->config('telegram.session_timeout') ?: 1800;

        $chatIds = $this->core->config('telegram.allowed_chat_ids') ?: '';
        $this->allowedChatIds = array_filter(array_map('trim', explode(',', $chatIds)));
    }

    /**
     * Main bot loop. Runs forever.
     */
    public function run(): void
    {
        $me = $this->api->getMe();
        if (!$me) {
            $this->logger->error('Failed to connect to Telegram. Check bot token.');
            echo "Error: Failed to connect to Telegram. Check bot token.\n";
            exit(1);
        }

        $botName = $me['username'] ?? 'unknown';
        $this->logger->info("Telegram bot started: @{$botName}");
        echo "AgentCore Telegram bot started: @{$botName}\n";
        echo "Listening for messages... (Ctrl+C to stop)\n";

        while (true) {
            try {
                $this->expireSessions();
                $this->pollAndProcess();
                $this->errorBackoff = 1;
            } catch (Throwable $e) {
                $this->logger->error("Bot error: {$e->getMessage()}");
                echo "Error: {$e->getMessage()}\n";
                sleep(min($this->errorBackoff, 60));
                $this->errorBackoff *= 2;
            }
        }
    }

    /**
     * Send a test message to the first allowed chat.
     */
    public function sendTest(): void
    {
        if (empty($this->allowedChatIds)) {
            echo "No allowed chat IDs configured.\n";
            return;
        }

        $chatId = $this->allowedChatIds[0];
        $result = $this->api->sendMessage($chatId, "AgentCore Telegram bot is working.");
        echo $result ? "Test message sent to {$chatId}\n" : "Failed to send test message\n";
    }

    private function pollAndProcess(): void
    {
        $updates = $this->api->getUpdates($this->lastUpdateId + 1, 30);

        foreach ($updates as $update) {
            $this->lastUpdateId = $update['update_id'];
            $message = $update['message'] ?? null;
            if (!$message || !isset($message['text'])) continue;

            $chatId = (string)$message['chat']['id'];
            $username = $message['from']['username'] ?? $message['from']['first_name'] ?? 'unknown';
            $text = trim($message['text']);

            // Auth check
            if (!empty($this->allowedChatIds) && !in_array($chatId, $this->allowedChatIds)) {
                $this->logger->warn("Unauthorized message from {$chatId} ({$username})");
                $this->api->sendMessage($chatId, "Unauthorized.");
                continue;
            }

            $this->logger->info("Message from {$username}: {$text}");
            $this->handleMessage($chatId, $username, $text);
        }
    }

    private function handleMessage(string $chatId, string $username, string $text): void
    {
        // Route commands
        if (str_starts_with($text, '/')) {
            $parts = explode(' ', $text, 3);
            $command = strtolower($parts[0]);
            $arg1 = $parts[1] ?? '';
            $arg2 = $parts[2] ?? '';

            switch ($command) {
                case '/run':
                    $this->handleRun($chatId, $username, $arg1, $arg2);
                    return;
                case '/status':
                    $this->handleStatus($chatId);
                    return;
                case '/list':
                    $this->handleList($chatId);
                    return;
                case '/schedules':
                    $this->handleSchedules($chatId);
                    return;
                case '/enable':
                    $this->handleToggle($chatId, $arg1, true);
                    return;
                case '/disable':
                    $this->handleToggle($chatId, $arg1, false);
                    return;
                case '/history':
                    $this->handleHistory($chatId, $arg1 ?: '5');
                    return;
                case '/new':
                    $this->handleNewSession($chatId);
                    return;
                case '/help':
                case '/start':
                    $this->handleHelp($chatId);
                    return;
            }
        }

        // Free-text conversation
        $this->handleConversation($chatId, $username, $text);
    }

    private function handleRun(string $chatId, string $username, string $skillName, string $args): void
    {
        if (!$skillName) {
            $this->api->sendMessage($chatId, "Usage: /run <skill-name> [arguments]");
            return;
        }

        $this->api->sendTyping($chatId);
        $this->api->sendMessage($chatId, "Running {$skillName}...");

        $result = $this->scheduler->runNow($skillName, 'telegram', $username, $args);

        $response = $result->success
            ? "Done ({$result->duration}s). " . $result->summary(3000)
            : "Failed: " . substr($result->error ?? 'Unknown error', 0, 1000);

        $this->api->sendMessage($chatId, $response);
    }

    private function handleStatus(string $chatId): void
    {
        $stmt = $this->db->query("
            SELECT skill_name, status, started_at, duration_seconds, model_used
            FROM skill_runs
            WHERE DATE(started_at) = CURDATE()
            ORDER BY started_at DESC
            LIMIT 15
        ");
        $runs = $stmt->fetchAll();

        if (empty($runs)) {
            $this->api->sendMessage($chatId, "No skill runs today.");
            return;
        }

        $lines = ["Today's runs:"];
        foreach ($runs as $r) {
            $icon = $r['status'] === 'completed' ? 'ok' : ($r['status'] === 'running' ? '..' : 'X');
            $time = date('g:ia', strtotime($r['started_at']));
            $dur = $r['duration_seconds'] ? "{$r['duration_seconds']}s" : '...';
            $lines[] = "[{$icon}] {$r['skill_name']} — {$time} ({$dur})";
        }

        // Next scheduled
        $next = $this->db->query("
            SELECT skill_name, next_run_at FROM skill_schedules
            WHERE enabled = 1 AND next_run_at IS NOT NULL
            ORDER BY next_run_at ASC LIMIT 3
        ")->fetchAll();

        if ($next) {
            $lines[] = "\nNext up:";
            foreach ($next as $n) {
                $time = date('g:ia', strtotime($n['next_run_at']));
                $lines[] = "  {$n['skill_name']} at {$time}";
            }
        }

        $this->api->sendMessage($chatId, implode("\n", $lines));
    }

    private function handleList(string $chatId): void
    {
        $skills = $this->runner->listSkills();
        if (empty($skills)) {
            $this->api->sendMessage($chatId, "No skills found.");
            return;
        }

        $lines = ["Available skills:"];
        foreach ($skills as $s) {
            $desc = $s['description'] ? " — {$s['description']}" : '';
            $lines[] = "  /{$s['name']}{$desc}";
        }
        $lines[] = "\nUse /run <skill-name> to execute.";

        $this->api->sendMessage($chatId, implode("\n", $lines));
    }

    private function handleSchedules(string $chatId): void
    {
        $schedules = $this->scheduler->listSchedules();
        if (empty($schedules)) {
            $this->api->sendMessage($chatId, "No schedules configured.");
            return;
        }

        $lines = ["Schedules:"];
        foreach ($schedules as $s) {
            $status = $s['enabled'] ? 'on' : 'OFF';
            $next = $s['next_run_at'] ? date('D g:ia', strtotime($s['next_run_at'])) : 'n/a';
            $cron = new CronExpression($s['cron_expression']);
            $lines[] = "[{$status}] {$s['skill_name']} — {$cron->describe()} — next: {$next}";
        }

        $this->api->sendMessage($chatId, implode("\n", $lines));
    }

    private function handleToggle(string $chatId, string $skillName, bool $enable): void
    {
        if (!$skillName) {
            $this->api->sendMessage($chatId, "Usage: /" . ($enable ? 'enable' : 'disable') . " <skill-name>");
            return;
        }

        $stmt = $this->db->prepare("UPDATE skill_schedules SET enabled = ? WHERE skill_name = ?");
        $stmt->execute([$enable ? 1 : 0, $skillName]);

        if ($stmt->rowCount() === 0) {
            $this->api->sendMessage($chatId, "Schedule not found: {$skillName}");
        } else {
            $action = $enable ? 'enabled' : 'disabled';
            $this->api->sendMessage($chatId, "Schedule {$action}: {$skillName}");
        }
    }

    private function handleHistory(string $chatId, string $count): void
    {
        $n = min(max((int)$count, 1), 20);
        $stmt = $this->db->prepare("
            SELECT skill_name, status, started_at, duration_seconds, model_used, budget_used
            FROM skill_runs ORDER BY started_at DESC LIMIT ?
        ");
        $stmt->execute([$n]);
        $runs = $stmt->fetchAll();

        if (empty($runs)) {
            $this->api->sendMessage($chatId, "No run history.");
            return;
        }

        $lines = ["Last {$n} runs:"];
        foreach ($runs as $r) {
            $icon = $r['status'] === 'completed' ? 'ok' : 'X';
            $date = date('M j g:ia', strtotime($r['started_at']));
            $cost = $r['budget_used'] ? '$' . number_format($r['budget_used'], 4) : '';
            $lines[] = "[{$icon}] {$r['skill_name']} — {$date} — {$r['duration_seconds']}s {$cost}";
        }

        $this->api->sendMessage($chatId, implode("\n", $lines));
    }

    private function handleNewSession(string $chatId): void
    {
        $this->db->prepare("UPDATE telegram_sessions SET status = 'expired' WHERE chat_id = ? AND status = 'active'")
            ->execute([$chatId]);
        $this->api->sendMessage($chatId, "Session cleared. Next message starts fresh.");
    }

    private function handleHelp(string $chatId): void
    {
        $help = "AgentCore Commands:\n\n"
            . "/run <skill> [args] — Run a skill now\n"
            . "/status — Today's skill runs\n"
            . "/list — Available skills\n"
            . "/schedules — All schedules\n"
            . "/enable <skill> — Enable a schedule\n"
            . "/disable <skill> — Disable a schedule\n"
            . "/history [n] — Last n runs (default 5)\n"
            . "/new — Start a fresh conversation\n"
            . "/help — This message\n\n"
            . "Or just send any text to chat.";

        $this->api->sendMessage($chatId, $help);
    }

    private function handleConversation(string $chatId, string $username, string $text): void
    {
        $this->api->sendTyping($chatId);

        // Check for active session
        $stmt = $this->db->prepare("
            SELECT * FROM telegram_sessions
            WHERE chat_id = ? AND status = 'active'
            ORDER BY last_message_at DESC LIMIT 1
        ");
        $stmt->execute([$chatId]);
        $session = $stmt->fetch();

        $claudeBin = $this->core->claudeBinary();
        $projectRoot = $this->core->projectRoot();
        $personaFile = $this->core->config('paths.persona');
        $statusFile = $this->core->config('paths.status');
        $claudeMd = $this->core->config('paths.claude_md');

        if ($session) {
            // Continue existing session
            $cmd = $claudeBin . ' -p'
                . ' --resume ' . escapeshellarg($session['session_id'])
                . ' --max-turns 5'
                . ' --max-budget-usd 1.00'
                . ' --output-format json'
                . ' ' . escapeshellarg($text);

            $result = $this->executeCommand($cmd);

            // Update session
            $this->db->prepare("
                UPDATE telegram_sessions
                SET last_message_at = NOW(), message_count = message_count + 1
                WHERE id = ?
            ")->execute([$session['id']]);
        } else {
            // Start new session
            $sessionId = $this->generateSessionId();

            // Build system prompt additions
            $systemPrompt = '';
            if ($personaFile && file_exists($personaFile)) {
                $systemPrompt .= file_get_contents($personaFile) . "\n\n";
            }
            if ($statusFile && file_exists($statusFile)) {
                $systemPrompt .= "## Current Status\n\n" . file_get_contents($statusFile) . "\n\n";
            }

            $cmd = $claudeBin . ' -p'
                . ' --model ' . escapeshellarg($this->core->defaultModel())
                . ' --max-turns 5'
                . ' --max-budget-usd 1.00'
                . ' --session-id ' . escapeshellarg($sessionId)
                . ' --output-format json';

            if ($projectRoot && is_dir($projectRoot)) {
                $cmd .= ' --add-dir ' . escapeshellarg($projectRoot);
            }

            if ($systemPrompt) {
                $tempFile = sys_get_temp_dir() . '/agentcore-persona-' . $chatId . '.md';
                file_put_contents($tempFile, $systemPrompt);
                $cmd .= ' --append-system-prompt-file ' . escapeshellarg($tempFile);
            }

            $cmd .= ' ' . escapeshellarg($text);

            $result = $this->executeCommand($cmd);

            // Clean up temp file
            if (isset($tempFile) && file_exists($tempFile)) {
                unlink($tempFile);
            }

            // Save session
            $this->db->prepare("
                INSERT INTO telegram_sessions (chat_id, session_id, status)
                VALUES (?, ?, 'active')
            ")->execute([$chatId, $sessionId]);
        }

        // Parse and send response
        $output = $result['stdout'];
        $jsonOutput = json_decode($output, true);
        $responseText = $jsonOutput['result'] ?? $output;

        if (!$responseText || trim($responseText) === '') {
            $responseText = $result['stderr'] ? "Error: " . substr($result['stderr'], 0, 500) : "No response.";
        }

        $this->api->sendMessage($chatId, $responseText);
    }

    private function expireSessions(): void
    {
        $this->db->prepare("
            UPDATE telegram_sessions
            SET status = 'expired'
            WHERE status = 'active'
              AND last_message_at < NOW() - INTERVAL ? SECOND
        ")->execute([$this->sessionTimeout]);
    }

    private function generateSessionId(): string
    {
        return 'ac-' . bin2hex(random_bytes(8));
    }

    private function executeCommand(string $cmd): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            return ['stdout' => '', 'stderr' => 'Failed to start process', 'exit_code' => 1];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return ['stdout' => $stdout, 'stderr' => $stderr, 'exit_code' => $exitCode];
    }
}

// ── CLI Entry Point ────────────────────────────────────────────────────────

if (php_sapi_name() === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $core = AgentCore::init(dirname(__DIR__) . '/config/config.php');
    $bot = new TelegramBot($core);

    $action = $argv[1] ?? '';

    if ($action === '--test') {
        $bot->sendTest();
    } else {
        $bot->run();
    }
}
