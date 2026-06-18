<?php
/**
 * GET /api/maps-key.php
 * 
 * Returns Google Maps API key for client-side use
 * Key is stored server-side and served on-demand
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

http_response_code(200);
echo json_encode(['key' => GOOGLE_MAPS_API_KEY]);

?>
