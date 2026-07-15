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
    $progress['result_url'] = '/agentado/output/jobs/' . $jobId . '/final_output.mp4';
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

header('Content-Type: application/json');
echo json_encode([
    'status'      => $status,
    'stage'       => $progress['stage'] ?? '',
    'progress'    => $progress['progress'] ?? 0,
    'total_clips' => $progress['total_clips'] ?? 0,
    'completed_clips' => $progress['completed_clips'] ?? 0,
    'error'       => $progress['error'] ?? null,
    'result_url'  => $progress['result_url'] ?? ($hasOutput ? ('/agentado/output/jobs/' . $jobId . '/final_output.mp4') : null),
    'has_output'  => $hasOutput,
    'pid_alive'   => $pidAlive,
]);