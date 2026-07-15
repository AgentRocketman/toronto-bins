<?php
/**
 * ElevenLabs Music Generator
 * Accepts a text prompt → generates instrumental background music via ElevenLabs Music v2 API
 * Caches results by MD5 hash of prompt+duration to avoid re-generating the same track
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$prompt      = trim($input['prompt'] ?? '');
$durationMs  = intval($input['duration_ms'] ?? 60000);
$modelId     = $input['model_id'] ?? 'music_v2';

// Clamp duration: min 3s, max 600s (10 min), default 60s
$durationMs = max(3000, min(600000, $durationMs > 0 ? $durationMs : 60000));

if (!$prompt || strlen($prompt) < 10) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Prompt too short (min 10 chars)']);
    exit;
}

// ElevenLabs API key
$apiKey = 'sk_1b300ca925e3778885f4a8595960423130fe660c3e2d5082';

// Cache directory
$cacheDir = __DIR__ . '/../output/music-cache';
if (!is_dir($cacheDir)) { mkdir($cacheDir, 0755, true); }

$cacheKey    = md5($prompt . '_' . $durationMs . '_' . $modelId);
$cacheFile   = $cacheDir . '/' . $cacheKey . '.mp3';

// Serve from cache if available
if (file_exists($cacheFile) && filesize($cacheFile) > 0) {
    header('Content-Type: audio/mpeg');
    header('Content-Length: ' . filesize($cacheFile));
    header('X-Cache: HIT');
    readfile($cacheFile);
    exit;
}

// Build the request to ElevenLabs Music API
$body = json_encode([
    'prompt'             => $prompt,
    'music_length_ms'    => $durationMs,
    'model_id'           => $modelId,
    'force_instrumental' => true,
    'output_format'      => 'mp3_48000_192',
]);

$ch = curl_init('https://api.elevenlabs.io/v1/music');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_TIMEOUT        => 120,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'xi-api-key: ' . $apiKey,
    ],
    CURLOPT_POSTFIELDS     => $body,
]);

$response = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($httpCode !== 200) {
    // Try to decode error response
    $err = json_decode($response, true);
    $detail = $err['detail']['message'] ?? $err['error'] ?? 'Unknown error';
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode([
        'error'  => 'ElevenLabs Music API error',
        'code'   => $httpCode,
        'detail' => $detail,
    ]);
    exit;
}

// Save to cache
file_put_contents($cacheFile, $response);

// Return the audio
header('Content-Type: audio/mpeg');
header('Content-Length: ' . strlen($response));
header('X-Cache: MISS');
echo $response;