<?php
/**
 * Hostinger-side proxy for 3D video start.
 * Frontend calls THIS (on Hostinger Apache), not the Docker container directly.
 * Proxies to Docker via tunnel with automatic failover.
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-cache, no-store, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

require_once __DIR__ . '/video/tunnel-config.php';

$tunnelResult = tunnelRequest('/agentado/api/start-3d-video.php', $_POST, 30, 5);

if (!$tunnelResult['response']) {
    http_response_code(502);
    echo json_encode([
        'error' => 'Video service is temporarily unavailable. Please try again.',
        'retry' => true,
    ]);
    exit;
}

echo $tunnelResult['response'];