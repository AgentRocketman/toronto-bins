<?php
require_once __DIR__ . '/../config.php';

requireMCAuth();
header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    // Paginate through all status events (Airtable returns max 100 per page)
    $allRecords = [];
    $offset = null;
    $maxPages = 10; // safety cap → 1000 most recent events
    $page = 0;
    do {
        $params = ['pageSize' => 100];
        if ($offset) $params['offset'] = $offset;
        $result = airtableRequest('GET', MC_AGENTSTATUS_TABLE, $params);
        if ($result['code'] !== 200) {
            http_response_code($result['code']);
            echo json_encode(['error' => 'Failed to fetch agent statuses', 'details' => $result['body']]);
            exit;
        }
        $body = $result['body'];
        $allRecords = array_merge($allRecords, $body['records'] ?? []);
        $offset = $body['offset'] ?? null;
        $page++;
    } while ($offset && $page < $maxPages);

    // Sort by createdTime descending so latest comes first
    usort($allRecords, function($a, $b) {
        return strcmp($b['createdTime'] ?? '', $a['createdTime'] ?? '');
    });

    // Group by (project, stage, status) so the front-end has the FULL history
    // — i.e. it keeps both the 'running' AND 'waiting_approval' (or 'complete') events
    // per stage. The client needs both timestamps to compute duration.
    $seen = [];
    $kept = [];
    foreach ($allRecords as $record) {
        $fields = $record['fields'];
        $projectName = $fields['project_name'] ?? '';
        $stage = $fields['stage_v2'] ?? ($fields['stage'] ?? '');
        $status = $fields['status'] ?? '';
        $record['fields']['stage'] = $stage; // normalize

        $key = $projectName . '|' . $stage . '|' . $status;
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $kept[] = $record;
        }
    }

    echo json_encode([
        'success' => true,
        'statuses' => $kept,
        'total_fetched' => count($allRecords),
        'kept' => count($kept)
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
