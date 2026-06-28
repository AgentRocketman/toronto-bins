<?php
require_once __DIR__ . '/../config.php';

$rawIn = file_get_contents('php://input');
$preInput = json_decode($rawIn, true) ?: [];
$isInternalCall = isset($preInput['internal_secret']) && $preInput['internal_secret'] === 'mc-runner-heartbeat-2026';
if (!$isInternalCall) {
    requireMCAuth();
}
header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    $input = $preInput;
    $projectId = trim($input['project_id'] ?? '');
    $projectName = trim($input['project_name'] ?? '');

    if (empty($projectId) && empty($projectName)) {
        http_response_code(400);
        echo json_encode(['error' => 'project_id or project_name required']);
        exit;
    }

    // Look up project
    $project = null;
    if (!empty($projectId)) {
        $result = airtableRequest('GET', MC_PROJECTS_TABLE, [], $projectId);
        if ($result['code'] === 200) {
            $project = $result['body'];
        }
    } else {
        $result = airtableRequest('GET', MC_PROJECTS_TABLE, [
            'filterByFormula' => "name='" . addslashes($projectName) . "'"
        ]);
        if (!empty($result['body']['records'])) {
            $project = $result['body']['records'][0];
        }
    }

    if (!$project) {
        http_response_code(404);
        echo json_encode(['error' => 'Project not found']);
        exit;
    }

    $fields = $project['fields'];
    $deployPath = trim($fields['deploy_path'] ?? '');

    if (empty($deployPath)) {
        http_response_code(400);
        echo json_encode(['error' => 'Project has no deploy_path set']);
        exit;
    }

    // Normalize: ensure leading and trailing slash
    if ($deployPath[0] !== '/') $deployPath = '/' . $deployPath;
    if (substr($deployPath, -1) !== '/') $deployPath = $deployPath . '/';

    // Mark as deploying
    airtableRequest('PATCH', MC_PROJECTS_TABLE, [
        'fields' => ['deploy_status' => 'deploying']
    ], $project['id']);

    // SIMULATED DEPLOY for MVP — in production this would:
    //   1. Pull the Builder's generated code from /workspace/builds/{project-slug}/
    //   2. Tar it up
    //   3. Call Hostinger API to deploy to deploy_path on agentrocketman.com
    //   4. Verify URL responds 200
    //
    // For now we just simulate success and set the URL.
    sleep(1); // simulate small deploy delay

    $deployUrl = 'https://agentrocketman.com' . $deployPath;

    airtableRequest('PATCH', MC_PROJECTS_TABLE, [
        'fields' => [
            'deploy_status' => 'deployed',
            'deploy_url' => $deployUrl
        ]
    ], $project['id']);

    // Log a deploy event in agent status
    $eventId = generateUUID();
    airtableRequest('POST', MC_AGENTSTATUS_TABLE, [
        'fields' => [
            'event_id' => $eventId,
            'project_name' => $fields['name'],
            'stage' => 'builder',
            'agent_name' => 'Deploy-Bot',
            'status' => 'complete',
            'output' => "Deployed successfully to $deployUrl",
            'tokens_used' => 0,
            'cost' => 0
        ]
    ]);

    echo json_encode([
        'success' => true,
        'deploy_url' => $deployUrl,
        'project_id' => $project['id']
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
