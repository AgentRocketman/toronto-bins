<?php
/**
 * GetMyBin API Configuration
 *
 * This file is required by every API endpoint. It defines all the credentials and
 * helper functions. Two helper styles coexist here for historical reasons:
 *   - airtableRequest / stripeRequest / corsHeaders / generateJWT  (auth, billing, bookings)
 *   - airtableCall / openaiCall / getClientIP / getBrowserInfo     (chat logging, summaries)
 */

// ============================================================
// AIRTABLE
// ============================================================
define('AIRTABLE_API_KEY', 'patxbDkv88pOMXmYx.c7e5fd7974954e3a674087090835d11dd69504f3912f0ef86c3c59f1e91febdd');
define('AIRTABLE_BASE_ID', 'apptYNRJTXwItvied');
define('AIRTABLE_BASE',    'apptYNRJTXwItvied'); // alias used by older code
define('AIRTABLE_API_BASE', 'https://api.airtable.com/v0');

// Table IDs
define('AIRTABLE_BOOKINGS',  'tblKMhGnYjsH0z7Lj');
define('AIRTABLE_ORDERS',    'tblGhNRi3ENwVpNty');
define('AIRTABLE_EMPLOYEES', 'tbldH1el7qM0VNEje');
define('AIRTABLE_CHATLOGS_TABLE', 'tblatXRj8Ka7hyGyZ');

// ============================================================
// STRIPE
// ============================================================
define('STRIPE_SECRET_KEY', 'sk_test_51SFgOXRoaqSc6FkpqmcozU4mGNxDZTQJfkgcwti8z2kg7Lq3SkuCrsenYDn2kDYDZ9Gu6v4xPCtZiPDhALX7w9KN00Zw7AOcpt');
define('STRIPE_API_BASE',   'https://api.stripe.com/v1');

// ============================================================
// OPENAI (server-side only — never sent to client)
// ============================================================
define('OPENAI_API_KEY', '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');

// ============================================================
// GOOGLE MAPS (server-side only — exposed via /api/maps-key.php)
// ============================================================
define('GOOGLE_MAPS_API_KEY', 'AIzaSyCzfj1D1eF01IDHwCMGqt4O4XU1ncouSRI');

// ============================================================
// AUTH
// ============================================================
define('JWT_SECRET',     'GetMyBin_JWT_s3cr3t_2026_X9kQ_mP7vR!');
define('JWT_EXPIRY',     8 * 3600); // 8 hours
define('ADMIN_KEY',      'getmybin-admin-xK9mP2026');
define('ADMIN_PASSWORD', 'GetMyBinAdmin2026!');

// ============================================================
// SMTP (Hostinger)
// ============================================================
define('SMTP_HOST',     'smtp.hostinger.com');
define('SMTP_PORT',     465);
define('SMTP_USER',     'support@getmybin.com');
define('SMTP_PASS',     'AgentEmail1!');
define('SUPPORT_EMAIL', 'support@getmybin.com');

// ============================================================
// CORS / SECURITY HEADERS
// ============================================================
// Modern endpoints call corsHeaders() explicitly. Legacy endpoints rely on the
// implicit headers below being emitted at config-load time.
function corsHeaders() {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

// Auto-handle OPTIONS preflight when this file is included on a request that's
// hitting an endpoint that doesn't explicitly call corsHeaders().
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ============================================================
// HELPERS — visitor info (used by chat logging)
// ============================================================
function getClientIP() {
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) return $_SERVER['HTTP_CF_CONNECTING_IP'];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    if (!empty($_SERVER['HTTP_X_FORWARDED']))     return $_SERVER['HTTP_X_FORWARDED'];
    if (!empty($_SERVER['HTTP_FORWARDED_FOR']))   return $_SERVER['HTTP_FORWARDED_FOR'];
    if (!empty($_SERVER['HTTP_FORWARDED']))       return $_SERVER['HTTP_FORWARDED'];
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function getBrowserInfo($ua = null) {
    $ua = $ua ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $browser = 'Unknown';
    $device = 'desktop';
    if (preg_match('/(iPhone|iPad|iPod|Android)/i', $ua))   $device = 'mobile';
    elseif (preg_match('/(Tablet|iPad)/i', $ua))            $device = 'tablet';
    if (preg_match('/Chrome/i', $ua))        $browser = 'Chrome';
    elseif (preg_match('/Safari/i', $ua))    $browser = 'Safari';
    elseif (preg_match('/Firefox/i', $ua))   $browser = 'Firefox';
    elseif (preg_match('/Edge/i', $ua))      $browser = 'Edge';
    elseif (preg_match('/Opera/i', $ua))     $browser = 'Opera';
    return ['browser' => $browser, 'device' => $device];
}

// ============================================================
// HELPERS — Airtable (two compatible signatures)
// ============================================================

// Legacy style: airtableCall('GET', '/' . AIRTABLE_CHATLOGS_TABLE . '?...', null)
function airtableCall($method, $endpoint, $data = null) {
    $url = AIRTABLE_API_BASE . '/' . AIRTABLE_BASE_ID . $endpoint;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . AIRTABLE_API_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    if ($data !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'body' => json_decode($response, true)];
}

// Auth/booking style: airtableRequest('GET', AIRTABLE_EMPLOYEES, ['filterByFormula'=>...])
function airtableRequest($method, $table, $data = [], $recordId = '') {
    $url = AIRTABLE_API_BASE . '/' . AIRTABLE_BASE . '/' . $table . ($recordId ? '/' . $recordId : '');
    if ($method === 'GET' && !empty($data)) {
        $url .= '?' . http_build_query($data);
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . AIRTABLE_API_KEY,
        'Content-Type: application/json',
    ]);
    if ($method === 'PATCH') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } elseif ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'body' => json_decode($response, true)];
}

// ============================================================
// HELPERS — Stripe
// ============================================================
function stripeRequest($method, $endpoint, $data = []) {
    $url = STRIPE_API_BASE . $endpoint;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, STRIPE_SECRET_KEY . ':');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        if (!empty($data)) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    } elseif ($method === 'GET' && !empty($data)) {
        $url .= '?' . http_build_query($data);
        curl_setopt($ch, CURLOPT_URL, $url);
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'body' => json_decode($response, true)];
}

// ============================================================
// HELPERS — OpenAI (chat / summarization)
// ============================================================
function openaiCall($messages, $model = 'gpt-3.5-turbo', $temperature = 0.5) {
    $url = 'https://api.openai.com/v1/chat/completions';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . OPENAI_API_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => $temperature,
            'max_tokens'  => 1000,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'body' => json_decode($response, true)];
}

// ============================================================
// HELPERS — JWT
// ============================================================
function b64url($data) { return rtrim(strtr(base64_encode($data), '+/', '-_'), '='); }

function generateJWT($payload) {
    $header = b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $body   = b64url(json_encode($payload));
    $sig    = b64url(hash_hmac('sha256', "$header.$body", JWT_SECRET, true));
    return "$header.$body.$sig";
}

function verifyJWT($token) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return false;
    [$h, $b, $s] = $parts;
    $expected = b64url(hash_hmac('sha256', "$h.$b", JWT_SECRET, true));
    if (!hash_equals($expected, $s)) return false;
    $pad = strlen($b) % 4 ? strlen($b) + 4 - strlen($b) % 4 : strlen($b);
    $data = json_decode(base64_decode(str_pad(strtr($b, '-_', '+/'), $pad, '=')), true);
    if (!$data || ($data['exp'] ?? 0) < time()) return false;
    return $data;
}

// ============================================================
// HELPERS — SMTP email (Hostinger)
// ============================================================
function sendSmtpEmail($to, $toName, $subject, $html) {
    $context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
    $sock = @stream_socket_client('ssl://' . SMTP_HOST . ':' . SMTP_PORT, $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
    if (!$sock) return false;
    $read = function() use ($sock) { return fgets($sock, 512); };
    $send = function($cmd) use ($sock) { fwrite($sock, "$cmd\r\n"); };
    $read();
    $send('EHLO localhost');
    while (($line = $read()) && substr($line, 3, 1) === '-') {}
    $send('AUTH LOGIN'); $read();
    $send(base64_encode(SMTP_USER)); $read();
    $send(base64_encode(SMTP_PASS)); $read();
    $send('MAIL FROM: <' . SMTP_USER . '>'); $read();
    $send('RCPT TO: <' . $to . '>'); $read();
    $send('DATA'); $read();
    $msg  = 'From: GetMyBin <' . SMTP_USER . ">\r\n";
    $msg .= "To: $toName <$to>\r\n";
    $msg .= "Subject: $subject\r\n";
    $msg .= "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n";
    $msg .= $html . "\r\n.\r\n";
    $send($msg); $read();
    $send('QUIT');
    fclose($sock);
    return true;
}
