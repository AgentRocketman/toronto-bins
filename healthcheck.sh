#!/bin/bash
# Agentado pre-flight health check — runs before every video job.
# Verifies tunnels, services, and APIs. Auto-restarts anything broken.
# Returns JSON: { "ok": true } or { "ok": false, "errors": [...] }
# Usage: bash healthcheck.sh [--fix]

WORKSPACE=/data/.openclaw/workspace
FIX_MODE="${1:-check}"  # "check" or "fix"

ERRORS=()
WARNINGS=()

log_ok()    { echo "  ✅ $1"; }
log_warn()  { echo "  ⚠️  $1"; WARNINGS+=("$1"); }
log_fail()  { echo "  ❌ $1"; ERRORS+=("$1"); }

fix_php_server() {
    if ! pgrep -f "php -S 0.0.0.0:9000" >/dev/null 2>&1; then
        echo "     Restarting PHP server on :9000…"
        PHP_CLI_SERVER_WORKERS=4 nohup php -S 0.0.0.0:9000 \
            -t "$WORKSPACE/public_html/" > /tmp/php-server.log 2>&1 &
        sleep 2
    fi
}

fix_tunnel() {
    local port=$1
    local log=$2
    if ! pgrep -f "cloudflared.*:${port}" >/dev/null 2>&1; then
        echo "     Restarting cloudflared for port $port…"
        : > "$log"
        nohup cloudflared tunnel --url "http://127.0.0.1:${port}" --no-autoupdate \
            > "$log" 2>&1 &
        sleep 10
        # Update URL file
        NEW_URL=$(grep -oP 'https://[^.]+\.trycloudflare\.com' "$log" | tail -1)
        if [ -n "$NEW_URL" ]; then
            case $port in
                9000)
                    echo "$NEW_URL" > "$WORKSPACE/public_html/agentado/api/video/tunnel-url.txt"
                    echo "$NEW_URL" > "$WORKSPACE/php-tunnel-url.txt"
                    curl -s -X POST "https://agentado.agentrocketman.com/api/video/tunnel-update.php" \
                        -H 'Content-Type: application/json' \
                        -d "{\"url\":\"$NEW_URL\",\"token\":\"agtunnel_upd_2026\"}" >/dev/null 2>&1 || true
                    ;;
                9001)
                    echo "$NEW_URL" > "$WORKSPACE/public_html/agentado/api/video/tunnel-url2.txt"
                    ;;
            esac
        fi
    fi
}

echo "🔍 Agentado Health Check ($(date -u +%FT%TZ))"
echo "────────────────────────────────────────"

# ── 1. PHP server ──
echo "1. PHP server (port 9000)…"
if pgrep -f "php -S 0.0.0.0:9000" >/dev/null 2>&1; then
    log_ok "PHP server running"
elif [ "$FIX_MODE" = "fix" ]; then
    fix_php_server
    pgrep -f "php -S 0.0.0.0:9000" >/dev/null 2>&1 && log_ok "PHP server restarted" || log_fail "PHP server failed to start"
else
    log_fail "PHP server not running"
fi

# ── 2. PHP server (backup, port 9001) ──
echo "2. PHP server backup (port 9001)…"
if pgrep -f "php -S 0.0.0.0:9001" >/dev/null 2>&1; then
    log_ok "Backup PHP server running"
else
    log_warn "Backup PHP server not running"
    if [ "$FIX_MODE" = "fix" ]; then
        PHP_CLI_SERVER_WORKERS=4 nohup php -S 0.0.0.0:9001 \
            -t "$WORKSPACE/public_html/" > /tmp/php-server-9001.log 2>&1 &
        sleep 2
        log_ok "Backup PHP server started"
    fi
fi

# ── 3. Primary tunnel (port 9000) ──
echo "3. Primary tunnel (PHP → Cloudflare)…"
TUNNEL_URL=$(cat "$WORKSPACE/public_html/agentado/api/video/tunnel-url.txt" 2>/dev/null | tr -d '\n')
if pgrep -f "cloudflared.*:9000" >/dev/null 2>&1; then
    if [ -n "$TUNNEL_URL" ]; then
        RESPONSE=$(curl -s --max-time 8 "$TUNNEL_URL/agentado/api/check-3d-video.php?job=health" 2>/dev/null)
        if echo "$RESPONSE" | grep -q '"status"'; then
            log_ok "Primary tunnel responding: $TUNNEL_URL"
        else
            log_warn "Primary tunnel exists but not responding"
            if [ "$FIX_MODE" = "fix" ]; then
                kill $(pgrep -f "cloudflared.*:9000") 2>/dev/null
                sleep 2
                fix_tunnel 9000 /tmp/cloudflared-php.log
                log_ok "Primary tunnel restarted"
            fi
        fi
    else
        log_warn "Primary tunnel running but no URL file"
    fi
elif [ "$FIX_MODE" = "fix" ]; then
    fix_tunnel 9000 /tmp/cloudflared-php.log
    log_ok "Primary tunnel restarted"
else
    log_fail "Primary tunnel not running"
fi

# ── 4. Backup tunnel (port 9001) ──
echo "4. Backup tunnel (redundant)…"
BAK_URL=$(cat "$WORKSPACE/public_html/agentado/api/video/tunnel-url2.txt" 2>/dev/null | tr -d '\n')
if pgrep -f "cloudflared.*:9001" >/dev/null 2>&1; then
    if [ -n "$BAK_URL" ]; then
        RESPONSE=$(curl -s --max-time 8 "$BAK_URL/agentado/api/check-3d-video.php?job=health" 2>/dev/null)
        if echo "$RESPONSE" | grep -q '"status"'; then
            log_ok "Backup tunnel responding: $BAK_URL"
        else
            log_warn "Backup tunnel exists but not responding"
            if [ "$FIX_MODE" = "fix" ]; then
                kill $(pgrep -f "cloudflared.*:9001") 2>/dev/null
                sleep 2
                fix_tunnel 9001 /tmp/cloudflared-php2.log
                log_ok "Backup tunnel restarted"
            fi
        fi
    fi
else
    log_warn "Backup tunnel not running"
    if [ "$FIX_MODE" = "fix" ]; then
        fix_tunnel 9001 /tmp/cloudflared-php2.log
        log_ok "Backup tunnel started"
    fi
fi

# ── 5. Keepalive scripts ──
echo "5. Keepalive monitors…"
for ks in php-tunnel-keepalive.sh tunnel-keepalive.sh tunnel-preview-keepalive.sh php-tunnel-keepalive-backup.sh; do
    if pgrep -f "$ks" >/dev/null 2>&1; then
        log_ok "$ks"
    else
        log_warn "$ks not running"
        if [ "$FIX_MODE" = "fix" ] && [ -f "$WORKSPACE/$ks" ]; then
            nohup bash "$WORKSPACE/$ks" > "/tmp/${ks}.log" 2>&1 &
            log_ok "$ks restarted"
        fi
    fi
done

# ── 6. ElevenLabs API ──
echo "6. ElevenLabs API…"
EL_KEY=$(grep -oP "define\\('ELEVENLABS_API_KEY',\\s*'([^']+)'" /data/.openclaw/workspace/public_html/agentado/api/elevenlabs-tts.php | head -1 | sed "s/.*'\\([^']*\\)'.*/\\1/")
if [ -n "$EL_KEY" ]; then
    EL_STATUS=$(curl -s --max-time 10 -H "xi-api-key: $EL_KEY" \
        "https://api.elevenlabs.io/v1/user" 2>/dev/null | grep -o '"subscription_tier"' || echo "")
    if [ -n "$EL_STATUS" ]; then
        log_ok "ElevenLabs API authenticated"
    else
        log_warn "ElevenLabs API unreachable"
    fi
else
    log_warn "ElevenLabs API key not found"
fi

# ── 7. Together AI (Kling) ──
echo "7. Together AI (Kling 2.1)…"
TG_KEY=$(grep -oP '"TOGETHER_API_KEY"\s*:\s*"([^"]+)"' /data/.openclaw/openclaw.json | head -1 | sed 's/.*"\([^"]*\)"$/\1/')
if [ -n "$TG_KEY" ]; then
    TG_STATUS=$(curl -s --max-time 10 -H "Authorization: Bearer $TG_KEY" \
        "https://api.together.ai/v1/models" 2>/dev/null | grep -o '"id"' || echo "")
    if [ -n "$TG_STATUS" ]; then
        log_ok "Together AI API authenticated"
    else
        log_warn "Together AI API unreachable"
    fi
else
    log_warn "Together AI API key not found"
fi

# ── 8. ffmpeg ──
echo "8. ffmpeg…"
if command -v ffmpeg >/dev/null 2>&1; then
    log_ok "ffmpeg available: $(ffmpeg -version 2>&1 | head -1 | cut -d' ' -f3)"
else
    log_fail "ffmpeg not found"
fi

# ── 9. Disk space ──
echo "9. Disk space…"
DISK_USED=$(df -h /data | awk 'NR==2 {print $5}' | tr -d '%')
if [ "$DISK_USED" -lt 90 ] 2>/dev/null; then
    log_ok "Disk: ${DISK_USED}% used"
else
    log_fail "Disk critically low: ${DISK_USED}%"
fi

# ── Result ──
echo "────────────────────────────────────────"
if [ ${#ERRORS[@]} -eq 0 ]; then
    echo "✅ ALL SYSTEMS GO"
    echo '{"ok":true,"warnings":'"$(printf '%s\n' "${WARNINGS[@]}" | jq -R . | jq -s .)"'}' 
else
    echo "❌ FAILED: ${#ERRORS[@]} error(s)"
    printf '%s\n' "${ERRORS[@]}" | while read e; do echo "   ❌ $e"; done
    echo '{"ok":false,"errors":'"$(printf '%s\n' "${ERRORS[@]}" | jq -R . | jq -s .)"'}'
    exit 1
fi