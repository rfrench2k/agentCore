# AgentCore

Open-source framework that turns Claude Code into a personal AI agent system.

PHP 8.0+ / MySQL 8.0+ / Claude Code CLI.

## Structure

- `src/` — Core PHP classes (AgentCore, Scheduler, SkillRunner, TelegramBot, MemoryManager)
- `web/` — Skills manager web UI (standalone + embeddable)
- `config/` — Configuration (env vars + local override)
- `migrations/` — Database schema (own `agentcore` database)
- `scripts/` — .bat/.sh wrappers for OS-level scheduling
- `templates/` — Example skills, persona, CLAUDE.md
