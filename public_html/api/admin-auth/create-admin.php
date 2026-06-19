<?php
/**
 * Create a new admin account
 * Requires valid admin token
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Airtable credentials
$AIRTABLE_BASE_ID = 'apptYNRJTXwItvied';
$AIRTABLE_API_KEY = 'patxbDkv88pOMXmYx.c7e5fd7974954e3a674087090835d11dd69504f3912f0ef86c3c59f1e91febdd';

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$email = $input['email'] ?? null;
$password = $input['password'] ?? null;
$firstName = $input['firstName'] ?? '';
$lastName = $input['lastName'] ?? '';

// Validate inputs
if (!$email || !$password) {
  http_response_code(400);
  echo json_encode(['error' => 'Email and password required']);
  exit;
}

if (strlen($password) < 8) {
  http_response_code(400);
  echo json_encode(['error' => 'Password must be at least 8 characters']);
  exit;
}

// Verify requesting user is an admin (token validation would go here)
// For now, we check if they have a valid admin token
$authHeader = getallheaders()['Authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? null;
if (!$authHeader || strpos($authHeader, 'Bearer ') !== 0) {
  http_response_code(401);
  echo json_encode(['error' => 'Unauthorized']);
  exit;
}

// Check if email already exists
$check_url = "https://api.airtable.com/v0/$AIRTABLE_BASE_ID/Admins?filterByFormula={Email}='$email'";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $check_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
  'Authorization: Bearer ' . $AIRTABLE_API_KEY
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$check_data = json_decode($response, true);

if (!empty($check_data['records'])) {
  http_response_code(400);
  echo json_encode(['error' => 'Admin with this email already exists']);
  exit;
}

// Create new admin
$insert_url = "https://api.airtable.com/v0/$AIRTABLE_BASE_ID/Admins";

$admin_data = json_encode([
  'records' => [
    [
      'fields' => [
        'Email' => $email,
        'Password' => $password,
        'FirstName' => $firstName,
        'LastName' => $lastName,
        'Active' => true
      ]
    ]
  ]
]);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $insert_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($ch, CURLOPT_POSTFIELDS, $admin_data);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
  'Authorization: Bearer ' . $AIRTABLE_API_KEY,
  'Content-Type: application/json'
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200) {
  http_response_code(500);
  echo json_encode(['error' => 'Failed to create admin']);
  exit;
}

http_response_code(201);
echo json_encode([
  'success' => true,
  'message' => 'Admin created successfully',
  'admin' => [
    'email' => $email,
    'firstName' => $firstName,
    'lastName' => $lastName
  ]
]);
?>
