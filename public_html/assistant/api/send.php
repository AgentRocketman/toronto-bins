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
$caption = "🎙️ [PWA {$requestId}]\n\"{$transcription}\"";

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
file_put_contents($storePath, json_encode($store, JSON_PRETTY_PRINT));

// Fire agent turn via /hooks/agent
$wakeStatus = null;
if (!empty($config['openclaw_hook_url']) && !empty($config['openclaw_hook_token'])) {
    $escapedText = addslashes($transcription);
    $context = "You are a helpful voice assistant for GetMyBin, a bin collection rollout service in Toronto, Canada.\n"
             . "SERVICE INFO:\n"
             . "- GetMyBin rolls bins to the curb the evening before pickup day, then back to the property the afternoon after city collection.\n"
             . "- Weekly subscription: \$5.95/week. Ad-hoc (one-time): \$8.95. \$1 promo offer available.\n"
             . "- HST (13%) applies to all pricing.\n"
             . "- Service area: City of Toronto only (Old Toronto, North York, Scarborough, Etobicoke, East York, York). NOT GTA (no Mississauga, Brampton, etc).\n"
             . "- Contact: support@getmybin.com\n\n"
             . "TASK: A user sent a VOICE message via the GetMyBin assistant page. Transcription:\n"
             . "\"{$transcription}\"\n\n"
             . "Reply in 1-2 SHORT sentences (voice TTS — keep it brief and natural). Then use the exec tool to POST your reply:\n"
             . "curl -sS -X POST -H 'Content-Type: application/json' -H 'X-Hook-Auth: curbin-hook-auth-2026' "
             . "-d '{\"request_id\":\"{$requestId}\",\"reply\":\"REPLACE_WITH_YOUR_REPLY\"}' "
             . "https://agentrocketman.com/assistant/api/reply.php\n\n"
             . "Replace REPLACE_WITH_YOUR_REPLY with your reply (escape quotes). After curl, output only: DONE";

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
