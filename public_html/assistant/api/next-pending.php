<?php
require_once __DIR__ . '/config.php';
$config = require __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-Hook-Auth');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$authHeader = $_SERVER['HTTP_X_HOOK_AUTH'] ?? '';
if ($authHeader !== $config['hook_auth_token']) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$storePath = $config['request_store'];
$store = [];
if (file_exists($storePath)) {
    $store = json_decode(file_get_contents($storePath), true) ?: [];
}

// Find the oldest unreplied AND unnotified request
$oldest = null;
$oldestId = null;
$oldestTime = PHP_INT_MAX;

foreach ($store as $id => $entry) {
    if (isset($entry['reply'])) continue; // already replied
    if (isset($entry['notified'])) continue; // already handed to cron
    $t = $entry['created_at'] ?? PHP_INT_MAX;
    if ($t < $oldestTime) {
        $oldestTime = $t;
        $oldest = $entry;
        $oldestId = $id;
    }
}

if (!$oldest) {
    echo json_encode(['ok' => true, 'pending' => null]);
    exit;
}

// Mark as notified so cron doesn't pick it up again
$store[$oldestId]['notified'] = time();
file_put_contents($storePath, json_encode($store, JSON_PRETTY_PRINT));

echo json_encode([
    'ok' => true,
    'pending' => [
        'request_id' => $oldestId,
        'text' => $oldest['text'] ?? '',
        'created_at' => $oldest['created_at'] ?? null,
        'message_id' => $oldest['message_id'] ?? null,
    ],
]);
