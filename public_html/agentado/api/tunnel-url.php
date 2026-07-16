<?php
/**
 * Agentado Tunnel URLs — dynamically reads from auto-updated files.
 * Returns both primary and backup tunnel URLs for the frontend.
 * The frontend should use proxy-*.php for all API calls, but uses
 * tunnel URLs directly for video file downloads.
 */
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

$tunnelDir = __DIR__ . '/video';
$primaryFile = "$tunnelDir/tunnel-url.txt";
$backupFile = "$tunnelDir/tunnel-url2.txt";

$primary = file_exists($primaryFile) ? trim(file_get_contents($primaryFile)) : null;
$backup = file_exists($backupFile) ? trim(file_get_contents($backupFile)) : null;

// Fallback if primary missing
if (!$primary) {
    require_once __DIR__ . '/video/tunnel-config.php';
    $primary = $AGENTADO_TUNNEL;
}

echo json_encode([
    'url' => $primary,
    'backup' => $backup,
    'proxy' => true,  // frontend flag: use proxy-*.php for API calls
]);