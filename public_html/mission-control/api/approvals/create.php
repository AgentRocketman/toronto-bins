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

    $projectName = trim($input['project_name'] ?? '');
    $stage = trim($input['stage'] ?? '');
    $agentOutput = trim($input['agent_output'] ?? '');

    if (empty($projectName) || empty($stage) || empty($agentOutput)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields']);
        exit;
    }

    // Generate approval ID
    $approvalId = generateUUID();

    // Check if auto-approve is enabled for this project + stage
    // Map stage names to auto-approve field names (some stages have shorter field names)
    $stageToAutoField = [
        'scout' => 'auto_scout',
        'architect' => 'auto_architect',
        'designer' => 'auto_designer',
        'tester' => 'auto_tester',
        'reviewer' => 'auto_reviewer',
        'builder' => 'auto_builder',
        'code_reviewer' => 'auto_code_auditor', // field name kept for backwards compat
        'code_auditor' => 'auto_code_auditor',  // legacy stage name still works
        'smoke_test' => 'auto_smoke_test',
        'security_auditor' => 'auto_security',
        'performance_auditor' => 'auto_performance',
        'innovator' => 'auto_innovator' // legacy
    ];
    $autoApproved = false;
    $projectsResult = airtableRequest('GET', MC_PROJECTS_TABLE, [
        'filterByFormula' => "name='" . addslashes($projectName) . "'"
    ]);
    if (!empty($projectsResult['body']['records'])) {
        $project = $projectsResult['body']['records'][0];
        $autoFlagField = $stageToAutoField[$stage] ?? ('auto_' . $stage);
        if (!empty($project['fields'][$autoFlagField])) {
            $autoApproved = true;
        }
    }

    // Create approval
    $result = airtableRequest('POST', MC_APPROVALS_TABLE, [
        'fields' => [
            'approval_id' => $approvalId,
            'project_name' => $projectName,
            'stage' => $stage,
            'agent_output' => $agentOutput,
            'decision' => $autoApproved ? 'approved' : 'pending',
            'comments' => $autoApproved ? '[Auto-approved by project setting]' : '',
            'telegram_sent' => false
        ]
    ]);

    if ($result['code'] !== 200) {
        http_response_code($result['code']);
        echo json_encode(['error' => 'Failed to create approval', 'details' => $result['body']]);
        exit;
    }

    // Send Telegram notification (skip if auto-approved — no human action needed)
    $telegramSent = false;
    if (!$autoApproved) {
        $outputPreview = substr($agentOutput, 0, 200);
        if (strlen($agentOutput) > 200) {
            $outputPreview .= '...';
        }

        $message = "🚨 *Approval Needed*\n";
        $message .= "Project: $projectName\n";
        $message .= "Stage: $stage\n\n";
        $message .= "$outputPreview\n\n";
        $message .= "Decide: https://agentrocketman.com/mission-control/pipeline.html";

        $telegramSent = sendTelegramNotification($message);
    }

    // Update telegram_sent flag
    if ($telegramSent) {
        airtableRequest('PATCH', MC_APPROVALS_TABLE, [
            'fields' => ['telegram_sent' => true]
        ], $result['body']['id']);
    }

    echo json_encode([
        'success' => true,
        'approval_id' => $approvalId,
        'auto_approved' => $autoApproved,
        'telegram_sent' => $telegramSent
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
