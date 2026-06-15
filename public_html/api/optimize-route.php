<?php
// Route optimization API
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    if (!isset($data['stops']) || !is_array($data['stops'])) {
        throw new Exception('Invalid stops data');
    }

    $stops = $data['stops'];
    if (count($stops) === 0) {
        throw new Exception('No stops provided');
    }

    // Simple optimization: sort by distance from origin (Toronto center)
    // For a real implementation, you'd use Google Maps Directions API
    $origin_lat = 43.6629;
    $origin_lng = -79.3957;

    // Calculate distance from origin for each stop
    $stops_with_distance = array_map(function($stop) use ($origin_lat, $origin_lng) {
        $lat = $stop['lat'] ?? 43.6629;
        $lng = $stop['lng'] ?? -79.3957;
        
        // Haversine distance formula (simplified)
        $distance = sqrt(
            pow($lat - $origin_lat, 2) + 
            pow($lng - $origin_lng, 2)
        );

        return array_merge($stop, ['distance' => $distance]);
    }, $stops);

    // Sort by distance (nearest first)
    usort($stops_with_distance, function($a, $b) {
        return $a['distance'] <=> $b['distance'];
    });

    // Extract optimized IDs
    $optimized_ids = array_map(function($stop) {
        return $stop['id'];
    }, $stops_with_distance);

    // Calculate total distance and estimated time
    $total_distance = 0;
    $total_time_minutes = 0;

    for ($i = 0; $i < count($stops_with_distance) - 1; $i++) {
        $current = $stops_with_distance[$i];
        $next = $stops_with_distance[$i + 1];

        $dist = sqrt(
            pow($next['lat'] - $current['lat'], 2) * 111 +
            pow($next['lng'] - $current['lng'], 2) * 85
        );

        $total_distance += $dist;
        $total_time_minutes += ($dist / 40 * 60); // Assume 40 km/h average
    }

    // Add time per stop (5 minutes per stop for service)
    $total_time_minutes += count($stops) * 5;

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'optimizedIds' => $optimized_ids,
        'distance' => round($total_distance, 2) . ' km',
        'duration' => round($total_time_minutes) . ' min',
        'message' => 'Route optimized successfully'
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
