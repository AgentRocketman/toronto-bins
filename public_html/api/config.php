<?php
// GetMyBin Configuration

// Stripe
define('STRIPE_SECRET_KEY', 'sk_test_51SFgOXRoaqSc6FkpqmcozU4mGNxDZTQJfkgcwti8z2kg7Lq3SkuCrsenYDn2kDYDZ9Gu6v4xPCtZiPDhALX7w9KN00Zw7AOcpt');
define('STRIPE_API_BASE', 'https://api.stripe.com/v1');

// Airtable
define('AIRTABLE_API_KEY', 'patxbDkv88pOMXmYx.c7e5fd7974954e3a674087090835d11dd69504f3912f0ef86c3c59f1e91febdd');
define('AIRTABLE_BASE', 'apptYNRJTXwItvied');
define('AIRTABLE_BOOKINGS', 'tblKMhGnYjsH0z7Lj');
define('AIRTABLE_ORDERS', 'tblGhNRi3ENwVpNty');
define('AIRTABLE_EMPLOYEES', 'tbldH1el7qM0VNEje');
define('AIRTABLE_API_BASE', 'https://api.airtable.com/v0');

// Auth
define('JWT_SECRET', 'GetMyBin_JWT_s3cr3t_2026_X9kQ_mP7vR!');
define('JWT_EXPIRY', 8 * 3600); // 8 hours
define('ADMIN_KEY', 'getmybin-admin-xK9mP2026');
define('ADMIN_PASSWORD', 'GetMyBinAdmin2026!');

// SMTP
define('SMTP_HOST', 'smtp.hostinger.com');
define('SMTP_PORT', 465);
define('SMTP_USER', 'support@agentrocketman.com');
define('SMTP_PASS', 'AgentEmail1!');
define('SUPPORT_EMAIL', 'support@agentrocketman.com');

function corsHeaders() {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

// Stripe API helper (GET, POST, DELETE)
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

// Airtable API helper (GET, PATCH, POST)
function airtableRequest($method, $table, $data = [], $recordId = '') {
    $url = AIRTABLE_API_BASE . '/' . AIRTABLE_BASE . '/' . $table . ($recordId ? '/' . $recordId : '');
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . AIRTABLE_API_KEY,
        'Content-Type: application/json'
    ]);
    if ($method === 'GET' && !empty($data)) {
        $url .= '?' . http_build_query($data);
        curl_setopt($ch, CURLOPT_URL, $url);
    } elseif ($method === 'PATCH') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } elseif ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'body' => json_decode($response, true)];
}

// JWT helpers
function b64url($data) { return rtrim(strtr(base64_encode($data), '+/', '-_'), '='); }
function generateJWT($payload) {
    $header = b64url(json_encode(['alg'=>'HS256','typ'=>'JWT']));
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
    $data = json_decode(base64_decode(str_pad(strtr($b, '-_', '+/'), strlen($b) % 4 ? strlen($b) + 4 - strlen($b) % 4 : strlen($b), '=')), true);
    if (!$data || $data['exp'] < time()) return false;
    return $data;
}

// SMTP email sender
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
