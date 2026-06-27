<?php
/**
 * POLCU API Configuration
 *
 * Shared configuration for POLCU chatbot endpoints
 */

// ============================================================
// AIRTABLE
// ============================================================
define('AIRTABLE_API_KEY', 'patxbDkv88pOMXmYx.c7e5fd7974954e3a674087090835d11dd69504f3912f0ef86c3c59f1e91febdd');
define('AIRTABLE_BASE_ID', 'apptYNRJTXwItvied');
define('AIRTABLE_BASE',    'apptYNRJTXwItvied');
define('AIRTABLE_API_BASE', 'https://api.airtable.com/v0');

// Table IDs
define('POLCU_CHATLOGS_TABLE', 'tblNHbuQjTwZRnhpQ');

// ============================================================
// OPENAI (server-side only — never sent to client)
// ============================================================
define('OPENAI_API_KEY', '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');

// ============================================================
// CORS / SECURITY HEADERS
// ============================================================
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

// Auto-handle OPTIONS preflight
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
// HELPERS — Airtable
// ============================================================
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
