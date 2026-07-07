<?php
require_once __DIR__ . '/config.php';
$config = require __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Hook-Auth');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function error($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error('POST required', 405);
}

$authHeader = $_SERVER['HTTP_X_HOOK_AUTH'] ?? '';
if ($authHeader !== $config['hook_auth_token']) {
    error('Unauthorized', 401);
}

$body = file_get_contents('php://input');
$data = json_decode($body, true);
if (!$data || !isset($data['request_id']) || !isset($data['reply'])) {
    error('Invalid payload');
}

$requestId = $data['request_id'];
$replyText = $data['reply'];

if (!preg_match('/^[a-f0-9]{16}$/', $requestId)) {
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

$store[$requestId]['reply'] = [
    'text' => $replyText,
    'received_at' => time(),
];
file_put_contents($storePath, json_encode($store, JSON_PRETTY_PRINT), LOCK_EX);

// Also append the assistant reply to the persistent conversation log.
$conversationId = 'main';
$convPath = $config['conversation_store'];
$convStore = [];
if (file_exists($convPath)) {
    $convStore = json_decode(file_get_contents($convPath), true) ?: [];
}
if (!isset($convStore[$conversationId]) || !is_array($convStore[$conversationId])) {
    $convStore[$conversationId] = [];
}
$convStore[$conversationId][] = [
    'role' => 'assistant',
    'text' => $replyText,
    'timestamp' => time(),
    'request_id' => $requestId,
];
$convStore[$conversationId] = array_slice($convStore[$conversationId], -100);
file_put_contents($convPath, json_encode($convStore, JSON_PRETTY_PRINT), LOCK_EX);

echo json_encode(['ok' => true]);
