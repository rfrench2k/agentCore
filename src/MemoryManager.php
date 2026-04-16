<?php
/**
 * AgentCore — Memory Manager
 *
 * Manages the three-tier memory system:
 *   STATUS.md    — Today's awareness (overwritten daily)
 *   MEMORY.md    — Long-term patterns (curated weekly)
 *   archive/     — Daily snapshots (kept forever)
 *   LEARNINGS.md — Per-skill operational knowledge
 */

require_once __DIR__ . '/AgentCore.php';
require_once __DIR__ . '/Logger.php';

class MemoryManager
{
    private AgentCore $core;
    private Logger $logger;

    public function __construct(?AgentCore $core = null)
    {
        $this->core = $core ?: AgentCore::getInstance();
        $this->logger = new Logger(null, 'memory');
    }

    /**
     * Archive today's STATUS.md (the orchestrator runs at ~10:55 PM PT on day N,
     * archiving the content of day N to memory/archive/{day N}.md) and reset
     * STATUS.md with a fresh template for day N+1 (tomorrow).
     *
     * Order matters: COPY first, then OVERWRITE. Never the reverse — that loses data.
     */
    public function archiveStatus(): void
    {
        $statusFile = $this->core->config('paths.status');
        $archiveDir = $this->core->memoryDir() . '/archive';

        if (!is_dir($archiveDir)) {
            mkdir($archiveDir, 0755, true);
        }

        // The file content we're archiving IS today's content (the orchestrator runs at 10:55 PM PT).
        $todayDate = date('Y-m-d');
        $tomorrowDate = date('Y-m-d', strtotime('+1 day'));
        $tomorrowDay = date('l', strtotime('+1 day'));

        // STEP 1: Copy today's STATUS.md to archive/{today}.md — BEFORE overwriting it.
        if (file_exists($statusFile) && trim(file_get_contents($statusFile)) !== '') {
            $archiveFile = $archiveDir . '/' . $todayDate . '.md';

            // If an archive for today already exists (re-run protection), append a suffix
            if (file_exists($archiveFile)) {
                $archiveFile = $archiveDir . '/' . $todayDate . '-rerun-' . date('His') . '.md';
            }

            copy($statusFile, $archiveFile);
            $this->logger->info("Archived STATUS.md", ['file' => basename($archiveFile)]);
        }

        // STEP 2: Reset STATUS.md to a fresh template dated for TOMORROW (day N+1).
        $template = "# Status — {$tomorrowDay}, {$tomorrowDate}\n\n"
                  . "Rolling today file. The nightly end-of-day task archives a snapshot to `memory/archive/YYYY-MM-DD.md` "
                  . "and resets this file for the next day. Skill run summaries land here throughout the day, and captures "
                  . "routed from Telegram land in their target files (USER.md, MEMORY.md, or per-skill LEARNINGS.md).\n\n"
                  . "## Captures\n\n"
                  . "<!-- Telegram captures route directly to target files; this section is reserved for anything that "
                  . "doesn't fit one of those buckets. -->\n\n"
                  . "## Skill Runs\n\n"
                  . "<!-- MemoryManager::appendStatus writes skill run summaries here -->\n";

        file_put_contents($statusFile, $template, LOCK_EX);
        $this->logger->info("Reset STATUS.md for {$tomorrowDate}");
    }

    /**
     * Append a skill run summary to STATUS.md.
     */
    public function appendStatus(string $skillName, string $status, string $summary, int $duration): void
    {
        $statusFile = $this->core->config('paths.status');

        if (!file_exists($statusFile)) {
            $today = date('Y-m-d');
            file_put_contents($statusFile, "# Status — {$today}\n\n## Skill Runs\n\n");
        }

        $time = date('g:ia');
        $icon = $status === 'completed' ? 'ok' : 'FAIL';
        $line = "- [{$icon}] **{$skillName}** ({$time}, {$duration}s) — {$summary}\n";

        file_put_contents($statusFile, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Resolve the MEMORY.md file path. Prefers paths.memory_file (explicit),
     * falls back to memoryDir()/MEMORY.md for back-compat.
     */
    private function memoryFilePath(): string
    {
        $explicit = $this->core->config('paths.memory_file');
        if ($explicit) return $explicit;
        return $this->core->memoryDir() . '/MEMORY.md';
    }

    /**
     * Read MEMORY.md content.
     */
    public function readMemory(): string
    {
        $memoryFile = $this->memoryFilePath();
        if (!file_exists($memoryFile)) {
            return '';
        }
        return file_get_contents($memoryFile);
    }

    /**
     * Write MEMORY.md content (full rewrite).
     */
    public function writeMemory(string $content): void
    {
        $memoryFile = $this->memoryFilePath();
        $memoryDir = dirname($memoryFile);
        if (!is_dir($memoryDir)) {
            mkdir($memoryDir, 0755, true);
        }
        file_put_contents($memoryFile, $content, LOCK_EX);
    }

    /**
     * Route a captured statement to the right file based on its target.
     * Targets: 'user' → USER.md, 'memory' → MEMORY.md, 'skill:<name>' → skills/<name>/LEARNINGS.md.
     * Returns the path the capture was written to (or null if the target was unknown).
     */
    public function captureToTarget(string $target, string $body): ?string
    {
        $target = strtolower(trim($target));

        if ($target === 'user') {
            return $this->appendUnderCaptures($this->core->config('paths.user_file'), $body, '## Captures');
        }

        if ($target === 'memory') {
            return $this->appendUnderCaptures($this->core->config('paths.memory_file'), $body, '## Captures');
        }

        if (strpos($target, 'skill:') === 0) {
            $skillName = substr($target, 6);
            // Basic sanitization — only allow directory-safe characters
            if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $skillName)) {
                $this->logger->warn("Capture target has invalid skill name", ['target' => $target]);
                return null;
            }
            $learningsFile = $this->core->skillsDir() . '/' . $skillName . '/LEARNINGS.md';
            if (!is_dir(dirname($learningsFile))) {
                $this->logger->warn("Capture target skill does not exist", ['skill' => $skillName]);
                return null;
            }
            return $this->appendLearningsLine($learningsFile, $body);
        }

        $this->logger->warn("Unknown capture target", ['target' => $target, 'body' => substr($body, 0, 80)]);
        return null;
    }

    /**
     * Append a timestamped bullet under a "## Captures" heading in the target file.
     * Creates the file + section if missing. Returns the file path on success.
     */
    private function appendUnderCaptures(string $file, string $body, string $sectionHeading): ?string
    {
        if (!$file) return null;

        if (!file_exists($file)) {
            // Don't create USER.md or MEMORY.md from nothing — those are curated files.
            $this->logger->warn("Capture target file does not exist", ['file' => $file]);
            return null;
        }

        $time = date('Y-m-d H:i:s');
        $line = "- **{$time}** — {$body}\n";

        $content = file_get_contents($file);

        // Locate the Captures heading. If not present, append one to the end.
        $escapedHeading = preg_quote($sectionHeading, '/');
        if (preg_match("/^{$escapedHeading}\\s*\\n/m", $content, $m, PREG_OFFSET_CAPTURE)) {
            $insertAt = $m[0][1] + strlen($m[0][0]);
            // Skip past any existing HTML comment placeholder on the following line
            $rest = substr($content, $insertAt);
            if (preg_match('/^\s*<!--[^>]*-->\s*\n/', $rest, $cm)) {
                $insertAt += strlen($cm[0]);
                // Also skip any blank line after the comment so new captures group together
                $rest2 = substr($content, $insertAt);
                if (preg_match('/^\s*\n/', $rest2, $bm)) {
                    $insertAt += strlen($bm[0]);
                }
            }
            $new = substr($content, 0, $insertAt) . $line . substr($content, $insertAt);
            file_put_contents($file, $new, LOCK_EX);
        } else {
            file_put_contents($file, "\n" . $sectionHeading . "\n\n" . $line, FILE_APPEND | LOCK_EX);
        }

        $this->logger->info("Captured to " . basename($file), ['body' => substr($body, 0, 120)]);
        return $file;
    }

    /**
     * Append a dated learning entry to a skill's LEARNINGS.md (creates the file if missing).
     */
    private function appendLearningsLine(string $file, string $body): ?string
    {
        $dir = dirname($file);
        if (!is_dir($dir)) return null;

        if (!file_exists($file)) {
            $skillName = basename($dir);
            file_put_contents($file, "# Learnings — {$skillName}\n\nAccumulated rules and lessons for this skill. Read at the start of every run; append after every run.\n\n");
        }

        $date = date('Y-m-d');
        $line = "- **{$date}** — {$body}\n";
        file_put_contents($file, $line, FILE_APPEND | LOCK_EX);

        $this->logger->info("Captured to LEARNINGS.md", ['file' => $file, 'body' => substr($body, 0, 120)]);
        return $file;
    }

    /**
     * Archive the current MEMORY.md as a weekly snapshot.
     */
    public function archiveMemory(): void
    {
        $memoryFile = $this->memoryFilePath();
        $archiveDir = $this->core->memoryDir() . '/archive/weekly';

        if (!file_exists($memoryFile)) return;

        if (!is_dir($archiveDir)) {
            mkdir($archiveDir, 0755, true);
        }

        $week = date('Y-\WW');
        $archiveFile = $archiveDir . '/' . $week . '.md';

        if (!file_exists($archiveFile)) {
            copy($memoryFile, $archiveFile);
            $this->logger->info("Archived MEMORY.md to {$week}.md");
        }
    }

    /**
     * Read a skill's LEARNINGS.md.
     */
    public function readLearnings(string $skillName): string
    {
        $file = $this->core->skillsDir() . '/' . $skillName . '/LEARNINGS.md';
        if (!file_exists($file)) {
            return '';
        }
        return file_get_contents($file);
    }

    /**
     * Append a learning entry to a skill's LEARNINGS.md.
     */
    public function appendLearning(string $skillName, string $learning): void
    {
        $file = $this->core->skillsDir() . '/' . $skillName . '/LEARNINGS.md';
        $date = date('Y-m-d');
        $entry = "{$date}: {$learning}\n";

        file_put_contents($file, $entry, FILE_APPEND | LOCK_EX);
        $this->logger->info("Added learning for {$skillName}");
    }

    /**
     * Read STATUS.md content.
     */
    public function readStatus(): string
    {
        $statusFile = $this->core->config('paths.status');
        if (!file_exists($statusFile)) {
            return '';
        }
        return file_get_contents($statusFile);
    }

    /**
     * Get archive files for a date range.
     */
    public function getArchiveFiles(int $days = 7): array
    {
        $archiveDir = $this->core->memoryDir() . '/archive';
        if (!is_dir($archiveDir)) {
            return [];
        }

        $files = [];
        $cutoff = date('Y-m-d', strtotime("-{$days} days"));

        foreach (glob($archiveDir . '/????-??-??.md') as $file) {
            $date = basename($file, '.md');
            if ($date >= $cutoff) {
                $files[$date] = file_get_contents($file);
            }
        }

        ksort($files);
        return $files;
    }
}
