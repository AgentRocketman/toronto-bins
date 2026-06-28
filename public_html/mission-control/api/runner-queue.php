<?php
// Long-poll endpoint for the Builder Runner.
// Runner connects with ?wait=25 (seconds). We hang the connection until
// a builder-needed job appears in Airtable, then return immediately.
// If timeout elapses, return empty (runner reconnects).
//
// Auth: shared secret in ?secret= (same as runner-heartbeat).

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$secret = $_GET['secret'] ?? '';
if ($secret !== 'mc-runner-heartbeat-2026') {
    http_response_code(401);
    echo json_encode(['error' => 'bad secret']);
    exit;
}

$waitSec = (int)($_GET['wait'] ?? 25);
if ($waitSec < 1) $waitSec = 1;
if ($waitSec > 25) $waitSec = 25; // keep under PHP-FPM/Hostinger timeout

set_time_limit($waitSec + 10);

$start = time();
$pollInterval = 1; // seconds between Airtable checks

while ((time() - $start) < $waitSec) {
    // Look for projects with current_stage=builder that don't already have a running/waiting/complete event
    $r = airtableRequest('GET', MC_PROJECTS_TABLE, [
        'filterByFormula' => "{current_stage}='builder'",
        'maxRecords' => 10
    ]);
    if ($r['code'] === 200 && !empty($r['body']['records'])) {
        foreach ($r['body']['records'] as $project) {
            $projectName = $project['fields']['name'] ?? '';
            if (!$projectName) continue;

            // Check if there's already an active Builder event for this project
            $events = airtableRequest('GET', MC_AGENTSTATUS_TABLE, [
                'filterByFormula' => "AND({project_name}='" . addslashes($projectName) . "', {stage}='builder', OR({status}='running', {status}='waiting_approval', {status}='complete'))",
                'maxRecords' => 1
            ]);
            $alreadyHandled = !empty($events['body']['records']);
            if ($alreadyHandled) continue;

            // Found a fresh job — return it immediately
            echo json_encode([
                'job' => true,
                'project_id' => $project['id'],
                'project_name' => $projectName,
                'fields' => $project['fields']
            ]);
            exit;
        }
    }
    sleep($pollInterval);
}

// Timeout — return empty so runner reconnects
echo json_encode(['job' => false, 'waited' => $waitSec]);
