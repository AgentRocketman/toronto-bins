<?php
/**
 * Agentado Tunnel URL Update Endpoint
 * POST: { url: "new-tunnel-url", token: "..." }
 * Updates the tunnel URL so preview generation survives tunnel restarts.
 */

// Simple shared secret
define('UPDATE_TOKEN', 'agtunnel_upd_2026');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('POST only');
}

$body = json_decode(file_get_contents('php://input'), true);
if (($body['token'] ?? '') !== UPDATE_TOKEN) {
    http_response_code(403);
    exit('Forbidden');
}

$url = $body['url'] ?? '';
if (!$url || !preg_match('#^https://.+\.trycloudflare\.com$#', $url)) {
    http_response_code(400);
    exit('Invalid URL');
}

$configFile = __DIR__ . '/tunnel-url.txt';
file_put_contents($configFile, $url);
chmod($configFile, 0644);

echo "OK: tunnel URL updated to $url";