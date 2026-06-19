<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Airtable credentials
$AIRTABLE_BASE_ID = 'apptYNRJTXwItvied';
$AIRTABLE_API_KEY = 'patxbDkv88pOMXmYx.c7e5fd7974954e3a674087090835d11dd69504f3912f0ef86c3c59f1e91febdd';
$AIRTABLE_TABLE_NAME = 'Admins';
$JWT_SECRET = 'getmybin-jwt-secret-key-2026';

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$email = $input['email'] ?? null;
$password = $input['password'] ?? null;

if (!$email || !$password) {
  http_response_code(400);
  echo json_encode(['error' => 'Email and password required']);
  exit;
}

// Query Airtable for admin by email
$airtable_url = "https://api.airtable.com/v0/$AIRTABLE_BASE_ID/$AIRTABLE_TABLE_NAME?filterByFormula={Email}='$email'";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $airtable_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
  'Authorization: Bearer ' . $AIRTABLE_API_KEY
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200) {
  http_response_code(401);
  echo json_encode(['error' => 'Authentication service error']);
  exit;
}

$data = json_decode($response, true);

if (empty($data['records'])) {
  http_response_code(401);
  echo json_encode(['error' => 'Invalid email or password']);
  exit;
}

$admin = $data['records'][0]['fields'];

// Verify password
if (!isset($admin['Password']) || $admin['Password'] !== $password) {
  http_response_code(401);
  echo json_encode(['error' => 'Invalid email or password']);
  exit;
}

// Verify admin is active
if (isset($admin['Active']) && $admin['Active'] === false) {
  http_response_code(401);
  echo json_encode(['error' => 'Admin account is inactive']);
  exit;
}

// Generate JWT token
$header = base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
$payload = base64_encode(json_encode([
  'email' => $email,
  'iat' => time(),
  'exp' => time() + 86400 * 7 // 7 days
]));

$signature = base64_encode(hash_hmac('sha256', "$header.$payload", $JWT_SECRET, true));
$token = "$header.$payload.$signature";

http_response_code(200);
echo json_encode([
  'success' => true,
  'token' => $token,
  'email' => $email,
  'firstName' => $admin['FirstName'] ?? 'Admin'
]);
?>
