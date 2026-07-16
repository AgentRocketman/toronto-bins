<?php
/**
 * Delete a generated video/job directory on Docker disk
 * POST: job_id=xxx&type=job|video|preview
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$jobId = $_POST['job_id'] ?? '';
$type = $_POST['type'] ?? 'job';

if (empty($jobId)) {
    echo json_encode(['ok' => false, 'error' => 'Missing job_id']);
    exit;
}

$base = realpath(__DIR__ . '/../output');
if (!$base) { echo json_encode(['ok' => false, 'error' => 'Output dir not found']); exit; }

$deleted = false;

if ($type === 'job') {
    $dir = $base . '/jobs/' . basename($jobId);
    if (is_dir($dir)) {
        shell_exec('rm -rf ' . escapeshellarg($dir));
        $deleted = !is_dir($dir);
    }
} elseif ($type === 'preview') {
    $file = $base . '/previews/' . basename($jobId);
    if (is_file($file) && str_ends_with($jobId, '.mp4')) {
        $deleted = @unlink($file);
    }
} elseif ($type === 'video') {
    $file = $base . '/videos/' . basename($jobId);
    if (is_file($file) && str_ends_with($jobId, '.mp4')) {
        $deleted = @unlink($file);
    }
}

echo json_encode(['ok' => $deleted, 'deleted' => $deleted]);