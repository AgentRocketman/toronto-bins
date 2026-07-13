<?php
/**
 * Agentado Upload API
 * Handles photo uploads for Shotstack rendering (when API key is configured)
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/config.php';

$response = ['success' => false];

try {
    // Check if Shotstack is configured
    if (empty(SHOTSTACK_API_KEY)) {
        throw new Exception('Shotstack not configured. Client-side rendering is used instead.');
    }

    if (empty($_FILES['photos'])) {
        throw new Exception('No photos uploaded');
    }

    $files = $_FILES['photos'];
    $uploaded = [];
    $uploadDir = __DIR__ . '/../uploads/' . uniqid('job_') . '/';
    mkdir($uploadDir, 0755, true);

    for ($i = 0; $i < count($files['name']); $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
        if (!in_array($files['type'][$i], ALLOWED_TYPES)) continue;
        if ($files['size'][$i] > MAX_FILE_SIZE) continue;

        $ext = pathinfo($files['name'][$i], PATHINFO_EXTENSION);
        $dest = $uploadDir . sprintf('photo_%02d.%s', $i, $ext);
        move_uploaded_file($files['tmp_name'][$i], $dest);
        $uploaded[] = $dest;
    }

    if (count($uploaded) < MIN_PHOTOS) {
        throw new Exception('Need at least ' . MIN_PHOTOS . ' valid photos');
    }

    $jobId = basename(dirname($uploadDir));
    $response = [
        'success' => true,
        'job_id' => $jobId,
        'photo_count' => count($uploaded)
    ];

} catch (Exception $e) {
    $response['error'] = $e->getMessage();
    http_response_code(400);
}

echo json_encode($response);