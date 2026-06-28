<?php
require_once __DIR__ . '/../config.php';

requireMCAuth();
header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $projectId = $input['project_id'] ?? null;

    if (!$projectId) {
        http_response_code(400);
        echo json_encode(['error' => 'project_id is required']);
        exit;
    }

    // Load the project to get its name (for cleaning up approvals)
    $projectResult = airtableRequest('GET', MC_PROJECTS_TABLE, [], $projectId);

    if ($projectResult['code'] === 404) {
        http_response_code(404);
        echo json_encode(['error' => 'Project not found']);
        exit;
    }

    if ($projectResult['code'] !== 200) {
        http_response_code($projectResult['code']);
        echo json_encode(['error' => 'Failed to load project', 'details' => $projectResult['body']]);
        exit;
    }

    $project = $projectResult['body'];
    $projectName = $project['fields']['name'] ?? null;

    // Delete the project record
    $deleteResult = airtableRequest('DELETE', MC_PROJECTS_TABLE, [], $projectId);

    if ($deleteResult['code'] !== 200) {
        http_response_code($deleteResult['code']);
        echo json_encode(['error' => 'Failed to delete project', 'details' => $deleteResult['body']]);
        exit;
    }

    // Clean up any pending or stale approval records for this project
    if ($projectName) {
        $approvalsResult = airtableRequest('GET', MC_APPROVALS_TABLE, [
            'filterByFormula' => "project_name='" . str_replace("'", "\\'", $projectName) . "'"
        ]);

        if ($approvalsResult['code'] === 200) {
            $approvals = $approvalsResult['body']['records'] ?? [];
            foreach ($approvals as $approval) {
                // Delete each approval record (Airtable doesn't have batch delete in the simple API)
                airtableRequest('DELETE', MC_APPROVALS_TABLE, [], $approval['id']);
            }
        }
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
