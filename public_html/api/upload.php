<?php
// Enable error reporting for development
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    // Check if file was uploaded
    if (!isset($_FILES['file'])) {
        throw new Exception('No file provided');
    }

    $file = $_FILES['file'];
    $stopId = isset($_POST['stopId']) ? sanitize($_POST['stopId']) : 'unknown';
    $date = isset($_POST['date']) ? sanitize($_POST['date']) : date('Y-m-d');

    // Validate file
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($file['type'], $allowed_types)) {
        throw new Exception('Invalid file type. Only JPEG, PNG, GIF, and WebP allowed.');
    }

    // Check file size (10MB max)
    if ($file['size'] > 10 * 1024 * 1024) {
        throw new Exception('File too large. Maximum 10MB allowed.');
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Upload error: ' . $file['error']);
    }

    // Create bin-pics directory if it doesn't exist
    $upload_dir = __DIR__ . '/../bin-pics';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    // Generate unique filename
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = $stopId . '_' . time() . '.' . $ext;
    $filepath = $upload_dir . '/' . $filename;

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        throw new Exception('Failed to save file');
    }

    // Return success with image URL
    $imageUrl = '/bin-pics/' . $filename;
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'imageUrl' => $imageUrl,
        'message' => 'File uploaded successfully'
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

function sanitize($input) {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}
?>
