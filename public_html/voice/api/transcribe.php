<?php
/**
 * CurbIn Voice Assistant - Audio Transcription Proxy
 *
 * Receives an uploaded audio blob from the browser and sends it to
 * the OpenAI Whisper API. Returns the transcribed text as JSON.
 */

require_once __DIR__ . '/../../api/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
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

if (!function_exists('voiceTranscribeClientIP')) {
    function voiceTranscribeClientIP(): string {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) return $_SERVER['HTTP_CF_CONNECTING_IP'];
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        if (!empty($_SERVER['HTTP_X_FORWARDED']))       return $_SERVER['HTTP_X_FORWARDED'];
        if (!empty($_SERVER['HTTP_FORWARDED_FOR']))     return $_SERVER['HTTP_FORWARDED_FOR'];
        if (!empty($_SERVER['HTTP_FORWARDED']))         return $_SERVER['HTTP_FORWARDED'];
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
}

// Rate limit: one transcription per 2 seconds per IP.
$ip = voiceTranscribeClientIP();
$rateFile = sys_get_temp_dir() . '/transcribe_rate_' . md5($ip) . '.json';
$now = microtime(true);
$last = 0.0;
if (file_exists($rateFile)) {
    $stored = json_decode(file_get_contents($rateFile), true);
    $last = is_array($stored) && isset($stored['ts']) ? (float)$stored['ts'] : 0.0;
}
if ($now - $last < 2.0) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many requests. Please wait.']);
    exit;
}
file_put_contents($rateFile, json_encode(['ts' => $now]));

if (empty($_FILES['audio']) || empty($_FILES['audio']['tmp_name'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No audio uploaded.']);
    exit;
}

$file = $_FILES['audio'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'Upload error: ' . $file['error']]);
    exit;
}

$maxSize = 10 * 1024 * 1024; // 10 MB
if ($file['size'] > $maxSize) {
    http_response_code(413);
    echo json_encode(['error' => 'Audio file too large.']);
    exit;
}

$mimeType = $file['type'] ?? 'audio/wav';
$allowedMimeTypes = [
    'audio/wav', 'audio/x-wav', 'audio/webm', 'audio/mp4', 'audio/mpeg',
    'audio/mpga', 'audio/m4a', 'audio/ogg', 'audio/flac', 'video/webm',
];
if (!in_array($mimeType, $allowedMimeTypes, true)) {
    // Be permissive: Whisper can usually figure it out from the file extension.
    $mimeType = 'audio/wav';
}

$ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [
        'file' => new CURLFile($file['tmp_name'], $mimeType, $file['name'] ?? 'recording.wav'),
        'model' => 'whisper-1',
    ],
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . OPENAI_API_KEY,
    ],
    CURLOPT_TIMEOUT => 60,
    CURLOPT_FOLLOWLOCATION => true,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError || $httpCode !== 200 || !$response) {
    http_response_code(502);
    $body = $response ?: '';
    error_log(sprintf('OpenAI Whisper error: HTTP %s, curl error: %s, response: %s', $httpCode, $curlError, $body));
    echo json_encode(['error' => 'Transcription service failed.', 'http' => $httpCode]);
    exit;
}

$data = json_decode($response, true);
if (!$data || !isset($data['text'])) {
    http_response_code(502);
    error_log('OpenAI Whisper unexpected response: ' . $response);
    echo json_encode(['error' => 'Unexpected transcription response.']);
    exit;
}

echo json_encode([
    'text' => $data['text']
]);
