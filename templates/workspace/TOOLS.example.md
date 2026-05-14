# TOOLS.md — Tools and Access

How scheduled skills (and the bot) can read and write data. The Telegram bot and SkillRunner
inject this file so the model knows the actual connection strings, paths, and tool patterns
to use instead of guessing.

Replace the examples below with your own setup. Delete sections that don't apply.

## Databases

### app_main (full read/write)

- Host: `127.0.0.1`, Port: `5432`
- User / pass: `APP_DB_USER` / `APP_DB_PASS` env vars (loaded from your workspace `.env`)
- DB: `app_main`
- Notable tables: `users`, `events`, `subscriptions`, `usage_log`
- Migrations live in `<your-app>/migrations/` — never apply migrations from a skill.

### analytics_prod (READ-ONLY)

- Host: `analytics.internal`, Port: `5432`
- Same creds as app_main
- DB: `analytics_prod`
- Notable tables: `daily_active`, `feature_use`, `cohort_retention`
- **Never run INSERT/UPDATE/DELETE here** — this is the production analytics warehouse.

## Command-line tools

```bash
# Postgres client — use this exact form so skills don't have to discover it
psql -h "$PG_HOST" -p "$PG_PORT" -U "$APP_DB_USER" "$APP_DB_NAME"
```

- `psql` is at `/usr/bin/psql` on Linux, `C:\Program Files\PostgreSQL\16\bin\psql.exe` on Windows.
- `jq` is available for JSON munging — prefer it over manual string parsing.

## External APIs

### GitHub

- Auth: personal access token in `$GITHUB_TOKEN` (workspace .env)
- Base URL: `https://api.github.com`
- Rate limit: 5000 req/hr authenticated. Skills should batch.
- Use the `gh` CLI when possible — it handles auth + pagination automatically.

### OpenWeather (free tier)

- Auth: `$OPENWEATHER_API_KEY`
- Base URL: `https://api.openweathermap.org/data/2.5`
- Rate limit: 60 calls/minute. Cache responses for at least 10 minutes.

## File locations

- **Workspace root:** `/path/to/workspace` (also `paths.project_root` in agentcore config)
- **Daily memory archive:** `<workspace>/memory/` — one `.md` per day, written by the
  orchestrator
- **Documents:** `<workspace>/documents/` — long-form references skills can read but
  shouldn't modify
- **Logs:** `<workspace>/logs/` — append-only, rotated weekly
