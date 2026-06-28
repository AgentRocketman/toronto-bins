<?php
/**
 * Builder Runner status — reads the heartbeat file written by builder-runner.py
 * The heartbeat lives in the OpenClaw container (not Hostinger), so this endpoint
 * reads it via the same backup-images.php pattern... actually we need a different approach.
 *
 * Since Hostinger PHP and the OpenClaw Docker container don't share filesystems,
 * the runner POSTs its heartbeat to /api/runner-heartbeat.php which stores it in a
 * file on Hostinger. This endpoint reads that.
 */
require_once __DIR__ . '/config.php';
requireMCAuth();
header('Content-Type: application/json');

$file = __DIR__ . '/../runner-heartbeat.json';
if (!file_exists($file)) {
    echo json_encode(['online' => false, 'reason' => 'No heartbeat received yet']);
    exit;
}

$raw = @file_get_contents($file);
$data = json_decode($raw, true);
if (!$data) {
    echo json_encode(['online' => false, 'reason' => 'Heartbeat unreadable']);
    exit;
}

$lastPollTs = isset($data['last_poll']) ? strtotime($data['last_poll']) : 0;
$ageSec = time() - $lastPollTs;
$online = $ageSec < 60;  // anything older than 1 min = offline

echo json_encode([
    'online' => $online,
    'state' => $data['state'] ?? 'unknown',
    'last_poll_age_sec' => $ageSec,
    'last_poll' => $data['last_poll'] ?? null,
    'last_job' => $data['last_job'] ?? null,
    'last_error' => $data['last_error'] ?? null,
    'version' => $data['version'] ?? null
]);
