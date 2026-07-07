<?php
// GET ?request_id=xxx — client polls this to see if a reply landed yet.
require_once __DIR__ . '/config.php';
$config = require __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-Auth-Token');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function bail($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

$authHeader = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
if ($authHeader !== $config['api_auth_token']) bail('Unauthorized', 401);

$requestId = $_GET['request_id'] ?? '';
if (!preg_match('/^[a-f0-9]{16}$/', $requestId)) bail('Invalid request_id');
$since = isset($_GET['since']) ? max(-1, (int)$_GET['since']) : -1;

if (!file_exists($config['store_file'])) {
    echo json_encode(['ok' => true, 'status' => 'pending', 'chunks' => [], 'done' => false]);
    exit;
}

$store = json_decode(file_get_contents($config['store_file']), true) ?: [];

if (!isset($store[$requestId])) {
    echo json_encode(['ok' => true, 'status' => 'unknown', 'chunks' => [], 'done' => false]);
    exit;
}

$entry  = $store[$requestId];
$chunks = $entry['chunks'] ?? [];
$done   = !empty($entry['done']) || isset($entry['reply']['text']);

// Return only new chunks after `since` (seq strictly greater than `since`).
$newChunks = [];
foreach ($chunks as $c) {
    if (($c['seq'] ?? -1) > $since) $newChunks[] = $c;
}

// Legacy: if there are no chunks yet but a final reply exists (older clients),
// synthesize a single final chunk so the frontend has something to render.
if (empty($chunks) && isset($entry['reply']['text']) && $since < 0) {
    $newChunks[] = [
        'seq'    => 0,
        'text'   => $entry['reply']['text'],
        'ts'     => $entry['reply']['received_at'] ?? time(),
        'status' => false,
    ];
}

$status = $done ? 'replied' : (empty($chunks) ? 'pending' : 'streaming');

echo json_encode([
    'ok'     => true,
    'status' => $status,
    'chunks' => $newChunks,
    'done'   => $done,
    // Backward compat for existing clients
    'reply'       => $entry['reply']['text'] ?? null,
    'received_at' => $entry['reply']['received_at'] ?? null,
]);
