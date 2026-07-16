<?php
/**
 * Serve video from Docker filesystem directly.
 * GET /agentado/api/video/serve-job-video.php?job=<job_id>
 */
$jobId = preg_replace('/[^a-f0-9]/', '', $_GET['job'] ?? '');
if (empty($jobId)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing job ID']);
    exit;
}

$videoFile = __DIR__ . '/../../output/jobs/' . $jobId . '/final_output.mp4';

if (!file_exists($videoFile) || filesize($videoFile) < 1024) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Video not found']);
    exit;
}

$size = filesize($videoFile);

// Handle range requests for video seeking
if (isset($_SERVER['HTTP_RANGE'])) {
    preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches);
    $start = intval($matches[1]);
    $end = $matches[2] !== '' ? intval($matches[2]) : $size - 1;
    $length = $end - $start + 1;

    http_response_code(206);
    header('Content-Type: video/mp4');
    header("Content-Range: bytes $start-$end/$size");
    header("Content-Length: $length");
    header('Accept-Ranges: bytes');
    header('Cache-Control: public, max-age=86400');
    header('Access-Control-Allow-Origin: *');

    $fp = fopen($videoFile, 'rb');
    fseek($fp, $start);
    $remaining = $length;
    while ($remaining > 0 && !feof($fp)) {
        $chunk = min($remaining, 8192);
        echo fread($fp, $chunk);
        $remaining -= $chunk;
    }
    fclose($fp);
    exit;
}

header('Content-Type: video/mp4');
header("Content-Length: $size");
header('Accept-Ranges: bytes');
header('Cache-Control: public, max-age=86400');
header('Access-Control-Allow-Origin: *');
readfile($videoFile);
