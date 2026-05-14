# MEMORY.md — Global Operational Rules

Long-term system rules that apply across every scheduled skill and every Telegram
conversation. Quiet hours, scope rules, cross-cutting "always/nevers". The Telegram bot's
`[CAPTURE:memory]` tags append here.

This is the curated cross-skill memory — keep it tight. Rules specific to one skill belong
in that skill's `LEARNINGS.md` instead.

## Operating rules

> Replace these with your own. Each rule should be short, durable, and apply to more than
> one skill (otherwise it belongs in a skill's LEARNINGS.md).

- **Quiet hours 10pm–7am ET.** No Telegram messages from any skill during this window.
- **Read-only databases:** `analytics_prod`, `billing_replica`. Any skill that touches these
  must use SELECT only — no INSERT/UPDATE/DELETE.
- **Cost ceiling for ad-hoc skill runs:** flag any single run that exceeds $0.50 in the
  daily orchestrator summary.
- **Dates are absolute, not relative.** Convert "yesterday" / "next Thursday" to YYYY-MM-DD
  before writing to any file. Relative dates rot.

## Captures

<!-- The Telegram bot appends captured memory rules here. -->
