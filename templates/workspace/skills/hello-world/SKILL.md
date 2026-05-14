---
name: hello-world
description: A simple test skill that confirms AgentCore is wired up correctly.
allowed-tools:
  - Bash
  - Read
effort: low
---

# Hello World

Confirm AgentCore is running.

Print a single line: `AgentCore is running! Today is <day>, <date>.`

That's the entire task — no database calls, no file writes, no external requests. If this
runs successfully (status `completed` in `skill_runs`, non-empty `output_full`), the
framework is correctly configured.
