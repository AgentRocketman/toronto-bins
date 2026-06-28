<?php
require_once __DIR__ . '/../config.php';

/**
 * Read URLs data from JSON file with shared lock
 */
function readUrlData() {
    if (!file_exists(URLS_FILE)) {
        return [];
    }

    $fp = fopen(URLS_FILE, 'r');
    if (!$fp) {
        throw new Exception('Failed to open urls.json for reading');
    }

    if (!acquireLock($fp, LOCK_SH)) {
        fclose($fp);
        throw new Exception('Failed to acquire read lock on urls.json');
    }

    try {
        $content = fread($fp, filesize(URLS_FILE));
        $data = json_decode($content, true);
        if ($data === null && $content !== '{}' && $content !== '') {
            throw new Exception('Corrupted urls.json file');
        }
        return $data ?: [];
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

/**
 * Write URLs data to JSON file with exclusive lock
 */
function writeUrlData($data) {
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0755, true);
    }

    $fp = fopen(URLS_FILE, 'c');
    if (!$fp) {
        throw new Exception('Failed to open urls.json for writing');
    }

    if (!acquireLock($fp, LOCK_EX)) {
        fclose($fp);
        throw new Exception('Failed to acquire write lock on urls.json');
    }

    try {
        ftruncate($fp, 0);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP));
        fflush($fp);
        return true;
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

/**
 * Read stats data from JSON file with shared lock
 */
function readStatsData() {
    if (!file_exists(STATS_FILE)) {
        return [];
    }

    $fp = fopen(STATS_FILE, 'r');
    if (!$fp) {
        throw new Exception('Failed to open stats.json for reading');
    }

    if (!acquireLock($fp, LOCK_SH)) {
        fclose($fp);
        throw new Exception('Failed to acquire read lock on stats.json');
    }

    try {
        $content = fread($fp, filesize(STATS_FILE));
        $data = json_decode($content, true);
        if ($data === null && $content !== '{}' && $content !== '') {
            throw new Exception('Corrupted stats.json file');
        }
        return $data ?: [];
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

/**
 * Write stats data to JSON file with exclusive lock
 */
function writeStatsData($data) {
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0755, true);
    }

    $fp = fopen(STATS_FILE, 'c');
    if (!$fp) {
        throw new Exception('Failed to open stats.json for writing');
    }

    if (!acquireLock($fp, LOCK_EX)) {
        fclose($fp);
        throw new Exception('Failed to acquire write lock on stats.json');
    }

    try {
        ftruncate($fp, 0);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP));
        fflush($fp);
        return true;
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

/**
 * Acquire file lock with exponential backoff and jitter (per H3)
 */
function acquireLock($fp, $lockType) {
    for ($attempt = 0; $attempt < LOCK_RETRY_LIMIT; $attempt++) {
        if (flock($fp, $lockType | LOCK_NB)) {
            return true;
        }

        if ($attempt < LOCK_RETRY_LIMIT - 1) {
            $delay = LOCK_RETRY_BASE_MS * pow(2, $attempt);
            $jitter = mt_rand(-20, 20);
            usleep(($delay + $jitter) * 1000);
        }
    }

    return false;
}

/**
 * Validate URL format and protocol
 */
function validateUrl($url) {
    if (empty($url)) {
        return ['valid' => false, 'error' => 'URL required'];
    }

    if (strlen($url) > MAX_URL_LENGTH) {
        return ['valid' => false, 'error' => 'URL too long (max ' . MAX_URL_LENGTH . ' characters)'];
    }

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return ['valid' => false, 'error' => 'Invalid URL format'];
    }

    $parsedUrl = parse_url($url);
    if (!isset($parsedUrl['scheme']) || !in_array($parsedUrl['scheme'], ALLOWED_PROTOCOLS)) {
        return ['valid' => false, 'error' => 'Only HTTP and HTTPS protocols are allowed'];
    }

    return ['valid' => true];
}

/**
 * Validate custom alias format
 */
function validateCustomAlias($alias) {
    if (!ENABLE_CUSTOM_ALIASES) {
        return ['valid' => false, 'error' => 'Custom aliases are not enabled'];
    }

    if (empty($alias)) {
        return ['valid' => true];
    }

    if (!preg_match(CUSTOM_ALIAS_PATTERN, $alias)) {
        return ['valid' => false, 'error' => 'Alias must be ' . CUSTOM_ALIAS_MIN_LENGTH . '-' . CUSTOM_ALIAS_MAX_LENGTH . ' alphanumeric characters'];
    }

    return ['valid' => true];
}

/**
 * Generate random short code
 */
function generateShortCode() {
    $chars = SHORT_CODE_CHARS;
    $code = '';
    $charsLength = strlen($chars);

    for ($i = 0; $i < SHORT_CODE_LENGTH; $i++) {
        $code .= $chars[random_int(0, $charsLength - 1)];
    }

    return $code;
}

/**
 * Check if short code exists
 */
function shortCodeExists($code, $urls) {
    return isset($urls[$code]);
}

/**
 * Session-based rate limiting (per C2 - avoids file contention)
 * CR-H3 fix: regenerate session ID on first access to prevent session fixation
 */
function checkRateLimit() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Regenerate session ID on first rate limit check to prevent session fixation
    if (!isset($_SESSION['rate_limit_initialized'])) {
        session_regenerate_id(true);
        $_SESSION['rate_limit_initialized'] = true;
    }

    $now = time();
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    if (!isset($_SESSION['rate_limit'])) {
        $_SESSION['rate_limit'] = [];
    }

    if (!isset($_SESSION['rate_limit'][$ip])) {
        $_SESSION['rate_limit'][$ip] = [
            'count' => 0,
            'resetTime' => $now + RATE_LIMIT_WINDOW
        ];
    }

    $rateData = $_SESSION['rate_limit'][$ip];

    if ($now >= $rateData['resetTime']) {
        $_SESSION['rate_limit'][$ip] = [
            'count' => 1,
            'resetTime' => $now + RATE_LIMIT_WINDOW
        ];
        return ['allowed' => true];
    }

    if ($rateData['count'] >= RATE_LIMIT_MAX) {
        $retryAfter = $rateData['resetTime'] - $now;
        return [
            'allowed' => false,
            'retryAfter' => $retryAfter,
            'message' => 'Rate limit exceeded. Try again in ' . ceil($retryAfter / 60) . ' minutes.'
        ];
    }

    $_SESSION['rate_limit'][$ip]['count']++;
    return ['allowed' => true];
}

/**
 * Send JSON response
 */
function sendJsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    header('Cache-Control: no-cache');
    echo json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

/**
 * Generate QR Code (per H1 - fallback gracefully if library fails)
 */
function generateQrCode($url) {
    if (!ENABLE_QR_CODES) {
        return null;
    }

    $qrLibPath = __DIR__ . '/../assets/lib/phpqrcode.php';
    if (!file_exists($qrLibPath)) {
        error_log('QR library not found, skipping QR code generation');
        return null;
    }

    try {
        require_once $qrLibPath;

        ob_start();
        QRcode::png($url, false, QR_ECLEVEL_L, QR_CODE_SIZE, QR_CODE_MARGIN);
        $imageData = ob_get_clean();

        if ($imageData) {
            return 'data:image/png;base64,' . base64_encode($imageData);
        }
    } catch (Exception $e) {
        error_log('QR code generation failed: ' . $e->getMessage());
    }

    return null;
}
