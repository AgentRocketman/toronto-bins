<?php
/**
 * Subtitle AI Rewriter
 * Takes raw property description → returns optimized 3-4 word subtitle phrases
 * Called by the frontend when "AI video-optimized subtitles" toggle is ON
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$description = trim($input['description'] ?? '');

if (!$description || strlen($description) < 10) {
    http_response_code(400);
    echo json_encode(['error' => 'Description too short (min 10 chars)']);
    exit;
}

require_once __DIR__ . '/../../api/config.php'; // OPENAI_API_KEY

$prompt = <<<PROMPT
You are a luxury real estate copywriter writing subtitles for a video walkthrough. 

Take this property description and rewrite it into a flat JSON array of short, punchy subtitle phrases. Each phrase must be exactly 3-4 words. Sound like someone casually narrating a walkthrough — natural, attractive, pointing out the best features and facts. No fluff. No markdown. Just the array.

Description:
$description
PROMPT;

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_TIMEOUT        => 25,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . OPENAI_API_KEY,
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'model'       => 'gpt-4o-mini',
        'messages'    => [
            ['role' => 'system', 'content' => 'You are a luxury real estate copywriter. You respond ONLY with valid JSON arrays. No explanations, no markdown, no code fences.'],
            ['role' => 'user', 'content' => $prompt],
        ],
        'temperature' => 0.7,
        'max_tokens'  => 600,
    ]),
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    $err = json_decode($response, true);
    http_response_code(502);
    echo json_encode([
        'error' => 'OpenAI API error',
        'code'  => $httpCode,
        'detail' => $err['error']['message'] ?? 'Unknown error'
    ]);
    exit;
}

$data    = json_decode($response, true);
$content = $data['choices'][0]['message']['content'] ?? '';

// Parse the JSON array from the response
$phrases = json_decode($content, true);

// If direct parse fails, try to extract array from the content
if (!is_array($phrases) && preg_match('/\[.*\]/s', $content, $m)) {
    $phrases = json_decode($m[0], true);
}

if (!is_array($phrases) || count($phrases) === 0) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to parse subtitle phrases from AI response',
        'raw'   => $content
    ]);
    exit;
}

// Return the phrases
echo json_encode(['phrases' => $phrases]);