#!/bin/bash
# Cloudflare quick-tunnel keepalive for OpenClaw /hooks endpoint.
# Restarts cloudflared if it dies. Updates tunnel-url.txt when the URL changes.
#
# Run in the background:
#   nohup bash /data/.openclaw/workspace/tunnel-keepalive.sh > /tmp/tunnel-keepalive.log 2>&1 &

set -u
LOG=/tmp/cloudflared.log
URL_FILE=/data/.openclaw/workspace/tunnel-url.txt
GATEWAY_PORT=${OPENCLAW_GATEWAY_PORT:-18789}

while true; do
  if ! pgrep -f "cloudflared tunnel --url http://127.0.0.1:${GATEWAY_PORT}" >/dev/null 2>&1; then
    echo "[$(date -u +%FT%TZ)] Starting cloudflared…" >&2
    nohup cloudflared tunnel --url "http://127.0.0.1:${GATEWAY_PORT}" --no-autoupdate > "$LOG" 2>&1 &
    sleep 10
    URL=$(grep -oE 'https://[a-z0-9-]+\.trycloudflare\.com' "$LOG" | head -1)
    if [ -n "$URL" ]; then
      echo "[$(date -u +%FT%TZ)] Tunnel URL: $URL" >&2
      OLD=$(cat "$URL_FILE" 2>/dev/null | tr -d '\n')
      if [ "$OLD" != "$URL" ]; then
        echo "$URL" > "$URL_FILE"
        echo "[$(date -u +%FT%TZ)] URL changed. Updated $URL_FILE" >&2
      fi
    fi
  fi
  sleep 30
done
