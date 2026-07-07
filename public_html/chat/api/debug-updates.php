<?php
// GET — returns the full store so the OpenClaw agent can see pending chat requests.
// Same role as /assistant/api/debug-updates.php but for /chat/.
require_once __DIR__ . '/config.php';
$config = require __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-Auth-Token');

$authHeader = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
if ($authHeader !== $config['api_auth_token']) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$store = [];
if (file_exists($config['store_file'])) {
    $store = json_decode(file_get_contents($config['store_file']), true) ?: [];
}

echo json_encode(['ok' => true, 'store' => $store]);
