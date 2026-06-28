<?php
/**
 * Heartbeat receiver — runner POSTs its status here every poll cycle.
 * We trust the runner because the path is well-known; basic shared secret could be added.
 */
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Optional shared secret check
$secret = $data['secret'] ?? '';
if ($secret !== 'mc-runner-heartbeat-2026') {
    http_response_code(401);
    echo json_encode(['error' => 'Bad secret']);
    exit;
}

// Server-side timestamp wins (in case runner clock skews)
$data['last_poll'] = date('c');
unset($data['secret']);

$file = __DIR__ . '/../runner-heartbeat.json';
if (file_put_contents($file, json_encode($data), LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to write heartbeat']);
    exit;
}

echo json_encode(['ok' => true]);
