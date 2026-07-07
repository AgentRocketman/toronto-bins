<?php
require_once __DIR__ . '/config.php';
$config = require __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Auth-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function error($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

$authHeader = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
if ($authHeader !== $config['api_auth_token']) {
    error('Unauthorized', 401);
}

if (!$config['openai_api_key']) {
    error('OpenAI API key not configured', 500);
}

$body = json_decode(file_get_contents('php://input'), true);
$text = trim($body['text'] ?? '');
$voice = $body['voice'] ?? 'alloy';

if (!$text) {
    error('Text required');
}

$allowedVoices = ['alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer'];
if (!in_array($voice, $allowedVoices, true)) {
    $voice = 'alloy';
}

$ch = curl_init('https://api.openai.com/v1/audio/speech');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'model' => 'tts-1',
    'input' => $text,
    'voice' => $voice,
    'response_format' => 'mp3',
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $config['openai_api_key'],
    'Content-Type: application/json',
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);
$audio = curl_exec($ch);
$err = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($err || !$audio || $httpCode !== 200) {
    error('TTS failed: ' . ($err ?: 'http ' . $httpCode), 502);
}

echo json_encode([
    'ok' => true,
    'audio_base64' => base64_encode($audio),
    'mime_type' => 'audio/mpeg',
]);
