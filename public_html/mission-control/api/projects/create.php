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
    $name = trim($input['name'] ?? '');
    $requirements = trim($input['requirements'] ?? '');
    $budgetCap = floatval($input['budget_cap'] ?? 10.00);
    $deployPath = trim($input['deploy_path'] ?? '');

    if (empty($name)) {
        http_response_code(400);
        echo json_encode(['error' => 'Project name is required']);
        exit;
    }

    if (empty($requirements)) {
        http_response_code(400);
        echo json_encode(['error' => 'Requirements are required']);
        exit;
    }

    // Check for duplicate name
    $existingCheck = airtableRequest('GET', MC_PROJECTS_TABLE, [
        'filterByFormula' => "name='" . addslashes($name) . "'"
    ]);

    if (!empty($existingCheck['body']['records'])) {
        http_response_code(409);
        echo json_encode(['error' => 'Project with this name already exists']);
        exit;
    }

    // Build full field set including all toggles
    $fields = [
        'name' => $name,
        'requirements' => $requirements,
        'status' => 'draft',
        'budget_cap' => $budgetCap,
        'budget_spent' => 0.00,
        'notes' => '',
        'deploy_path' => $deployPath,
        'deploy_status' => 'not_deployed'
    ];

    // Auto-approve flags (note: auto_code_auditor field is reused for Code Reviewer)
    $autoFlags = ['auto_scout', 'auto_architect', 'auto_designer', 'auto_tester',
                  'auto_reviewer', 'auto_builder', 'auto_code_auditor',
                  'auto_smoke_test', 'auto_security', 'auto_performance', 'auto_deploy'];
    foreach ($autoFlags as $flag) {
        $fields[$flag] = !empty($input[$flag]);
    }

    // Enable flags for optional stages
    $enableFlags = ['enable_designer', 'enable_security', 'enable_performance'];
    foreach ($enableFlags as $flag) {
        $fields[$flag] = !empty($input[$flag]);
    }

    // Create project
    $result = airtableRequest('POST', MC_PROJECTS_TABLE, [
        'fields' => $fields
    ]);

    if ($result['code'] === 200) {
        echo json_encode([
            'success' => true,
            'project_id' => $result['body']['id'],
            'project' => $result['body']
        ]);
    } else {
        http_response_code($result['code']);
        echo json_encode(['error' => 'Failed to create project', 'details' => $result['body']]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
