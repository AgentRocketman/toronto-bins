---
summary: "Workspace template for TOOLS.md"
read_when:
  - Bootstrapping a workspace manually
---

# TOOLS.md - Local Notes

Skills define _how_ tools work. This file is for _your_ specifics — the stuff that's unique to your setup.

## What Goes Here

Things like:

- Camera names and locations
- SSH hosts and aliases
- Preferred voices for TTS
- Speaker/room names
- Device nicknames
- Anything environment-specific

## Examples

```markdown
### Cameras

- living-room → Main area, 180° wide angle
- front-door → Entrance, motion-triggered

### SSH

- home-server → 192.168.1.100, user: admin

### TTS

- Preferred voice: "Nova" (warm, slightly British)
- Default speaker: Kitchen HomePod
```

## Why Separate?

Skills are shared. Your setup is yours. Keeping them apart means you can update skills without losing your notes, and share skills without leaking your infrastructure.

---

Add whatever helps you do your job. This is your cheat sheet.
### Model Strategy (2026-06-10) — PERMANENT RULE

**ALWAYS route coding through Claude Code CLI, NEVER through Nexos subagents.**

| Task | Route | Billing |
|------|-------|---------|
| Conversation, planning, design | Me (Nexos Opus) | Nexos |
| ALL coding & building | `claude -p "..."` | Anthropic |
| Deploy, git push, Hostinger | Me directly | Nexos (minimal) |

### Claude Code CLI
- Binary: `claude` (globally installed)
- API key: `~/.anthropic_key` (auto-loaded via `~/.bashrc`)
- Usage: `export ANTHROPIC_API_KEY=$(cat ~/.anthropic_key) && claude -p "<task>" --allowedTools "Bash(command)" "Write(file_path, content)" "Read(file_path)"`
- Run in the project directory so Claude Code has file context
- For big tasks, use `claude -p` with detailed specs

### Background: ALL DISABLED
- Heartbeat: off (`every: "0"`)
- Cron: none
- Auto-updates: off
