<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Airtable credentials
$AIRTABLE_BASE_ID = 'apptYNRJTXwItvied';
$AIRTABLE_API_KEY = 'patxbDkv88pOMXmYx.c7e5fd7974954e3a674087090835d11dd69504f3912f0ef86c3c59f1e91febdd';
$AIRTABLE_TABLE_NAME = 'Admins';

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$email = $input['email'] ?? null;
$currentPassword = $input['currentPassword'] ?? null;
$newPassword = $input['newPassword'] ?? null;

// Validate inputs
if (!$email || !$currentPassword || !$newPassword) {
  http_response_code(400);
  echo json_encode(['error' => 'All fields required']);
  exit;
}

// Validate new password strength
if (strlen($newPassword) < 8) {
  http_response_code(400);
  echo json_encode(['error' => 'Password must be at least 8 characters']);
  exit;
}

if ($newPassword === $currentPassword) {
  http_response_code(400);
  echo json_encode(['error' => 'New password must be different from current password']);
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
  echo json_encode(['error' => 'Admin not found']);
  exit;
}

$admin_record = $data['records'][0];
$admin = $admin_record['fields'];
$record_id = $admin_record['id'];

// Verify current password
if (!isset($admin['Password']) || $admin['Password'] !== $currentPassword) {
  http_response_code(401);
  echo json_encode(['error' => 'Current password is incorrect']);
  exit;
}

// Update password in Airtable
$update_url = "https://api.airtable.com/v0/$AIRTABLE_BASE_ID/$AIRTABLE_TABLE_NAME/$record_id";
$update_data = json_encode([
  'fields' => [
    'Password' => $newPassword
  ]
]);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $update_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
curl_setopt($ch, CURLOPT_POSTFIELDS, $update_data);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
  'Authorization: Bearer ' . $AIRTABLE_API_KEY,
  'Content-Type: application/json'
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200) {
  http_response_code(500);
  echo json_encode(['error' => 'Failed to update password']);
  exit;
}

http_response_code(200);
echo json_encode([
  'success' => true,
  'message' => 'Password changed successfully'
]);
?>
