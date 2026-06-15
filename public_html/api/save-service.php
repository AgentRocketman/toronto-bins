<?php
// Enable error reporting
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
    // Get JSON payload
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    if (!$data) {
        throw new Exception('Invalid JSON payload');
    }

    // Validate required fields
    $required = ['id', 'address', 'type', 'date'];
    foreach ($required as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }

    // Airtable configuration
    $airtable_token = 'patxbDkv88pOMXmYx.c7e5fd7974954e3a674087090835d11dd69504f3912f0ef86c3c59f1e91febdd';
    $airtable_base = 'apptYNRJTXwItvied'; // "Curbin" base
    $airtable_table = 'ServiceStops';

    // Prepare Airtable record fields
    $fields = [
        'Stop ID' => $data['id'],
        'Address' => $data['address'],
        'Service Type' => ($data['type'] === 'rollout') ? 'Roll Out' : 'Roll In',
        'Date' => $data['date'],
        'Completed' => (bool)($data['completed'] ?? false),
        'Image URL' => $data['imageUrl'] ?? '',
        'Worker Name' => $data['workerName'] ?? 'Driver'
    ];
    
    // Add completion date/time if provided and completed
    if (!empty($data['completedDateTime']) && $data['completed']) {
        $fields['Completed Date'] = $data['completedDateTime'];
        error_log('📅 Saving completion date: ' . $data['completedDateTime']);
    }
    
    $record = ['fields' => $fields];

    // Create Airtable record via API
    $url = "https://api.airtable.com/v0/$airtable_base/$airtable_table";
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $airtable_token,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($record));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    // Handle errors
    if ($curl_error) {
        throw new Exception('cURL error: ' . $curl_error);
    }

    $response_data = json_decode($response, true);

    if ($http_code !== 200) {
        $error_msg = isset($response_data['error']['message']) 
            ? $response_data['error']['message'] 
            : 'Airtable API error (HTTP ' . $http_code . ')';
        throw new Exception($error_msg);
    }

    // Return success
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'recordId' => $response_data['id'] ?? 'unknown',
        'message' => 'Service record saved to Airtable'
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
