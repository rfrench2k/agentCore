-- AgentCore — Core Tables
-- Run: php migrate.php
-- Note: The database must already exist. migrate.php connects to it directly.

-- Skill schedules: when and how to run each skill
CREATE TABLE IF NOT EXISTS skill_schedules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  skill_name VARCHAR(100) NOT NULL UNIQUE,
  exec_command TEXT NULL,            -- for exec:* rows that run a shell command instead of a skill
  cron_expression VARCHAR(50) NOT NULL,
  timezone VARCHAR(50) NOT NULL DEFAULT 'America/Los_Angeles',
  model VARCHAR(50) DEFAULT 'sonnet',
  max_turns INT DEFAULT 10,
  max_budget_usd DECIMAL(5,2) DEFAULT 2.00,
  effort VARCHAR(10) DEFAULT 'high',
  enabled TINYINT(1) DEFAULT 1,
  notify_on_error TINYINT(1) DEFAULT 1,
  notify_on_success TINYINT(1) DEFAULT 0,
  consecutive_failures INT DEFAULT 0,
  auto_disable_after INT DEFAULT 3,
  last_run_at TIMESTAMP NULL,
  next_run_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Skill run history: every execution logged
CREATE TABLE IF NOT EXISTS skill_runs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  skill_name VARCHAR(100) NOT NULL,
  schedule_id INT NULL,
  trigger_type ENUM('schedule','telegram','web','cli') DEFAULT 'schedule',
  trigger_by VARCHAR(100) NULL,
  model_used VARCHAR(50) NULL,
  status ENUM('running','completed','failed','timeout') DEFAULT 'running',
  started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  completed_at TIMESTAMP NULL,
  duration_seconds INT NULL,
  output_summary TEXT NULL,
  output_full LONGTEXT NULL,
  error_message TEXT NULL,
  budget_used DECIMAL(6,4) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_skill_date (skill_name, started_at),
  KEY idx_status (status)
) ENGINE=InnoDB;

-- Telegram conversation sessions
CREATE TABLE IF NOT EXISTS telegram_sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  chat_id VARCHAR(50) NOT NULL,
  session_id VARCHAR(100) NOT NULL,
  started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  last_message_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  message_count INT DEFAULT 1,
  status ENUM('active','expired','compacted') DEFAULT 'active',
  summary TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_chat_active (chat_id, status)
) ENGINE=InnoDB;
