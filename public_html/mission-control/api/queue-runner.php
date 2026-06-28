<?php
/**
 * Queue Runner state — server-side on/off switch for the auto-start queue runner.
 * GET  returns {"active": true|false} read from the state file.
 * POST {"active": true|false} updates the state file.
 *
 * Auth: either a valid MC_INTERNAL_SECRET in the request body/query (server-side
 * runner) OR a valid Mission Control session (dashboard).
 */
require_once __DIR__ . '/config.php';

$STATE_FILE = sys_get_temp_dir() . '/mc-queue-runner-state.json';

// Decode request body once so we can check the internal secret before requiring auth
$rawIn = file_get_contents('php://input');
$preInput = json_decode($rawIn, true) ?: [];
$secret = $preInput['internal_secret'] ?? ($_GET['secret'] ?? '');
$isInternalCall = $secret === MC_INTERNAL_SECRET;
if (!$isInternalCall) {
    requireMCAuth();
}
header('Content-Type: application/json');

// Ensure the state file exists (default: inactive)
if (!file_exists($STATE_FILE)) {
    $dir = dirname($STATE_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    file_put_contents($STATE_FILE, json_encode(['active' => false]), LOCK_EX);
}

function readQueueState($file) {
    $data = json_decode(@file_get_contents($file), true);
    return ['active' => !empty($data['active'])];
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode(readQueueState($STATE_FILE));
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $active = !empty($preInput['active']);
        if (file_put_contents($STATE_FILE, json_encode(['active' => $active]), LOCK_EX) === false) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to write queue runner state']);
            exit;
        }
        echo json_encode(['active' => $active]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
