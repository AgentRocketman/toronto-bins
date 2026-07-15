<?php
/**
 * Start 3D AI Cinematic Video Generation
 * Orchestrates: subtitle generation → voice-over TTS → music → audio mix → Kling Python script
 * Runs Python process in background, returns job ID for polling
 */
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
if (!$input || empty($input['photos'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing photos array']);
    exit;
}

$photos      = $input['photos'];
$listing     = $input['listing'] ?? [];
$description = $input['description'] ?? '';

$showVoiceOver   = !empty($input['showVoiceOver']);
$voiceId         = $input['voiceId'] ?? 'rachel';
$showMusic       = !empty($input['showMusic']);
$musicVibe       = $input['musicVibe'] ?? 'elegant-piano';
$showSubtitles   = !empty($input['showSubtitles']);
$showPriceIntro  = $input['showPriceIntro'] ?? true;
$showPriceBar    = $input['showPriceBar'] ?? true;
$showContactSlide = $input['showContactSlide'] ?? true;

// ElevenLabs API key
$elKey = 'sk_1b300ca925e3778885f4a8595960423130fe660c3e2d5082';

// Create job
$jobId = bin2hex(random_bytes(16));
$jobDir = __DIR__ . '/../output/jobs/' . $jobId;
if (!is_dir(__DIR__ . '/../output/jobs/')) {
    mkdir(__DIR__ . '/../output/jobs/', 0755, true);
}
mkdir($jobDir, 0755, true);

// Write initial progress
$progressPath = $jobDir . '/progress.json';
file_put_contents($progressPath, json_encode([
    'status'   => 'preparing',
    'stage'    => 'Setting up…',
    'progress' => 0,
    'total_clips' => count($photos),
    'completed_clips' => 0,
    'error'    => null,
    'result_url' => null,
]));

// ═══════════════ Helper: call ElevenLabs TTS ═══════════════
function generateVoiceOver($text, $voiceId, $elKey, $jobDir) {
    $cacheDir = $jobDir . '/audio';
    if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);

    $cacheKey = md5($text . $voiceId);
    $cacheFile = $cacheDir . '/voice_' . $cacheKey . '.mp3';

    // Check if cached globally
    $globalCache = __DIR__ . '/../output/tts-cache/' . $cacheKey . '.mp3';
    if (file_exists($globalCache) && filesize($globalCache) > 0) {
        copy($globalCache, $cacheFile);
        return $cacheFile;
    }

    $ch = curl_init('https://api.elevenlabs.io/v1/text-to-speech/' . urlencode($voiceId));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'xi-api-key: ' . $elKey,
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'text' => $text,
            'model_id' => 'eleven_multilingual_v2',
            'voice_settings' => [
                'stability' => 0.5,
                'similarity_boost' => 0.75,
            ],
        ]),
    ]);
    $audio = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || empty($audio)) {
        return null;
    }

    file_put_contents($cacheFile, $audio);
    if (is_dir(__DIR__ . '/../output/tts-cache/')) {
        copy($cacheFile, $globalCache);
    }
    return $cacheFile;
}

// ═══════════════ Helper: call ElevenLabs Music ═══════════════
function generateMusic($prompt, $durationMs, $elKey, $jobDir) {
    $cacheDir = $jobDir . '/audio';
    if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);

    $cacheKey = md5($prompt . '_' . $durationMs);
    $cacheFile = $cacheDir . '/music_' . $cacheKey . '.mp3';

    // Check global cache
    $globalCache = __DIR__ . '/../output/music-cache/' . $cacheKey . '.mp3';
    if (file_exists($globalCache) && filesize($globalCache) > 0) {
        copy($globalCache, $cacheFile);
        return $cacheFile;
    }

    $ch = curl_init('https://api.elevenlabs.io/v1/music');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'xi-api-key: ' . $elKey,
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'prompt' => $prompt,
            'music_length_ms' => $durationMs,
            'model_id' => 'music_v2',
            'force_instrumental' => true,
            'output_format' => 'mp3_48000_192',
        ]),
    ]);
    $audio = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || empty($audio)) {
        return null;
    }

    file_put_contents($cacheFile, $audio);
    return $cacheFile;
}

// ═══════════════ Helper: generate AI subtitles via GPT ═══════════════
function generateSubtitles($description, $jobDir) {
    if (empty($description)) return [];
    $descriptionsFile = __DIR__ . '/descriptions.txt';
    file_put_contents($descriptionsFile, $description);

    // Call our own endpoint via curl
    $ch = curl_init('http://localhost/agentado/api/subtitles-rewrite.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['description' => $description]),
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200) {
        $phrases = json_decode($resp, true);
        if (is_array($phrases)) return $phrases;
    }
    return [];
}

// ═══════════════ Helper: create ASS subtitle file ═══════════════
function createAssSubtitles($phrases, $audioDuration, $jobDir) {
    $n = count($phrases);
    if ($n === 0) return null;

    $assPath = $jobDir . '/subtitles.ass';
    $fadeMs = 300; // fade in/out per phrase
    $phraseTime = $audioDuration / $n;

    // ASS header
    $ass = "[Script Info]\n";
    $ass .= "Title: Agentado Subtitles\n";
    $ass .= "ScriptType: v4.00+\n";
    $ass .= "WrapStyle: 0\n";
    $ass .= "ScaledBorderAndShadow: yes\n";
    $ass .= "PlayResX: 1280\n";
    $ass .= "PlayResY: 720\n\n";

    $ass .= "[V4+ Styles]\n";
    $ass .= "Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\n";
    // White text, black outline, BOLD (not strict ExtraBold — works with any Bold weight)
    $ass .= "Style: Default,Poppins,54,&H00FFFFFF,&H000000FF,&H00000000,&H80000000,-1,0,0,0,100,100,1.5,0,1,7.5,0,2,40,40,140,1\n\n";

    $ass .= "[Events]\n";
    $ass .= "Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n";

    for ($i = 0; $i < $n; $i++) {
        $start   = $i * $phraseTime;
        $end     = $start + $phraseTime;
        $fadeIn  = min($fadeMs, intval($phraseTime * 500)); // ms
        $fadeOut = min($fadeMs, intval($phraseTime * 500));

        $startStr  = formatAssTime($start);
        $endStr    = formatAssTime($end);
        $text      = htmlspecialchars($phrases[$i], ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $ass .= "Dialogue: 0,{$startStr},{$endStr},Default,,0,0,0,,{{\\fad($fadeIn,$fadeOut)}}{$text}\n";
    }

    file_put_contents($assPath, $ass);
    return $assPath;
}

function formatAssTime($seconds) {
    $h = floor($seconds / 3600);
    $m = floor(($seconds % 3600) / 60);
    $s = floor($seconds % 60);
    $cs = floor(($seconds - floor($seconds)) * 100);
    return sprintf('%d:%02d:%02d.%02d', $h, $m, $s, $cs);
}

// ═══════════════ Helper: get audio duration ═══════════════
function getAudioDuration($filePath) {
    $cmd = sprintf('ffprobe -v quiet -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s 2>&1',
        escapeshellarg($filePath));
    $dur = trim(shell_exec($cmd));
    return floatval($dur);
}

// ═══════════════ Helper: download photo ═══════════════
function downloadPhoto($url, $jobDir, $index) {
    $ext = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
    $path = $jobDir . '/photo_' . $index . '.' . $ext;
    $data = @file_get_contents($url, false, stream_context_create([
        'http' => ['timeout' => 30, 'user_agent' => 'Agentado/1.0'],
        'ssl'  => ['verify_peer' => false],
    ]));
    if ($data) {
        file_put_contents($path, $data);
        return $path;
    }
    return null;
}

// ════════════════════════ PIPELINE START ════════════════════════

// Step 1: Download all photos
file_put_contents($progressPath, json_encode([
    'status' => 'preparing', 'stage' => 'Downloading photos…', 'progress' => 0,
    'total_clips' => count($photos), 'completed_clips' => 0, 'error' => null, 'result_url' => null,
]));

$localPhotos = [];
foreach ($photos as $i => $url) {
    $local = downloadPhoto($url, $jobDir, $i);
    if ($local) {
        $localPhotos[] = $local;
    } else {
        // Can't download → this will fail later
        $localPhotos[] = $url;
        error_log("Failed to download photo $i: $url");
    }
}

// Step 2: Build narration text (used for voice-over + subtitles)
$narration = $description;
if (empty($narration)) {
    $addr = ($listing['address'] ?? $listing['addr'] ?? 'this beautiful property');
    $narration = "Welcome to $addr. A stunning property with exceptional features.";
}

// Step 3: Generate subtitles (if enabled)
$phrases = [];
if ($showSubtitles) {
    file_put_contents($progressPath, json_encode([
        'status' => 'preparing', 'stage' => 'Generating AI subtitles…', 'progress' => 0,
        'total_clips' => count($photos), 'completed_clips' => 0, 'error' => null, 'result_url' => null,
    ]));
    $phrases = generateSubtitles($narration, $jobDir);
}

// Step 4: Generate voice-over (if enabled) — determine audio duration
$audioPath = null;
$audioDuration = 0;

if ($showVoiceOver) {
    file_put_contents($progressPath, json_encode([
        'status' => 'preparing', 'stage' => 'Generating voice-over…', 'progress' => 0,
        'total_clips' => count($photos), 'completed_clips' => 0, 'error' => null, 'result_url' => null,
    ]));
    $voiceText = !empty($phrases) ? implode(' ', $phrases) : $narration;
    $voicePath = generateVoiceOver($voiceText, $voiceId, $elKey, $jobDir);
    if ($voicePath) {
        $audioDuration = getAudioDuration($voicePath);
        $audioPath = $voicePath;
    }
}

// Step 5: Generate background music (if enabled)
$musicPath = null;
if ($showMusic && !empty($musicVibe) && $musicVibe !== 'none') {
    file_put_contents($progressPath, json_encode([
        'status' => 'preparing', 'stage' => 'Generating background music…', 'progress' => 0,
        'total_clips' => count($photos), 'completed_clips' => 0, 'error' => null, 'result_url' => null,
    ]));

    $musicPrompts = [
        'elegant-piano' => 'Elegant, warm solo piano piece for a luxury real estate walkthrough. Flowing arpeggios and soft chords. Sophisticated and refined. Instrumental.',
        'upbeat-modern' => 'Bright, uplifting acoustic guitar with light percussion. Fresh, modern energy for a contemporary property tour. Breezy and inviting. Instrumental.',
        'chill-ambient' => 'Serene atmospheric ambient with soft pads and gentle textures. Calming spa-like feel. Floating peaceful background. Instrumental.',
        'cinematic' => 'Cinematic orchestral swell with warm strings and soft brass. Slow, sweeping, emotional. Grand and inspiring. Instrumental.',
        'smooth-jazz' => 'Smooth lounge jazz with soft piano, walking bass, light brush drums. Relaxed sophisticated ambiance. Instrumental.',
    ];

    $prompt = $musicPrompts[$musicVibe] ?? $musicPrompts['elegant-piano'];
    $musicDur = $audioDuration > 0 ? intval(($audioDuration + 5) * 1000) : count($photos) * 5000;
    $musicPath = generateMusic($prompt, $musicDur, $elKey, $jobDir);
}

// Step 6: Mix voice + music (if both available)
$mixedAudioPath = null;
if ($voicePath && $musicPath) {
    file_put_contents($progressPath, json_encode([
        'status' => 'preparing', 'stage' => 'Mixing audio…', 'progress' => 0,
        'total_clips' => count($photos), 'completed_clips' => 0, 'error' => null, 'result_url' => null,
    ]));

    $mixedPath = $jobDir . '/mixed_audio.mp3';
    $cmd = sprintf(
        'ffmpeg -y -i %s -i %s -filter_complex "[1:a]volume=0.12[music];[0:a][music]amix=inputs=2:duration=first:dropout_transition=2" -c:a libmp3lame -b:a 192k %s 2>&1',
        escapeshellarg($voicePath),
        escapeshellarg($musicPath),
        escapeshellarg($mixedPath)
    );
    shell_exec($cmd);
    if (file_exists($mixedPath) && filesize($mixedPath) > 0) {
        $mixedAudioPath = $mixedPath;
        $audioDuration = getAudioDuration($mixedPath);
    }
} elseif ($musicPath && !$voicePath) {
    $mixedAudioPath = $musicPath;
} elseif ($voicePath) {
    $mixedAudioPath = $voicePath;
}

// Step 7: Create ASS subtitle file (timed to audio duration)
$subsPath = null;
if (!empty($phrases) && $audioDuration > 0) {
    $subsPath = createAssSubtitles($phrases, $audioDuration, $jobDir);
} elseif (!empty($phrases)) {
    // No audio — use estimated video duration (5s per clip - crossfades)
    $estDuration = count($photos) * 5.0 - (count($photos) - 1) * 0.8;
    $subsPath = createAssSubtitles($phrases, $estDuration, $jobDir);
}

// Step 8: Write Python config
$clipDuration = 5.0;
$pyConfig = [
    'photos' => $localPhotos,
    'listing' => $listing,
    'audio_path' => $mixedAudioPath,
    'subs_path' => $subsPath,
    'show_price_intro' => $showPriceIntro,
    'show_price_bar' => $showPriceBar,
    'show_contact_slide' => $showContactSlide,
    'clip_duration' => $clipDuration,
    'crossfade' => 0.8,
];
$configPath = $jobDir . '/config.json';
file_put_contents($configPath, json_encode($pyConfig, JSON_PRETTY_PRINT));

// Step 9: Spawn Python in background
$outputMp4 = $jobDir . '/final_output.mp4';
$pyScript = __DIR__ . '/video/generate_ai_walkthrough.py';
$logFile = $jobDir . '/python.log';

$cmd = sprintf(
    'python3 %s %s %s %s > %s 2>&1 & echo $!',
    escapeshellarg($pyScript),
    escapeshellarg($jobDir),
    escapeshellarg($outputMp4),
    escapeshellarg($configPath),
    escapeshellarg($logFile)
);
$pid = trim(shell_exec($cmd));
file_put_contents($jobDir . '/pid.txt', $pid);

// Return job ID
header('Content-Type: application/json');
echo json_encode([
    'job_id' => $jobId,
    'status' => 'started',
    'message' => '3D cinematic video generation started',
    'photo_count' => count($photos),
    'has_voice' => $showVoiceOver && $voicePath,
    'has_music' => $showMusic && $musicPath,
    'has_subtitles' => !empty($phrases),
]);