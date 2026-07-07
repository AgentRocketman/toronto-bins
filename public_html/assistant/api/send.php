<?php
require_once __DIR__ . '/config.php';
$config = require __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-Auth-Token');

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

if ($config['telegram_bot_token'] === '__SET_ME__' || !$config['telegram_bot_token']) {
    error('Telegram bot token not configured', 500);
}
if (!$config['openai_api_key']) {
    error('OpenAI API key not configured', 500);
}

if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
    error('Audio file required');
}

function addConversationMessage($config, $conversationId, $role, $text, $extra = []) {
    $storePath = $config['conversation_store'];
    $store = [];
    if (file_exists($storePath)) {
        $store = json_decode(file_get_contents($storePath), true) ?: [];
    }
    if (!isset($store[$conversationId]) || !is_array($store[$conversationId])) {
        $store[$conversationId] = [];
    }
    $entry = array_merge([
        'role' => $role,
        'text' => $text,
        'timestamp' => time(),
    ], $extra);
    $store[$conversationId][] = $entry;
    // Keep last 100 messages per conversation.
    $store[$conversationId] = array_slice($store[$conversationId], -100);
    file_put_contents($storePath, json_encode($store, JSON_PRETTY_PRINT), LOCK_EX);
}

$conversationId = 'main';

$allowedTypes = ['audio/wav', 'audio/webm', 'audio/mp4', 'audio/mpeg', 'audio/ogg', 'audio/aac', 'audio/x-m4a'];
$mime = $_FILES['audio']['type'];
if (!in_array($mime, $allowedTypes, true)) {
    error('Unsupported audio type: ' . $mime);
}

$uploadDir = rtrim($config['upload_dir'], '/');
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$requestId = bin2hex(random_bytes(8));
$ext = pathinfo($_FILES['audio']['name'], PATHINFO_EXTENSION) ?: 'bin';
$filename = $requestId . '_' . time() . '.' . $ext;
$filepath = $uploadDir . '/' . $filename;

if (!move_uploaded_file($_FILES['audio']['tmp_name'], $filepath)) {
    error('Failed to save audio', 500);
}

// Transcribe with OpenAI Whisper
$transcription = transcribeAudio($filepath, $mime, $config['openai_api_key']);
if (!$transcription) {
    @unlink($filepath);
    error('Transcription failed', 502);
}

// Send transcribed text to Telegram
$apiBase = rtrim($config['telegram_api_base'], '/') . '/bot' . $config['telegram_bot_token'];
$caption = "🎙️ [Voice {$requestId}]\n\"{$transcription}\"";

$ch = curl_init($apiBase . '/sendMessage');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'chat_id' => $config['allowed_chat_id'],
    'text' => $caption,
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
$response = curl_exec($ch);
$err = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($err) {
    @unlink($filepath);
    error('Telegram request failed: ' . $err, 502);
}

$data = json_decode($response, true);
if (!$data || !($data['ok'] ?? false) || !isset($data['result']['message_id'])) {
    @unlink($filepath);
    error('Telegram send failed: ' . ($data['description'] ?? 'unknown') . ' | http=' . $httpCode . ' | response=' . substr($response, 0, 500), 502);
}

$messageId = $data['result']['message_id'];

// Store mapping request_id -> telegram message_id + transcription
$storePath = $config['request_store'];
$store = [];
if (file_exists($storePath)) {
    $store = json_decode(file_get_contents($storePath), true) ?: [];
}
$store[$requestId] = [
    'message_id' => $messageId,
    'created_at' => time(),
    'text' => $transcription,
    'file' => $filename,
    'mime' => $mime,
];
file_put_contents($storePath, json_encode($store, JSON_PRETTY_PRINT), LOCK_EX);

// Append this user message to the persistent conversation log.
addConversationMessage($config, $conversationId, 'user', $transcription, ['request_id' => $requestId]);

// Fire agent turn via /hooks/agent
$wakeStatus = null;
if (!empty($config['openclaw_hook_url']) && !empty($config['openclaw_hook_token'])) {
    $context = 'Voice message from the user. Treat this exactly like a normal Telegram message, but keep your reply to 1-3 short sentences that work well spoken aloud.\n\n'
             . 'After you finish any work (or immediately if there is none), POST the reply to the PWA:\n'
             . 'curl -sS -X POST -H \'Content-Type: application/json\' -H \'X-Hook-Auth: curbin-hook-auth-2026\' '
             . '-d \'{"request_id":"' . $requestId . '","reply":"YOUR_REPLY_HERE"}\' '
             . 'https://agentrocketman.com/assistant/api/reply.php\n\n'
             . 'Then output only: NO_REPLY\n\n'
             . 'Transcription: "' . $transcription . '"';

    $payload = [
        'message'        => $context,
        'name'           => 'VoiceBridge',
        'sessionKey'     => $config['openclaw_session_key'],
        'timeoutSeconds' => 60,
    ];

    $ch2 = curl_init($config['openclaw_hook_url']);
    curl_setopt($ch2, CURLOPT_POST, true);
    curl_setopt($ch2, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch2, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $config['openclaw_hook_token'],
    ]);
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch2, CURLOPT_CONNECTTIMEOUT, 3);
    $wakeResp = curl_exec($ch2);
    $wakeErr  = curl_error($ch2);
    $wakeCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);
    $wakeStatus = $wakeErr ? ('error: ' . $wakeErr) : ('http ' . $wakeCode);
}

echo json_encode([
    'ok'                  => true,
    'request_id'          => $requestId,
    'telegram_message_id' => $messageId,
    'text'                => $transcription,
    'wake_status'         => $wakeStatus,
]);

function transcribeAudio($filepath, $mime, $apiKey) {
    $postFields = [
        'file' => new CURLFile($filepath, $mime, basename($filepath)),
        'model' => 'whisper-1',
        'response_format' => 'json',
    ];

    $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey,
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        error_log('Whisper curl error: ' . $err);
        return null;
    }

    $data = json_decode($response, true);
    if (!$data || !isset($data['text'])) {
        error_log('Whisper bad response: ' . substr($response, 0, 500));
        return null;
    }

    return trim($data['text']);
}
