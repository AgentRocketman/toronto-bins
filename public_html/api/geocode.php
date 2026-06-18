<?php
/**
 * Server-side geocoder proxy.
 *
 *   GET /api/geocode.php?address=7+Terryellen+Cres,+Toronto
 *   → { "lat": 43.628, "lng": -79.561, "formatted": "7 Terryellen Cres, Etobicoke, ON M9R 1B1, Canada" }
 *
 * Wraps the Google Maps Geocoding API so the key stays server-side, and biases
 * results to the Toronto area when the address doesn't include a city. Result
 * cached at the edge for a year (a given postal address doesn't move).
 */
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); exit; }

$address = trim($_GET['address'] ?? '');
if ($address === '') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'address is required']);
    exit;
}

// Bias toward Toronto when the address doesn't already include a city/region.
if (!preg_match('/\b(toronto|ontario|on|canada|gta)\b/i', $address)) {
    $address .= ', Toronto, ON, Canada';
}

$params = [
    'address' => $address,
    'region'  => 'ca',
    'key'     => GOOGLE_MAPS_API_KEY,
];
$url = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query($params);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

header('Content-Type: application/json');

if ($code !== 200 || !$resp) {
    http_response_code(502);
    echo json_encode(['error' => 'geocode upstream failed', 'code' => $code]);
    exit;
}

$data = json_decode($resp, true);
$status = $data['status'] ?? 'UNKNOWN';

if ($status !== 'OK' || empty($data['results'])) {
    echo json_encode(['error' => 'no results', 'status' => $status]);
    exit;
}

$first = $data['results'][0];
$loc   = $first['geometry']['location'] ?? null;

if (!$loc) {
    echo json_encode(['error' => 'no geometry in result']);
    exit;
}

// Cache for 1 year — addresses don't move.
header('Cache-Control: public, max-age=31536000, immutable');
echo json_encode([
    'lat'       => (float)$loc['lat'],
    'lng'       => (float)$loc['lng'],
    'formatted' => $first['formatted_address'] ?? null,
]);
