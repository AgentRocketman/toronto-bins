<?php
require_once '../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$generationId = isset($_GET['generation_id']) ? sanitizeInput($_GET['generation_id']) : '';

if (empty($generationId)) {
    jsonResponse(['error' => 'Missing generation_id'], 400);
}

$sessionId = getSessionId();

try {
    $pdo = getDB();

    $stmt = $pdo->prepare("
        SELECT status, completed_at
        FROM generation_queue
        WHERE generation_id = ? AND session_id = ?
    ");
    $stmt->execute([$generationId, $sessionId]);
    $generation = $stmt->fetch();

    if (!$generation) {
        jsonResponse(['error' => 'Generation not found'], 404);
    }

    $stmt = $pdo->prepare("
        SELECT domain, status
        FROM generated_domains
        WHERE generation_id = ?
        ORDER BY id
    ");
    $stmt->execute([$generationId]);
    $domains = $stmt->fetchAll();

    $results = array_map(function($domain) {
        return [
            'domain' => $domain['domain'],
            'status' => $domain['status']
        ];
    }, $domains);

    jsonResponse([
        'generation_id' => $generationId,
        'status' => $generation['status'],
        'results' => $results
    ]);

} catch (Exception $e) {
    error_log("Poll error: " . $e->getMessage());
    jsonResponse(['error' => 'Failed to retrieve results'], 500);
}
