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

    $projectName = $_GET['project'] ?? '';

    if (empty($projectName)) {
        http_response_code(400);
        echo json_encode(['error' => 'Project name is required']);
        exit;
    }

    // Find project by name
    $result = airtableRequest('GET', MC_PROJECTS_TABLE, [
        'filterByFormula' => "name='" . addslashes($projectName) . "'"
    ]);

    if (empty($result['body']['records'])) {
        http_response_code(404);
        echo json_encode(['error' => 'Project not found']);
        exit;
    }

    $project = $result['body']['records'][0]['fields'];
    $budgetCap = floatval($project['budget_cap'] ?? 10.00);
    $budgetSpent = floatval($project['budget_spent'] ?? 0.00);
    $remaining = max(0, $budgetCap - $budgetSpent);
    $percentUsed = $budgetCap > 0 ? ($budgetSpent / $budgetCap) * 100 : 0;

    echo json_encode([
        'success' => true,
        'budget_cap' => $budgetCap,
        'budget_spent' => $budgetSpent,
        'remaining' => $remaining,
        'percent_used' => round($percentUsed, 2),
        'warning_at_90' => $percentUsed >= 90
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
