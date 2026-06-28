#!/bin/bash
# Ensures builder-runner.py is alive. If not, starts it via setsid (full detach).
# Run from cron every minute.

RUNNER=/data/.openclaw/workspace/builder-runner.py
LOG=/data/.openclaw/workspace/builder-runner.log

if pgrep -f "$RUNNER" > /dev/null 2>&1; then
    exit 0
fi

# Not running — start it fully detached
echo "[$(date -Is)] Watchdog: starting runner" >> "$LOG"
setsid nohup python3 -u "$RUNNER" >> "$LOG" 2>&1 < /dev/null &
disown 2>/dev/null
exit 0
