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
require_once __DIR__ . '/MemoryManager.php';

class TelegramBot
{
    private AgentCore $core;
    private PDO $db;
    private TelegramApi $api;
    private Scheduler $scheduler;
    private SkillRunner $runner;
    private MemoryManager $memory;
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
        $this->memory = new MemoryManager($this->core);
        $this->sessionTimeout = $this->core->config('telegram.session_timeout') ?: 1800;

        $chatIds = $this->core->config('telegram.allowed_chat_ids') ?: '';
        $this->allowedChatIds = array_filter(array_map('trim', explode(',', $chatIds)));
    }

    /**
     * Source files whose mtime the bot watches. If any of these change after startup,
     * the bot exits cleanly at the next poll boundary so the .bat wrapper loop can
     * relaunch PHP and pick up the new code. No manual restart required when I push
     * a code change.
     */
    private function reloadWatchFiles(): array
    {
        $srcDir = __DIR__;
        return [
            $srcDir . '/TelegramBot.php',
            $srcDir . '/TelegramApi.php',
            $srcDir . '/MemoryManager.php',
            $srcDir . '/SkillRunner.php',
            $srcDir . '/Scheduler.php',
            $srcDir . '/CronExpression.php',
            $srcDir . '/AgentCore.php',
            $srcDir . '/Logger.php',
            dirname($srcDir) . '/config/config.local.php',
            dirname($srcDir) . '/config/config.defaults.php',
            dirname($srcDir) . '/config/config.php',
        ];
    }

    /**
     * Snapshot mtimes of the watched files at startup. Returns file → mtime map.
     */
    private function snapshotSourceMtimes(): array
    {
        $snap = [];
        foreach ($this->reloadWatchFiles() as $file) {
            if (file_exists($file)) {
                $snap[$file] = filemtime($file);
            }
        }
        return $snap;
    }

    /**
     * Returns the first file whose mtime has changed since the snapshot, or null.
     */
    private function detectSourceChange(array $snapshot): ?string
    {
        foreach ($snapshot as $file => $mtime) {
            if (!file_exists($file)) continue;
            $now = filemtime($file);
            if ($now !== false && $now > $mtime) {
                return $file;
            }
        }
        return null;
    }

    /**
     * Main bot loop. Runs forever — or until source files change and we self-restart.
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

        $sourceSnapshot = $this->snapshotSourceMtimes();

        while (true) {
            try {
                $this->expireSessions();
                $this->pollAndProcess();
                $this->errorBackoff = 1;

                // Hot-reload: if any source file changed since startup, exit cleanly.
                // The .bat wrapper's :loop will restart PHP and pick up the new code.
                $changed = $this->detectSourceChange($sourceSnapshot);
                if ($changed !== null) {
                    $this->logger->info("Source changed, exiting for reload", ['file' => basename($changed)]);
                    echo "Source file changed (" . basename($changed) . "), exiting for reload...\n";
                    exit(0);
                }
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

        if ($session) {
            // Continue existing session — no need to re-inject system prompt, Claude remembers.
            $cmd = $claudeBin . ' -p'
                . ' --resume ' . escapeshellarg($session['session_id'])
                . ' --max-turns 8'
                . ' --output-format json'
                . ' --dangerously-skip-permissions';

            $result = $this->execute($cmd, $text);

            // Self-heal: if resume failed because the session is stale/invalid, expire it
            // and drop through to the new-session path so the user isn't wedged.
            if ($this->looksLikeSessionFailure($result)) {
                $this->logger->warn("Resume failed for session {$session['session_id']}; expiring and starting fresh");
                $this->db->prepare("UPDATE telegram_sessions SET status = 'expired' WHERE id = ?")
                    ->execute([$session['id']]);
                $session = null;
            } else {
                $this->db->prepare("
                    UPDATE telegram_sessions
                    SET last_message_at = NOW(), message_count = message_count + 1
                    WHERE id = ?
                ")->execute([$session['id']]);
            }
        }

        if (!$session) {
            // Start new session — build full system prompt with persona, user info, memory, status, capture reflex.
            $sessionId = $this->generateSessionId();
            $systemPromptFile = $this->buildSystemPromptFile($chatId);

            $cmd = $claudeBin . ' -p'
                . ' --model ' . escapeshellarg($this->core->defaultModel())
                . ' --max-turns 8'
                . ' --session-id ' . escapeshellarg($sessionId)
                . ' --output-format json'
                . ' --dangerously-skip-permissions';

            if ($systemPromptFile) {
                $cmd .= ' --append-system-prompt-file ' . escapeshellarg($systemPromptFile);
            }

            $result = $this->execute($cmd, $text);

            if ($systemPromptFile && file_exists($systemPromptFile)) {
                unlink($systemPromptFile);
            }

            $this->db->prepare("
                INSERT INTO telegram_sessions (chat_id, session_id, status)
                VALUES (?, ?, 'active')
            ")->execute([$chatId, $sessionId]);
        }

        // Parse JSON response
        $output = $result['stdout'];
        $jsonOutput = json_decode($output, true);
        $responseText = $jsonOutput['result'] ?? $output;

        if (!$responseText || trim($responseText) === '') {
            $responseText = $result['stderr'] ? "Error: " . substr($result['stderr'], 0, 500) : "No response.";
        }

        // Extract any [CAPTURE]...[/CAPTURE] blocks → write to STATUS.md, strip from reply.
        $responseText = $this->extractCaptures($responseText);

        $this->api->sendMessage($chatId, $responseText);
    }

    /**
     * Detect errors from claude -p that indicate the session is unusable and we should
     * expire it + fall back to a new session. Also covers "Not logged in" errors so
     * those get surfaced cleanly instead of looking like a successful response.
     */
    private function looksLikeSessionFailure(array $result): bool
    {
        $blob = strtolower(($result['stdout'] ?? '') . ' ' . ($result['stderr'] ?? ''));

        $needles = [
            'invalid session',
            'session not found',
            'no such session',
            'session does not exist',
            'no conversation found',       // "No conversation found with session ID: ..."
            'conversation not found',
            'valid uuid',                  // session id was malformed
            'must be a valid',             // same shape, different wording
        ];
        foreach ($needles as $needle) {
            if (strpos($blob, $needle) !== false) return true;
        }
        return false;
    }

    /**
     * Assemble a full system prompt for the Telegram conversation:
     *   1. Capture reflex instructions (TOP — primacy matters, can't be drowned out)
     *   2. SOUL.md  — voice/character/values
     *   3. USER.md  — who the operator is
     *   4. MEMORY.md — system rules
     *   5. TOOLS.md  — DB/tool access
     *   6. STATUS.md — today's status
     */
    private function buildSystemPromptFile(string $chatId): ?string
    {
        $parts = [];

        // 1. Capture reflex at the TOP — this is the most important instruction.
        $parts[] = $this->captureReflexInstructions();

        $loadIfExists = function(string $pathKey, string $heading) use (&$parts) {
            $file = $this->core->config("paths.{$pathKey}");
            if ($file && file_exists($file)) {
                $parts[] = "## {$heading}\n\n" . file_get_contents($file);
            }
        };

        $userHeading = $this->core->config('telegram.user_heading') ?: 'About You';

        // 2. Voice / character
        $loadIfExists('soul_file',   'Voice and Character (SOUL.md)');
        // 3. Context files, in priority order
        $loadIfExists('user_file',   $userHeading . ' (USER.md)');
        $loadIfExists('memory_file', 'System Operational Rules (MEMORY.md)');
        $loadIfExists('tools_file',  'Tools & DB Access (TOOLS.md)');
        $loadIfExists('status',      "Today's Status (STATUS.md)");

        if (empty($parts)) return null;

        $content = implode("\n\n---\n\n", $parts);
        $tempFile = sys_get_temp_dir() . '/agentcore-bot-' . preg_replace('/[^a-z0-9]/i', '', $chatId) . '-' . bin2hex(random_bytes(4)) . '.md';
        file_put_contents($tempFile, $content);
        return $tempFile;
    }

    /**
     * The capture-reflex instructions placed at the top of the bot's system prompt.
     *
     * Source of truth is the workspace file at paths.telegram_instructions (typically
     * TELEGRAM_INSTRUCTIONS.md in the project root). The workspace file may contain
     * the placeholder {{SKILL_LIST}}, which is substituted with the live list of
     * directory names under paths.skills at runtime.
     *
     * If the workspace file is missing, a built-in generic version is used so a
     * fresh install behaves reasonably until the operator customizes the file.
     */
    private function captureReflexInstructions(): string
    {
        $template = null;
        $file = $this->core->config('paths.telegram_instructions');
        if ($file && file_exists($file)) {
            $template = file_get_contents($file);
        }
        if (!$template) {
            $template = $this->defaultCaptureReflexInstructions();
        }

        $skillList = $this->discoverSkillNames();
        $rendered = strtr($template, [
            '{{SKILL_LIST}}'        => $skillList === ''
                ? '(no skills found in skills directory)'
                : $skillList,
            '{{SKILL_LIST_INLINE}}' => $skillList === ''
                ? '(no skills found in skills directory)'
                : $skillList,
        ]);

        return $rendered;
    }

    /**
     * Comma-separated, backtick-quoted list of valid skill names, discovered by
     * scanning paths.skills for directories that contain a SKILL.md. Empty string
     * if the skills directory does not exist or contains no skills.
     */
    private function discoverSkillNames(): string
    {
        $skillsDir = $this->core->skillsDir();
        if (!$skillsDir || !is_dir($skillsDir)) {
            return '';
        }
        $names = [];
        foreach (scandir($skillsDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if (is_file($skillsDir . '/' . $entry . '/SKILL.md')) {
                $names[] = $entry;
            }
        }
        sort($names);
        return implode(', ', array_map(fn($n) => "`{$n}`", $names));
    }

    /**
     * Generic fallback used when no workspace TELEGRAM_INSTRUCTIONS.md is present.
     * Describes the capture-tag protocol without operator-specific examples. The
     * intended path is for each workspace to ship its own customized version.
     */
    private function defaultCaptureReflexInstructions(): string
    {
        return <<<MD
# How To Handle Messages (READ THIS FIRST — HARD REQUIREMENT)

You are a scheduled-skills control interface running via Telegram. You have NO name, NO
persona, NO identity. You are the interface the operator talks to so the system can learn
things and change.

Your job is not only to answer the operator's message — it is to **capture rules, preferences,
and facts** they tell you so those persist beyond this conversation and shape how scheduled
skills behave tomorrow. If you don't capture them correctly, they are lost forever.

## The Capture Contract (You Cannot Break This)

When the operator states something worth remembering, you MUST wrap it in a capture tag that
names the target file it should land in. Format:

```
[CAPTURE:<target>] <one-line durable statement of the fact or rule> [/CAPTURE]
```

**Target options:**

- `[CAPTURE:user]` — facts about the operator, their people, their preferences, their background. Lands in `USER.md`.
- `[CAPTURE:memory]` — global operational rules that apply across the whole system (quiet hours, cross-skill behavior, hard rules). Lands in `MEMORY.md`.
- `[CAPTURE:skill:<skill-name>]` — rules specific to one scheduled skill. Lands in `skills/<skill-name>/LEARNINGS.md`. Valid skill names: {{SKILL_LIST}}.

## The Hardest Rule

**If the operator tells you to remember / note / save anything, and you reply "got it" / "noted"
/ "saved" WITHOUT a `[CAPTURE:...]` tag in your response — THE INFORMATION IS LOST.**

Acknowledging without tagging is worse than silence, because the operator believes it worked.
The bot strips the capture tags from your reply before showing them, so your visible message
is just the natural acknowledgement — but the tag is required or nothing is saved.

If you are not sure which target file something should go in, or the scope is ambiguous,
ask one sharp clarifying question instead of guessing.

## Do NOT Capture

- Small talk or greetings
- Questions the operator asks you
- One-off actions ("run the data check now") — those are commands, not rules
- Anything ambiguous — ask first

## Tone

Short, direct, technical. No filler. No "I'd be happy to help". No summaries of what you just
did. If you captured something, a brief "Got it." is enough — the capture block already records
the rule; don't echo it back in plain text.
MD;
    }

    /**
     * Scan the reply for [CAPTURE:<target>]...[/CAPTURE] blocks, route each to the right
     * target file via MemoryManager, and return the reply with capture blocks stripped out.
     * Targets: 'user', 'memory', 'skill:<skill-name>'.
     */
    private function extractCaptures(string $reply): string
    {
        if (strpos($reply, '[CAPTURE:') === false) {
            return $reply;
        }

        // Match [CAPTURE:target] body [/CAPTURE]. Target is everything after the colon up to the closing bracket.
        $count = preg_match_all('/\[CAPTURE:([^\]]+)\](.*?)\[\/CAPTURE\]/s', $reply, $matches, PREG_SET_ORDER);
        if ($count) {
            foreach ($matches as $m) {
                $target = trim($m[1]);
                $body = trim($m[2]);
                if ($body === '' || $target === '') continue;
                try {
                    $written = $this->memory->captureToTarget($target, $body);
                    if ($written) {
                        $this->logger->info("Captured from Telegram", ['target' => $target, 'file' => basename($written), 'body' => substr($body, 0, 120)]);
                    } else {
                        $this->logger->warn("Capture target unresolved", ['target' => $target, 'body' => substr($body, 0, 120)]);
                    }
                } catch (Throwable $e) {
                    $this->logger->error("Failed to capture: {$e->getMessage()}");
                }
            }
            // Strip all capture blocks from the user-visible reply.
            $reply = preg_replace('/\[CAPTURE:[^\]]+\].*?\[\/CAPTURE\]\s*/s', '', $reply);
            $reply = trim($reply);
            if ($reply === '') {
                $reply = "Got it.";
            }
        }

        return $reply;
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
        // Claude Code's --session-id requires a valid v4 UUID.
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variant 10
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * Execute a claude -p command. Prompt is passed via stdin (avoids Windows cmd.exe
     * argument-mangling bugs with long quoted strings). cwd is set to project_root so
     * CLAUDE.md auto-loads.
     */
    private function execute(string $cmd, string $stdin = ''): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $cwd = $this->core->projectRoot();
        if ($cwd && !is_dir($cwd)) {
            $cwd = null;
        }

        $process = proc_open($cmd, $descriptors, $pipes, $cwd);
        if (!is_resource($process)) {
            return ['stdout' => '', 'stderr' => 'Failed to start process', 'exit_code' => 1];
        }

        if ($stdin !== '') {
            fwrite($pipes[0], $stdin);
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
