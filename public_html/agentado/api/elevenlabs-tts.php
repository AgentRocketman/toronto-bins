<?php
/**
 * ElevenLabs Text-to-Speech
 * Takes text + voice_id → returns MP3 audio stream
 * Cached by content hash to avoid re-generating for the same text+voice combo
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'POST only']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$text    = trim($input['text'] ?? '');
$voiceId = trim($input['voice_id'] ?? 'pNInz6obpgDQGcFmaJgB'); // default: Adam

if (!$text || strlen($text) < 1) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Text is required']);
    exit;
}

require_once __DIR__ . '/../../api/config.php'; // for path consistency

// ElevenLabs API key (hardcoded — not in shared config to avoid accidental exposure)
define('ELEVENLABS_API_KEY', 'sk_2d92db8936ed1e04ca0ac9bbd236e2e540c0c74d49fea9ec');

// Cache: store in a temp dir keyed by md5(text + voice_id)
$cacheDir = __DIR__ . '/../output/tts-cache';
if (!is_dir($cacheDir)) { mkdir($cacheDir, 0755, true); }
$cacheKey = md5($text . '|' . $voiceId);
$cachePath = $cacheDir . '/' . $cacheKey . '.mp3';

if (file_exists($cachePath) && filesize($cachePath) > 0) {
    header('Content-Type: audio/mpeg');
    header('Content-Length: ' . filesize($cachePath));
    header('X-Cache: HIT');
    readfile($cachePath);
    exit;
}

// Call ElevenLabs TTS API
$ch = curl_init("https://api.elevenlabs.io/v1/text-to-speech/{$voiceId}");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'xi-api-key: ' . ELEVENLABS_API_KEY,
        'Accept: audio/mpeg',
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'text'               => $text,
        'model_id'           => 'eleven_turbo_v2',
        'voice_settings'     => [
            'stability'          => 0.50,
            'similarity_boost'   => 0.75,
            'style'              => 0.0,
            'use_speaker_boost'  => true,
        ],
    ]),
]);

$audio = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($httpCode !== 200 || !$audio) {
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'ElevenLabs API error',
        'code'  => $httpCode,
    ]);
    exit;
}

// Cache the result
file_put_contents($cachePath, $audio);

header('Content-Type: audio/mpeg');
header('Content-Length: ' . strlen($audio));
header('X-Cache: MISS');
echo $audio;