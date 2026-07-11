<?php
// POST multipart with `audio` file → transcribes with Whisper → routes through the
// same send + wake pipeline as send.php.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/session-store.php';
$config = require __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-Auth-Token');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function bail($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') bail('POST required', 405);

$authHeader = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
if ($authHeader !== $config['api_auth_token']) bail('Unauthorized', 401);

if (empty($config['openai_api_key'])) bail('OpenAI key not configured', 500);
if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
    bail('Audio file required');
}

$allowedTypes = ['audio/wav', 'audio/webm', 'audio/mp4', 'audio/mpeg', 'audio/ogg', 'audio/aac', 'audio/x-m4a'];
$mime = $_FILES['audio']['type'] ?: 'audio/webm';
if (!in_array($mime, $allowedTypes, true) && !str_starts_with($mime, 'audio/')) {
    bail('Unsupported audio type: ' . $mime);
}

$uploadDir = rtrim($config['upload_dir'], '/');
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

$ext = pathinfo($_FILES['audio']['name'], PATHINFO_EXTENSION) ?: 'bin';
$tmpName = $uploadDir . '/chat_' . bin2hex(random_bytes(6)) . '_' . time() . '.' . $ext;
if (!move_uploaded_file($_FILES['audio']['tmp_name'], $tmpName)) bail('Failed to save audio', 500);

// Reject tiny uploads outright — they cause Whisper hallucinations.
if (filesize($tmpName) < 2000) {
    @unlink($tmpName);
    bail('Recording too short — hold the mic longer', 422);
}

// Whisper transcription (with language hint to reduce hallucinations)
$transcription = transcribe($tmpName, $mime, $config['openai_api_key']);
@unlink($tmpName);
if ($transcription === null) bail('Transcription failed', 502);
$transcription = trim($transcription);
if ($transcription === '') bail('Empty transcription — please try recording again', 422);

$sessionId = isset($_POST['session_id']) && is_string($_POST['session_id'])
    ? trim($_POST['session_id'])
    : '';
if ($sessionId === '') {
    $sessionId = bin2hex(random_bytes(8));
}

if (!empty($_POST['history'])) {
    $clientHistory = json_decode($_POST['history'], true);
    if (is_array($clientHistory)) {
        chatLogInitFromClient($sessionId, $clientHistory);
    }
}
chatLogAppend($sessionId, 'user', $transcription);

// Filter out known Whisper hallucinations on silence/noise.
$hallucinationPatterns = [
    '/^MBC ?뉴스/u',                    // Korean news intro
    '/채널 ?등록/u',                     // Korean "channel subscribe"
    '/チャンネル登録/u',                 // Japanese "channel subscribe"
    '/ご視聴ありがとう/u',              // Japanese "thanks for watching"
    '/Thanks for watching\.?$/i',      // English YouTube signoff
    '/Subscribe (to|for) more/i',
    '/^\s*[\.\,\-\!\?]+\s*$/u',        // Just punctuation
    '/^\s*(you|thank you|thanks|bye)\s*[\.\!\?]?\s*$/i', // Common short hallucinations
];
foreach ($hallucinationPatterns as $rx) {
    if (preg_match($rx, $transcription)) {
        bail('Could not hear you clearly — try again in a quieter spot', 422);
    }
}

// From here on, mirror send.php's storage + wake logic.
if (!is_dir($config['store_dir'])) mkdir($config['store_dir'], 0755, true);

$requestId = bin2hex(random_bytes(8));

// Push to Telegram (with a 🎤 marker so I can tell it was voice)
$apiBase = rtrim($config['telegram_api_base'], '/') . '/bot' . $config['telegram_bot_token'];
$caption = "🎤💬 [Chat {$requestId}]\n{$transcription}";

$ch = curl_init($apiBase . '/sendMessage');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'chat_id' => $config['allowed_chat_id'],
    'text'    => $caption,
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
$response = curl_exec($ch);
curl_close($ch);
$tg = json_decode($response, true);
$messageId = $tg['result']['message_id'] ?? null;

// Persist to the shared /chat/ store
$store = [];
if (file_exists($config['store_file'])) {
    $store = json_decode(file_get_contents($config['store_file']), true) ?: [];
}
$store[$requestId] = [
    'created_at' => time(),
    'text'       => $transcription,
    'session_id' => $sessionId,
    'source'     => 'voice',
    'telegram_message_id' => $messageId,
];
if (count($store) > 100) {
    uasort($store, fn($a, $b) => $a['created_at'] <=> $b['created_at']);
    $store = array_slice($store, -100, null, true);
}
file_put_contents($config['store_file'], json_encode($store, JSON_PRETTY_PRINT), LOCK_EX);

// Fire agent turn via /hooks/agent
$wakeStatus = null;
if (!empty($config['openclaw_hook_url']) && !empty($config['openclaw_hook_token'])) {
    $historyBlock = chatLogHistoryText($sessionId, 20);
    $context = "You are a helpful assistant for GetMyBin, a bin collection rollout service in Toronto, Canada.\n"
             . "SERVICE INFO:\n"
             . "- GetMyBin rolls bins to the curb the evening before pickup day, then back to the property the afternoon after city collection.\n"
             . "- Weekly subscription: $6.95/week. Ad-hoc (one-time): $9.95. $1 promo offer available.\n"
             . "- HST (13%) applies to all pricing.\n"
             . "- Service area: City of Toronto only (Old Toronto, North York, Scarborough, Etobicoke, East York, York). NOT GTA (no Mississauga, Brampton, etc).\n"
             . "- Contact: support@getmybin.com\n\n";
    if ($historyBlock !== '') {
        $context .= $historyBlock . "\n\n";
    }
    $context .= "TASK: A customer sent the following VOICE message via the GetMyBin website chat:\n"
              . "\"{$transcription}\"\n\n"
              . "Reply helpfully and briefly (1-2 sentences, will be read aloud via TTS).\n\n"
              . "IMPORTANT — Progress updates during long tasks:\n"
              . "If answering will take longer than ~30 seconds, first send 3-8 short progress updates (5-10 words each) using this curl with status:true:\n"
              . "curl -sS -X POST -H 'Content-Type: application/json' -H 'X-Hook-Auth: curbin-chat-hook-2026' "
              . "-d '{\"request_id\":\"{$requestId}\",\"reply\":\"STATUS_TEXT_HERE\",\"status\":true}' "
              . "https://agentrocketman.com/chat/api/reply.php\n\n"
              . "Then send the FINAL reply by running this curl command EXACTLY:\n"
              . "curl -sS -X POST -H 'Content-Type: application/json' -H 'X-Hook-Auth: curbin-chat-hook-2026' "
              . "-d '{\"request_id\":\"{$requestId}\",\"reply\":\"REPLACE_WITH_YOUR_REPLY\"}' "
              . "https://agentrocketman.com/chat/api/reply.php\n\n"
              . "Replace REPLACE_WITH_YOUR_REPLY with your actual reply text (escape quotes). After the final curl returns ok:true, output only: DONE";
    $ch2 = curl_init($config['openclaw_hook_url']);
    curl_setopt($ch2, CURLOPT_POST, true);
    curl_setopt($ch2, CURLOPT_POSTFIELDS, json_encode([
        'message'        => $context,
        'name'           => 'ChatBridgeVoice',
        'sessionKey'     => $config['openclaw_session_key'],
        'timeoutSeconds' => 180,
    ]));
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
    'session_id'          => $sessionId,
    'text'                => $transcription,
    'telegram_message_id' => $messageId,
    'wake_status'         => $wakeStatus,
]);

function transcribe($path, $mime, $apiKey) {
    $post = [
        'file'  => new CURLFile($path, $mime, basename($path)),
        'model' => 'whisper-1',
        'response_format' => 'json',
        'language' => 'en',                  // Bias toward English — reduces cross-language hallucinations
        'temperature' => '0',                // Deterministic output
        'prompt' => 'The following is a message from Chris talking to his AI assistant.',
    ];
    $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $apiKey]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) { error_log('Whisper curl error: ' . $err); return null; }
    $d = json_decode($resp, true);
    if (!$d || !isset($d['text'])) { error_log('Whisper bad resp: ' . substr($resp, 0, 400)); return null; }
    return $d['text'];
}
