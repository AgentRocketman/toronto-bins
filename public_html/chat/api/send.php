<?php
// POST { text: "..." } → forwards to Telegram, stores request, fires /hooks/agent wake.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/session-store.php';
$config = require __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Auth-Token');
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

$body = file_get_contents('php://input');
$data = json_decode($body, true);
if (!$data || !isset($data['text']) || !is_string($data['text'])) bail('text required');

$text = trim($data['text']);
if ($text === '') bail('text empty');
if (strlen($text) > 4000) bail('text too long (max 4000 chars)');

$sessionId = isset($data['session_id']) && is_string($data['session_id'])
    ? trim($data['session_id'])
    : '';
if ($sessionId === '') {
    $sessionId = bin2hex(random_bytes(8));
}

// Seed server-side log from client history on first use, then append this message.
if (!empty($data['history']) && is_array($data['history'])) {
    chatLogInitFromClient($sessionId, $data['history']);
}
chatLogAppend($sessionId, 'user', $text);

if (!$config['telegram_bot_token']) bail('Telegram not configured', 500);

if (!is_dir($config['store_dir'])) mkdir($config['store_dir'], 0755, true);

$requestId = bin2hex(random_bytes(8));

// Send to Telegram
$apiBase = rtrim($config['telegram_api_base'], '/') . '/bot' . $config['telegram_bot_token'];
$caption = "💬 [Chat {$requestId}]\n{$text}";

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
$err = curl_error($ch);
curl_close($ch);

if ($err) bail('Telegram error: ' . $err, 502);

$tg = json_decode($response, true);
if (!$tg || !($tg['ok'] ?? false)) {
    bail('Telegram send failed: ' . ($tg['description'] ?? 'unknown'), 502);
}

$messageId = $tg['result']['message_id'] ?? null;

// Store message
$store = [];
if (file_exists($config['store_file'])) {
    $store = json_decode(file_get_contents($config['store_file']), true) ?: [];
}
$store[$requestId] = [
    'created_at'          => time(),
    'text'                => $text,
    'session_id'          => $sessionId,
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
             . "- Weekly subscription: $5.95/week. Ad-hoc (one-time): $8.95. $1 promo offer available.\n"
             . "- HST (13%) applies to all pricing.\n"
             . "- Service area: City of Toronto only (Old Toronto, North York, Scarborough, Etobicoke, East York, York). NOT GTA (no Mississauga, Brampton, etc).\n"
             . "- Contact: support@getmybin.com\n\n";
    if ($historyBlock !== '') {
        $context .= $historyBlock . "\n\n";
    }
    $context .= "TASK: A customer sent the following message via the GetMyBin website chat:\n"
              . "\"{$text}\"\n\n"
              . "Reply helpfully in 1-3 sentences.\n\n"
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
        'name'           => 'ChatBridge',
        'sessionKey'     => $config['openclaw_session_key'],
        'model'          => 'openrouter/moonshotai/kimi-k2.7-code',
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
    'telegram_message_id' => $messageId,
    'wake_status'         => $wakeStatus,
]);
