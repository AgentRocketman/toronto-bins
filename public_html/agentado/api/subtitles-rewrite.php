<?php
/**
 * Subtitle AI Rewriter — V2 with Photo-Order Awareness
 * Takes property description + photo tags → returns narrative phrases + photo sequence
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
$photoCount = intval($input['photoCount'] ?? 0);
$photoTags = $input['photoTags'] ?? null; // { "0": "exterior_front", "1": "kitchen", ... }

if (!$description || strlen($description) < 10) {
    http_response_code(400);
    echo json_encode(['error' => 'Description too short (min 10 chars)']);
    exit;
}

require_once __DIR__ . '/config.php'; // OPENROUTER_API_KEY

// ── Build photo inventory for the AI ──
$photoInventory = '';
$photoOrderGuidance = '';
if ($photoTags && is_array($photoTags) && count($photoTags) > 0) {
    $byRoom = [];
    foreach ($photoTags as $idx => $tag) {
        $byRoom[$tag][] = intval($idx);
    }
    $photoInventory = "\n\nAVAILABLE PHOTOS (room type → photo numbers):\n";
    foreach ($byRoom as $room => $ids) {
        $photoInventory .= "- $room: photos [" . implode(', ', $ids) . "]\n";
    }
    $photoOrderGuidance = "\n\nCRITICAL — PHOTO ORDERING: You MUST also output a 'photo_order' array that maps each subtitle phrase to a specific photo. "
        . "Match the room/feature being described to the most appropriate photo from the inventory above. "
        . "The tour should flow naturally — start exterior, enter foyer, flow through main floor, then upstairs/bedrooms, end with a closing exterior or best room.\n"
        . "Use EVERY photo exactly once. No repeats, no omissions.\n"
        . "Return format: {\"phrases\": [...], \"photo_order\": [0, 3, 7, 2, ...]}";
}

// Build timing guidance
$timingGuidance = '';
if ($photoCount > 0) {
    $totalSecs = round($photoCount * 5.0 - 0.8 * max(0, $photoCount - 1));
    $perPhrase = round($totalSecs / $photoCount, 1);
    $timingGuidance = "\n\nIMPORTANT: You are narrating a video with EXACTLY $photoCount photo clips. The total video duration is approximately $totalSecs seconds.\n"
        . "- You MUST return EXACTLY $photoCount subtitle phrases — one per clip, no more, no less.\n"
        . "- Each phrase must be naturally speakable in about $perPhrase seconds when read aloud (roughly 8-12 words max per phrase).\n"
        . "- Summarize and pace the description to fit within this timeframe — prioritize the most compelling features.";
}

$prompt = <<<PROMPT
You are a luxury real estate tour guide narrating a video walkthrough. Your job is to tell a STORY — not list features.

Take this property description and produce a flat JSON array of narrative subtitle phrases. Each phrase should:
- Flow naturally like spoken narration (not a bullet list)
- Weave specs and features INTO the story ("The chef's kitchen boasts marble counters" not "Marble counters. Chef's kitchen.")
- Vary rhythm: some short and dramatic, others more descriptive
- Move through the home like you're walking someone through it
$photoInventory
Start with a warm welcome, tour the key rooms, and close with an inviting send-off. The viewer should feel like they're being guided through the home by someone who loves it.
$timingGuidance
$photoOrderGuidance

Return ONLY a JSON object with "phrases" (array of strings) and "photo_order" (array of integers). No markdown, no explanation.

Description:
$description
PROMPT;

$ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . OPENROUTER_API_KEY,
        'HTTP-Referer: https://agentrocketman.com',
        'X-Title: Agentado Subtitles',
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'model'       => 'openai/gpt-4o-mini',
        'messages'    => [
            ['role' => 'system', 'content' => 'You are a real estate tour guide who narrates walkthrough videos. You respond ONLY with valid JSON. No explanations, no markdown, no code fences.'],
            ['role' => 'user', 'content' => $prompt],
        ],
        'temperature' => 0.7,
        'max_tokens'  => 1200,
    ]),
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($httpCode !== 200) {
    $err = json_decode($response, true);
    http_response_code(502);
    echo json_encode([
        'error' => 'OpenRouter API error',
        'code'  => $httpCode,
        'detail' => $err['error']['message'] ?? 'Unknown error'
    ]);
    exit;
}

$data    = json_decode($response, true);
$content = $data['choices'][0]['message']['content'] ?? '';

// Parse JSON from response (handle markdown fences)
$result = json_decode($content, true);
if (!is_array($result) && preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $m)) {
    $result = json_decode($m[1], true);
}
if (!is_array($result) && preg_match('/\{(?:.|\n)*"phrases"(?:.|\n)*\}/s', $content, $m)) {
    $result = json_decode($m[0], true);
}

if (!is_array($result) || empty($result['phrases'])) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to parse subtitle response',
        'raw'   => $content
    ]);
    exit;
}

$phrases = $result['phrases'];
$photoOrder = $result['photo_order'] ?? null;

// Validate photo_order
if (is_array($photoOrder) && count($photoOrder) === $photoCount) {
    $unique = array_unique($photoOrder);
    if (count($unique) !== $photoCount) {
        // Has duplicates — fall back to natural order
        $photoOrder = null;
    }
} else {
    $photoOrder = null;
}

echo json_encode([
    'phrases' => $phrases,
    'photo_order' => $photoOrder,
]);