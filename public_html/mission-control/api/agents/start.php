<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/prompts.php';

// Allow internal calls (from the server-side queue runner) to bypass session auth via shared secret
$rawIn = file_get_contents('php://input');
$preInput = json_decode($rawIn, true) ?: [];
$isInternalCall = isset($preInput['internal_secret']) && $preInput['internal_secret'] === MC_INTERNAL_SECRET;
if (!$isInternalCall) {
    requireMCAuth();
}
header('Content-Type: application/json');

function triggerAsync($url, $payload, $cookieStr) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Cookie: ' . $cookieStr
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // More generous timeouts so PHP-FPM cold-start doesn't kill the trigger
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, 4);
    curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
    curl_exec($ch);
    curl_close($ch);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    $input = $preInput; // already decoded above
    $projectId = trim($input['project_id'] ?? '');
    if (empty($projectId)) {
        http_response_code(400);
        echo json_encode(['error' => 'project_id required']);
        exit;
    }

    // Load the project to determine the correct first stage based on patch_mode
    $projResult = airtableRequest('GET', MC_PROJECTS_TABLE, [], $projectId);
    if ($projResult['code'] !== 200) {
        http_response_code(404);
        echo json_encode(['error' => 'Project not found']);
        exit;
    }
    $project = $projResult['body'];
    $firstStage = getFirstStage($project);

    // CRITICAL: mark the project as in-progress on the right stage BEFORE firing the
    // async trigger. If the fire-and-forget fails (PHP-FPM cold start, etc.), this
    // lets the runner/webhook pick it up. Also prevents the dashboard from showing
    // a stale "draft" state with the Start button.
    $patchFields = ['current_stage' => $firstStage];
    if ($firstStage === 'builder') {
        $patchFields['current_agent'] = 'Builder-Runner (queued)';
        $patchFields['status'] = 'builder';
    } else {
        $patchFields['current_agent'] = ucfirst($firstStage) . ' (queued)';
        // legacy status field is singleSelect; only set when we know the value is valid
        $legacy = ['scout','architect','tester','reviewer','builder'];
        if (in_array($firstStage, $legacy, true)) {
            $patchFields['status'] = $firstStage;
        }
    }
    airtableRequest('PATCH', MC_PROJECTS_TABLE, ['fields' => $patchFields], $projectId);

    $cookieStr = isset($_COOKIE['PHPSESSID']) ? 'PHPSESSID=' . $_COOKIE['PHPSESSID'] : '';

    triggerAsync(
        'https://agentrocketman.com/mission-control/api/agents/run.php',
        [
            'project_id' => $projectId,
            'stage' => $firstStage,
            'internal_secret' => 'mc-runner-heartbeat-2026'
        ],
        $cookieStr
    );

    echo json_encode([
        'started' => true,
        'stage' => $firstStage,
        'message' => $firstStage === 'builder'
            ? 'Builder Runner will pick up within ~1s (long-poll).'
            : ucfirst($firstStage) . '-1 is running in the background. Refresh in 30-60s.'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
