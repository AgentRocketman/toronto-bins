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

$authHeader = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
if ($authHeader !== $config['api_auth_token']) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$updatesPath = $config['upload_dir'] . '/updates.jsonl';
$lines = [];
if (file_exists($updatesPath)) {
    $raw = file($updatesPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $lines = array_slice($raw, -20);
}

$storePath = $config['request_store'];
$store = [];
if (file_exists($storePath)) {
    $store = json_decode(file_get_contents($storePath), true) ?: [];
}

echo json_encode(['ok' => true, 'updates' => $lines, 'store' => $store], JSON_PRETTY_PRINT);
