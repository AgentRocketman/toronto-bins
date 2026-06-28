<?php
require_once __DIR__ . '/../config.php';

requireMCAuth();
header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    // Get all pending approvals
    $result = airtableRequest('GET', MC_APPROVALS_TABLE, [
        'filterByFormula' => "decision='pending'",
        'sort[0][field]' => 'approval_id',
        'sort[0][direction]' => 'desc'
    ]);

    if ($result['code'] === 200) {
        echo json_encode([
            'success' => true,
            'approvals' => $result['body']['records'] ?? []
        ]);
    } else {
        http_response_code($result['code']);
        echo json_encode(['error' => 'Failed to fetch approvals', 'details' => $result['body']]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
