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

        // Build the prompt
        $date = date('Y-m-d');
        $day = date('l');
        $prompt = "Execute this skill now. Today is {$day}, {$date}.";
        if ($arguments !== '') {
            $prompt .= " Arguments: {$arguments}";
        }

        // Check for LEARNINGS.md
        $learningsFile = $skillDir . '/LEARNINGS.md';
        $learningsContext = '';
        if (file_exists($learningsFile)) {
            $learnings = file_get_contents($learningsFile);
            if (trim($learnings) !== '') {
                $learningsContext = "\n\n## Learnings from Previous Runs\n\n" . $learnings;
            }
        }

        // Build command
        $claudeBin = $this->core->claudeBinary();
        $projectRoot = $this->core->projectRoot();

        $cmd = $claudeBin
            . ' -p'
            . ' --model ' . escapeshellarg($model)
            . ' --max-turns ' . (int)$maxTurns
            . ' --max-budget-usd ' . number_format((float)$budget, 2, '.', '')
            . ' --output-format json'
            . ' --append-system-prompt-file ' . escapeshellarg($this->normalizePath($skillFile));

        if ($projectRoot && is_dir($projectRoot)) {
            $cmd .= ' --add-dir ' . escapeshellarg($this->normalizePath($projectRoot));
        }

        // Append learnings as additional system prompt if they exist
        if ($learningsContext !== '') {
            $tempFile = sys_get_temp_dir() . '/agentcore-learnings-' . $skillName . '.md';
            file_put_contents($tempFile, $learningsContext);
            $cmd .= ' --append-system-prompt-file ' . escapeshellarg($this->normalizePath($tempFile));
        }

        $cmd .= ' ' . escapeshellarg($prompt);

        $this->logger->info("Running skill: {$skillName}", [
            'model' => $model,
            'max_turns' => $maxTurns,
            'budget' => $budget,
        ]);

        // Execute
        $startTime = microtime(true);
        $result = $this->execute($cmd);
        $duration = (int)(microtime(true) - $startTime);

        // Clean up temp file
        if (isset($tempFile) && file_exists($tempFile)) {
            unlink($tempFile);
        }

        // Parse output
        $output = $result['stdout'];
        $sessionId = null;
        $budgetUsed = null;

        // Try to parse JSON output
        $jsonOutput = json_decode($output, true);
        if ($jsonOutput !== null) {
            $output = $jsonOutput['result'] ?? $output;
            $sessionId = $jsonOutput['session_id'] ?? null;
            if (isset($jsonOutput['usage']['cost_usd'])) {
                $budgetUsed = (float)$jsonOutput['usage']['cost_usd'];
            }
        }

        $success = $result['exit_code'] === 0;

        if (!$success) {
            $this->logger->error("Skill failed: {$skillName}", [
                'exit_code' => $result['exit_code'],
                'stderr' => substr($result['stderr'], 0, 500),
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
            error: $success ? null : ($result['stderr'] ?: 'Unknown error'),
            duration: $duration,
            sessionId: $sessionId,
            budgetUsed: $budgetUsed,
            model: $model
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
     */
    private function execute(string $cmd): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $this->logger->debug("Executing: {$cmd}");

        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            return ['stdout' => '', 'stderr' => 'Failed to start process', 'exit_code' => 1];
        }

        fclose($pipes[0]); // Close stdin

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
