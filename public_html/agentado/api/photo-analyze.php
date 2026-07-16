<?php
/**
 * Photo Room Analyzer
 * Takes local photo paths → sends to vision model → returns room tags
 * Called by prepare-3d-job.php during video job setup
 */

if ($argc < 3) { fwrite(STDERR, "Usage: php photo-analyze.php <jobDir> <photoCount>\n"); exit(1); }

$jobDir = $argv[1];
$photoCount = intval($argv[2]);

require_once '/var/www/agentrocketman.com/agentado/api/config.php';

$cacheFile = $jobDir . '/photo_tags.json';
if (file_exists($cacheFile)) {
    $cached = json_decode(file_get_contents($cacheFile), true);
    if (count($cached) >= $photoCount) {
        echo json_encode($cached);
        exit(0);
    }
}

// Collect photo paths
$photos = [];
for ($i = 0; $i < $photoCount; $i++) {
    // Try different extensions
    $found = null;
    foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
        $path = $jobDir . '/photo_' . $i . '.' . $ext;
        if (file_exists($path)) { $found = $path; break; }
    }
    if ($found) $photos[$i] = $found;
}

if (count($photos) < 3) {
    // Too few photos to meaningfully analyze — just return sequential order
    $tags = [];
    foreach ($photos as $i => $p) {
        $tags["photo_$i"] = 'interior';
    }
    file_put_contents($cacheFile, json_encode($tags));
    echo json_encode($tags);
    exit(0);
}

fwrite(STDERR, "Analyzing " . count($photos) . " photos...\n");

// Build array of base64 images
$images = [];
foreach ($photos as $i => $path) {
    $data = file_get_contents($path);
    if ($data && strlen($data) < 10 * 1024 * 1024) { // 10MB limit
        $mime = 'image/' . pathinfo($path, PATHINFO_EXTENSION);
        if ($mime === 'image/jpg') $mime = 'image/jpeg';
        $images[] = [
            'index' => $i,
            'data' => base64_encode($data),
            'mime' => $mime,
        ];
    }
}

if (count($images) === 0) {
    $tags = [];
    foreach ($photos as $i => $p) {
        $tags["photo_$i"] = 'interior';
    }
    file_put_contents($cacheFile, json_encode($tags));
    echo json_encode($tags);
    exit(0);
}

// Build messages for vision model
$content = [];
$content[] = [
    'type' => 'text',
    'text' => "You are analyzing real estate listing photos. For each photo below, identify which room or area is shown. Choose from these categories:\n\n"
        . "exterior_front, exterior_back, exterior_side, aerial_drone, driveway, garage\n"
        . "foyer_entryway, hallway, stairs\n"
        . "living_room, family_room, den_office\n"
        . "kitchen, dining_room, breakfast_nook\n"
        . "master_bedroom, bedroom, walk_in_closet\n"
        . "bathroom, ensuite, powder_room\n"
        . "pool, backyard, patio_deck, garden, balcony\n"
        . "basement, laundry_room, home_gym, home_theater, wine_cellar\n"
        . "other_interior, other_exterior\n\n"
        . "Return ONLY a JSON object mapping photo numbers to categories. Example:\n"
        . "{\"0\": \"exterior_front\", \"1\": \"foyer_entryway\", \"2\": \"living_room\", ...}\n"
        . "Be specific and accurate. If you cannot determine, use 'other_interior' or 'other_exterior'.",
];

$startIdx = 0;
// Do photos in batches of 6 to stay within context limits
$batchSize = 6;
$allTags = [];

for ($batch = 0; $batch < ceil(count($images) / $batchSize); $batch++) {
    $batchImages = array_slice($images, $batch * $batchSize, $batchSize);
    $batchContent = $content;

    foreach ($batchImages as $img) {
        $batchContent[] = [
            'type' => 'text',
            'text' => "Photo " . $img['index'] . ":",
        ];
        $batchContent[] = [
            'type' => 'image_url',
            'image_url' => ['url' => 'data:' . $img['mime'] . ';base64,' . $img['data']],
        ];
    }

    $response = openrouterChat([
        ['role' => 'user', 'content' => $batchContent],
    ], 'openai/gpt-4o-mini', 0.3, 500);

    $batchTags = parseTagsResponse($response, $batchImages);
    $allTags = array_merge($allTags, $batchTags);

    fwrite(STDERR, "Batch " . ($batch + 1) . "/" . ceil(count($images) / $batchSize) . " done (" . count($batchTags) . " tags)\n");
}

// Fill in missing indices
for ($i = 0; $i < $photoCount; $i++) {
    if (!isset($allTags["photo_$i"])) {
        $allTags["photo_$i"] = 'interior';
    }
}

// Save cache
file_put_contents($cacheFile, json_encode($allTags));

// Also save a simplified version for subtitles PHP
$simplified = [];
foreach ($allTags as $k => $v) {
    $idx = str_replace('photo_', '', $k);
    $simplified[$idx] = $v;
}
file_put_contents($jobDir . '/photo_tags_simple.json', json_encode($simplified));

echo json_encode($allTags);

// ── Helpers ──

function openrouterChat($messages, $model, $temperature, $maxTokens) {
    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENROUTER_API_KEY,
            'HTTP-Referer: https://agentrocketman.com',
            'X-Title: Agentado Photo Analyzer',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $model,
            'messages' => $messages,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
        ]),
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($code !== 200) {
        fwrite(STDERR, "OpenRouter error $code: " . substr($resp, 0, 500) . "\n");
        return '';
    }
    $data = json_decode($resp, true);
    return $data['choices'][0]['message']['content'] ?? '';
}

function parseTagsResponse($response, $batchImages) {
    $tags = [];
    // Try to extract JSON from response
    $content = trim($response);
    // Remove markdown code fences if present
    if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $m)) {
        $content = $m[1];
    }
    $parsed = json_decode($content, true);
    if (is_array($parsed)) {
        foreach ($parsed as $k => $v) {
            $tags["photo_$k"] = $v;
        }
        return $tags;
    }
    // Fallback: try to match key-value pairs
    if (preg_match_all('/"(\d+)"\s*:\s*"([^"]+)"/', $content, $m)) {
        for ($i = 0; $i < count($m[0]); $i++) {
            $tags['photo_' . $m[1][$i]] = $m[2][$i];
        }
        return $tags;
    }
    fwrite(STDERR, "Failed to parse vision response: " . substr($content, 0, 300) . "\n");
    // Assign 'interior' as fallback
    foreach ($batchImages as $img) {
        $tags['photo_' . $img['index']] = 'interior';
    }
    return $tags;
}