<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/anthropic.php';
require_once __DIR__ . '/prompts.php';

// Allow internal calls (from webhook auto-cascade) to bypass session auth via shared secret
$rawIn = file_get_contents('php://input');
$preInput = json_decode($rawIn, true) ?: [];
$isInternalCall = isset($preInput['internal_secret']) && $preInput['internal_secret'] === MC_INTERNAL_SECRET;
if (!$isInternalCall) {
    requireMCAuth();
}
header('Content-Type: application/json');

// Increase PHP execution time for LLM calls
set_time_limit(240);

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    $input = $preInput; // already decoded above
    $projectId = trim($input['project_id'] ?? '');
    $stage = trim($input['stage'] ?? '');

    if (empty($projectId) || empty($stage)) {
        http_response_code(400);
        echo json_encode(['error' => 'project_id and stage required']);
        exit;
    }

    // Load project
    $projectResult = airtableRequest('GET', MC_PROJECTS_TABLE, [], $projectId);
    if ($projectResult['code'] !== 200) {
        http_response_code(404);
        echo json_encode(['error' => 'Project not found']);
        exit;
    }
    $project = $projectResult['body'];
    $projectName = $project['fields']['name'];

    // Builder is run by the external Builder Runner (polls Mission Control).
    // We just mark the project as needing Builder so the runner picks it up.
    if ($stage === 'builder') {
        airtableRequest('PATCH', MC_PROJECTS_TABLE, [
            'fields' => [
                'current_stage' => 'builder',
                'current_agent' => 'Builder-Runner (queued)',
                'status' => 'builder'  // legacy enum field
            ]
        ], $project['id']);

        // Post a queued status event so the timeline reflects it
        airtableRequest('POST', MC_AGENTSTATUS_TABLE, [
            'fields' => [
                'event_id' => generateUUID(),
                'project_name' => $projectName,
                'stage' => 'builder',
                'stage_v2' => 'builder',
                'agent_name' => 'Builder-Runner (queued)',
                'status' => 'idle',
                'output' => 'Queued for Builder Runner. Long-poll connection will pick this up within ~1s.',
                'tokens_used' => 0,
                'cost' => 0
            ]
        ]);

        echo json_encode([
            'ok' => true,
            'queued' => true,
            'message' => 'Builder Runner will pick this up within ~1 second (long-poll).',
            'project_id' => $project['id']
        ]);
        exit;
    }
    if ($stage === 'smoke_test') {
        echo json_encode(runSmokeTest($project));
        exit;
    }

    // Get agent config for this stage
    $agentCfg = getAgentConfig($stage);
    if (!$agentCfg) {
        http_response_code(400);
        echo json_encode(['error' => "No agent configured for stage: $stage"]);
        exit;
    }

    // Check budget
    $budgetSpent = floatval($project['fields']['budget_spent'] ?? 0);
    $budgetCap = floatval($project['fields']['budget_cap'] ?? 10);
    if ($budgetSpent >= $budgetCap) {
        http_response_code(402);
        echo json_encode(['error' => 'Budget cap reached', 'spent' => $budgetSpent, 'cap' => $budgetCap]);
        exit;
    }

    // Post "running" status FIRST so client knows we picked up the job
    postAgentStatus($projectName, $stage, $agentCfg['name'], 'running',
        'Starting analysis...', 0, 0);

    // Return early to the caller (decide.php) so it doesn't time out waiting.
    // PHP-FPM keeps this request running after fastcgi_finish_request().
    if (function_exists('fastcgi_finish_request')) {
        echo json_encode([
            'ok' => true,
            'started' => true,
            'stage' => $stage,
            'message' => 'Agent running in background.'
        ]);
        fastcgi_finish_request();
        // From here on, output is discarded but execution continues.
    }

    // Assemble context from prior agents (this project)
    $context = assembleContext($projectName, $stage);

    // If this is a patch/iteration, also load context from parent project
    $parentContext = '';
    if (!empty($project['fields']['parent_project_id'])) {
        $parentContext = assembleParentContext($project['fields']['parent_project_id']);
    }

    // Build user message
    $userMessage = "PROJECT NAME: $projectName\n\n";
    if (!empty($project['fields']['patch_mode']) && $project['fields']['patch_mode'] !== 'full') {
        $userMessage .= "PATCH MODE: " . $project['fields']['patch_mode'] . "\n";
        $userMessage .= "You are editing an EXISTING project. Be surgical — don't rewrite, modify only what's needed.\n\n";
    }
    $userMessage .= "REQUIREMENTS / CHANGE REQUEST:\n" . ($project['fields']['requirements'] ?? '') . "\n\n";
    if (!empty($parentContext)) {
        $userMessage .= "PARENT PROJECT (existing code base context):\n" . $parentContext . "\n\n";
    }
    if (!empty($context)) {
        $userMessage .= "PRIOR AGENT OUTPUTS (this iteration):\n" . $context . "\n\n";
    }
    $userMessage .= "Now perform your role as " . strtoupper($stage) . ". Follow your output format strictly.";

    // Call Anthropic
    $result = callAnthropic(
        $agentCfg['model'],
        $agentCfg['system'],
        [['role' => 'user', 'content' => $userMessage]],
        $agentCfg['max_tokens']
    );

    if (!$result['ok']) {
        // Mark as failed
        postAgentStatus($projectName, $stage, $agentCfg['name'], 'failed',
            'Anthropic API error: ' . $result['error'], 0, 0);
        http_response_code(500);
        echo json_encode(['error' => 'Agent call failed: ' . $result['error']]);
        exit;
    }

    // Post output as waiting_approval
    postAgentStatus($projectName, $stage, $agentCfg['name'], 'waiting_approval',
        $result['response'], $result['total_tokens'], $result['cost']);

    // Create approval request (auto-decides if auto-approve flag is set)
    $approvalResult = createApproval($projectName, $stage, $result['response']);

    // Only echo final result if we didn't already finish the request early
    if (!function_exists('fastcgi_finish_request')) {
        echo json_encode([
            'ok' => true,
            'stage' => $stage,
            'agent' => $agentCfg['name'],
            'tokens' => $result['total_tokens'],
            'cost' => $result['cost'],
            'auto_approved' => $approvalResult['auto_approved'] ?? false,
            'output_preview' => substr($result['response'], 0, 300) . '...'
        ]);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
}

/**
 * Post an event to the agent-status webhook (internal call, no auth check needed since same session).
 */
function postAgentStatus($projectName, $stage, $agentName, $status, $output, $tokens, $cost) {
    $eventId = generateUUID();
    $legacyStages = ['scout', 'tester', 'reviewer', 'architect', 'innovator', 'builder'];

    $fields = [
        'event_id' => $eventId,
        'project_name' => $projectName,
        'stage_v2' => $stage,
        'agent_name' => $agentName,
        'status' => $status,
        'output' => $output,
        'tokens_used' => $tokens,
        'cost' => $cost
    ];
    if (in_array($stage, $legacyStages, true)) {
        $fields['stage'] = $stage;
    }

    airtableRequest('POST', MC_AGENTSTATUS_TABLE, ['fields' => $fields]);

    // Also update project: current_agent + budget_spent (cumulative) + status
    $projectsResult = airtableRequest('GET', MC_PROJECTS_TABLE, [
        'filterByFormula' => "name='" . addslashes($projectName) . "'"
    ]);
    if (!empty($projectsResult['body']['records'])) {
        $proj = $projectsResult['body']['records'][0];
        $projectId = $proj['id'];
        $currentSpent = floatval($proj['fields']['budget_spent'] ?? 0);

        $updateFields = [
            'current_agent' => $agentName,
            'budget_spent' => $currentSpent + $cost
        ];

        // Only update current_stage when a stage STARTS (status=running). Never on
        // waiting_approval or complete — those can race with the next stage's
        // running update and roll the project back to the previous stage.
        if ($status === 'running') {
            // current_stage is plain text, accepts any stage
            $updateFields['current_stage'] = $stage;
            // Legacy singleSelect status only for stages that exist in its enum
            if (in_array($stage, $legacyStages, true)) {
                $updateFields['status'] = $stage;
            }
        } elseif ($status === 'failed') {
            $updateFields['status'] = 'failed';
            $updateFields['current_stage'] = 'failed';
        }

        airtableRequest('PATCH', MC_PROJECTS_TABLE, ['fields' => $updateFields], $projectId);
    }
}

/**
 * Create approval record (handles auto-approve internally).
 */
function createApproval($projectName, $stage, $output) {
    $stageToAutoField = [
        'scout' => 'auto_scout', 'architect' => 'auto_architect', 'designer' => 'auto_designer',
        'tester' => 'auto_tester', 'reviewer' => 'auto_reviewer', 'builder' => 'auto_builder',
        'code_reviewer' => 'auto_code_auditor', 'smoke_test' => 'auto_smoke_test',
        'security_auditor' => 'auto_security', 'performance_auditor' => 'auto_performance'
    ];

    $autoApproved = false;
    $projectsResult = airtableRequest('GET', MC_PROJECTS_TABLE, [
        'filterByFormula' => "name='" . addslashes($projectName) . "'"
    ]);
    if (!empty($projectsResult['body']['records'])) {
        $project = $projectsResult['body']['records'][0];
        $autoFlag = $stageToAutoField[$stage] ?? ('auto_' . $stage);
        if (!empty($project['fields'][$autoFlag])) {
            $autoApproved = true;
        }
    }

    $approvalId = generateUUID();
    airtableRequest('POST', MC_APPROVALS_TABLE, [
        'fields' => [
            'approval_id' => $approvalId,
            'project_name' => $projectName,
            'stage' => $stage,
            'agent_output' => $output,
            'decision' => $autoApproved ? 'approved' : 'pending',
            'comments' => $autoApproved ? '[Auto-approved]' : '',
            'telegram_sent' => false
        ]
    ]);

    // If auto-approved, fire next stage immediately (domino cascade)
    if ($autoApproved && isset($project)) {
        $nextStage = getNextStage($stage, $project);
        if ($nextStage === null) {
            $expected = getPipelineStages($project);
            if (!in_array($stage, $expected, true) && !empty($expected)) {
                $nextStage = $expected[0];
            }
        }
        if ($nextStage && $nextStage !== 'deploy') {
            $ch = curl_init('https://agentrocketman.com/mission-control/api/agents/run.php');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'project_id' => $project['id'],
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
        } elseif ($nextStage === 'deploy' && !empty($project['fields']['auto_deploy']) && !empty($project['fields']['deploy_path'])) {
            $ch = curl_init('https://agentrocketman.com/mission-control/api/projects/deploy.php');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'project_id' => $project['id'],
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

    return ['approval_id' => $approvalId, 'auto_approved' => $autoApproved];
}

/**
 * Pull the latest accepted outputs of the parent project's agents.
 * Used for patches — gives the child agents knowledge of how the existing project was designed/built.
 */
function assembleParentContext($parentId) {
    $parentRes = airtableRequest('GET', MC_PROJECTS_TABLE, [], $parentId);
    if ($parentRes['code'] !== 200) return '';
    $parent = $parentRes['body'];
    $parentName = $parent['fields']['name'] ?? '';
    $deployUrl = $parent['fields']['deploy_url'] ?? '';

    $intro = "Parent project: $parentName\n";
    if ($deployUrl) $intro .= "Currently deployed at: $deployUrl\n";
    $intro .= "\n";

    // Get latest waiting_approval/complete events per stage on the parent
    $result = airtableRequest('GET', MC_AGENTSTATUS_TABLE, [
        'filterByFormula' => "AND({project_name}='" . addslashes($parentName) . "', OR({status}='waiting_approval', {status}='complete'))",
        'pageSize' => 100
    ]);
    if (empty($result['body']['records'])) return $intro;

    $records = $result['body']['records'];
    usort($records, function($a, $b) {
        return strcmp($b['createdTime'] ?? '', $a['createdTime'] ?? '');
    });

    $latestByStage = [];
    foreach ($records as $r) {
        $s = $r['fields']['stage_v2'] ?? ($r['fields']['stage'] ?? '');
        if (!isset($latestByStage[$s])) $latestByStage[$s] = $r;
    }

    // Only include the most impactful outputs to save tokens: architect + latest builder
    $important = ['architect', 'builder'];
    $context = $intro;
    foreach ($important as $s) {
        if (isset($latestByStage[$s])) {
            $f = $latestByStage[$s]['fields'];
            $context .= "─── PARENT AGENT: " . strtoupper($s) . " ───\n";
            // Truncate parent outputs to keep token cost reasonable on patches
            $out = $f['output'] ?? '';
            if (strlen($out) > 6000) $out = substr($out, 0, 6000) . "\n...[truncated for token economy]";
            $context .= $out . "\n\n";
        }
    }
    return $context;
}

/**
 * Pull all prior agent outputs for this project + stage.
 * Returns a formatted context string.
 */
function assembleContext($projectName, $currentStage) {
    $stagesBeforeMe = [];
    $allStages = ['scout', 'architect', 'designer', 'tester', 'reviewer', 'builder', 'code_reviewer', 'smoke_test', 'security_auditor', 'performance_auditor'];
    foreach ($allStages as $s) {
        if ($s === $currentStage) break;
        $stagesBeforeMe[] = $s;
    }

    $result = airtableRequest('GET', MC_AGENTSTATUS_TABLE, [
        'filterByFormula' => "AND({project_name}='" . addslashes($projectName) . "', OR({status}='waiting_approval', {status}='complete'))",
        'pageSize' => 100
    ]);

    if (empty($result['body']['records'])) return '';

    // Sort by createdTime descending, then dedupe to latest per stage
    $records = $result['body']['records'];
    usort($records, function($a, $b) {
        return strcmp($b['createdTime'] ?? '', $a['createdTime'] ?? '');
    });

    $latestByStage = [];
    foreach ($records as $r) {
        $s = $r['fields']['stage_v2'] ?? ($r['fields']['stage'] ?? '');
        if (!isset($latestByStage[$s])) {
            $latestByStage[$s] = $r;
        }
    }

    $context = '';
    foreach ($stagesBeforeMe as $s) {
        if (isset($latestByStage[$s])) {
            $f = $latestByStage[$s]['fields'];
            $context .= "═══════════════════════════════════\n";
            $context .= "AGENT: " . ($f['agent_name'] ?? $s) . " (" . strtoupper($s) . ")\n";
            $context .= "═══════════════════════════════════\n";
            $context .= $f['output'] . "\n\n";
        }
    }
    return $context;
}

/**
 * Build the exact Claude Code CLI command for the user to run locally for Builder stage.
 */
function buildClaudeCodeCommand($project) {
    $f = $project['fields'];
    $name = $f['name'];
    $reqs = $f['requirements'];
    $deployPath = $f['deploy_path'] ?? '/output/';

    // Sanitize for shell
    $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
    $workDir = "/data/.openclaw/workspace/builds/$safeName";

    return "mkdir -p $workDir && cd $workDir && claude -p \"Build the project '$name'. Requirements:\n\n$reqs\n\nDeploy target: $deployPath\n\nFollow the spec from prior agents (Scout, Architect, etc.). Run tests with: npm test (or equivalent). When done, post Builder output to Mission Control webhook.\" --allowedTools Read Write Edit \"Bash(npm install:*)\" \"Bash(npm test:*)\" \"Bash(php:*)\"";
}

/**
 * Run a simple smoke test against the deploy URL (curl-based, no Playwright yet).
 */
function runSmokeTest($project) {
    $f = $project['fields'];
    $name = $f['name'];
    $deployPath = $f['deploy_path'] ?? null;

    if (empty($deployPath)) {
        return ['ok' => false, 'error' => 'No deploy_path set, cannot smoke test'];
    }

    // Normalize
    if ($deployPath[0] !== '/') $deployPath = '/' . $deployPath;
    if (substr($deployPath, -1) !== '/') $deployPath .= '/';
    $url = 'https://agentrocketman.com' . $deployPath;

    postAgentStatus($name, 'smoke_test', 'Smoke-Tester', 'running', "Hitting $url with curl...", 0, 0);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_NOBODY, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    $size = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
    curl_close($ch);

    $passes = [];
    $fails = [];

    if ($httpCode >= 200 && $httpCode < 300) {
        $passes[] = "HTTP $httpCode (OK)";
    } else {
        $fails[] = "HTTP $httpCode (expected 2xx)";
    }
    if ($totalTime < 3.0) {
        $passes[] = "Response time " . round($totalTime, 2) . "s (under 3s)";
    } else {
        $fails[] = "Slow: " . round($totalTime, 2) . "s";
    }
    if ($size > 100) {
        $passes[] = "Page size " . $size . " bytes (non-empty)";
    } else {
        $fails[] = "Page nearly empty: $size bytes";
    }

    $output = "💨 SMOKE TEST RESULTS for $url\n\n";
    $output .= "✅ PASSED (" . count($passes) . "):\n" . implode("\n", array_map(fn($p) => "  • $p", $passes)) . "\n\n";
    if (count($fails) > 0) {
        $output .= "❌ FAILED (" . count($fails) . "):\n" . implode("\n", array_map(fn($f) => "  • $f", $fails)) . "\n\n";
    }
    $output .= "Note: This is basic curl-based smoke testing. Real browser-level checks (Playwright) require local runner — coming next.";

    $status = count($fails) === 0 ? 'waiting_approval' : 'failed';
    postAgentStatus($name, 'smoke_test', 'Smoke-Tester', $status, $output, 0, 0);

    if ($status === 'waiting_approval') {
        $approval = createApproval($name, 'smoke_test', $output);
        return ['ok' => true, 'passes' => count($passes), 'fails' => 0, 'auto_approved' => $approval['auto_approved'] ?? false];
    } else {
        return ['ok' => false, 'passes' => count($passes), 'fails' => count($fails), 'output' => $output];
    }
}
