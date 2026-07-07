<?php
require_once __DIR__ . '/config.php';
$config = require __DIR__ . '/config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-Auth-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function error($msg, $code = 400) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

$authHeader = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
if ($authHeader !== $config['api_auth_token']) {
    error('Unauthorized', 401);
}

if ($config['telegram_bot_token'] === '__SET_ME__') {
    error('Telegram bot token not configured', 500);
}

$fileId = $_GET['file_id'] ?? '';
if (!$fileId) {
    error('file_id required');
}

$apiBase = rtrim($config['telegram_api_base'], '/') . '/bot' . $config['telegram_bot_token'];

// Get file path
$infoUrl = $apiBase . '/getFile?file_id=' . urlencode($fileId);
$ch = curl_init($infoUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
$info = curl_exec($ch);
curl_close($ch);

$infoData = json_decode($info, true);
if (!$infoData || !($infoData['ok'] ?? false) || !isset($infoData['result']['file_path'])) {
    error('Failed to get file info', 502);
}

$filePath = $infoData['result']['file_path'];
$downloadUrl = 'https://api.telegram.org/file/bot' . $config['telegram_bot_token'] . '/' . $filePath;

// Stream the file to the client
$ch = curl_init($downloadUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);
$audio = curl_exec($ch);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$err = curl_error($ch);
curl_close($ch);

if ($err || !$audio) {
    error('Failed to download audio', 502);
}

header('Content-Type: ' . ($contentType ?: 'audio/ogg'));
header('Content-Length: ' . strlen($audio));
echo $audio;
