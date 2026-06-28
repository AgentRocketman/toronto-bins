<?php
require_once __DIR__ . '/helpers.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

try {
    $urls = readUrlData();
    $stats = readStatsData();

    $combined = [];

    foreach ($urls as $code => $urlData) {
        $statsData = $stats[$code] ?? ['count' => 0, 'lastAccess' => null];

        $combined[] = [
            'code' => $code,
            'url' => $urlData['url'],
            'created' => $urlData['created'],
            'customAlias' => $urlData['customAlias'] ?? false,
            'count' => $statsData['count'],
            'lastAccess' => $statsData['lastAccess']
        ];
    }

    usort($combined, function($a, $b) {
        return strtotime($b['created']) - strtotime($a['created']);
    });

    sendJsonResponse(['success' => true, 'data' => $combined]);

} catch (Exception $e) {
    error_log('Stats API error: ' . $e->getMessage());
    sendJsonResponse(['success' => false, 'error' => 'Failed to load analytics data'], 500);
}
