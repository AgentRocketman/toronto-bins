<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'domain_generator');
define('DB_USER', 'root');
define('DB_PASS', '');

// API Keys - Replace with actual keys
define('OPENAI_API_KEY', 'your-openai-api-key-here');
define('NAMECHEAP_API_USER', 'your-namecheap-username');
define('NAMECHEAP_API_KEY', 'your-namecheap-api-key');
define('NAMECHEAP_USERNAME', 'your-namecheap-username');
define('NAMECHEAP_CLIENT_IP', 'your-client-ip'); // Your whitelisted IP

// API Endpoints
define('NAMECHEAP_API_URL', 'https://api.namecheap.com/xml.response');
define('OPENAI_API_URL', 'https://api.openai.com/v1/chat/completions');

// Application settings
define('CACHE_DURATION', 21600); // 6 hours in seconds
define('RATE_LIMIT_SECONDS', 3);
define('MAX_DESCRIPTION_LENGTH', 500);
define('MIN_DESCRIPTION_LENGTH', 10);
define('DOMAINS_PER_GENERATION', 10);

// Error reporting
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Session configuration
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database connection
function getDB() {
    static $pdo = null;

    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Service temporarily unavailable']);
            exit;
        }
    }

    return $pdo;
}

// Get or create session ID
function getSessionId() {
    if (!isset($_SESSION['session_id'])) {
        $_SESSION['session_id'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['session_id'];
}

// Rate limiting
function checkRateLimit($sessionId) {
    $pdo = getDB();

    $stmt = $pdo->prepare("
        SELECT created_at
        FROM generation_queue
        WHERE session_id = ?
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$sessionId]);
    $lastGeneration = $stmt->fetch();

    if ($lastGeneration) {
        $timeSince = time() - strtotime($lastGeneration['created_at']);
        if ($timeSince < RATE_LIMIT_SECONDS) {
            return false;
        }
    }

    return true;
}

// Sanitize input
function sanitizeInput($input, $maxLength = null) {
    $input = strip_tags($input);
    $input = trim($input);

    if ($maxLength !== null) {
        $input = substr($input, 0, $maxLength);
    }

    return $input;
}

// JSON response helper
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
