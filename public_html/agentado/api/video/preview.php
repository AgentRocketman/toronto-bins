<?php
/**
 * Agentado Video Preview — generates a watermarked Ken Burns preview
 * POST: listingData (JSON), previewPhotos (JSON: [{url}])
 * Returns: { videoUrl, jobId }
 */
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/tunnel-config.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$listingData = json_decode($_POST['listingData'] ?? '{}', true);
$previewPhotos = json_decode($_POST['previewPhotos'] ?? '[]', true);
if (empty($previewPhotos)) {
    http_response_code(400);
    echo json_encode(['error' => 'No photos provided']);
    exit;
}

// Generate job ID
$jobId = 'prev_' . bin2hex(random_bytes(8));
$outDir = __DIR__ . '/../../output/previews';
if (!is_dir($outDir)) mkdir($outDir, 0755, true);
$outFile = "$outDir/$jobId.mp4";
$outUrl = "/api/video/serve.php?id=$jobId";

// Build Python path for image download + overlay generation
$workDir = sys_get_temp_dir() . "/$jobId";
mkdir($workDir, 0755, true);

// Download photos
$photoPaths = [];
$i = 0;
foreach (array_slice($previewPhotos, 0, 3) as $p) {
    $url = $p['url'] ?? '';
    if (!$url) continue;
    $dest = "$workDir/p$i.jpg";
    // Download via curl
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'Mozilla/5.0',
    ]);
    $imgData = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 200 && $imgData) {
        file_put_contents($dest, $imgData);
        $photoPaths[] = $dest;
    }
    $i++;
}

if (empty($photoPaths)) {
    rmdirRecursive($workDir);
    http_response_code(400);
    echo json_encode(['error' => 'Could not download any photos']);
    exit;
}

// Forward to container preview server (exec() disabled on Hostinger)
$tunnelUrl = $AGENTADO_TUNNEL;
$ch = curl_init($tunnelUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $_POST,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,    // fast fail if tunnel is down
    CURLOPT_TIMEOUT => 90,           // generation takes ~30s
]);
$genResult = curl_exec($ch);
$genCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$genError = curl_error($ch);
curl_close($ch);

if ($genCode !== 200 || !$genResult) {
    rmdirRecursive($workDir);
    http_response_code(502);
    echo json_encode(['error' => 'Preview generation service unavailable. Please try again.']);
    exit;
}

$genData = json_decode($genResult, true);
if (!$genData || isset($genData['error'])) {
    rmdirRecursive($workDir);
    http_response_code(500);
    echo $genResult;
    exit;
}

// Decode video from base64 in JSON response (avoids Cloudflare-blocked GET download)
if (!empty($genData['videoData'])) {
    $videoData = base64_decode($genData['videoData']);
    if ($videoData && strlen($videoData) > 1024) {
        file_put_contents($outFile, $videoData);
    }
}

rmdirRecursive($workDir);

if (file_exists($outFile)) {
    echo json_encode([
        'videoUrl' => $outUrl . '?t=' . time(),
        'jobId' => $jobId,
    ]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Preview generation failed — could not download result']);
}

function rmdirRecursive($dir) {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = "$dir/$item";
        is_dir($path) ? rmdirRecursive($path) : unlink($path);
    }
    rmdir($dir);
}