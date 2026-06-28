<?php
require_once __DIR__ . '/../config.php';

requireMCAuth();

// SSE headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

// Disable output buffering
if (ob_get_level()) ob_end_clean();

// Keep track of last seen event
$lastEventId = $_GET['lastEventId'] ?? '';

// Send a comment to keep connection alive
function sendComment($msg) {
    echo ": $msg\n\n";
    flush();
}

// Send an event
function sendEvent($eventName, $data) {
    echo "event: $eventName\n";
    echo "data: " . json_encode($data) . "\n\n";
    flush();
}

sendComment('Connected to Mission Control event stream');

// Poll Airtable every 3 seconds for new events
while (true) {
    try {
        // Get recent agent status events
        $query = [
            'sort[0][field]' => 'event_id',
            'sort[0][direction]' => 'desc',
            'maxRecords' => 10
        ];

        $result = airtableRequest('GET', MC_AGENTSTATUS_TABLE, $query);

        if ($result['code'] === 200) {
            $records = $result['body']['records'] ?? [];

            // Send new events
            foreach (array_reverse($records) as $record) {
                $eventId = $record['fields']['event_id'] ?? '';

                if ($eventId && $eventId !== $lastEventId) {
                    sendEvent('status', [
                        'event_id' => $eventId,
                        'project_name' => $record['fields']['project_name'] ?? '',
                        'stage' => $record['fields']['stage'] ?? '',
                        'agent_name' => $record['fields']['agent_name'] ?? '',
                        'status' => $record['fields']['status'] ?? '',
                        'output' => $record['fields']['output'] ?? '',
                        'tokens_used' => $record['fields']['tokens_used'] ?? 0,
                        'cost' => $record['fields']['cost'] ?? 0
                    ]);

                    $lastEventId = $eventId;
                }
            }
        }

        // Check for new approvals
        $approvalResult = airtableRequest('GET', MC_APPROVALS_TABLE, [
            'filterByFormula' => "decision='pending'",
            'maxRecords' => 5
        ]);

        if ($approvalResult['code'] === 200) {
            $approvals = $approvalResult['body']['records'] ?? [];
            if (!empty($approvals)) {
                sendEvent('approval', [
                    'count' => count($approvals),
                    'approvals' => $approvals
                ]);
            }
        }

        sendComment('heartbeat');

    } catch (Exception $e) {
        sendComment('Error: ' . $e->getMessage());
    }

    // Check if client disconnected
    if (connection_aborted()) {
        break;
    }

    sleep(3);
}
