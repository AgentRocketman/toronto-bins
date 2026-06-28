<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../agents/prompts.php';
require_once __DIR__ . '/../projects/patch-functions.php';

requireMCAuth();
header('Content-Type: application/json');
set_time_limit(240);

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    $approvalId = trim($input['approval_id'] ?? '');
    $decision = trim($input['decision'] ?? '');
    $comments = trim($input['comments'] ?? '');

    if (empty($approvalId) || !in_array($decision, ['approved', 'rejected'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid approval ID or decision']);
        exit;
    }

    // Find the approval by approval_id
    $approvalResult = airtableRequest('GET', MC_APPROVALS_TABLE, [
        'filterByFormula' => "approval_id='" . addslashes($approvalId) . "'"
    ]);

    if (empty($approvalResult['body']['records'])) {
        http_response_code(404);
        echo json_encode(['error' => 'Approval not found']);
        exit;
    }

    $approval = $approvalResult['body']['records'][0];
    $airtableId = $approval['id'];
    $projectName = $approval['fields']['project_name'] ?? '';
    $previousDecision = $approval['fields']['decision'] ?? 'pending';

    // Prevent double-processing: only proceed if it was pending
    if ($previousDecision !== 'pending') {
        echo json_encode([
            'success' => true,
            'decision' => $previousDecision,
            'note' => 'Already ' . $previousDecision . ' — no-op'
        ]);
        exit;
    }

    // Update approval decision
    $updateResult = airtableRequest('PATCH', MC_APPROVALS_TABLE, [
        'fields' => [
            'decision' => $decision,
            'comments' => $comments
        ]
    ], $airtableId);

    if ($updateResult['code'] !== 200) {
        http_response_code($updateResult['code']);
        echo json_encode(['error' => 'Failed to update approval', 'details' => $updateResult['body']]);
        exit;
    }

    // If rejected, pause the project and optionally create an iteration
    $iterationCreated = false;
    $iterationResult = null;
    if ($decision === 'rejected' && !empty($projectName)) {
        $projectResult = airtableRequest('GET', MC_PROJECTS_TABLE, [
            'filterByFormula' => "name='" . addslashes($projectName) . "'"
        ]);

        if (!empty($projectResult['body']['records'])) {
            $project = $projectResult['body']['records'][0];
            $projectId = $project['id'];
            $projectFields = $project['fields'];

            // Pause the project
            airtableRequest('PATCH', MC_PROJECTS_TABLE, [
                'fields' => ['status' => 'paused']
            ], $projectId);

            // Auto-iterate if rejection has comments
            if (!empty($comments)) {
                // Determine mode: reuse parent's patch_mode if set, otherwise infer
                $parentPatchMode = $projectFields['patch_mode'] ?? '';
                if ($parentPatchMode === 'quick_fix') {
                    $iterationMode = 'quick_fix';
                } elseif (in_array($parentPatchMode, ['bug_fix', 'add_feature'], true)) {
                    $iterationMode = $parentPatchMode;
                } else {
                    // 'full' builds or missing patch_mode -> default to bug_fix
                    $iterationMode = 'bug_fix';
                }

                // Calculate budget cap
                $parentBudgetCap = floatval($projectFields['budget_cap'] ?? 0.00);
                $parentBudgetSpent = floatval($projectFields['budget_spent'] ?? 0.00);
                $remainingBudget = $parentBudgetCap - $parentBudgetSpent;

                // Default caps per mode
                $defaultCaps = [
                    'quick_fix' => 3.00,
                    'bug_fix' => 3.00,
                    'add_feature' => 5.00
                ];
                $desiredCap = $defaultCaps[$iterationMode];

                if ($remainingBudget > 0) {
                    $iterationBudgetCap = min($desiredCap, $remainingBudget);

                    // Create the iteration
                    $iterationResult = mcCreatePatchProject(
                        $projectId,
                        $iterationMode,
                        $comments,
                        $iterationBudgetCap,
                        'mc-runner-heartbeat-2026'
                    );

                    if ($iterationResult['success']) {
                        $iterationCreated = true;
                    }
                } else {
                    // Budget exhausted
                    $iterationResult = [
                        'success' => false,
                        'error' => 'Budget exhausted — cannot create iteration'
                    ];
                }
            }
        }
    }

    // On approval, kick off the NEXT stage agent (if there is one)
    $nextStage = null;
    $nextStageResult = null;
    if ($decision === 'approved' && !empty($projectName)) {
        $stage = $approval['fields']['stage'] ?? '';

        // Reload project to compute next stage based on its enable flags
        $projectResult = airtableRequest('GET', MC_PROJECTS_TABLE, [
            'filterByFormula' => "name='" . addslashes($projectName) . "'"
        ]);
        if (!empty($projectResult['body']['records'])) {
            $project = $projectResult['body']['records'][0];
            $nextStage = getNextStage($stage, $project);

            // Fallback: if the approved stage isn't in this project's mode pipeline
            // (e.g. Scout ran on a quick_fix project), advance to the actual first stage of the mode.
            if ($nextStage === null) {
                $expectedStages = getPipelineStages($project);
                if (!in_array($stage, $expectedStages, true) && !empty($expectedStages)) {
                    $nextStage = $expectedStages[0];
                }
            }

            if ($nextStage && $nextStage !== 'deploy') {
                // Trigger next agent ASYNC — fire-and-forget so we return fast
                $cookieStr = isset($_COOKIE['PHPSESSID']) ? 'PHPSESSID=' . $_COOKIE['PHPSESSID'] : '';
                $ch = curl_init('https://agentrocketman.com/mission-control/api/agents/run.php');
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                    'project_id' => $project['id'],
                    'stage' => $nextStage
                ]));
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Cookie: ' . $cookieStr
                ]);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                // Need enough time for HTTPS handshake + PHP-FPM to start the script.
                // We use fastcgi_finish_request on the receiving side to return immediately
                // so this curl can disconnect, but PHP-FPM keeps the request running.
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
                curl_setopt($ch, CURLOPT_TIMEOUT, 3);
                curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
                curl_exec($ch);
                curl_close($ch);
                $nextStageResult = ['triggered_async' => true];
            } elseif ($nextStage === 'deploy') {
                // Trigger deploy if auto_deploy enabled, otherwise leave for manual
                if (!empty($project['fields']['auto_deploy']) && !empty($project['fields']['deploy_path'])) {
                    $cookieStr = isset($_COOKIE['PHPSESSID']) ? 'PHPSESSID=' . $_COOKIE['PHPSESSID'] : '';
                    $ch = curl_init('https://agentrocketman.com/mission-control/api/projects/deploy.php');
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['project_id' => $project['id']]));
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Cookie: ' . $cookieStr]);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
                    $resp = curl_exec($ch);
                    curl_close($ch);
                    $nextStageResult = ['deployed' => true, 'response' => json_decode($resp, true)];
                } else {
                    $nextStageResult = ['deploy_pending_manual' => true];
                }
            }
        }
    }

    $response = [
        'success' => true,
        'decision' => $decision,
        'next_stage' => $nextStage,
        'next_stage_result' => $nextStageResult
    ];

    // Include iteration details if one was created
    if ($iterationCreated && $iterationResult) {
        $response['iteration_created'] = true;
        $response['child_project_id'] = $iterationResult['project_id'];
        $response['iteration_label'] = $iterationResult['iteration_label'];
        $response['first_stage'] = $iterationResult['first_stage'];
        $response['message'] = "Rejected and created iteration {$iterationResult['iteration_label']} — {$iterationResult['first_stage']} is running.";
    } elseif ($decision === 'rejected' && $iterationResult && !$iterationResult['success']) {
        $response['iteration_created'] = false;
        $response['iteration_error'] = $iterationResult['error'];
        $response['message'] = 'Rejected and paused. ' . $iterationResult['error'];
    } elseif ($decision === 'rejected' && empty($comments)) {
        $response['iteration_created'] = false;
        $response['message'] = 'Rejected and paused. Provide rejection comments to auto-iterate.';
    }

    echo json_encode($response);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
