<?php
// Configuration constants for My Little Shorter

// Base URL for the application (change this for deployment)
define('BASE_URL', 'http://localhost:8000');

// File paths for JSON storage
define('DATA_DIR', __DIR__ . '/data/');
define('URLS_FILE', DATA_DIR . 'urls.json');
define('STATS_FILE', DATA_DIR . 'stats.json');

// Short code configuration
define('SHORT_CODE_LENGTH', 6);
define('SHORT_CODE_CHARS', 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789');
define('COLLISION_RETRY_LIMIT', 5);

// URL validation
define('MAX_URL_LENGTH', 2048);
define('ALLOWED_PROTOCOLS', ['http', 'https']);

// Storage limits
define('MAX_URLS', 10000);

// File locking configuration (exponential backoff with jitter)
define('LOCK_RETRY_LIMIT', 3);
define('LOCK_RETRY_BASE_MS', 100);

// Rate limiting (in-memory session-based to avoid file contention per C2)
define('RATE_LIMIT_MAX', 10);
define('RATE_LIMIT_WINDOW', 3600); // 1 hour in seconds

// Custom aliases (OPTIONAL feature per C1 - enabled by default)
define('ENABLE_CUSTOM_ALIASES', true);
define('CUSTOM_ALIAS_MIN_LENGTH', 3);
define('CUSTOM_ALIAS_MAX_LENGTH', 10);
define('CUSTOM_ALIAS_PATTERN', '/^[a-zA-Z0-9]{' . CUSTOM_ALIAS_MIN_LENGTH . ',' . CUSTOM_ALIAS_MAX_LENGTH . '}$/');

// QR Code configuration (OPTIONAL feature per H1)
define('ENABLE_QR_CODES', true);
define('QR_CODE_SIZE', 4);
define('QR_CODE_MARGIN', 2);

// CR-M4 fix: Warn if BASE_URL hasn't been configured for production
if (BASE_URL === 'http://localhost:8000') {
    error_log('WARNING: BASE_URL is set to localhost. Update config.php for production deployment.');
}
