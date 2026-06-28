<?php
require_once __DIR__ . '/api/helpers.php';

$code = $_GET['code'] ?? '';

if (empty($code)) {
    http_response_code(404);
    include __DIR__ . '/error.html';
    exit;
}

try {
    $urls = readUrlData();

    if (!isset($urls[$code])) {
        http_response_code(404);
        include __DIR__ . '/error.html';
        exit;
    }

    $urlData = $urls[$code];
    $targetUrl = $urlData['url'];

    $urlValidation = validateUrl($targetUrl);
    if (!$urlValidation['valid']) {
        http_response_code(400);
        echo 'Invalid redirect URL';
        exit;
    }

    // CR-H2 fix: Atomic stats increment inside single lock hold
    $fp = fopen(STATS_FILE, 'c+');
    if (!$fp) {
        error_log('Failed to open stats.json for increment');
    } else {
        if (acquireLock($fp, LOCK_EX)) {
            try {
                // Read current stats inside lock
                $content = '';
                if (filesize(STATS_FILE) > 0) {
                    rewind($fp);
                    $content = fread($fp, filesize(STATS_FILE));
                }
                $stats = $content ? json_decode($content, true) : [];
                if ($stats === null) {
                    $stats = [];
                }

                if (!isset($stats[$code])) {
                    $stats[$code] = ['count' => 0, 'lastAccess' => null];
                }

                $stats[$code]['count']++;
                $stats[$code]['lastAccess'] = date('c');

                // Write atomically
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, json_encode($stats, JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP));
                fflush($fp);
            } finally {
                flock($fp, LOCK_UN);
                fclose($fp);
            }
        } else {
            error_log('Failed to acquire lock on stats.json for increment');
            fclose($fp);
        }
    }

    header('Location: ' . $targetUrl, true, 302);
    exit;

} catch (Exception $e) {
    // CR-M1 fix: Log detailed error server-side, return generic message to client
    error_log('Redirect error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo 'Service temporarily unavailable';
    exit;
}
