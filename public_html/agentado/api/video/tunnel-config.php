<?php
// Agentado tunnel config — auto-updated via tunnel-update.php when cloudflared URL changes
// Fallback: if tunnel-url.txt doesn't exist yet, use the hardcoded URL
$tunnelFile = __DIR__ . '/tunnel-url.txt';
if (file_exists($tunnelFile)) {
    $AGENTADO_TUNNEL = trim(file_get_contents($tunnelFile));
} else {
    $AGENTADO_TUNNEL = 'https://catalogs-associated-deadline-cornwall.trycloudflare.com';
}