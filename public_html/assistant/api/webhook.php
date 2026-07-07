<?php
require_once __DIR__ . '/config.php';
$config = require __DIR__ . '/config.php';

// Telegram expects a fast 200 OK. We process asynchronously.
ignore_user_abort(true);
set_time_limit(30);

$body = file_get_contents('php://input');
if (!$body) {
    http_response_code(204);
    exit;
}

$update = json_decode($body, true);
if (!$update) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

// Store update for poll.php
$storePath = $config['upload_dir'] . '/updates.jsonl';
if (!is_dir($config['upload_dir'])) {
    mkdir($config['upload_dir'], 0755, true);
}

$entry = [
    'received_at' => time(),
    'update' => $update,
];
file_put_contents($storePath, json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);

// Trim old updates to keep file bounded (keep last 200)
trimUpdateStore($storePath, 200);

// Forward to OpenClaw local webhook listener
$openclawWebhook = rtrim($config['openclaw_webhook_url'], '/');
if ($openclawWebhook) {
    $headers = ['Content-Type: application/json'];
    $secret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if ($secret) {
        $headers[] = 'X-Telegram-Bot-Api-Secret-Token: ' . $secret;
    }

    $ch = curl_init($openclawWebhook);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_exec($ch);
    curl_close($ch);
}

http_response_code(200);
echo json_encode(['ok' => true]);

function trimUpdateStore($path, $max) {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (count($lines) <= $max) return;
    $lines = array_slice($lines, -$max);
    file_put_contents($path, implode("\n", $lines) . "\n", LOCK_EX);
}
