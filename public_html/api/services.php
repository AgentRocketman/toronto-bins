<?php
// Get services from Airtable
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    $airtable_token = 'patxbDkv88pOMXmYx.c7e5fd7974954e3a674087090835d11dd69504f3912f0ef86c3c59f1e91febdd';
    $airtable_base = 'apptYNRJTXwItvied'; // "Curbin" base
    $airtable_table = 'ServiceStops';

    $url = "https://api.airtable.com/v0/$airtable_base/$airtable_table";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $airtable_token
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        throw new Exception('cURL error: ' . $curl_error);
    }

    $response_data = json_decode($response, true);

    if ($http_code !== 200) {
        $error_msg = isset($response_data['error']) ? $response_data['error'] : 'Unknown error';
        throw new Exception('Airtable API error: ' . $error_msg);
    }

    // Format response
    $services = [];
    if (isset($response_data['records'])) {
        foreach ($response_data['records'] as $record) {
            $services[] = [
                'id' => $record['id'],
                'fields' => $record['fields']
            ];
        }
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'services' => $services,
        'count' => count($services)
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
