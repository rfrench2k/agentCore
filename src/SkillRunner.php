<?php
/**
 * AgentCore — Skill Runner
 *
 * Invokes claude -p to execute a skill. Assembles the command with
 * appropriate flags (model, max-turns, budget) and captures output.
 */

require_once __DIR__ . '/AgentCore.php';
require_once __DIR__ . '/Logger.php';

class SkillRunner
{
    private AgentCore $core;
    private Logger $logger;

    public function __construct(?AgentCore $core = null)
    {
        $this->core = $core ?: AgentCore::getInstance();
        $this->logger = new Logger(null, 'skill-runner');
    }

    /**
     * Run a skill by name.
     *
     * @param string $skillName   Directory name under skills/
     * @param array  $overrides   Override model, max_turns, max_budget_usd, effort
     * @param string $arguments   Additional arguments to pass to the skill
     * @return SkillRunResult
     */
    public function run(string $skillName, array $overrides = [], string $arguments = ''): SkillRunResult
    {
        $skillDir = $this->core->skillsDir() . '/' . $skillName;
        $skillFile = $skillDir . '/SKILL.md';

        if (!file_exists($skillFile)) {
            $this->logger->error("Skill not found: {$skillName}", ['path' => $skillFile]);
            return new SkillRunResult(
                success: false,
                output: '',
                error: "Skill file not found: {$skillFile}",
                duration: 0
            );
        }

        // Parse SKILL.md frontmatter for defaults
        $skillDefaults = $this->parseFrontmatter($skillFile);

        // Determine final settings (overrides > skill frontmatter > global defaults)
        $model     = $overrides['model']          ?? $skillDefaults['model']  ?? $this->core->defaultModel();
        $maxTurns  = $overrides['max_turns']       ?? $skillDefaults['max-turns'] ?? 10;
        $budget    = $overrides['max_budget_usd']  ?? $skillDefaults['max-budget'] ?? 2.00;
        $effort    = $overrides['effort']          ?? $skillDefaults['effort'] ?? 'high';

        // Build the prompt. Avoid the word "skill" — Claude Code has a built-in Skill tool
        // that intercepts "run the X skill" phrasing and tries to look X up in its native
        // skill registry, which fails for our scheduled job names. Use "task" and "instructions".
        $date = date('Y-m-d');
        $day = date('l');
        $prompt = "The system prompt above contains a complete task called `{$skillName}` with "
                . "numbered steps. Work through those steps yourself, right now, using the tools "
                . "available (Bash, Read, Write, Edit, Glob, Grep, WebSearch, WebFetch). Do NOT "
                . "attempt to invoke `{$skillName}` as a Claude Code Skill tool — it is NOT one. "
                . "It is a set of instructions you execute directly. Today is {$day}, {$date}.";
        if ($arguments !== '') {
            $prompt .= " Arguments: {$arguments}";
        }

        // Assemble the complete system prompt as a SINGLE file:
        //   1. SOUL.md         — voice/character/values, loaded first so tone is primed
        //   2. SKILL.md        — the actual task instructions for this run
        //   3. MEMORY.md       — global operational rules
        //   4. LEARNINGS.md    — skill-specific accumulated rules (if any)
        //
        // CRITICAL: we must combine everything into ONE --append-system-prompt-file. Passing
        // multiple --append-system-prompt-file flags causes Claude Code to use only the last
        // one, silently dropping the earlier files — including the skill instructions themselves.
        $combinedSystemPrompt = '';

        $soulFile = $this->core->config('paths.soul_file');
        if ($soulFile && file_exists($soulFile)) {
            $combinedSystemPrompt .= file_get_contents($soulFile) . "\n\n---\n\n";
        }

        $combinedSystemPrompt .= "## Task Instructions for This Run\n\n" . file_get_contents($skillFile);

        $memoryFile = $this->core->config('paths.memory_file');
        if ($memoryFile && file_exists($memoryFile)) {
            $memoryContent = file_get_contents($memoryFile);
            if (trim($memoryContent) !== '') {
                $combinedSystemPrompt .= "\n\n---\n\n## Global System Rules (MEMORY.md — authoritative)\n\n" . $memoryContent;
            }
        }

        $learningsFile = $skillDir . '/LEARNINGS.md';
        if (file_exists($learningsFile)) {
            $learnings = file_get_contents($learningsFile);
            if (trim($learnings) !== '') {
                $combinedSystemPrompt .= "\n\n---\n\n## Learnings from Previous Runs of This Task\n\n" . $learnings;
            }
        }

        // Build command
        $claudeBin = $this->core->claudeBinary();
        $projectRoot = $this->core->projectRoot();

        // Write the combined system prompt to a temp file and pass it as a single
        // --append-system-prompt-file. No --max-budget-usd — Ross is on Claude Code
        // subscription, not API billing. --max-turns is the real runaway guard.
        $tempFile = sys_get_temp_dir() . '/agentcore-prompt-' . $skillName . '-' . bin2hex(random_bytes(4)) . '.md';
        file_put_contents($tempFile, $combinedSystemPrompt);

        $cmd = $claudeBin
            . ' -p'
            . ' --model ' . escapeshellarg($model)
            . ' --max-turns ' . (int)$maxTurns
            . ' --output-format json'
            . ' --dangerously-skip-permissions'
            . ' --append-system-prompt-file ' . escapeshellarg($this->normalizePath($tempFile));

        // Note: intentionally NOT using --add-dir. Skills should access only
        // what they need via explicit paths or commands in their SKILL.md.
        // --add-dir causes Claude to explore the project directory, consuming
        // hundreds of thousands of cache tokens per run.

        // NOTE: prompt is passed via stdin, not as a command-line argument.
        // On Windows, passing long quoted strings through proc_open can trigger
        // cmd.exe argument parsing bugs (especially with backslash-containing paths).
        // stdin avoids all quoting issues.

        $this->logger->info("Running skill: {$skillName}", [
            'model' => $model,
            'max_turns' => $maxTurns,
            'budget' => $budget,
        ]);

        // Execute
        $startTime = microtime(true);
        $result = $this->execute($cmd, $prompt);

        // Retry once on transient Anthropic upstream 5xx. claude-code prints those
        // to stdout (not JSON) and exits non-zero, so the JSON parse below would miss it.
        if (preg_match('/API Error:\s*5\d\d\b/', $result['stdout'] ?? '')) {
            $this->logger->warn("Skill {$skillName} hit transient API 5xx; retrying once in 30s");
            sleep(30);
            $result = $this->execute($cmd, $prompt);
        }

        $duration = (int)(microtime(true) - $startTime);

        // Clean up temp file
        if (isset($tempFile) && file_exists($tempFile)) {
            unlink($tempFile);
        }

        // Parse output
        $rawOutput = $result['stdout'];
        $output = $rawOutput;
        $sessionId = null;
        $budgetUsed = null;
        $errorDetail = null;
        $isError = false;
        $tokensIn = 0;
        $tokensOut = 0;
        $cacheRead = 0;
        $cacheWrite = 0;
        $modelUsed = $model;

        // Try to parse JSON output
        $jsonOutput = json_decode($rawOutput, true);
        if ($jsonOutput !== null) {
            $output = $jsonOutput['result'] ?? $rawOutput;
            $sessionId = $jsonOutput['session_id'] ?? null;

            // total_cost_usd is the correct field (not usage.cost_usd)
            if (isset($jsonOutput['total_cost_usd'])) {
                $budgetUsed = (float)$jsonOutput['total_cost_usd'];
            }

            // Token usage for usage_log
            if (isset($jsonOutput['usage'])) {
                $u = $jsonOutput['usage'];
                $tokensIn  = (int)($u['input_tokens'] ?? 0);
                $tokensOut = (int)($u['output_tokens'] ?? 0);
                $cacheRead  = (int)($u['cache_read_input_tokens'] ?? 0);
                $cacheWrite = (int)($u['cache_creation_input_tokens'] ?? 0);
            }

            // Actual model used (from modelUsage map)
            if (isset($jsonOutput['modelUsage']) && is_array($jsonOutput['modelUsage'])) {
                $firstKey = array_key_first($jsonOutput['modelUsage']);
                if ($firstKey) {
                    $modelUsed = $firstKey;
                }
            }

            // Detect error conditions from JSON
            $isError = ($jsonOutput['is_error'] ?? false) === true;
            if ($isError) {
                $subtype = $jsonOutput['subtype'] ?? 'error';
                $errors = $jsonOutput['errors'] ?? [];
                $errorDetail = $subtype;
                if (!empty($errors)) {
                    $errorDetail .= ': ' . implode('; ', (array)$errors);
                }
                if ($subtype === 'error_max_turns') {
                    $numTurns = $jsonOutput['num_turns'] ?? '?';
                    $errorDetail = "Hit max turns ({$numTurns}). Increase max_turns in skill_schedules.";
                }
            }
        }

        // Skill succeeded if exit code is 0 AND JSON doesn't indicate error
        $success = ($result['exit_code'] === 0) && !$isError;

        $finalError = $success ? null : $this->extractError($result, $errorDetail);

        if (!$success) {
            $this->logger->error("Skill failed: {$skillName}", [
                'exit_code' => $result['exit_code'],
                'error' => $finalError,
            ]);
        } else {
            $this->logger->info("Skill completed: {$skillName}", [
                'duration' => $duration,
                'budget_used' => $budgetUsed,
            ]);
        }

        return new SkillRunResult(
            success: $success,
            output: $output,
            error: $finalError,
            duration: $duration,
            sessionId: $sessionId,
            budgetUsed: $budgetUsed,
            model: $modelUsed,
            tokensIn: $tokensIn,
            tokensOut: $tokensOut,
            cacheRead: $cacheRead,
            cacheWrite: $cacheWrite,
        );
    }

    /**
     * List all available skills.
     *
     * @return array Array of ['name' => ..., 'description' => ..., 'path' => ...]
     */
    public function listSkills(): array
    {
        $skillsDir = $this->core->skillsDir();
        if (!is_dir($skillsDir)) {
            return [];
        }

        $skills = [];
        foreach (glob($skillsDir . '/*/SKILL.md') as $skillFile) {
            $name = basename(dirname($skillFile));
            $frontmatter = $this->parseFrontmatter($skillFile);
            $skills[] = [
                'name'        => $name,
                'description' => $frontmatter['description'] ?? '',
                'path'        => dirname($skillFile),
                'has_learnings' => file_exists(dirname($skillFile) . '/LEARNINGS.md'),
            ];
        }

        usort($skills, fn($a, $b) => strcmp($a['name'], $b['name']));
        return $skills;
    }

    /**
     * Parse YAML frontmatter from a SKILL.md file.
     */
    private function parseFrontmatter(string $file): array
    {
        $content = file_get_contents($file);
        if (!str_starts_with($content, '---')) {
            return [];
        }

        $endPos = strpos($content, '---', 3);
        if ($endPos === false) {
            return [];
        }

        $yaml = substr($content, 3, $endPos - 3);
        $result = [];

        foreach (explode("\n", $yaml) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;

            $colonPos = strpos($line, ':');
            if ($colonPos === false) continue;

            $key = trim(substr($line, 0, $colonPos));
            $value = trim(substr($line, $colonPos + 1));

            // Strip quotes
            if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                $value = substr($value, 1, -1);
            }

            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * Normalize path separators for the current OS.
     */
    private function normalizePath(string $path): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return str_replace('/', '\\', $path);
        }
        return $path;
    }

    /**
     * Execute a shell command and capture output.
     *
     * @param string $cmd    The command to run
     * @param string $stdin  Optional data to write to the process's stdin
     */
    /**
     * Build the best available error string for a failed skill run.
     *
     * claude-code on Windows often writes the literal string "success" to stderr
     * (a strerror(0) artifact) even when the real error is in stdout — e.g. an
     * upstream "API Error: 500 ..." line that isn't valid JSON. We prefer any
     * parsed JSON error, then look for known error patterns in stdout, then fall
     * back to stderr (ignoring the bogus "success" sentinel).
     */
    private function extractError(array $result, ?string $errorDetail): string
    {
        if ($errorDetail) return $errorDetail;

        $stdout = $result['stdout'] ?? '';
        $stderr = trim($result['stderr'] ?? '');

        if (preg_match('/^(API Error:[^\n]+)/m', $stdout, $m)) return trim($m[1]);
        if (preg_match('/^(Fatal:[^\n]+)/m', $stdout, $m))      return trim($m[1]);
        if (preg_match('/^(Error:[^\n]+)/m', $stdout, $m))      return trim($m[1]);

        if ($stderr !== '' && strcasecmp($stderr, 'success') !== 0) {
            return $stderr;
        }

        $trimmedStdout = trim($stdout);
        if ($trimmedStdout !== '') {
            return substr($trimmedStdout, 0, 500);
        }

        return 'Unknown error (exit code ' . ($result['exit_code'] ?? '?') . ')';
    }

    private function execute(string $cmd, string $stdin = ''): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        // Set CWD to project_root (skoopix) so Claude Code auto-loads CLAUDE.md
        $cwd = $this->core->projectRoot();
        if ($cwd && !is_dir($cwd)) {
            $cwd = null;
        }

        $this->logger->debug("Executing: {$cmd}" . ($stdin ? " [stdin: " . substr($stdin, 0, 80) . "]" : '') . ($cwd ? " [cwd: $cwd]" : ''));

        $process = proc_open($cmd, $descriptors, $pipes, $cwd);
        if (!is_resource($process)) {
            return ['stdout' => '', 'stderr' => 'Failed to start process', 'exit_code' => 1];
        }

        // Write prompt to stdin if provided
        if ($stdin !== '') {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'stdout'    => $stdout,
            'stderr'    => $stderr,
            'exit_code' => $exitCode,
        ];
    }
}

/**
 * Result of a skill execution.
 */
class SkillRunResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $output,
        public readonly ?string $error,
        public readonly int $duration,
        public readonly ?string $sessionId = null,
        public readonly ?float $budgetUsed = null,
        public readonly ?string $model = null,
        public readonly int $tokensIn = 0,
        public readonly int $tokensOut = 0,
        public readonly int $cacheRead = 0,
        public readonly int $cacheWrite = 0,
    ) {}

    public function summary(int $maxLength = 200): string
    {
        $text = strip_tags($this->output);
        if (strlen($text) <= $maxLength) {
            return $text;
        }
        return substr($text, 0, $maxLength) . '...';
    }
}
