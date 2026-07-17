<?php
/**
 * Photo Room Analyzer V2 — Deep Analysis
 * Takes local photo paths → vision model → room tags + visual details + quality + hero flags
 * Called by prepare-3d-job.php during video job setup
 *
 * Storyline V2: Provides rich visual context to the storyteller AI so it can
 * weave specific photo details into the narration (not just room types).
 */

if ($argc < 3) { fwrite(STDERR, "Usage: php photo-analyze-v2.php <jobDir> <photoCount>\n"); exit(1); }

$jobDir = $argv[1];
$photoCount = intval($argv[2]);

require_once '/var/www/agentrocketman.com/agentado/api/config.php';

$cacheFile = $jobDir . '/photo_tags_v2.json';
if (file_exists($cacheFile)) {
    $cached = json_decode(file_get_contents($cacheFile), true);
    if (is_array($cached) && count($cached) >= $photoCount) {
        echo json_encode($cached);
        exit(0);
    }
}

// Collect photo paths
$photos = [];
for ($i = 0; $i < $photoCount; $i++) {
    $found = null;
    foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
        $path = $jobDir . '/photo_' . $i . '.' . $ext;
        if (file_exists($path)) { $found = $path; break; }
    }
    if ($found) $photos[$i] = $found;
}

if (count($photos) < 3) {
    $tags = [];
    foreach ($photos as $i => $p) {
        $tags["photo_$i"] = ['room' => 'interior', 'details' => 'property interior', 'quality' => 3, 'is_hero' => false];
    }
    file_put_contents($cacheFile, json_encode($tags));
    echo json_encode($tags);
    exit(0);
}

fwrite(STDERR, "Analyzing " . count($photos) . " photos (V2 deep analysis)...\n");

// Build array of base64 images
$images = [];
foreach ($photos as $i => $path) {
    $data = file_get_contents($path);
    if ($data && strlen($data) < 10 * 1024 * 1024) {
        $mime = 'image/' . pathinfo($path, PATHINFO_EXTENSION);
        if ($mime === 'image/jpg') $mime = 'image/jpeg';
        $images[] = ['index' => $i, 'data' => base64_encode($data), 'mime' => $mime];
    }
}

if (count($images) === 0) {
    $tags = [];
    foreach ($photos as $i => $p) {
        $tags["photo_$i"] = ['room' => 'interior', 'details' => 'property interior', 'quality' => 3, 'is_hero' => false];
    }
    file_put_contents($cacheFile, json_encode($tags));
    echo json_encode($tags);
    exit(0);
}

// V2 prompt — asks for room, visual details, quality score, and hero flag
$systemPrompt = "You are a luxury real estate photo analyst. For each photo below, identify:\n\n"
    . "1. **room**: Which room/area is shown. Choose from: exterior_front, exterior_back, aerial_drone, driveway, garage, "
    . "foyer_entryway, hallway, stairs, living_room, family_room, den_office, kitchen, dining_room, breakfast_nook, "
    . "master_bedroom, bedroom, walk_in_closet, bathroom, ensuite, powder_room, pool, backyard, patio_deck, garden, "
    . "balcony, basement, laundry_room, home_gym, home_theater, wine_cellar, other_interior, other_exterior\n\n"
    . "2. **details**: A short visual description of notable features in the photo (10-30 words). Focus on things a "
    . "narrator would mention: materials, colors, fixtures, lighting, views, unique elements. Be specific.\n"
    . "Example: \"white shaker cabinets, marble waterfall island with pendant lights, stainless steel appliances, "
    . "hardwood floors\"\n\n"
    . "3. **quality**: A 1-5 score for photo composition/lighting/impact (5=stunning magazine quality, 1=blurry/dark/poor angle)\n\n"
    . "4. **is_hero**: true if this photo is dramatic, impressive, or would make a great opening/closing shot (top 20% only)\n\n"
    . "Return ONLY a JSON object. Example:\n"
    . "{\"0\": {\"room\":\"kitchen\", \"details\":\"white cabinets, marble island, pendant lights, stainless appliances, hardwood floors\", \"quality\":4, \"is_hero\":false}}";

$content = [['type' => 'text', 'text' => $systemPrompt]];

// Do photos in batches of 4 (fewer per batch due to longer responses)
$batchSize = 4;
$allTags = [];

for ($batch = 0; $batch < ceil(count($images) / $batchSize); $batch++) {
    $batchImages = array_slice($images, $batch * $batchSize, $batchSize);
    $batchContent = $content;

    foreach ($batchImages as $img) {
        $batchContent[] = ['type' => 'text', 'text' => "Photo " . $img['index'] . ":"];
        $batchContent[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $img['mime'] . ';base64,' . $img['data']]];
    }

    $response = openrouterChatV2([
        ['role' => 'user', 'content' => $batchContent],
    ], 'openai/gpt-4o-mini', 0.3, 1000);

    $batchTags = parseV2Response($response, $batchImages);
    $allTags = array_merge($allTags, $batchTags);

    fwrite(STDERR, "Batch " . ($batch + 1) . "/" . ceil(count($images) / $batchSize) . " done (" . count($batchTags) . " photos)\n");
}

// Fill missing
for ($i = 0; $i < $photoCount; $i++) {
    if (!isset($allTags["photo_$i"])) {
        $allTags["photo_$i"] = ['room' => 'interior', 'details' => 'property interior', 'quality' => 3, 'is_hero' => false];
    }
}

// Ensure proper structure
foreach ($allTags as $k => &$v) {
    if (is_string($v)) {
        // Legacy format — wrap in struct
        $v = ['room' => $v, 'details' => $v, 'quality' => 3, 'is_hero' => false];
    }
    if (!isset($v['room'])) $v['room'] = 'interior';
    if (!isset($v['details'])) $v['details'] = $v['room'];
    if (!isset($v['quality'])) $v['quality'] = 3;
    if (!isset($v['is_hero'])) $v['is_hero'] = false;
}
unset($v);

// Save V2 cache
file_put_contents($cacheFile, json_encode($allTags));

// Save backwards-compatible simple tags for v1 consumers
$simplified = [];
foreach ($allTags as $k => $v) {
    $idx = str_replace('photo_', '', $k);
    $simplified[$idx] = $v['room']; // just the room string
}
file_put_contents($jobDir . '/photo_tags_simple.json', json_encode($simplified));

echo json_encode($allTags);

// ── Helpers ──

function openrouterChatV2($messages, $model, $temperature, $maxTokens) {
    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 90,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENROUTER_API_KEY,
            'HTTP-Referer: https://agentrocketman.com',
            'X-Title: Agentado Photo Analyzer V2',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $model, 'messages' => $messages,
            'temperature' => $temperature, 'max_tokens' => $maxTokens,
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

function parseV2Response($response, $batchImages) {
    $tags = [];
    $content = trim($response);

    // Strip markdown code fences
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

    // Fallback: try to extract structured key-value
    if (preg_match_all('/"(\d+)"\s*:\s*\{[^}]+\}/s', $content, $m)) {
        // Try re-parsing just the { } blocks
        $repaired = preg_replace('/\s+/', ' ', $content);
        if (preg_match('/^.*?(\{.*\}).*?$/s', $repaired, $mm)) {
            $parsed = json_decode($mm[1], true);
            if (is_array($parsed)) {
                foreach ($parsed as $k => $v) {
                    $tags["photo_$k"] = $v;
                }
                return $tags;
            }
        }
    }

    fwrite(STDERR, "Failed to parse V2 vision response: " . substr($content, 0, 300) . "\n");

    // Last resort: assign basic tags
    foreach ($batchImages as $img) {
        $tags['photo_' . $img['index']] = ['room' => 'interior', 'details' => 'property interior', 'quality' => 3, 'is_hero' => false];
    }
    return $tags;
}