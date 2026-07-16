#!/bin/bash
# Agentado SECOND PHP server (port 9001) + cloudflared tunnel — redundant failover.
# If the primary tunnel (port 9000) goes down, Hostinger PHP auto-fails over to this one.
# Restart window: ~15 seconds.

PHP_PORT=9001
CF_PORT=9001  # cloudflared targets this
WORKSPACE=/data/.openclaw/workspace
TUNNEL_FILE="$WORKSPACE/public_html/agentado/api/video/tunnel-url2.txt"
LOG_FILE="/tmp/php-server-9001.log"
CF_LOG="/tmp/cloudflared-php2.log"

echo "[backup-tunnel] $(date -u +%FT%TZ) Keepalive started for backup tunnel"

while true; do
    # ── Ensure PHP server is running on port 9001 ──
    if ! pgrep -f "php -S 0.0.0.0:${PHP_PORT}" > /dev/null 2>&1; then
        echo "[backup-tunnel] $(date -u +%FT%TZ) Starting PHP server on :${PHP_PORT}…"
        PHP_CLI_SERVER_WORKERS=4 nohup php -S 0.0.0.0:${PHP_PORT} \
            -t "$WORKSPACE/public_html/" > "$LOG_FILE" 2>&1 &
        sleep 2
    fi

    # ── Ensure cloudflared tunnel is running for port 9001 ──
    if ! pgrep -f "cloudflared.*:${CF_PORT}" > /dev/null 2>&1; then
        echo "[backup-tunnel] $(date -u +%FT%TZ) Starting cloudflared for :${CF_PORT}…"
        : > "$CF_LOG"
        nohup cloudflared tunnel --url "http://127.0.0.1:${CF_PORT}" --no-autoupdate \
            > "$CF_LOG" 2>&1 &
        sleep 10

        NEW_URL=$(grep -oP 'https://[^.]+\.trycloudflare\.com' "$CF_LOG" | head -1)
        if [ -n "$NEW_URL" ]; then
            echo "$NEW_URL" > "$TUNNEL_FILE"
            echo "[backup-tunnel] $(date -u +%FT%TZ) New URL: $NEW_URL"

            # Push to Hostinger
            curl -s -X POST "https://agentado.agentrocketman.com/api/video/tunnel-update.php" \
                -H 'Content-Type: application/json' \
                -d "{\"url\":\"$NEW_URL\",\"token\":\"agtunnel_upd_2026\"}" \
                -o /dev/null -w "[backup-tunnel] Hostinger push: HTTP %{http_code}\n" || true
        fi
    fi

    sleep 15
done