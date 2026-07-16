<?php
/**
 * Agentado Full Video Generator — forwards to Docker tunnel (no exec() needed)
 * POST: tier, listingData, photoOrder, email, sessionId, photoCount
 * Returns: { downloadUrl, jobId }
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$tier = $_POST['tier'] ?? 'kenburns';
$listingData = $_POST['listingData'] ?? '{}';
$photoOrder = $_POST['photoOrder'] ?? '[]';
$email = $_POST['email'] ?? '';
$sessionId = $_POST['sessionId'] ?? '';
$photoCount = intval($_POST['photoCount'] ?? 0);

$photos = json_decode($photoOrder, true);
if (empty($photos)) {
    http_response_code(400);
    echo json_encode(['error' => 'No photos provided']);
    exit;
}

// ── Verify order is paid ──
$sessionsDir = __DIR__ . '/../../../data/orders';
$orderFile = "$sessionsDir/$sessionId.json";
if (!file_exists($orderFile)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid session']);
    exit;
}
$order = json_decode(file_get_contents($orderFile), true);
if (($order['status'] ?? '') !== 'paid') {
    http_response_code(402);
    echo json_encode(['error' => 'Payment required']);
    exit;
}

// ── Load tunnel URL ──
require_once __DIR__ . '/tunnel-config.php';
$tunnelUrl = rtrim($AGENTADO_TUNNEL, '/');

// ── Generate job ID ──
$jobId = 'gen_' . bin2hex(random_bytes(8));
$outDir = __DIR__ . '/../../../output/videos';
if (!is_dir($outDir)) { mkdir($outDir, 0755, true); }
$outFile = "$outDir/$jobId.mp4";
$outUrl = "/agentado/output/videos/$jobId.mp4";

// ── Forward to tunnel with failover ──
$tunnelResult = tunnelRequest('/agentado/api/generate.php', [
    'mode' => 'generate',
    'tier' => $tier,
    'listingData' => $listingData,
    'photoOrder' => $photoOrder,
    'photoCount' => $photoCount,
], 3600, 10);

if (!$tunnelResult['response']) {
    http_response_code(502);
    echo json_encode([
        'error' => 'Generation service is temporarily unavailable. Please try again in a few seconds.',
        'retry' => true,
    ]);
    exit;
}

$genResult = $tunnelResult['response'];
$genData = json_decode($genResult, true);
if (!$genData || isset($genData['error'])) {
    http_response_code(500);
    echo $genResult;
    exit;
}

// ── Decode video from base64 ──
if (!empty($genData['videoData'])) {
    $videoData = base64_decode($genData['videoData']);
    if ($videoData && strlen($videoData) > 1024) {
        file_put_contents($outFile, $videoData);
    }
}

// ── Update order ──
if (file_exists($outFile)) {
    $order['status'] = 'completed';
    $order['downloadUrl'] = $outUrl;
    $order['completedAt'] = date('c');
    file_put_contents($orderFile, json_encode($order, JSON_PRETTY_PRINT));

    echo json_encode([
        'downloadUrl' => $outUrl . '?t=' . time(),
        'jobId' => $jobId,
    ]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Generation failed — could not save result']);
}