#!/bin/bash
# Cloudflare quick-tunnel keepalive for PHP built-in server on port 9000.
# Restarts if it dies. Updates php-tunnel-url.txt when URL changes.
set -u
LOG=/tmp/cloudflared-php.log
URL_FILE=/data/.openclaw/workspace/php-tunnel-url.txt
DEPLOY_URL_FILE=/data/.openclaw/workspace/public_html/agentado/api/video/tunnel-url.txt
PHP_PORT=9000

while true; do
  # Restart PHP server if dead
  if ! pgrep -f "php -S 0.0.0.0:${PHP_PORT}" >/dev/null 2>&1; then
    echo "[$(date -u +%FT%TZ)] Starting PHP server on port ${PHP_PORT}…" >&2
    # Use 4 workers so video downloads don't block other requests
    PHP_CLI_SERVER_WORKERS=4 nohup php -S 0.0.0.0:${PHP_PORT} -t /data/.openclaw/workspace/public_html/ > /tmp/php-server.log 2>&1 &
  fi

  if ! pgrep -f "cloudflared tunnel --url http://127.0.0.1:${PHP_PORT}" >/dev/null 2>&1; then
    echo "[$(date -u +%FT%TZ)] Starting cloudflared for PHP…" >&2
    nohup cloudflared tunnel --url "http://127.0.0.1:${PHP_PORT}" --no-autoupdate > "$LOG" 2>&1 &
    sleep 10
    URL=$(grep -oE 'https://[a-z0-9-]+\.trycloudflare\.com' "$LOG" | head -1)
    if [ -n "$URL" ]; then
      echo "[$(date -u +%FT%TZ)] PHP Tunnel URL: $URL" >&2
      OLD=$(cat "$URL_FILE" 2>/dev/null | tr -d '\n')
      if [ "$OLD" != "$URL" ]; then
        echo "$URL" > "$URL_FILE"
        echo "$URL" > "$DEPLOY_URL_FILE"
        echo "[$(date -u +%FT%TZ)] URL changed. Updated both URL files" >&2
        # Push to Hostinger
        curl -s -X POST "https://agentado.agentrocketman.com/api/video/tunnel-update.php" \
          -H 'Content-Type: application/json' \
          -d "{\"url\":\"$URL\",\"token\":\"agtunnel_upd_2026\",\"type\":\"php\"}" \
          -o /dev/null -w "[$(date -u +%FT%TZ)] Hostinger push: HTTP %{http_code}\n" || true
      fi
    fi
  fi
  sleep 30
done