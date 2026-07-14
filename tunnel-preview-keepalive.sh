#!/bin/bash
# Agentado preview tunnel keepalive.
# Restarts cloudflared + preview server if dead.
# Auto-updates Hostinger config when tunnel URL changes.
# Run: nohup bash /data/.openclaw/workspace/tunnel-preview-keepalive.sh > /tmp/tunnel-preview-keepalive.log 2>&1 &

set -euo pipefail
LOG=/tmp/cloudflared-preview.log
URL_FILE=/data/.openclaw/workspace/preview-tunnel-url.txt
PORT=18900
DOMAIN="agentado.agentrocketman.com"
UPDATE_URL="https://${DOMAIN}/api/video/tunnel-update.php"
TOKEN="agtunnel_upd_2026"

while true; do
    # Restart cloudflared if dead
    if ! pgrep -f "cloudflared.*:$PORT " > /dev/null; then
        echo "$(date) cloudflared dead, restarting..." | tee -a /tmp/tunnel-preview-keepalive.log
        : > "$LOG"
        rm -f "$URL_FILE"
        nohup cloudflared tunnel --url "http://127.0.0.1:$PORT" --no-autoupdate > "$LOG" 2>&1 &
        sleep 10
    fi

    # Restart preview server if dead
    if ! pgrep -f agentado-preview-server.py > /dev/null; then
        echo "$(date) preview server dead, restarting..." | tee -a /tmp/tunnel-preview-keepalive.log
        nohup python3 /data/.openclaw/workspace/agentado-preview-server.py > /tmp/preview-server.log 2>&1 &
        sleep 3
    fi

    # Check for URL change and auto-update Hostinger
    NEW_URL=$(grep -oP 'https://[^.]+\.trycloudflare\.com' "$LOG" 2>/dev/null | tail -1 || true)
    if [ -n "$NEW_URL" ]; then
        OLD_URL=$(cat "$URL_FILE" 2>/dev/null || echo '')
        if [ "$NEW_URL" != "$OLD_URL" ]; then
            echo "$(date) Tunnel URL changed: ${OLD_URL:-none} -> $NEW_URL"
            echo "$NEW_URL" > "$URL_FILE"

            # Push to Hostinger endpoint
            HTTP_CODE=$(curl -s -o /dev/null -w '%{http_code}' \
                -X POST "$UPDATE_URL" \
                -H 'Content-Type: application/json' \
                -d "{\"url\":\"$NEW_URL\",\"token\":\"$TOKEN\"}" 2>/dev/null || echo '000')

            if [ "$HTTP_CODE" = "200" ]; then
                echo "$(date) Hostinger config auto-updated: $NEW_URL"
            else
                echo "$(date) WARNING: Hostinger update returned HTTP $HTTP_CODE"
            fi
        fi
    fi

    sleep 15
done