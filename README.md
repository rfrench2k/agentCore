# AgentCore

Turn Claude Code into a personal AI agent system with scheduled skills, Telegram control, and persistent memory.

AgentCore is a lightweight PHP framework that replaces platforms like OpenClaw by using Claude Code's CLI (`claude -p`) as the execution engine. Each skill is a markdown file with instructions. AgentCore handles scheduling, logging, Telegram integration, and memory management.

## What It Does

- **Scheduled Skills** — Define AI tasks as markdown files. AgentCore runs them on cron schedules with configurable models and budget caps.
- **Telegram Bot** — Control your agent from your phone. Run skills, check status, or have free-form conversations.
- **Web UI** — View and edit skills, manage schedules, browse run history.
- **Memory System** — Three-tier memory (daily status, long-term patterns, per-skill learnings) that stays clean through weekly curation.
- **Token Optimization** — Each skill run is a fresh session. No accumulated context. Per-skill model selection and budget caps.

## Architecture

```
Windows Task Scheduler / cron
    |
    +-- scheduler.bat (every 5 min)
    |       |
    |       v
    |   Scheduler.php
    |       | checks skill_schedules for due skills
    |       v
    |   SkillRunner.php
    |       | claude -p --model X --max-turns Y --max-budget-usd Z
    |       v
    |   Claude executes skill -> writes to your DB -> exits
    |       |
    |       v
    |   Logs result to skill_runs table
    |
    +-- telegram-bot.bat (runs at startup)
            |
            v
        TelegramBot.php (long-polling)
            | /run, /status, /list, free-text chat
```

## Requirements

- PHP 8.0+
- MySQL 8.0+
- [Claude Code CLI](https://docs.anthropic.com/en/docs/claude-code) with an active subscription
- Windows (Task Scheduler) or Linux (cron)

## Quick Start

### 1. Clone

```bash
git clone https://github.com/rfrench2k/AgentCore.git
cd AgentCore
```

### 2. Configure

```bash
cp config/config.local.php.example config/config.local.php
```

Edit `config/config.local.php` with your MySQL credentials and paths.

### 3. Create Database

Create a MySQL database called `agentcore`, then run the migration:

```bash
php migrations/migrate.php
```

### 4. Create a Skill

```bash
mkdir -p /path/to/your/project/skills/hello-world
```

Create `skills/hello-world/SKILL.md`:

```markdown
---
name: hello-world
description: A simple test skill that confirms AgentCore is working.
---

# Hello World

Say "AgentCore is running!" and report the current date and time.
```

### 5. Schedule It

```bash
php src/Scheduler.php --run hello-world
```

### 6. Set Up Recurring Schedules

Add a schedule via the web UI at `http://localhost/agentcore/web/skills.php`, or insert directly:

```sql
INSERT INTO skill_schedules (skill_name, cron_expression, model, max_budget_usd)
VALUES ('hello-world', '0 9 * * *', 'haiku', 0.25);
```

Then add `scripts/scheduler.bat` to Windows Task Scheduler (every 5 minutes).

### 7. Telegram (Optional)

Create a bot via [@BotFather](https://t.me/BotFather), add the token to `config.local.php`, then start the bot:

```bash
php src/TelegramBot.php
```

## Skill Format

Skills are directories containing a `SKILL.md` file with YAML frontmatter:

```markdown
---
name: my-skill
description: What this skill does (shown in /list and web UI)
allowed-tools:
  - Bash
  - Read
  - WebSearch
effort: high
---

# My Skill

Instructions for Claude go here. Be specific about:
- What data to access
- What analysis to perform
- Where to write results
```

Optional `LEARNINGS.md` in the same directory accumulates operational knowledge across runs.

## Token Optimization

AgentCore is designed to minimize token usage:

| Strategy | How |
|----------|-----|
| Fresh sessions | Each `claude -p` call starts clean — no accumulated context |
| Per-skill models | Use `haiku` for simple tasks, `sonnet` for complex ones |
| Budget caps | `--max-budget-usd` prevents runaway spending |
| Turn limits | `--max-turns` caps agentic loops |
| Minimal CLAUDE.md | Keep it under 20 lines — loaded every run |
| On-demand learnings | LEARNINGS.md loaded only by its own skill |

## Project Structure

```
agentcore/
├── config/                  Configuration system
├── src/                     Core PHP classes
│   ├── AgentCore.php        Config, DB, path resolution
│   ├── Scheduler.php        Checks due skills, runs them
│   ├── SkillRunner.php      Invokes claude -p with flags
│   ├── CronExpression.php   Lightweight cron parser
│   ├── TelegramBot.php      Long-polling Telegram bot
│   ├── TelegramApi.php      Telegram API wrapper
│   ├── MemoryManager.php    STATUS/MEMORY/archive management
│   └── Logger.php           File-based logging
├── web/                     Skills manager web UI
├── migrations/              Database schema
├── scripts/                 OS-level wrappers (.bat/.sh)
├── templates/               Example skills and config
└── docs/                    Documentation
```

## License

MIT
