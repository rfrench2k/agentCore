<?php
/**
 * AgentCore — Scheduler
 *
 * Checks skill_schedules for due skills and runs them.
 * Designed to be invoked every 1-5 minutes via Task Scheduler or cron.
 *
 * Usage:
 *   php Scheduler.php              Run due skills
 *   php Scheduler.php --list       List all schedules
 *   php Scheduler.php --run NAME   Run a specific skill immediately
 */

require_once __DIR__ . '/AgentCore.php';
require_once __DIR__ . '/SkillRunner.php';
require_once __DIR__ . '/CronExpression.php';
require_once __DIR__ . '/Logger.php';

class Scheduler
{
    private AgentCore $core;
    private PDO $db;
    private SkillRunner $runner;
    private Logger $logger;

    public function __construct(?AgentCore $core = null)
    {
        $this->core = $core ?: AgentCore::getInstance();
        $this->db = $this->core->db();
        $this->runner = new SkillRunner($this->core);
        $this->logger = new Logger(null, 'scheduler');
    }

    /**
     * Check for and run all due skills.
     */
    public function runDue(): int
    {
        $now = new DateTime('now', new DateTimeZone($this->core->config('timezone')));
        $nowStr = $now->format('Y-m-d H:i:s');

        $stmt = $this->db->prepare("
            SELECT * FROM skill_schedules
            WHERE enabled = 1 AND next_run_at IS NOT NULL AND next_run_at <= ?
            ORDER BY next_run_at ASC
        ");
        $stmt->execute([$nowStr]);
        $dueSkills = $stmt->fetchAll();

        if (empty($dueSkills)) {
            return 0;
        }

        $this->logger->info("Found " . count($dueSkills) . " due skill(s)");
        $ran = 0;

        foreach ($dueSkills as $schedule) {
            // exec:<path-to-script> schedules run a direct command instead of claude -p
            if (str_starts_with($schedule['skill_name'], 'exec:')) {
                $this->runExec($schedule);
            } else {
                $this->runSkill($schedule);
            }
            $ran++;
        }

        return $ran;
    }

    /**
     * Run a direct exec schedule (e.g. Node scripts like eme-health-check.js).
     * skill_name holds a short identifier like 'exec:eme-health-check'.
     * exec_command holds the actual shell command to run.
     */
    private function runExec(array $schedule): void
    {
        $execCommand = $schedule['exec_command'] ?? '';
        if (!$execCommand) {
            $this->logger->error("Exec schedule {$schedule['skill_name']} has no exec_command set");
            return;
        }
        $this->logger->info("Running exec schedule: {$schedule['skill_name']}");

        $runId = $this->insertRun($schedule['skill_name'], $schedule['id'], 'schedule');
        $startTime = microtime(true);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $cwd = $this->core->projectRoot();
        if ($cwd && !is_dir($cwd)) $cwd = null;

        $process = proc_open($execCommand, $descriptors, $pipes, $cwd);
        if (!is_resource($process)) {
            $this->logger->error("Failed to start exec: {$execCommand}");
            return;
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $duration = (int)(microtime(true) - $startTime);

        $success = $exitCode === 0;
        $status = $success ? 'completed' : 'failed';

        // Update skill_runs row
        $this->db->prepare("
            UPDATE skill_runs
            SET status = ?, completed_at = NOW(), duration_seconds = ?,
                output_summary = ?, output_full = ?, error_message = ?
            WHERE id = ?
        ")->execute([
            $status,
            $duration,
            substr($stdout, 0, 500),
            $stdout,
            $success ? null : $stderr,
            $runId,
        ]);

        // Calculate next run + update schedule
        $tz = $schedule['timezone'] ?: $this->core->config('timezone');
        $cron = new CronExpression($schedule['cron_expression']);
        $nextRun = $cron->nextRunAfter(new DateTime('now', new DateTimeZone($tz)));

        if ($success) {
            $this->db->prepare("
                UPDATE skill_schedules
                SET last_run_at = NOW(), next_run_at = ?, consecutive_failures = 0
                WHERE id = ?
            ")->execute([$nextRun->format('Y-m-d H:i:s'), $schedule['id']]);
        } else {
            $failures = $schedule['consecutive_failures'] + 1;
            $this->db->prepare("
                UPDATE skill_schedules
                SET last_run_at = NOW(), next_run_at = ?, consecutive_failures = ?
                WHERE id = ?
            ")->execute([$nextRun->format('Y-m-d H:i:s'), $failures, $schedule['id']]);
            $this->logger->error("Exec failed ({$failures} consecutive): {$execCommand}\n{$stderr}");
        }
    }

    /**
     * Run a specific skill by name (immediate, ignores schedule).
     */
    public function runNow(string $skillName, string $triggerType = 'cli', string $triggerBy = '', string $arguments = ''): SkillRunResult
    {
        // Look up schedule for model/budget overrides
        $stmt = $this->db->prepare("SELECT * FROM skill_schedules WHERE skill_name = ?");
        $stmt->execute([$skillName]);
        $schedule = $stmt->fetch();

        $overrides = [];
        if ($schedule) {
            $overrides = [
                'model'          => $schedule['model'],
                'max_turns'      => $schedule['max_turns'],
                'max_budget_usd' => $schedule['max_budget_usd'],
                'effort'         => $schedule['effort'],
            ];
        }

        // Log the run
        $runId = $this->insertRun($skillName, $schedule['id'] ?? null, $triggerType, $triggerBy);

        // Execute
        $result = $this->runner->run($skillName, $overrides, $arguments);

        // Update run record
        $this->updateRun($runId, $result);

        // Write cost to usage_log (assistant DB)
        $this->writeUsageLog($skillName, $result, $triggerType);

        return $result;
    }

    /**
     * List all schedules.
     */
    public function listSchedules(): array
    {
        return $this->db->query("
            SELECT s.*,
                   (SELECT COUNT(*) FROM skill_runs r WHERE r.skill_name = s.skill_name) as total_runs,
                   (SELECT MAX(started_at) FROM skill_runs r WHERE r.skill_name = s.skill_name AND r.status = 'completed') as last_success
            FROM skill_schedules s
            ORDER BY s.next_run_at ASC
        ")->fetchAll();
    }

    /**
     * Add or update a schedule.
     */
    public function upsertSchedule(
        string $skillName,
        string $cronExpression,
        string $model = 'sonnet',
        int $maxTurns = 10,
        float $maxBudget = 2.00,
        string $effort = 'high',
        bool $enabled = true
    ): void {
        $tz = $this->core->config('timezone');
        $cron = new CronExpression($cronExpression);
        $nextRun = $cron->nextRunAfter(new DateTime('now', new DateTimeZone($tz)));

        $stmt = $this->db->prepare("
            INSERT INTO skill_schedules (skill_name, cron_expression, timezone, model, max_turns, max_budget_usd, effort, enabled, next_run_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                cron_expression = VALUES(cron_expression),
                model = VALUES(model),
                max_turns = VALUES(max_turns),
                max_budget_usd = VALUES(max_budget_usd),
                effort = VALUES(effort),
                enabled = VALUES(enabled),
                next_run_at = VALUES(next_run_at)
        ");
        $stmt->execute([
            $skillName, $cronExpression, $tz, $model, $maxTurns, $maxBudget, $effort, $enabled ? 1 : 0, $nextRun->format('Y-m-d H:i:s')
        ]);
    }

    /**
     * Run a scheduled skill and update its schedule.
     */
    private function runSkill(array $schedule): void
    {
        $skillName = $schedule['skill_name'];
        $this->logger->info("Running scheduled skill: {$skillName}");

        $overrides = [
            'model'          => $schedule['model'],
            'max_turns'      => $schedule['max_turns'],
            'max_budget_usd' => $schedule['max_budget_usd'],
            'effort'         => $schedule['effort'],
        ];

        // Insert run record
        $runId = $this->insertRun($skillName, $schedule['id'], 'schedule');

        // Execute the skill
        $result = $this->runner->run($skillName, $overrides);

        // Update run record
        $this->updateRun($runId, $result);

        // Write cost to usage_log (assistant DB)
        $this->writeUsageLog($skillName, $result, 'schedule');

        // Calculate next run
        $tz = $schedule['timezone'] ?: $this->core->config('timezone');
        $cron = new CronExpression($schedule['cron_expression']);
        $nextRun = $cron->nextRunAfter(new DateTime('now', new DateTimeZone($tz)));

        // Update schedule
        if ($result->success) {
            $this->db->prepare("
                UPDATE skill_schedules
                SET last_run_at = NOW(), next_run_at = ?, consecutive_failures = 0
                WHERE id = ?
            ")->execute([$nextRun->format('Y-m-d H:i:s'), $schedule['id']]);
        } else {
            $failures = $schedule['consecutive_failures'] + 1;
            $autoDisable = $schedule['auto_disable_after'];
            $disable = ($autoDisable > 0 && $failures >= $autoDisable) ? 0 : 1;

            $this->db->prepare("
                UPDATE skill_schedules
                SET last_run_at = NOW(), next_run_at = ?, consecutive_failures = ?, enabled = ?
                WHERE id = ?
            ")->execute([$nextRun->format('Y-m-d H:i:s'), $failures, $disable, $schedule['id']]);

            if (!$disable) {
                $this->logger->error("Auto-disabled skill '{$skillName}' after {$failures} consecutive failures");
            }

            // Send Telegram alert if configured
            if ($schedule['notify_on_error']) {
                $this->sendAlert($skillName, $result, $failures, !$disable);
            }
        }
    }

    private function insertRun(string $skillName, ?int $scheduleId, string $triggerType, string $triggerBy = ''): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO skill_runs (skill_name, schedule_id, trigger_type, trigger_by, status)
            VALUES (?, ?, ?, ?, 'running')
        ");
        $stmt->execute([$skillName, $scheduleId, $triggerType, $triggerBy ?: null]);
        return (int)$this->db->lastInsertId();
    }

    private function updateRun(int $runId, SkillRunResult $result): void
    {
        $status = $result->success ? 'completed' : 'failed';
        $stmt = $this->db->prepare("
            UPDATE skill_runs
            SET status = ?, completed_at = NOW(), duration_seconds = ?,
                output_summary = ?, output_full = ?, error_message = ?,
                budget_used = ?, model_used = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $status,
            $result->duration,
            $result->summary(500),
            $result->output,
            $result->error,
            $result->budgetUsed,
            $result->model,
            $runId,
        ]);
    }

    /**
     * Write a row to an external usage_log table for cost tracking. Opt-in via
     * config.usage_log.enabled. The target DB and table are configurable —
     * connection details fall back to the main db.* config if not overridden.
     *
     * The target table must have columns matching the INSERT below:
     *   timestamp, provider, model, task_type, cron_job_name, description,
     *   tokens_in, tokens_out, cache_read, cache_write, cost_usd, session_id.
     */
    private function writeUsageLog(string $skillName, SkillRunResult $result, string $triggerType): void
    {
        $cfg = $this->core->config('usage_log');
        if (!$cfg || empty($cfg['enabled']) || empty($cfg['db'])) {
            return;
        }

        // Only log if we got real usage data from claude -p JSON output
        if ($result->tokensIn === 0 && $result->tokensOut === 0 && $result->budgetUsed === null) {
            return;
        }

        $mainDb = $this->core->config('db');
        $host  = $cfg['host'] ?: $mainDb['host'];
        $port  = $cfg['port'] ?: $mainDb['port'];
        $user  = $cfg['user'] ?: $mainDb['user'];
        $pass  = $cfg['pass'] ?: $mainDb['pass'];
        $dbName = $cfg['db'];
        $table  = $cfg['table'] ?: 'usage_log';

        // Guard against SQL injection in the table identifier — config-controlled
        // but better to fail closed if someone sets a malformed value.
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            $this->logger->warn("usage_log.table is not a valid identifier; skipping log write", ['table' => $table]);
            return;
        }

        try {
            $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";
            $aDb = new \PDO($dsn, $user, $pass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);

            $taskType = match($triggerType) {
                'schedule'  => 'cron_skill',
                'telegram'  => 'telegram_chat',
                'web'       => 'web_skill',
                'cli'       => 'cli_skill',
                default     => 'skill',
            };

            $description = "Skill: {$skillName}";
            if ($result->success) {
                $summary = trim(preg_replace('/\s+/', ' ', $result->output));
                if (strlen($summary) > 5) {
                    $description .= ' — ' . substr($summary, 0, 180);
                }
            }

            $stmt = $aDb->prepare("
                INSERT INTO `{$table}`
                    (timestamp, provider, model, task_type, cron_job_name, description,
                     tokens_in, tokens_out, cache_read, cache_write, cost_usd, session_id)
                VALUES (NOW(), 'anthropic', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $result->model,
                $taskType,
                $skillName,
                $description,
                $result->tokensIn,
                $result->tokensOut,
                $result->cacheRead,
                $result->cacheWrite,
                $result->budgetUsed,
                $result->sessionId,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warn("Failed to write usage_log: " . $e->getMessage());
        }
    }

    private function sendAlert(string $skillName, SkillRunResult $result, int $failures, bool $disabled): void
    {
        $token = $this->core->config('telegram.bot_token');
        $chatIds = $this->core->config('telegram.allowed_chat_ids');

        if (!$token || !$chatIds) return;

        $msg = "AgentCore: Skill '{$skillName}' failed";
        if ($disabled) {
            $msg .= " (AUTO-DISABLED after {$failures} failures)";
        } else {
            $msg .= " ({$failures} consecutive failure(s))";
        }
        $msg .= "\n\nError: " . substr($result->error ?? 'Unknown', 0, 500);

        foreach (explode(',', $chatIds) as $chatId) {
            $chatId = trim($chatId);
            if (!$chatId) continue;

            @file_get_contents('https://api.telegram.org/bot' . $token . '/sendMessage?' . http_build_query([
                'chat_id' => $chatId,
                'text' => $msg,
            ]));
        }
    }
}

// ── CLI Entry Point ────────────────────────────────────────────────────────

if (php_sapi_name() === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $core = AgentCore::init(dirname(__DIR__) . '/config/config.php');
    $scheduler = new Scheduler($core);

    $action = $argv[1] ?? '';

    if ($action === '--list') {
        $schedules = $scheduler->listSchedules();
        if (empty($schedules)) {
            echo "No schedules found.\n";
        } else {
            printf("%-25s %-15s %-8s %-8s %-20s\n", 'Skill', 'Cron', 'Model', 'Enabled', 'Next Run');
            echo str_repeat('-', 80) . "\n";
            foreach ($schedules as $s) {
                printf("%-25s %-15s %-8s %-8s %-20s\n",
                    $s['skill_name'],
                    $s['cron_expression'],
                    $s['model'],
                    $s['enabled'] ? 'yes' : 'no',
                    $s['next_run_at'] ?? 'not set'
                );
            }
        }
    } elseif ($action === '--run' && isset($argv[2])) {
        $skillName = $argv[2];
        $args = $argv[3] ?? '';
        echo "Running skill: {$skillName}...\n";
        $result = $scheduler->runNow($skillName, 'cli', 'manual', $args);
        echo "Status: " . ($result->success ? 'completed' : 'FAILED') . "\n";
        echo "Duration: {$result->duration}s\n";
        if ($result->budgetUsed !== null) {
            echo "Cost: \${$result->budgetUsed}\n";
        }
        if ($result->error) {
            echo "Error: {$result->error}\n";
        }
        echo "\nOutput:\n" . substr($result->output, 0, 2000) . "\n";
    } else {
        // Default: run due skills
        $ran = $scheduler->runDue();
        if ($ran > 0) {
            echo "Ran {$ran} skill(s).\n";
        }
    }
}
