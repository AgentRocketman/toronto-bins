<?php
/**
 * Subtitle AI Rewriter V2 — GPT-4o + A/B Story Generation + Visual-Aware Narration
 * Takes property description + rich photo details → 3 narrative options → picks best one
 *
 * Storyline V2: Upgraded from V1 (GPT-4o-mini single-pass, room-only tags) to:
 * - GPT-4o for better narrative quality
 * - Richer photo inventory (visual details, quality scores, hero flags)
 * - A/B/C generation with evaluation pass
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
$photoTags = $input['photoTags'] ?? null;      // Legacy: simple room strings
$photoDetails = $input['photoDetails'] ?? null; // V2: rich objects with room, details, quality, is_hero

if (!$description || strlen($description) < 10) {
    http_response_code(400);
    echo json_encode(['error' => 'Description too short (min 10 chars)']);
    exit;
}

require_once __DIR__ . '/config.php'; // OPENROUTER_API_KEY

// ── Build rich photo inventory for the AI ──
$photoInventory = '';

if ($photoDetails && is_array($photoDetails) && count($photoDetails) > 0) {
    // V2: Rich photo data with visual details
    $byRoom = [];
    foreach ($photoDetails as $idx => $info) {
        $room = is_array($info) ? ($info['room'] ?? 'interior') : $info;
        $byRoom[$room][] = intval($idx);
    }
    $photoInventory = "\n\nAVAILABLE PHOTOS (with visual details):\n";
    foreach ($byRoom as $room => $ids) {
        $photoInventory .= "- {$room}: photos [" . implode(', ', $ids) . "]\n";
    }
    $photoInventory .= "\nPHOTO DETAILS:\n";
    foreach ($photoDetails as $idx => $info) {
        if (is_array($info)) {
            $d = $info['details'] ?? '';
            $q = $info['quality'] ?? 3;
            $h = !empty($info['is_hero']) ? ' ⭐ HERO SHOT' : '';
            $photoInventory .= "  Photo {$idx} [{$info['room']}] (quality {$q}/5{$h}): {$d}\n";
        }
    }
    $photoInventory .= "\nHERO shots are the most visually striking — use them at attention peaks (opening, mid-tour highlight, closing).\n";
} elseif ($photoTags && is_array($photoTags) && count($photoTags) > 0) {
    // V1 fallback: simple room tags
    $byRoom = [];
    foreach ($photoTags as $idx => $tag) {
        $byRoom[$tag][] = intval($idx);
    }
    $photoInventory = "\n\nAVAILABLE PHOTOS (room type → photo numbers):\n";
    foreach ($byRoom as $room => $ids) {
        $photoInventory .= "- {$room}: photos [" . implode(', ', $ids) . "]\n";
    }
}

$photoOrderGuidance = "\n\nCRITICAL — PHOTO ORDERING: You MUST also output a 'photo_order' array that maps each subtitle phrase to a specific photo. "
    . "Match the room/feature being described to the most appropriate photo from the inventory above. "
    . "The tour should flow naturally — start with exterior or a HERO shot, enter foyer, flow through main floor, "
    . "then upstairs/bedrooms, end with a closing HERO shot or inviting exterior.\n"
    . "Use EVERY photo exactly once. No repeats, no omissions.\n"
    . "Return format: {\"phrases\": [...], \"photo_order\": [0, 3, 7, 2, ...]}";

// Build timing guidance
$timingGuidance = '';
if ($photoCount > 0) {
    $totalSecs = round($photoCount * 5.0 - 0.8 * max(0, $photoCount - 1));
    $perPhrase = round($totalSecs / $photoCount, 1);
    $timingGuidance = "\n\nIMPORTANT: You are narrating a video with EXACTLY {$photoCount} photo clips. The total video duration is approximately {$totalSecs} seconds.\n"
        . "- You MUST return EXACTLY {$photoCount} subtitle phrases — one per clip, no more, no less.\n"
        . "- Each phrase must be naturally speakable in about {$perPhrase} seconds when read aloud (roughly 8-12 words max per phrase).\n"
        . "- Summarize and pace the description to fit within this timeframe — prioritize the most compelling features.";
}

$systemPrompt = 'You are a luxury real estate tour guide who narrates cinematic walkthrough videos. '
    . 'You write compelling, natural-sounding narration that tells a STORY — not a list of features. '
    . 'You weave specs and details INTO the narrative ("The chef\'s kitchen centers on a dramatic marble waterfall island" not "Kitchen has marble counters"). '
    . 'You vary rhythm: some phrases short and dramatic, others more descriptive. '
    . 'You move through the home like you\'re walking someone through it — exterior welcome → main floor flow → upstairs retreat → closing send-off. '
    . 'You respond ONLY with valid JSON. No markdown, no explanations, no code fences.';

$userPrompt = "Tell the story of this property. Produce a flat JSON array of narrative subtitle phrases.\n\n"
    . "Start with a warm welcome at the best exterior or HERO shot, tour the key rooms, and close with an inviting send-off.\n"
    . "The viewer should feel like they're being guided through the home by someone who loves it."
    . $photoInventory
    . $timingGuidance
    . $photoOrderGuidance
    . "\n\nDescription:\n{$description}";

// ── Helper: call OpenRouter ──
function callStoryAI($system, $user, $model, $temp, $maxTokens) {
    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENROUTER_API_KEY,
            'HTTP-Referer: https://agentrocketman.com',
            'X-Title: Agentado Subtitles V2',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'temperature' => $temp, 'max_tokens' => $maxTokens,
        ]),
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($code !== 200) {
        return ['error' => "OpenRouter error {$code}: " . substr($resp, 0, 300)];
    }
    $data = json_decode($resp, true);
    return ['content' => $data['choices'][0]['message']['content'] ?? ''];
}

// ── Helper: parse JSON from response ──
function parseStoryResponse($content) {
    $result = json_decode($content, true);
    if (!is_array($result) && preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $m)) {
        $result = json_decode($m[1], true);
    }
    if (!is_array($result) && preg_match('/\{(?:.|\n)*"phrases"(?:.|\n)*\}/s', $content, $m)) {
        $result = json_decode($m[0], true);
    }
    return is_array($result) && !empty($result['phrases']) ? $result : null;
}

// ── Generate 3 story options in parallel (A/B/C) ──
// Use different temperatures for variety: 0.7, 0.85, 0.6
$temps = [0.7, 0.85, 0.6];
$modelV2 = 'openai/gpt-4o'; // V2 upgrade: GPT-4o for better narrative

$mh = curl_multi_init();
$handles = [];

for ($i = 0; $i < 3; $i++) {
    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENROUTER_API_KEY,
            'HTTP-Referer: https://agentrocketman.com',
            'X-Title: Agentado Story V2',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $modelV2,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'temperature' => $temps[$i], 'max_tokens' => 1500,
        ]),
    ]);
    curl_multi_add_handle($mh, $ch);
    $handles[] = $ch;
}

// Execute all in parallel
$running = null;
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh);
} while ($running > 0);

// Collect results
$candidates = [];
foreach ($handles as $i => $ch) {
    $resp = curl_multi_getcontent($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_multi_remove_handle($mh, $ch);

    if ($code === 200) {
        $data = json_decode($resp, true);
        $content = $data['choices'][0]['message']['content'] ?? '';
        $parsed = parseStoryResponse($content);
        if ($parsed) {
            $candidates[] = [
                'result' => $parsed,
                'temp' => $temps[$i],
                'raw' => $content,
            ];
        }
    }
}
curl_multi_close($mh);

// If all 3 failed, fall back to sequential single attempt
if (empty($candidates)) {
    $single = callStoryAI($systemPrompt, $userPrompt, $modelV2, 0.75, 1500);
    if (!isset($single['error'])) {
        $parsed = parseStoryResponse($single['content']);
        if ($parsed) {
            $candidates[] = ['result' => $parsed, 'temp' => 0.75, 'raw' => $single['content']];
        }
    }
}

// Still nothing? Fall back to V1 model
if (empty($candidates)) {
    $fallback = callStoryAI($systemPrompt, $userPrompt, 'openai/gpt-4o-mini', 0.7, 1200);
    if (!isset($fallback['error'])) {
        $parsed = parseStoryResponse($fallback['content']);
        if ($parsed) {
            $candidates[] = ['result' => $parsed, 'temp' => 0.7, 'raw' => $fallback['content']];
        }
    }
}

// If we have multiple candidates, evaluate and pick the best
if (count($candidates) === 1) {
    $best = $candidates[0];
} elseif (count($candidates) >= 2) {
    $best = evaluateAndPick($candidates, $description, $photoCount);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'All story generation attempts failed']);
    exit;
}

$result = $best['result'];
$phrases = $result['phrases'];
$photoOrder = $result['photo_order'] ?? null;

// Validate photo_order
if (is_array($photoOrder) && count($photoOrder) === $photoCount) {
    $unique = array_unique($photoOrder);
    if (count($unique) !== $photoCount) {
        $photoOrder = null;
    }
} else {
    $photoOrder = null;
}

echo json_encode([
    'phrases' => $phrases,
    'photo_order' => $photoOrder,
    'v2_info' => [
        'candidates' => count($candidates),
        'picked_temp' => $best['temp'],
        'model' => $modelV2,
    ],
]);

// ── Evaluation function: pick the best story ──
function evaluateAndPick($candidates, $description, $photoCount) {
    // Build evaluation prompt
    $options = '';
    foreach ($candidates as $i => $c) {
        $letter = chr(65 + $i); // A, B, C
        $joined = implode(' ', $c['result']['phrases']);
        $options .= "\nOPTION {$letter}:\n{$joined}\n";
    }

    $evalPrompt = "You are evaluating real estate video narration scripts. Rate each option on:\n"
        . "1. Natural flow — does it sound like spoken narration, not a bullet list?\n"
        . "2. Engagement — does it tell a compelling story that makes you want to see the home?\n"
        . "3. Rhythm variety — does it mix short dramatic phrases with longer descriptive ones?\n"
        . "4. Feature integration — are specs woven naturally into the story?\n\n"
        . "Property description for context:\n{$description}\n\n"
        . "Return ONLY a single letter (A, B, or C) for the best option, followed by a brief reason.\n"
        . "Format: \"LETTER: reason\""
        . "{$options}";

    $evalResult = callStoryAI(
        'You are a video script editor. You evaluate narration scripts and pick the best one. Return only "LETTER: reason".',
        $evalPrompt,
        'openai/gpt-4o-mini', // Cheap eval model
        0.3, 200
    );

    $default = $candidates[0]; // Fallback to first

    if (isset($evalResult['error'])) return $default;

    $content = trim($evalResult['content']);
    if (preg_match('/^([A-C])/', $content, $m)) {
        $picked = $m[1];
        $idx = ord($picked) - 65;
        if (isset($candidates[$idx])) {
            fwrite(STDERR, "Evaluator picked option {$picked}: " . substr($content, 0, 200) . "\n");
            return $candidates[$idx];
        }
    }

    fwrite(STDERR, "Evaluator returned ambiguous: {$content} — defaulting to A\n");
    return $default;
}