# Workspace — CLAUDE.md

Loaded by Claude Code (and by `claude -p`) whenever a skill runs with this directory as its
working directory. Keep it short — under 20 lines is ideal. Anything longer eats into every
skill run's context budget.

This is the spot for the *minimum* a skill needs to know without reading TOOLS.md or
MEMORY.md. If the skill needs more, it can read those files explicitly.

## Database access

```bash
# Replace with your own connection pattern — full details belong in TOOLS.md.
psql -h "$PG_HOST" -p "$PG_PORT" -U "$APP_DB_USER" "$APP_DB_NAME"
```

## Rules

- Don't take destructive actions without asking.
- `analytics_prod` is read-only — never INSERT/UPDATE/DELETE there.
- Convert relative dates ("yesterday", "next Thursday") to YYYY-MM-DD before writing them.
