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

$requestId = $_GET['request_id'] ?? '';
if (!$requestId || !preg_match('/^[a-f0-9]{16}$/', $requestId)) {
    error('Invalid request_id');
}

$storePath = $config['request_store'];
$store = [];
if (file_exists($storePath)) {
    $store = json_decode(file_get_contents($storePath), true) ?: [];
}

if (!isset($store[$requestId])) {
    error('Request not found', 404);
}

if (isset($store[$requestId]['reply'])) {
    echo json_encode([
        'ok' => true,
        'status' => 'replied',
        'reply' => [
            'type' => 'text',
            'text' => $store[$requestId]['reply']['text'],
        ],
    ]);
} else {
    echo json_encode([
        'ok' => true,
        'status' => 'waiting',
    ]);
}
