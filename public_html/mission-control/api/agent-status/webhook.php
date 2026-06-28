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
    $agentName = trim($input['agent_name'] ?? '');
    $status = trim($input['status'] ?? '');
    $output = trim($input['output'] ?? '');
    $tokensUsed = intval($input['tokens_used'] ?? 0);
    $cost = floatval($input['cost'] ?? 0.00);

    if (empty($projectName) || empty($stage) || empty($agentName) || empty($status)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields']);
        exit;
    }

    // Generate event ID
    $eventId = generateUUID();

    // Old singleSelect stage field only accepts these values; new stages go in stage_v2 only
    $legacyStages = ['scout', 'tester', 'reviewer', 'architect', 'innovator', 'builder'];
    $fieldsToWrite = [
        'event_id' => $eventId,
        'project_name' => $projectName,
        'stage_v2' => $stage,
        'agent_name' => $agentName,
        'status' => $status,
        'output' => $output,
        'tokens_used' => $tokensUsed,
        'cost' => $cost
    ];
    if (in_array($stage, $legacyStages, true)) {
        $fieldsToWrite['stage'] = $stage;
    }

    // Insert agent status event
    $eventResult = airtableRequest('POST', MC_AGENTSTATUS_TABLE, [
        'fields' => $fieldsToWrite
    ]);

    if ($eventResult['code'] !== 200) {
        http_response_code($eventResult['code']);
        echo json_encode(['error' => 'Failed to create agent status event', 'details' => $eventResult['body']]);
        exit;
    }

    // Auto-create approval record when an agent reports waiting_approval (only if no pending one exists for this stage)
    if ($status === 'waiting_approval') {
        $existing = airtableRequest('GET', MC_APPROVALS_TABLE, [
            'filterByFormula' => "AND({project_name}='" . addslashes($projectName) . "', {stage}='" . addslashes($stage) . "', {decision}='pending')"
        ]);
        if (empty($existing['body']['records'])) {
            // Check auto-approve flag
            $stageToAutoField = [
                'scout' => 'auto_scout', 'architect' => 'auto_architect', 'designer' => 'auto_designer',
                'tester' => 'auto_tester', 'reviewer' => 'auto_reviewer', 'builder' => 'auto_builder',
                'code_reviewer' => 'auto_code_auditor', 'smoke_test' => 'auto_smoke_test',
                'security_auditor' => 'auto_security', 'performance_auditor' => 'auto_performance'
            ];
            $autoApproved = false;
            $projCheck = airtableRequest('GET', MC_PROJECTS_TABLE, [
                'filterByFormula' => "name='" . addslashes($projectName) . "'"
            ]);
            if (!empty($projCheck['body']['records'])) {
                $autoFlag = $stageToAutoField[$stage] ?? ('auto_' . $stage);
                if (!empty($projCheck['body']['records'][0]['fields'][$autoFlag])) {
                    $autoApproved = true;
                }
            }

            airtableRequest('POST', MC_APPROVALS_TABLE, [
                'fields' => [
                    'approval_id' => generateUUID(),
                    'project_name' => $projectName,
                    'stage' => $stage,
                    'agent_output' => substr($output, 0, 100000),
                    'decision' => $autoApproved ? 'approved' : 'pending',
                    'comments' => $autoApproved ? '[Auto-approved by project setting]' : '',
                    'telegram_sent' => false
                ]
            ]);

            // If auto-approved, fire next stage immediately (no UI click needed)
            if ($autoApproved && !empty($projCheck['body']['records'])) {
                $autoProject = $projCheck['body']['records'][0];
                require_once __DIR__ . '/../agents/prompts.php';
                $nextStage = getNextStage($stage, $autoProject);
                if ($nextStage === null) {
                    $expectedStages = getPipelineStages($autoProject);
                    if (!in_array($stage, $expectedStages, true) && !empty($expectedStages)) {
                        $nextStage = $expectedStages[0];
                    }
                }
                if ($nextStage && $nextStage !== 'deploy') {
                    // Fire next agent async (fire-and-forget)
                    $ch = curl_init('https://agentrocketman.com/mission-control/api/agents/run.php');
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                        'project_id' => $autoProject['id'],
                        'stage' => $nextStage,
                        'internal_secret' => 'mc-runner-heartbeat-2026'
                    ]));
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
                    curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
                    curl_exec($ch);
                    curl_close($ch);
                } elseif ($nextStage === 'deploy' && !empty($autoProject['fields']['auto_deploy']) && !empty($autoProject['fields']['deploy_path'])) {
                    // Fire deploy directly
                    $ch = curl_init('https://agentrocketman.com/mission-control/api/projects/deploy.php');
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                        'project_id' => $autoProject['id'],
                        'internal_secret' => 'mc-runner-heartbeat-2026'
                    ]));
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
                    curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
                    curl_exec($ch);
                    curl_close($ch);
                }
            }
        }
    }

    // Find and update the project
    $projectsResult = airtableRequest('GET', MC_PROJECTS_TABLE, [
        'filterByFormula' => "name='" . addslashes($projectName) . "'"
    ]);

    if (!empty($projectsResult['body']['records'])) {
        $project = $projectsResult['body']['records'][0];
        $projectId = $project['id'];
        $currentBudgetSpent = floatval($project['fields']['budget_spent'] ?? 0);
        $budgetCap = floatval($project['fields']['budget_cap'] ?? 10.00);

        $newBudgetSpent = $currentBudgetSpent + $cost;

        // Update project
        $updateFields = [
            'current_agent' => $agentName,
            'budget_spent' => $newBudgetSpent
        ];

        // Only update current_stage when a stage STARTS (running). waiting_approval
        // and complete updates can race with the NEXT stage's running write and
        // roll the project back. Stage badges use the events table directly anyway.
        if ($status === 'running') {
            $updateFields['current_stage'] = $stage;
            if (in_array($stage, $legacyStages, true)) {
                $updateFields['status'] = $stage;
            }
        } elseif ($status === 'failed') {
            $updateFields['status'] = 'failed';
            $updateFields['current_stage'] = 'failed';
        }

        airtableRequest('PATCH', MC_PROJECTS_TABLE, [
            'fields' => $updateFields
        ], $projectId);

        // Auto-deploy: fires when the LAST enabled stage before Deploy completes.
        // Order: builder → code_reviewer → smoke_test → security_auditor (opt) → performance_auditor (opt) → deploy
        $isLastStage = false;
        $secEnabled  = !empty($project['fields']['enable_security']);
        $perfEnabled = !empty($project['fields']['enable_performance']);

        if ($stage === 'performance_auditor') {
            $isLastStage = $perfEnabled;
        } elseif ($stage === 'security_auditor') {
            $isLastStage = $secEnabled && !$perfEnabled;
        } elseif ($stage === 'smoke_test') {
            $isLastStage = !$secEnabled && !$perfEnabled;
        } elseif ($stage === 'code_reviewer' || $stage === 'code_auditor') {
            // Last only if Smoke Test, Security, and Performance are all disabled (rare)
            $isLastStage = !$secEnabled && !$perfEnabled;
            // Note: Smoke Test is a default stage now, so this case shouldn't fire normally
        }

        if ($isLastStage && $status === 'complete' && !empty($project['fields']['auto_deploy']) && !empty($project['fields']['deploy_path'])) {
            $deployPath = $project['fields']['deploy_path'];
            if ($deployPath[0] !== '/') $deployPath = '/' . $deployPath;
            if (substr($deployPath, -1) !== '/') $deployPath = $deployPath . '/';
            $deployUrl = 'https://agentrocketman.com' . $deployPath;

            airtableRequest('PATCH', MC_PROJECTS_TABLE, [
                'fields' => [
                    'deploy_status' => 'deployed',
                    'deploy_url' => $deployUrl
                ]
            ], $projectId);

            // Log auto-deploy event
            airtableRequest('POST', MC_AGENTSTATUS_TABLE, [
                'fields' => [
                    'event_id' => generateUUID(),
                    'project_name' => $projectName,
                    'stage' => 'builder',
                    'agent_name' => 'Deploy-Bot (auto)',
                    'status' => 'complete',
                    'output' => "Auto-deployed to $deployUrl",
                    'tokens_used' => 0,
                    'cost' => 0
                ]
            ]);
        }

        // Check budget and create approval if exceeded
        if ($newBudgetSpent >= $budgetCap) {
            airtableRequest('PATCH', MC_PROJECTS_TABLE, [
                'fields' => ['status' => 'paused']
            ], $projectId);

            // Create approval request
            $approvalId = generateUUID();
            airtableRequest('POST', MC_APPROVALS_TABLE, [
                'fields' => [
                    'approval_id' => $approvalId,
                    'project_name' => $projectName,
                    'stage' => $stage,
                    'agent_output' => "Budget cap ($" . number_format($budgetCap, 2) . ") reached. Current spend: $" . number_format($newBudgetSpent, 2),
                    'decision' => 'pending',
                    'telegram_sent' => false
                ]
            ]);

            // Send Telegram notification
            $message = "🚨 *Budget Alert*\n";
            $message .= "Project: $projectName\n";
            $message .= "Budget Cap: $" . number_format($budgetCap, 2) . "\n";
            $message .= "Current Spend: $" . number_format($newBudgetSpent, 2) . "\n\n";
            $message .= "Project paused. Decide: https://agentrocketman.com/mission-control/pipeline.html";

            sendTelegramNotification($message);
        }
    }

    echo json_encode([
        'success' => true,
        'event_id' => $eventId
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
