---
name: prune-learnings
description: Weekly LEARNINGS.md pruner. Walks every skill's LEARNINGS.md, dedupes, drops stale or contradicted entries, organizes by topic. Backs up before writing.
allowed-tools:
  - Read
  - Write
  - Glob
  - Bash
max-turns: 30
effort: medium
---

# Prune LEARNINGS.md (weekly)

Per-skill `LEARNINGS.md` files grow unbounded — every Telegram capture appends, every skill run can append. Without periodic pruning, contradictions accumulate, token costs rise on every run, and eventually the model starts ignoring parts of the system prompt silently.

This skill prunes them.

## Step 1: Discover candidates in ONE command

```bash
find <workspace>/skills -name LEARNINGS.md -size +1024c -not -path '*/prune-learnings/*'
```

This returns ONLY files >1KB and excludes this skill's own LEARNINGS.md (handled in Step 5).

## Step 2: Fast path — nothing to prune

**If Step 1 returns no files**, you're done with the per-file work. Skip directly to Step 5 with `K=0, N=0`. Do NOT read or evaluate each individual LEARNINGS file. The size filter has already decided.

## Step 3: For each file from Step 1 (only if non-empty)

For each path returned:

1. **Back up.** `cp <file> <file>.bak-YYYY-MM-DD` (skip if today's backup already exists).
2. **Read** the file.
3. **Dedupe** near-identical entries — keep the most recent date.
4. **Resolve contradictions** — newer date wins; drop older. If intent unclear, keep both.
5. **Age out** entries older than 90 days **unless** a newer entry on the same topic exists.
6. **Reorganize** by topic with markdown sub-headings. Keep the original date prefix on each entry.
7. **Hard cap** 50 entries. Keep the newest 50 if still over.
8. **Safety check** — if the file shrunk by more than 75%, restore from the backup (`cp <backup> <file>`) and log a warning. Don't trust your own pruning that aggressive.
9. **Write back.**
10. Append one line to `<workspace>/STATUS.md` under `## Skill Runs`:
    ```
    - **prune-learnings** — pruned <skill>: N → M entries
    ```

## Step 4: Trim old backups

```bash
find <workspace>/skills -name 'LEARNINGS.md.bak-*' -mtime +60 -delete
```

(Two months of rollback is plenty. Always-skips today's backup.)

## Step 5: Self-summary

Append exactly ONE line to `<workspace>/skills/prune-learnings/LEARNINGS.md`:

```
- **YYYY-MM-DD** — Pruned K files. Removed N entries total.
```

Then exit. Do not re-read files, do not summarize, do not check anything else.

## Rules

- Do NOT send Telegram messages.
- Never output HEARTBEAT_OK.
- The fast path (Step 2) is the common case. Do not turn it into the slow path by reading every file "just to check" — `find -size +1024c` already checked.
