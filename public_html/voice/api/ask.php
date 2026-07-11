<?php
/**
 * CurbIn Voice Assistant - Chat Completions Endpoint
 *
 * Receives transcribed voice/text input, forwards to OpenRouter, returns reply.
 */

require_once __DIR__ . '/../../api/config-openrouter.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

function getClientIP(): string {
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) return $_SERVER['HTTP_CF_CONNECTING_IP'];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    if (!empty($_SERVER['HTTP_X_FORWARDED']))       return $_SERVER['HTTP_X_FORWARDED'];
    if (!empty($_SERVER['HTTP_FORWARDED_FOR']))     return $_SERVER['HTTP_FORWARDED_FOR'];
    if (!empty($_SERVER['HTTP_FORWARDED']))         return $_SERVER['HTTP_FORWARDED'];
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

// Basic rate limit: 1 request per 2 seconds per IP.
$ip = getClientIP();
$rateFile = sys_get_temp_dir() . '/voice_rate_' . md5($ip) . '.json';
$now = microtime(true);
$last = 0.0;
if (file_exists($rateFile)) {
    $stored = json_decode(file_get_contents($rateFile), true);
    $last = is_array($stored) && isset($stored['ts']) ? (float)$stored['ts'] : 0.0;
}
if ($now - $last < 2.0) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many requests. Please wait a moment.']);
    exit;
}
file_put_contents($rateFile, json_encode(['ts' => $now]));

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !is_array($input) || empty($input['message']) || !is_string($input['message'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Message is required and must be a string.']);
    exit;
}

$message = trim($input['message']);
if ($message === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Message cannot be empty.']);
    exit;
}

$history = [];
if (!empty($input['history']) && is_array($input['history'])) {
    foreach ($input['history'] as $h) {
        if (is_array($h) && in_array($h['role'] ?? '', ['user', 'assistant'], true) && is_string($h['content'] ?? null)) {
            $history[] = ['role' => $h['role'], 'content' => $h['content']];
        }
    }
}

$systemPrompt = "You are a helpful voice assistant for Chris D. You help with CurbIn, a Toronto garbage bin collection/rollout service, and general projects. Be genuine, concise, and conversational. CurbIn v2 is live at getmybin.com (production) and agentrocketman.com (development). Pricing: $6.95/week subscription, $9.95 ad-hoc, current $1 first-time promo. HST is 13%. Service area is Toronto plus its districts (Old Toronto, North York, Scarborough, Etobicoke, East York, York) — not the wider GTA. Support email is support@getmybin.com. Bin rollout timing: evening before pickup, bins roll to the curb/street; collection day afternoon, bins roll back to the property after city pickup. For code deployments, only deploy to dev (agentrocketman.com) unless Chris explicitly says 'deploy to prod' or 'production'. Mission Control default stage models: Scout/Architect/Designer/Performance = Claude Sonnet 4.6; Builder and Smoke Test = Kimi K2.7 Code; Code Reviewer = Claude Haiku 4.5; Security Auditor = Claude Opus 4.7. Available pipelines are Quick Fix (Builder -> Code Reviewer -> Smoke Test -> Deploy) and Add Feature (Scout -> Architect -> Builder -> Code Reviewer -> Smoke Test -> Deploy). Keep spoken answers short for voice (1-3 sentences when possible). Today's date is " . gmdate('Y-m-d') . ".";

$messages = [
    ['role' => 'system', 'content' => $systemPrompt]
];
foreach (array_slice($history, -20) as $h) {
    $messages[] = $h;
}
$messages[] = ['role' => 'user', 'content' => $message];

$payload = [
    'model' => 'moonshotai/kimi-k2.7-code',
    'messages' => $messages,
    'temperature' => 0.7,
    'max_tokens' => 300,
];

if (OPENROUTER_API_KEY === 'sk-or-v1-REPLACE-ME' || strpos(OPENROUTER_API_KEY, 'sk-or-v1-') !== 0) {
    http_response_code(500);
    error_log('Voice assistant: OpenRouter API key not configured.');
    echo json_encode(['error' => 'Assistant is not configured. Please set the OpenRouter API key.']);
    exit;
}

$ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . OPENROUTER_API_KEY,
        'HTTP-Referer: https://agentrocketman.com/voice/',
        'X-Title: CurbIn Voice Assistant'
    ],
    CURLOPT_TIMEOUT => 30,
    CURLOPT_FOLLOWLOCATION => true,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError || $httpCode !== 200 || !$response) {
    http_response_code(502);
    error_log(sprintf('Voice assistant OpenRouter error: HTTP %s, curl error: %s, response: %s', $httpCode, $curlError, $response));
    echo json_encode(['error' => 'Assistant failed to respond. Please try again.']);
    exit;
}

$data = json_decode($response, true);
if (!$data || empty($data['choices'][0]['message']['content'])) {
    http_response_code(502);
    error_log('Voice assistant: unexpected OpenRouter response format: ' . $response);
    echo json_encode(['error' => 'Assistant returned an unexpected response.']);
    exit;
}

$reply = $data['choices'][0]['message']['content'];

echo json_encode([
    'response' => $reply,
    'model' => 'moonshotai/kimi-k2.7-code'
]);
