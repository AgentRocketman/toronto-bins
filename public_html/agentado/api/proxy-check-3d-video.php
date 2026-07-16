<?php
/**
 * Hostinger-side proxy for 3D video progress check.
 * Frontend calls THIS (on Hostinger Apache), not the Docker container directly.
 * Proxies to Docker via tunnel with automatic failover.
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-cache, no-store, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$jobId = $_GET['job'] ?? '';
if (!$jobId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing job ID']);
    exit;
}

require_once __DIR__ . '/video/tunnel-config.php';

$tunnelResult = tunnelRequest('/agentado/api/check-3d-video.php?job=' . urlencode($jobId), [], 15, 3);

if (!$tunnelResult['response']) {
    http_response_code(502);
    echo json_encode([
        'status' => 'retrying',
        'error' => 'Progress service temporarily unavailable',
        'retry' => true,
    ]);
    exit;
}

// Fix result URL: override absolute tunnel URL with relative path
$response = json_decode($tunnelResult['response'], true);
if ($response && !empty($response['result_url'])) {
    $parsed = parse_url($response['result_url']);
    $path = $parsed['path'] ?? '';
    if ($path) {
        $response['result_url'] = $path;
    }
}
echo json_encode($response);