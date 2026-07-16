#!/bin/bash
# Agentado Docker container startup — starts all services on boot.
# Called by Docker entrypoint or manually: bash /data/.openclaw/workspace/startup.sh

set -e
echo "[startup] $(date -u +%FT%TZ) Starting Agentado services…"

# ── PHP built-in server (port 9000) ──
if ! pgrep -f "php -S 0.0.0.0:9000" >/dev/null 2>&1; then
    echo "[startup] Starting PHP server on :9000…"
    # Use 4 workers so video downloads don't block other requests
    PHP_CLI_SERVER_WORKERS=4 nohup php -S 0.0.0.0:9000 -t /data/.openclaw/workspace/public_html/ \
        > /tmp/php-server.log 2>&1 &
    sleep 1
fi

# ── Cloudflared tunnel for PHP server (port 9000 → trycloudflare) ──
if ! pgrep -f "cloudflared.*:9000" >/dev/null 2>&1; then
    echo "[startup] Starting cloudflared for PHP (port 9000)…"
    : > /tmp/cloudflared-php.log
    nohup cloudflared tunnel --url http://127.0.0.1:9000 --no-autoupdate \
        > /tmp/cloudflared-php.log 2>&1 &
    sleep 8
    URL=$(grep -oP 'https://[^.]+\.trycloudflare\.com' /tmp/cloudflared-php.log | head -1)
    if [ -n "$URL" ]; then
        echo "$URL" > /data/.openclaw/workspace/php-tunnel-url.txt
        echo "[startup] PHP tunnel: $URL"
    fi
fi

# ── Agentado preview server (port 18900) ──
if ! pgrep -f agentado-preview-server.py >/dev/null 2>&1; then
    echo "[startup] Starting preview server on :18900…"
    nohup python3 /data/.openclaw/workspace/agentado-preview-server.py \
        > /tmp/preview-server.log 2>&1 &
    sleep 2
fi

# ── Cloudflared tunnel for preview server (port 18900 → trycloudflare) ──
if ! pgrep -f "cloudflared.*:18900" >/dev/null 2>&1; then
    echo "[startup] Starting cloudflared for preview (port 18900)…"
    : > /tmp/cloudflared-preview.log
    nohup cloudflared tunnel --url http://127.0.0.1:18900 --no-autoupdate \
        > /tmp/cloudflared-preview.log 2>&1 &
    sleep 8
    URL=$(grep -oP 'https://[^.]+\.trycloudflare\.com' /tmp/cloudflared-preview.log | head -1)
    if [ -n "$URL" ]; then
        echo "$URL" > /data/.openclaw/workspace/public_html/agentado/api/video/tunnel-url.txt
        echo "$URL" > /data/.openclaw/workspace/preview-tunnel-url.txt
        echo "[startup] Preview tunnel: $URL"
        # Push to Hostinger
        curl -s -X POST "https://agentado.agentrocketman.com/api/video/tunnel-update.php" \
            -H 'Content-Type: application/json' \
            -d "{\"url\":\"$URL\",\"token\":\"agtunnel_upd_2026\"}" \
            -o /dev/null -w " → Hostinger update: HTTP %{http_code}\n" || true
    fi
fi

# ── Cloudflared tunnel for OpenClaw hooks (port 18789) ──
if ! pgrep -f "cloudflared.*:18789" >/dev/null 2>&1; then
    echo "[startup] Starting cloudflared for hooks (port 18789)…"
    : > /tmp/cloudflared.log
    nohup cloudflared tunnel --url http://127.0.0.1:18789 --no-autoupdate \
        > /tmp/cloudflared.log 2>&1 &
fi

# ── Keepalive scripts ──
for ks in php-tunnel-keepalive.sh tunnel-keepalive.sh tunnel-preview-keepalive.sh php-tunnel-keepalive-backup.sh; do
    script="/data/.openclaw/workspace/${ks}.sh"
    if [ -f "$script" ] && ! pgrep -f "$script" >/dev/null 2>&1; then
        echo "[startup] Starting keepalive: $ks"
        nohup bash "$script" > "/tmp/${ks}.log" 2>&1 &
    fi
done

echo "[startup] $(date -u +%FT%TZ) All services started."