#!/bin/bash
# Cloudflare quick-tunnel keepalive for PHP built-in server on port 9000.
# Restarts if it dies. Updates php-tunnel-url.txt when URL changes.
set -u
LOG=/tmp/cloudflared-php.log
URL_FILE=/data/.openclaw/workspace/php-tunnel-url.txt
PHP_PORT=9000

while true; do
  # Restart PHP server if dead
  if ! pgrep -f "php -S 0.0.0.0:${PHP_PORT}" >/dev/null 2>&1; then
    echo "[$(date -u +%FT%TZ)] Starting PHP server on port ${PHP_PORT}…" >&2
    nohup php -S 0.0.0.0:${PHP_PORT} -t /data/.openclaw/workspace/public_html/ > /tmp/php-server.log 2>&1 &
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
        echo "[$(date -u +%FT%TZ)] URL changed. Updated $URL_FILE" >&2
      fi
    fi
  fi
  sleep 30
done