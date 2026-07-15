<?php
/**
 * Background job preparer — does all heavy work ASYNCHRONOUSLY.
 * Invoked by start-3d-video.php: php prepare-3d-job.php <jobId>
 * Updates progress.json at each stage.
 */
if ($argc < 2) { fwrite(STDERR, "Usage: php prepare-3d-job.php <jobId>\n"); exit(1); }

$jobId = $argv[1];
$jobsRoot = __DIR__ . '/../output/jobs';
$jobDir = $jobsRoot . '/' . $jobId;

if (!is_dir($jobDir)) { fwrite(STDERR, "Job dir not found: $jobDir\n"); exit(1); }

// Load input
$input = json_decode(file_get_contents($jobDir . '/input.json'), true);
$photos = $input['photos'] ?? [];
$listing = $input['listing'] ?? [];
$description = $input['description'] ?? '';
$showVoiceOver = !empty($input['showVoiceOver']);
$voiceId = $input['voiceId'] ?? '21m00Tcm4TlvDq8ikWAM';
$showMusic = !empty($input['showMusic']);
$musicVibe = $input['musicVibe'] ?? 'elegant-piano';
$showSubtitles = !empty($input['showSubtitles']);
$showPriceIntro = $input['showPriceIntro'] ?? true;
$showPriceBar = $input['showPriceBar'] ?? true;
$showContactSlide = $input['showContactSlide'] ?? true;

$elKey = 'sk_1b300ca925e3778885f4a8595960423130fe660c3e2d5082';

function updateProgress($jobDir, $data) {
    file_put_contents($jobDir . '/progress.json', json_encode($data));
}

// ── Step 1: Download photos ──
updateProgress($jobDir, [
    'status' => 'preparing', 'stage' => 'Downloading photos…', 'progress' => 2,
    'total_clips' => count($photos), 'completed_clips' => 0, 'error' => null, 'result_url' => null,
]);

$localPhotos = [];
foreach ($photos as $i => $url) {
    $ext = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
    $path = $jobDir . '/photo_' . $i . '.' . $ext;
    $ctx = stream_context_create([
        'http' => ['timeout' => 30, 'user_agent' => 'Agentado/1.0'],
        'ssl'  => ['verify_peer' => false],
    ]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data) {
        file_put_contents($path, $data);
        $localPhotos[] = $path;
    } else {
        $localPhotos[] = $url;
        fwrite(STDERR, "Failed to download photo $i: $url\n");
    }
}

// ── Step 2: Build narration text ──
$narration = $description;
if (empty($narration)) {
    $addr = $listing['address'] ?? $listing['addr'] ?? 'this beautiful property';
    $narration = "Welcome to $addr. A stunning property with exceptional design and features.";
}

// ── Step 3: Generate subtitles via GPT ──
$phrases = [];
if ($showSubtitles && !empty($narration)) {
    updateProgress($jobDir, [
        'status' => 'preparing', 'stage' => 'Generating AI subtitles…', 'progress' => 5,
        'total_clips' => count($photos), 'completed_clips' => 0, 'error' => null, 'result_url' => null,
    ]);

    // Call our own subtitles-rewrite endpoint via localhost PHP server
    $ch = curl_init('http://127.0.0.1:9000/agentado/api/subtitles-rewrite.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['description' => $narration]),
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 200) {
        $phrases = json_decode($resp, true) ?: [];
    }
}

// ── Step 4: Generate voice-over ──
$voicePath = null;
$audioDuration = 0;
if ($showVoiceOver) {
    updateProgress($jobDir, [
        'status' => 'preparing', 'stage' => 'Generating voice-over…', 'progress' => 10,
        'total_clips' => count($photos), 'completed_clips' => 0, 'error' => null, 'result_url' => null,
    ]);

    $voiceText = !empty($phrases) ? implode(' ', $phrases) : $narration;
    $voiceDir = $jobDir . '/audio';
    if (!is_dir($voiceDir)) mkdir($voiceDir, 0777, true);
    $voiceFile = $voiceDir . '/voice.mp3';

    // Check global cache
    $cacheKey = md5($voiceText . $voiceId);
    $globalCache = __DIR__ . '/../output/tts-cache/' . $cacheKey . '.mp3';
    if (file_exists($globalCache) && filesize($globalCache) > 0) {
        copy($globalCache, $voiceFile);
    } else {
        $ch = curl_init('https://api.elevenlabs.io/v1/text-to-speech/' . urlencode($voiceId));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'xi-api-key: ' . $elKey],
            CURLOPT_POSTFIELDS => json_encode([
                'text' => $voiceText, 'model_id' => 'eleven_multilingual_v2',
                'voice_settings' => ['stability' => 0.5, 'similarity_boost' => 0.75],
            ]),
        ]);
        $audio = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode === 200 && !empty($audio)) {
            file_put_contents($voiceFile, $audio);
            if (is_dir(__DIR__ . '/../output/tts-cache/')) {
                @mkdir(__DIR__ . '/../output/tts-cache/', 0777, true);
                copy($voiceFile, $globalCache);
            }
        }
    }

    if (file_exists($voiceFile) && filesize($voiceFile) > 0) {
        $voicePath = $voiceFile;
        $dur = trim(shell_exec('ffprobe -v quiet -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($voiceFile) . ' 2>&1'));
        $audioDuration = floatval($dur);
    }
}

// ── Step 5: Generate background music ──
$musicPath = null;
if ($showMusic && !empty($musicVibe) && $musicVibe !== 'none') {
    updateProgress($jobDir, [
        'status' => 'preparing', 'stage' => 'Generating background music…', 'progress' => 15,
        'total_clips' => count($photos), 'completed_clips' => 0, 'error' => null, 'result_url' => null,
    ]);

    $prompts = [
        'elegant-piano' => 'Elegant, warm solo piano piece for a luxury real estate walkthrough. Flowing arpeggios and soft chords. Instrumental.',
        'upbeat-modern' => 'Bright, uplifting acoustic guitar with light percussion. Fresh modern energy. Breezy and inviting. Instrumental.',
        'chill-ambient' => 'Serene atmospheric ambient with soft pads and gentle textures. Calming spa-like feel. Instrumental.',
        'cinematic' => 'Cinematic orchestral swell with warm strings and soft brass. Slow sweeping emotional. Instrumental.',
        'smooth-jazz' => 'Smooth lounge jazz with soft piano, walking bass, light brush drums. Sophisticated. Instrumental.',
    ];
    $prompt = $prompts[$musicVibe] ?? $prompts['elegant-piano'];
    $musicDur = $audioDuration > 0 ? intval(($audioDuration + 5) * 1000) : count($photos) * 5000;

    $musicDir = $jobDir . '/audio';
    if (!is_dir($musicDir)) mkdir($musicDir, 0777, true);
    $musicFile = $musicDir . '/music.mp3';

    $cacheKey = md5($prompt . '_' . $musicDur);
    $globalCache = __DIR__ . '/../output/music-cache/' . $cacheKey . '.mp3';
    if (file_exists($globalCache) && filesize($globalCache) > 0) {
        copy($globalCache, $musicFile);
    } else {
        $ch = curl_init('https://api.elevenlabs.io/v1/music');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'xi-api-key: ' . $elKey],
            CURLOPT_POSTFIELDS => json_encode([
                'prompt' => $prompt, 'music_length_ms' => $musicDur,
                'model_id' => 'music_v2', 'force_instrumental' => true, 'output_format' => 'mp3_48000_192',
            ]),
        ]);
        $audio = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode === 200 && !empty($audio)) {
            file_put_contents($musicFile, $audio);
            if (is_dir(__DIR__ . '/../output/music-cache/')) {
                @mkdir(__DIR__ . '/../output/music-cache/', 0777, true);
                copy($musicFile, $globalCache);
            }
        }
    }

    if (file_exists($musicFile) && filesize($musicFile) > 0) {
        $musicPath = $musicFile;
    }
}

// ── Step 6: Mix audio ──
$mixedAudioPath = null;
$mixFile = $jobDir . '/audio/mixed.mp3';
if ($voicePath && $musicPath) {
    updateProgress($jobDir, [
        'status' => 'preparing', 'stage' => 'Mixing audio…', 'progress' => 20,
        'total_clips' => count($photos), 'completed_clips' => 0, 'error' => null, 'result_url' => null,
    ]);
    $cmd = sprintf(
        'ffmpeg -y -i %s -i %s -filter_complex "[1:a]volume=0.12[music];[0:a][music]amix=inputs=2:duration=first:dropout_transition=2" -c:a libmp3lame -b:a 192k %s 2>&1',
        escapeshellarg($voicePath), escapeshellarg($musicPath), escapeshellarg($mixFile)
    );
    shell_exec($cmd);
    if (file_exists($mixFile) && filesize($mixFile) > 0) {
        $mixedAudioPath = $mixFile;
        $dur = trim(shell_exec('ffprobe -v quiet -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($mixFile) . ' 2>&1'));
        $audioDuration = floatval($dur);
    }
} elseif ($musicPath && !$voicePath) {
    $mixedAudioPath = $musicPath;
    $dur = trim(shell_exec('ffprobe -v quiet -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($musicPath) . ' 2>&1'));
    $audioDuration = floatval($dur);
} elseif ($voicePath) {
    $mixedAudioPath = $voicePath;
}

// ── Step 7: Create ASS subtitle file ──
$subsPath = null;
if (!empty($phrases)) {
    $dur = $audioDuration > 0 ? $audioDuration : (count($photos) * 5.0 - (count($photos) - 1) * 0.8);
    $subsPath = createAssFile($phrases, $dur, $jobDir);
}

// ── Step 8: Write Python config ──
$pyConfig = [
    'photos' => $localPhotos,
    'listing' => $listing,
    'audio_path' => $mixedAudioPath,
    'subs_path' => $subsPath,
    'show_price_intro' => $showPriceIntro,
    'show_price_bar' => $showPriceBar,
    'show_contact_slide' => $showContactSlide,
    'clip_duration' => 5.0,
    'crossfade' => 0.8,
];
file_put_contents($jobDir . '/config.json', json_encode($pyConfig, JSON_PRETTY_PRINT));

updateProgress($jobDir, [
    'status' => 'generating_clips', 'stage' => 'Generating 3D clips via Kling AI…', 'progress' => 25,
    'total_clips' => count($photos), 'completed_clips' => 0, 'error' => null, 'result_url' => null,
]);

// ── Step 9: Spawn Python ──
$outputMp4 = $jobDir . '/final_output.mp4';
$pyScript = __DIR__ . '/video/generate_ai_walkthrough.py';
$logFile = $jobDir . '/python.log';

$cmd = sprintf(
    'python3 %s %s %s %s > %s 2>&1 & echo $!',
    escapeshellarg($pyScript), escapeshellarg($jobDir),
    escapeshellarg($outputMp4), escapeshellarg($jobDir . '/config.json'),
    escapeshellarg($logFile)
);
$pid = trim(shell_exec($cmd));
file_put_contents($jobDir . '/pid.txt', $pid);

// ── Helper: create ASS subtitle file ──
function createAssFile($phrases, $duration, $jobDir) {
    $n = count($phrases);
    if ($n === 0) return null;
    $assPath = $jobDir . '/subtitles.ass';
    $fadeMs = 300;
    $phraseTime = $duration / $n;

    $ass = "[Script Info]\nTitle: Agentado Subtitles\nScriptType: v4.00+\nWrapStyle: 0\nScaledBorderAndShadow: yes\nPlayResX: 1280\nPlayResY: 720\n\n";
    $ass .= "[V4+ Styles]\nFormat: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\n";
    $ass .= "Style: Default,Poppins,54,&H00FFFFFF,&H000000FF,&H00000000,&H80000000,-1,0,0,0,100,100,1.5,0,1,7.5,0,2,40,40,140,1\n\n";
    $ass .= "[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n";

    for ($i = 0; $i < $n; $i++) {
        $start = $i * $phraseTime;
        $end = $start + $phraseTime;
        $fi = min($fadeMs, intval($phraseTime * 500));
        $fo = min($fadeMs, intval($phraseTime * 500));
        $s = sprintf('%d:%02d:%02d.%02d', floor($start/3600), floor(($start%3600)/60), floor($start%60), floor(($start-floor($start))*100));
        $e = sprintf('%d:%02d:%02d.%02d', floor($end/3600), floor(($end%3600)/60), floor($end%60), floor(($end-floor($end))*100));
        $text = htmlspecialchars($phrases[$i], ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $ass .= "Dialogue: 0,{$s},{$e},Default,,0,0,0,,{{\\fad($fi,$fo)}}{$text}\n";
    }

    file_put_contents($assPath, $ass);
    return $assPath;
}