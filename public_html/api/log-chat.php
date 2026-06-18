<?php
/**
 * POST /api/log-chat.php
 * 
 * Logs chat messages to Airtable
 * Request body: { sessionId, message, messageType, timestamp }
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

// Parse input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['message'], $input['messageType'], $input['sessionId'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields: message, messageType, sessionId']);
    exit();
}

$sessionId = trim($input['sessionId']);
$message = trim($input['message']);
$messageType = in_array($input['messageType'], ['question', 'answer']) ? $input['messageType'] : 'answer';
$timestamp = $input['timestamp'] ?? date('c');

// Extract date from timestamp
$dt = new DateTime($timestamp);
$date = $dt->format('Y-m-d');

// Get client info
$ip = getClientIP();
$browserInfo = getBrowserInfo();
$browser = $browserInfo['browser'];
$device = $browserInfo['device'];

// Prepare Airtable record
$record = [
    'fields' => [
        'sessionId' => $sessionId,
        'timestamp' => $timestamp,
        'date' => $date,
        'ipAddress' => $ip,
        'browser' => $browser,
        'deviceType' => $device,
        'messageType' => $messageType,
        'message' => $message
    ]
];

// Log to Airtable
$result = airtableCall('POST', '/' . AIRTABLE_CHATLOGS_TABLE, ['records' => [$record]]);

if ($result['code'] === 200) {
    http_response_code(200);
    echo json_encode(['success' => true, 'recordId' => $result['body']['records'][0]['id'] ?? null]);
} else {
    http_response_code($result['code'] ?? 500);
    echo json_encode(['error' => 'Failed to log message', 'details' => $result['body']]);
}

?>
