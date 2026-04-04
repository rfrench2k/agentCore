<?php
/**
 * AgentCore — Core Facade
 *
 * Central access point for configuration, database, and path resolution.
 * All other components use this to access shared resources.
 */

class AgentCore
{
    private static ?AgentCore $instance = null;
    private array $config;
    private ?PDO $db = null;

    private function __construct(array $config)
    {
        $this->config = $config;
    }

    public static function init(?string $configPath = null): self
    {
        if (self::$instance === null) {
            $path = $configPath ?: dirname(__DIR__) . '/config/config.php';
            $config = require $path;
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            return self::init();
        }
        return self::$instance;
    }

    public static function reset(): void
    {
        if (self::$instance?->db) {
            self::$instance->db = null;
        }
        self::$instance = null;
    }

    public function config(string $key = '', mixed $default = null): mixed
    {
        if ($key === '') {
            return $this->config;
        }

        $parts = explode('.', $key);
        $value = $this->config;
        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    public function db(): PDO
    {
        if ($this->db === null) {
            $c = $this->config['db'];
            $dsn = "mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4";
            $this->db = new PDO($dsn, $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            $tz = $this->config['timezone'];
            if ($tz !== 'UTC') {
                $offset = (new DateTime('now', new DateTimeZone($tz)))->format('P');
                $this->db->exec("SET time_zone = '$offset'");
            }
        }
        return $this->db;
    }

    public function path(string $key): string
    {
        return $this->config['paths'][$key] ?? '';
    }

    public function skillsDir(): string
    {
        return $this->path('skills');
    }

    public function memoryDir(): string
    {
        return $this->path('memory');
    }

    public function projectRoot(): string
    {
        return $this->path('project_root');
    }

    public function logsDir(): string
    {
        return $this->path('logs');
    }

    public function claudeBinary(): string
    {
        return $this->config['claude']['binary'] ?? 'claude';
    }

    public function defaultModel(): string
    {
        return $this->config['claude']['default_model'] ?? 'sonnet';
    }
}
