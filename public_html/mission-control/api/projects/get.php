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

    $id = $_GET['id'] ?? '';

    if (empty($id)) {
        http_response_code(400);
        echo json_encode(['error' => 'Project ID is required']);
        exit;
    }

    // Get project
    $projectResult = airtableRequest('GET', MC_PROJECTS_TABLE, [], $id);

    if ($projectResult['code'] !== 200) {
        http_response_code($projectResult['code']);
        echo json_encode(['error' => 'Failed to fetch project', 'details' => $projectResult['body']]);
        exit;
    }

    $project = $projectResult['body'];
    $projectName = $project['fields']['name'] ?? '';

    // Get related agent status events
    $agentStatusResult = airtableRequest('GET', MC_AGENTSTATUS_TABLE, [
        'filterByFormula' => "project_name='" . addslashes($projectName) . "'",
        'sort[0][field]' => 'event_id',
        'sort[0][direction]' => 'desc'
    ]);

    // Get pending approvals
    $approvalsResult = airtableRequest('GET', MC_APPROVALS_TABLE, [
        'filterByFormula' => "AND(project_name='" . addslashes($projectName) . "', decision='pending')"
    ]);

    echo json_encode([
        'success' => true,
        'project' => $project,
        'agent_events' => $agentStatusResult['body']['records'] ?? [],
        'pending_approvals' => $approvalsResult['body']['records'] ?? []
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
