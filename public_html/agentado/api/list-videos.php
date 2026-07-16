<?php
/**
 * List all generated videos on Docker disk
 * Returns JSON array of video metadata
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$base = realpath(__DIR__ . '/../output');
if (!$base) { echo json_encode(['ok' => false, 'error' => 'Output dir not found']); exit; }

$videos = [];

// 3D Cinematic jobs
$jobsDir = $base . '/jobs/';
if (is_dir($jobsDir)) {
    foreach (scandir($jobsDir, SCANDIR_SORT_DESCENDING) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $jobDir = $jobsDir . $entry;
        if (!is_dir($jobDir)) continue;
        $mp4 = $jobDir . '/final_output.mp4';
        if (!is_file($mp4)) continue;
        
        $config = file_exists($jobDir . '/config.json') ? json_decode(file_get_contents($jobDir . '/config.json'), true) : null;
        $progress = file_exists($jobDir . '/progress.json') ? json_decode(file_get_contents($jobDir . '/progress.json'), true) : null;
        
        $videos[] = [
            'id' => $entry,
            'type' => 'job',
            'url' => '/agentado/api/video/serve-job-video.php?job=' . $entry,
            'name' => '3D Cinematic — ' . substr($entry, 0, 8),
            'date' => filemtime($mp4),
            'size' => filesize($mp4),
            'photo_count' => $config ? count($config['photos'] ?? []) : 0,
            'voice' => $config ? ($config['showVoiceOver'] ?? false) : false,
            'subtitles' => $config ? ($config['showSubtitles'] ?? false) : false,
            'status' => $progress['status'] ?? 'unknown',
        ];
    }
}

// Ken Burns / standalone videos
$videosDir = $base . '/videos/';
if (is_dir($videosDir)) {
    foreach (scandir($videosDir, SCANDIR_SORT_DESCENDING) as $entry) {
        if (!str_ends_with($entry, '.mp4')) continue;
        $videos[] = [
            'id' => $entry,
            'type' => 'video',
            'url' => '/agentado/output/videos/' . $entry,
            'name' => 'Ken Burns — ' . pathinfo($entry, PATHINFO_FILENAME),
            'date' => filemtime($videosDir . $entry),
            'size' => filesize($videosDir . $entry),
            'photo_count' => 0,
            'voice' => false,
            'subtitles' => false,
            'status' => 'standalone',
        ];
    }
}

// Previews
$previewsDir = $base . '/previews/';
if (is_dir($previewsDir)) {
    foreach (scandir($previewsDir, SCANDIR_SORT_DESCENDING) as $entry) {
        if (!str_ends_with($entry, '.mp4')) continue;
        $videos[] = [
            'id' => $entry,
            'type' => 'preview',
            'url' => '/agentado/output/previews/' . $entry,
            'name' => 'Preview — ' . pathinfo($entry, PATHINFO_FILENAME),
            'date' => filemtime($previewsDir . $entry),
            'size' => filesize($previewsDir . $entry),
            'photo_count' => 0,
            'voice' => false,
            'subtitles' => false,
            'status' => 'preview',
        ];
    }
}

usort($videos, fn($a, $b) => $b['date'] <=> $a['date']);

echo json_encode(['ok' => true, 'videos' => $videos]);