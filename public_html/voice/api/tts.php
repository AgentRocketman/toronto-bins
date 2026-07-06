<?php
/**
 * CurbIn Voice Assistant - Text-to-Speech Proxy
 *
 * Proxies OpenAI TTS API and returns the generated MP3 as base64 JSON.
 * OpenAI API key is kept server-side via /public_html/api/config.php.
 */

require_once __DIR__ . '/../../api/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!defined('OPENAI_API_KEY')) {
    http_response_code(500);
    echo json_encode(['error' => 'OpenAI API key is not configured.']);
    exit;
}

// Avoid redeclaring helper names that may already exist in config.php.
if (!function_exists('voiceTTSClientIP')) {
    function voiceTTSClientIP(): string {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) return $_SERVER['HTTP_CF_CONNECTING_IP'];
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        if (!empty($_SERVER['HTTP_X_FORWARDED']))       return $_SERVER['HTTP_X_FORWARDED'];
        if (!empty($_SERVER['HTTP_FORWARDED_FOR']))     return $_SERVER['HTTP_FORWARDED_FOR'];
        if (!empty($_SERVER['HTTP_FORWARDED']))         return $_SERVER['HTTP_FORWARDED'];
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
}

// Rate limit: 1 TTS request per second per IP.
$ip = voiceTTSClientIP();
$rateFile = sys_get_temp_dir() . '/tts_rate_' . md5($ip) . '.json';
$now = microtime(true);
$last = 0.0;
if (file_exists($rateFile)) {
    $stored = json_decode(file_get_contents($rateFile), true);
    $last = is_array($stored) && isset($stored['ts']) ? (float)$stored['ts'] : 0.0;
}
if ($now - $last < 1.0) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many requests. Please wait.']);
    exit;
}
file_put_contents($rateFile, json_encode(['ts' => $now]));

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !is_array($input) || empty($input['text']) || !is_string($input['text'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Text is required and must be a string.']);
    exit;
}

$text = trim($input['text']);
if ($text === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Text cannot be empty.']);
    exit;
}

$allowedVoices = ['alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer'];
$voice = in_array($input['voice'] ?? '', $allowedVoices, true) ? $input['voice'] : 'alloy';

if (strlen($text) > 4000) {
    $text = substr($text, 0, 4000);
}

$payload = [
    'model' => 'tts-1',
    'input' => $text,
    'voice' => $voice,
    'response_format' => 'mp3',
];

$ch = curl_init('https://api.openai.com/v1/audio/speech');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . OPENAI_API_KEY,
    ],
    CURLOPT_TIMEOUT => 30,
    CURLOPT_FOLLOWLOCATION => true,
]);

$mp3 = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError || $httpCode !== 200 || !$mp3) {
    http_response_code(502);
    $body = $mp3 ?: '';
    error_log(sprintf('OpenAI TTS error: HTTP %s, curl error: %s, response: %s', $httpCode, $curlError, $body));
    echo json_encode([
        'error' => 'Failed to generate speech.',
        'http' => $httpCode,
        'curl_error' => $curlError,
        'body_preview' => substr($body, 0, 200)
    ]);
    exit;
}

echo json_encode([
    'audio' => base64_encode($mp3),
    'format' => 'mp3',
    'voice' => $voice,
]);
