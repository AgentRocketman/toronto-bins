<?php
require_once __DIR__ . '/helpers.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$rateLimit = checkRateLimit();
if (!$rateLimit['allowed']) {
    header('Retry-After: ' . $rateLimit['retryAfter']);
    sendJsonResponse(['success' => false, 'error' => $rateLimit['message']], 429);
}

$input = json_decode(file_get_contents('php://input'), true);
$url = $input['url'] ?? '';
$customAlias = $input['customAlias'] ?? '';

$urlValidation = validateUrl($url);
if (!$urlValidation['valid']) {
    sendJsonResponse(['success' => false, 'error' => $urlValidation['error']], 400);
}

if (!empty($customAlias)) {
    $aliasValidation = validateCustomAlias($customAlias);
    if (!$aliasValidation['valid']) {
        sendJsonResponse(['success' => false, 'error' => $aliasValidation['error']], 400);
    }
}

try {
    // CR-H1 fix: Move collision check inside write lock to prevent TOCTOU race
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0755, true);
    }

    $fp = fopen(URLS_FILE, 'c+');
    if (!$fp) {
        throw new Exception('Failed to open urls.json');
    }

    if (!acquireLock($fp, LOCK_EX)) {
        fclose($fp);
        throw new Exception('Failed to acquire write lock on urls.json');
    }

    try {
        // Read current data inside lock
        $content = '';
        if (filesize(URLS_FILE) > 0) {
            rewind($fp);
            $content = fread($fp, filesize(URLS_FILE));
        }
        $urls = $content ? json_decode($content, true) : [];
        if ($urls === null) {
            $urls = [];
        }

        if (count($urls) >= MAX_URLS) {
            sendJsonResponse(['success' => false, 'error' => 'Storage limit reached'], 507);
        }

        // Generate or validate short code inside lock
        if (!empty($customAlias)) {
            if (isset($urls[$customAlias])) {
                sendJsonResponse(['success' => false, 'error' => 'Alias already in use'], 409);
            }
            $shortCode = $customAlias;
        } else {
            $collisionAttempts = 0;
            do {
                $shortCode = generateShortCode();
                $collisionAttempts++;

                if ($collisionAttempts > COLLISION_RETRY_LIMIT) {
                    sendJsonResponse(['success' => false, 'error' => 'Failed to generate unique code'], 500);
                }
            } while (isset($urls[$shortCode]));
        }

        // Add new URL
        $urls[$shortCode] = [
            'url' => $url,
            'created' => date('c'),
            'customAlias' => !empty($customAlias)
        ];

        // Write atomically
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($urls, JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP));
        fflush($fp);
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    // Initialize stats for new URL
    $stats = readStatsData();
    $stats[$shortCode] = [
        'count' => 0,
        'lastAccess' => null
    ];
    writeStatsData($stats);

    $shortUrl = BASE_URL . '/' . $shortCode;

    $response = [
        'success' => true,
        'shortUrl' => $shortUrl,
        'shortCode' => $shortCode
    ];

    $qrCode = generateQrCode($shortUrl);
    if ($qrCode !== null) {
        $response['qrCode'] = $qrCode;
    }

    sendJsonResponse($response, 201);

} catch (Exception $e) {
    // CR-M1 fix: Log detailed error server-side, return generic message to client
    error_log('Shorten API error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    sendJsonResponse(['success' => false, 'error' => 'Service temporarily unavailable'], 503);
}
