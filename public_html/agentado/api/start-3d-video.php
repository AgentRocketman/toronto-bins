<?php
/**
 * Container-side 3D video start endpoint.
 * Runs on PHP built-in server inside Docker container.
 * Returns immediately — heavy work done by prepare-job.php in background.
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

// Create job
$jobId = bin2hex(random_bytes(16));
$jobsRoot = __DIR__ . '/../output/jobs';
if (!is_dir($jobsRoot)) mkdir($jobsRoot, 0777, true);
$jobDir = $jobsRoot . '/' . $jobId;
mkdir($jobDir, 0777, true);

// Write input for background processor
file_put_contents($jobDir . '/input.json', json_encode($input, JSON_PRETTY_PRINT));

// Write initial progress
file_put_contents($jobDir . '/progress.json', json_encode([
    'status'   => 'queued',
    'stage'    => 'Job created — waiting for processor…',
    'progress' => 0,
    'total_clips' => count($input['photos']),
    'completed_clips' => 0,
    'error'    => null,
    'result_url' => null,
]));

// Spawn background preparer (non-blocking)
$prepareScript = __DIR__ . '/prepare-3d-job.php';
$logFile = $jobDir . '/prepare.log';
$cmd = sprintf('php %s %s > %s 2>&1 & echo $!',
    escapeshellarg($prepareScript),
    escapeshellarg($jobId),
    escapeshellarg($logFile)
);
$pid = trim(shell_exec($cmd));
file_put_contents($jobDir . '/pid.txt', $pid);

// Return immediately
header('Content-Type: application/json');
echo json_encode([
    'job_id' => $jobId,
    'status' => 'queued',
    'message' => 'Job created — preparation starting',
    'photo_count' => count($input['photos']),
]);