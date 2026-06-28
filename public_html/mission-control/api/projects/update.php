<?php
require_once __DIR__ . '/../config.php';

requireMCAuth();
header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PATCH' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? '';

    if (empty($id)) {
        http_response_code(400);
        echo json_encode(['error' => 'Project ID is required']);
        exit;
    }

    // Build fields to update
    $fields = [];
    if (isset($input['status'])) $fields['status'] = $input['status'];
    if (isset($input['budget_spent'])) $fields['budget_spent'] = floatval($input['budget_spent']);
    if (isset($input['current_agent'])) $fields['current_agent'] = $input['current_agent'];
    if (isset($input['notes'])) $fields['notes'] = $input['notes'];
    if (isset($input['budget_cap'])) $fields['budget_cap'] = floatval($input['budget_cap']);
    if (isset($input['deploy_path'])) $fields['deploy_path'] = trim($input['deploy_path']);
    if (isset($input['deploy_status'])) $fields['deploy_status'] = $input['deploy_status'];
    if (isset($input['deploy_url'])) $fields['deploy_url'] = trim($input['deploy_url']);
    // Auto-approve toggles (full set including new agents)
    $autoFlags = ['auto_scout','auto_architect','auto_designer','auto_tester','auto_reviewer',
                  'auto_builder','auto_code_auditor','auto_smoke_test',
                  'auto_security','auto_performance','auto_deploy',
                  // legacy (no longer in flow but kept for old projects)
                  'auto_innovator'];
    foreach ($autoFlags as $tog) {
        if (array_key_exists($tog, $input)) $fields[$tog] = !empty($input[$tog]);
    }
    // Enable flags for optional stages
    foreach (['enable_designer', 'enable_security', 'enable_performance'] as $tog) {
        if (array_key_exists($tog, $input)) $fields[$tog] = !empty($input[$tog]);
    }

    if (empty($fields)) {
        http_response_code(400);
        echo json_encode(['error' => 'No fields to update']);
        exit;
    }

    $result = airtableRequest('PATCH', MC_PROJECTS_TABLE, [
        'fields' => $fields
    ], $id);

    if ($result['code'] === 200) {
        echo json_encode([
            'success' => true,
            'project' => $result['body']
        ]);
    } else {
        http_response_code($result['code']);
        echo json_encode(['error' => 'Failed to update project', 'details' => $result['body']]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
