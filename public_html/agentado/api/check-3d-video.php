<?php
/**
 * Check 3D AI Cinematic video generation progress
 * GET /agentado/api/check-3d-video.php?job=<job_id>
 */
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$jobId = preg_replace('/[^a-f0-9]/', '', $_GET['job'] ?? '');
if (empty($jobId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing job ID', 'status' => 'invalid']);
    exit;
}

$jobDir = __DIR__ . '/../output/jobs/' . $jobId;
$progressFile = $jobDir . '/progress.json';
$outputFile = $jobDir . '/final_output.mp4';
$logFile = $jobDir . '/python.log';

// Read PHP tunnel URL for serving videos from Docker
$tunnelFile = __DIR__ . '/video/tunnel-url.txt';
$tunnelUrl = '';
if (file_exists($tunnelFile)) {
    $tunnelUrl = rtrim(trim(file_get_contents($tunnelFile)), '/');
}

// Check if job exists
if (!is_dir($jobDir)) {
    http_response_code(404);
    echo json_encode(['status' => 'not_found', 'error' => 'Job not found']);
    exit;
}

// Read progress
$progress = null;
if (file_exists($progressFile)) {
    $progress = json_decode(file_get_contents($progressFile), true);
}

// If no progress file yet, but job dir exists
if (!$progress) {
    echo json_encode([
        'status' => 'preparing',
        'stage' => 'Setting up…',
        'progress' => 0,
        'has_output' => false,
    ]);
    exit;
}

// Check if output exists (job complete)
$hasOutput = file_exists($outputFile) && filesize($outputFile) > 0;

// Check if Python process is still running
$pidFile = $jobDir . '/pid.txt';
$pidAlive = false;
if (file_exists($pidFile)) {
    $pid = intval(file_get_contents($pidFile));
    $pidAlive = $pid > 0 && posix_kill($pid, 0);
}

// If output exists but progress doesn't say completed, update it
if ($hasOutput && ($progress['status'] ?? '') !== 'completed') {
    $progress['status'] = 'completed';
    $progress['stage'] = 'Done!';
    $progress['result_url'] = $tunnelUrl . '/agentado/api/video/serve-job-video.php?job=' . $jobId;
}

// Determine actual status
$status = $progress['status'] ?? 'unknown';

// If process died and no output, it failed
if (!$pidAlive && !$hasOutput && !in_array($status, ['completed', 'failed'])) {
    $status = 'failed';
    $logTail = '';
    if (file_exists($logFile)) {
        $logTail = substr(file_get_contents($logFile), -500);
    }
    $progress['error'] = $progress['error'] ?? "Process terminated unexpectedly.\n$logTail";
}

// Detect hung jobs: running > 45 minutes with no progress update
$startedFile = $jobDir . '/input.json';
if ($status === 'generating_clips' && file_exists($startedFile)) {
    $jobAge = time() - filemtime($startedFile);
    $progressAge = file_exists($progressFile) ? time() - filemtime($progressFile) : $jobAge;
    if ($jobAge > 2700 || $progressAge > 600) {  // 45min total or 10min no progress
        $status = 'failed';
        $progress['error'] = ($progress['error'] ?? '') . "\nJob hung — no progress for " . round($progressAge/60) . " minutes.";
    }
}

header('Content-Type: application/json');
echo json_encode([
    'status'      => $status,
    'stage'       => $progress['stage'] ?? '',
    'progress'    => $progress['progress'] ?? 0,
    'total_clips' => $progress['total_clips'] ?? 0,
    'completed_clips' => $progress['completed_clips'] ?? 0,
    'error'       => $progress['error'] ?? null,
    // Use tunnel URL so frontend loads video from Docker (where files actually live)
    'result_url'  => $hasOutput ? ($tunnelUrl . '/agentado/api/video/serve-job-video.php?job=' . $jobId) : null,
    'has_output'  => $hasOutput,
    'pid_alive'   => $pidAlive,
]);