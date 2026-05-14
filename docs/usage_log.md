# Usage Log Integration

AgentCore can optionally write a row to an external `usage_log` table after every scheduled
or telegram-triggered skill run. The row records token counts, cost, model, and a short
description, so you can build a dashboard in your own app DB. The integration is **off by
default**.

## Schema the integration expects

```sql
CREATE TABLE usage_log (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  timestamp     TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  provider      VARCHAR(32)      NOT NULL,                -- always 'anthropic' for now
  model         VARCHAR(64)      NULL,
  task_type     VARCHAR(32)      NOT NULL,                -- cron_skill | telegram_chat | web_skill | cli_skill
  cron_job_name VARCHAR(128)     NULL,                    -- the skill name
  description   TEXT             NULL,
  tokens_in     INT              NULL,
  tokens_out    INT              NULL,
  cache_read    INT              NULL,
  cache_write   INT              NULL,
  cost_usd      DECIMAL(10, 6)   NULL,
  session_id    VARCHAR(128)     NULL,
  KEY idx_ts (timestamp),
  KEY idx_task (task_type, timestamp)
) ENGINE=InnoDB;
```

Column types are recommendations — the integration only INSERTs, so wider types are fine.

## Enable it

In `config/config.local.php`:

```php
'usage_log' => [
    'enabled' => true,
    'db'      => 'your_app_db',
    'table'   => 'usage_log',     // optional, defaults to 'usage_log'
    // host/port/user/pass fall back to db.* unless explicitly set
],
```

If your app DB lives on a different host or uses different credentials than AgentCore's own
database, set the `host`/`port`/`user`/`pass` keys explicitly.

## What writes the row

`Scheduler::writeUsageLog()` is called from `runSkill()` and `runTelegram()` after a skill
completes. The write is wrapped in a try/catch — if the external DB is down or the table
is missing, the run still completes; the failure is logged at warn level only.

## Disabling

Either set `enabled => false` in `config.local.php`, or remove the `usage_log` block
entirely (the default is disabled).
