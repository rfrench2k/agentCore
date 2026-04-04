<?php
/**
 * AgentCore — Simple File Logger
 *
 * Writes timestamped log entries to daily log files.
 */

class Logger
{
    private string $logDir;
    private string $prefix;

    public function __construct(?string $logDir = null, string $prefix = 'agentcore')
    {
        $this->logDir = $logDir ?: AgentCore::getInstance()->logsDir();
        $this->prefix = $prefix;

        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }

    public function warn(string $message, array $context = []): void
    {
        $this->log('WARN', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('DEBUG', $message, $context);
    }

    private function log(string $level, string $message, array $context): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $line = "[{$timestamp}] [{$level}] {$message}";

        if (!empty($context)) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
        }

        $file = $this->logDir . '/' . $this->prefix . '-' . date('Y-m-d') . '.log';
        file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    public function getLogFile(string $date = ''): string
    {
        $date = $date ?: date('Y-m-d');
        return $this->logDir . '/' . $this->prefix . '-' . $date . '.log';
    }
}
