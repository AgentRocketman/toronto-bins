<?php
// POST { request_id, reply, append?, status? } — called BY the OpenClaw agent.
//
// - Default (no flags): sets the FINAL reply. Marks the request done.
//   Overwrites any earlier final reply for the same id.
//
// - status: true (with or without append): stores a lightweight interim status
//   line so the page can render a muted "progress" bubble. Does not mark done.
//
// - append: true (without status): appends an additional non-final reply chunk
//   (rare — use status for progress notes).
//
// The store gains a `chunks` array of { seq, text, ts, status } and a `done`
// flag when the final reply is set.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/session-store.php';
$config = require __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Hook-Auth');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function bail($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') bail('POST required', 405);

$authHeader = $_SERVER['HTTP_X_HOOK_AUTH'] ?? '';
if ($authHeader !== $config['hook_auth_token']) bail('Unauthorized', 401);

$body = file_get_contents('php://input');
$data = json_decode($body, true);
if (!$data || !isset($data['request_id']) || !isset($data['reply'])) bail('Invalid payload');

$requestId = $data['request_id'];
$replyText = trim((string)$data['reply']);
$isStatus  = !empty($data['status']);
$isAppend  = !empty($data['append']);

if (!preg_match('/^[a-f0-9]{16}$/', $requestId)) bail('Invalid request_id');
if ($replyText === '') bail('reply empty');

if (!file_exists($config['store_file'])) bail('Store not found', 404);
$store = json_decode(file_get_contents($config['store_file']), true) ?: [];

if (!isset($store[$requestId])) bail('Request not found', 404);

// Initialize chunks array + counters if missing (works with existing entries).
if (!isset($store[$requestId]['chunks']) || !is_array($store[$requestId]['chunks'])) {
    $store[$requestId]['chunks'] = [];
}
$nextSeq = count($store[$requestId]['chunks']);

$chunk = [
    'seq'    => $nextSeq,
    'text'   => $replyText,
    'ts'     => time(),
    'status' => $isStatus,
];
$store[$requestId]['chunks'][] = $chunk;

if (!$isStatus && !$isAppend) {
    // Legacy field for existing clients that only read .reply.text
    $store[$requestId]['reply'] = [
        'text'        => $replyText,
        'received_at' => time(),
    ];
    $store[$requestId]['done'] = true;

    // Keep the server-side conversation log in sync so future turns have context.
    if (!empty($store[$requestId]['session_id'])) {
        chatLogAppend($store[$requestId]['session_id'], 'assistant', $replyText);
    }
}

file_put_contents($config['store_file'], json_encode($store, JSON_PRETTY_PRINT), LOCK_EX);

echo json_encode([
    'ok'   => true,
    'seq'  => $nextSeq,
    'done' => !$isStatus && !$isAppend,
]);
