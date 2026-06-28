<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../agents/prompts.php';
require_once __DIR__ . '/patch-functions.php';

requireMCAuth();
header('Content-Type: application/json');
set_time_limit(120);

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    $parentId = trim($input['parent_project_id'] ?? '');
    $mode = trim($input['mode'] ?? '');
    $request = trim($input['request'] ?? '');
    $budgetCap = floatval($input['budget_cap'] ?? 3.00);
    $queue = !empty($input['queue']);

    if (empty($parentId) || empty($mode) || empty($request)) {
        http_response_code(400);
        echo json_encode(['error' => 'parent_project_id, mode, and request are required']);
        exit;
    }

    $result = mcCreatePatchProject($parentId, $mode, $request, $budgetCap, 'mc-runner-heartbeat-2026', $queue);

    if (!$result['success']) {
        http_response_code(400);
        echo json_encode(['error' => $result['error'], 'details' => $result['details'] ?? null]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'project_id' => $result['project_id'],
        'project_name' => $result['project_name'],
        'iteration_label' => $result['iteration_label'],
        'first_stage' => $result['first_stage'],
        'queued' => !empty($result['queued']),
        'message' => !empty($result['queued'])
            ? ($result['message'] ?? "Patch '{$result['iteration_label']}' queued.")
            : "Patch '{$result['iteration_label']}' created. {$result['first_stage']} agent is running in background."
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
