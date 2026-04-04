---
name: weekly-synthesis
description: Weekly memory curation — review the week's archives and LEARNINGS.md files, update MEMORY.md with long-term insights, prune stale entries.
allowed-tools:
  - Bash
  - Read
  - Write
  - Glob
  - Grep
effort: high
---

# Weekly Synthesis

You are the memory curator. Your job is to review the past week and update long-term memory.

## Steps

### 1. Archive Current MEMORY.md

Before making changes, the current MEMORY.md should be preserved. Copy it to memory/archive/weekly/ with this week's identifier.

### 2. Read This Week's Data

- Read all files in memory/archive/ from the past 7 days (daily STATUS snapshots)
- Read all skills/*/LEARNINGS.md files
- Read the current MEMORY.md

### 3. Analyze

Ask yourself three questions:

**What's new?**
- Any pattern that showed up this week that isn't in MEMORY.md yet
- New insights about users, products, operations, or the system itself

**What's resolved?**
- Anything in MEMORY.md that is no longer true or relevant
- Issues that were fixed, patterns that stopped recurring

**What's redundant?**
- Multiple entries saying the same thing in different words
- Entries that can be merged or consolidated

### 4. Curate LEARNINGS.md Files

For each skill's LEARNINGS.md:
- Remove entries older than 30 days that are no longer relevant
- Merge entries that describe the same thing
- Keep the file under 50 entries
- Preserve entries that are still operationally useful

### 5. Rewrite MEMORY.md

**Rewrite the entire file** (not append). The new MEMORY.md should contain only what matters long-term, organized by topic:

```markdown
# Long-Term Memory

## [Topic 1]
- Insight or pattern (with date first observed)

## [Topic 2]
- ...
```

**Constraints:**
- Keep under 5000 words
- Prefer actionable insights over raw observations
- Date-stamp all entries
- Remove anything that's no longer relevant

## Before Finishing

Append a summary of what changed to this skill's LEARNINGS.md:
- How many MEMORY.md entries were added/removed/merged
- How many LEARNINGS.md entries were pruned across all skills
