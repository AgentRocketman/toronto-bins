<?php
/**
 * Street View Static API thumbnail proxy
 *
 * Returns a JPEG showing the saved Street View pose for a stop.
 * Inputs (query string):
 *   pano       — Street View panorama ID (preferred when known)
 *   lat, lng   — fallback location if pano isn't available
 *   heading    — camera heading (degrees, 0=N)
 *   pitch      — camera pitch (degrees)
 *   fov        — field of view (degrees, default 90)
 *   w, h       — image size (px, default 200x140, max 640x640 per Google)
 *
 * Keeps the Google Maps API key server-side. Cached aggressively because
 * a given panorama+POV always returns the same image.
 */
require_once __DIR__ . '/config.php';

// Allow GET only
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); exit; }

$pano    = isset($_GET['pano'])    ? trim($_GET['pano'])    : '';
$lat     = isset($_GET['lat'])     ? floatval($_GET['lat']) : null;
$lng     = isset($_GET['lng'])     ? floatval($_GET['lng']) : null;
$heading = isset($_GET['heading']) ? floatval($_GET['heading']) : 0;
$pitch   = isset($_GET['pitch'])   ? floatval($_GET['pitch'])   : 0;
$fov     = isset($_GET['fov'])     ? floatval($_GET['fov'])     : 90;
$w       = max(64, min(640, intval($_GET['w'] ?? 200)));
$h       = max(64, min(640, intval($_GET['h'] ?? 140)));

if (!$pano && ($lat === null || $lng === null)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Either pano or lat+lng is required']);
    exit;
}

$params = [
    'size'    => $w . 'x' . $h,
    'heading' => $heading,
    'pitch'   => $pitch,
    'fov'     => $fov,
    'key'     => GOOGLE_MAPS_API_KEY,
];
if ($pano)               $params['pano']     = $pano;
else                     $params['location'] = $lat . ',' . $lng;

$url = 'https://maps.googleapis.com/maps/api/streetview?' . http_build_query($params);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 15,
]);
$response = curl_exec($ch);
$code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$ctype    = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'image/jpeg';
curl_close($ch);

if ($code !== 200 || !$response) {
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Street View fetch failed', 'code' => $code]);
    exit;
}

// Browser-cache for a week — same pose always returns the same image.
header('Content-Type: ' . $ctype);
header('Cache-Control: public, max-age=604800, immutable');
echo $response;
