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
     * Archive yesterday's STATUS.md and start fresh for today.
     */
    public function archiveStatus(): void
    {
        $statusFile = $this->core->config('paths.status');
        $archiveDir = $this->core->memoryDir() . '/archive';

        if (!is_dir($archiveDir)) {
            mkdir($archiveDir, 0755, true);
        }

        if (file_exists($statusFile) && trim(file_get_contents($statusFile)) !== '') {
            // Archive with yesterday's date
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            $archiveFile = $archiveDir . '/' . $yesterday . '.md';

            // Don't overwrite if already archived
            if (!file_exists($archiveFile)) {
                copy($statusFile, $archiveFile);
                $this->logger->info("Archived STATUS.md to {$yesterday}.md");
            }
        }

        // Start fresh
        $today = date('Y-m-d');
        $day = date('l');
        file_put_contents($statusFile, "# Status — {$day}, {$today}\n\n## Skill Runs\n\n");
        $this->logger->info("Reset STATUS.md for {$today}");
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
     * Read MEMORY.md content.
     */
    public function readMemory(): string
    {
        $memoryFile = $this->core->memoryDir() . '/MEMORY.md';
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
        $memoryFile = $this->core->memoryDir() . '/MEMORY.md';
        $memoryDir = dirname($memoryFile);
        if (!is_dir($memoryDir)) {
            mkdir($memoryDir, 0755, true);
        }
        file_put_contents($memoryFile, $content, LOCK_EX);
    }

    /**
     * Archive the current MEMORY.md as a weekly snapshot.
     */
    public function archiveMemory(): void
    {
        $memoryFile = $this->core->memoryDir() . '/MEMORY.md';
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
