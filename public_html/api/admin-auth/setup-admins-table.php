<?php
/**
 * SETUP SCRIPT: Creates the Admins table in Airtable and adds the initial admin
 * Run this ONCE to initialize the admin system
 * 
 * Access: https://agentrocketman.com/api/admin-auth/setup-admins-table.php?setupKey=getmybin-setup-2026
 */

header('Content-Type: application/json');

// Security: require setup key
$setupKey = $_GET['setupKey'] ?? null;
if ($setupKey !== 'getmybin-setup-2026') {
  http_response_code(403);
  echo json_encode(['error' => 'Setup key required']);
  exit;
}

// Airtable credentials
$AIRTABLE_BASE_ID = 'apptYNRJTXwItvied';
$AIRTABLE_API_KEY = 'patxbDkv88pOMXmYx.c7e5fd7974954e3a674087090835d11dd69504f3912f0ef86c3c59f1e91febdd';

// Step 1: Test API connection
error_log("Step 1: Testing Airtable connection...");

$test_url = "https://api.airtable.com/v0/$AIRTABLE_BASE_ID/Bookings?maxRecords=1";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $test_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
  'Authorization: Bearer ' . $AIRTABLE_API_KEY
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200) {
  http_response_code(401);
  echo json_encode([
    'error' => 'Airtable API authentication failed',
    'message' => 'Check your Airtable base ID and API key'
  ]);
  exit;
}

error_log("✓ Airtable connection OK");

// Step 2: Create Admins table with proper field definitions
error_log("Step 2: Creating Admins table...");

$create_table_url = "https://api.airtable.com/v0/meta/bases/$AIRTABLE_BASE_ID/tables";

$table_data = json_encode([
  'name' => 'Admins',
  'fields' => [
    [
      'name' => 'Email',
      'type' => 'email'
    ],
    [
      'name' => 'Password',
      'type' => 'singleLineText'
    ],
    [
      'name' => 'FirstName',
      'type' => 'singleLineText'
    ],
    [
      'name' => 'LastName',
      'type' => 'singleLineText'
    ],
    [
      'name' => 'Active',
      'type' => 'checkbox',
      'options' => [
        'icon' => 'check',
        'color' => 'greenBright'
      ]
    ]
  ]
]);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $create_table_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($ch, CURLOPT_POSTFIELDS, $table_data);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
  'Authorization: Bearer ' . $AIRTABLE_API_KEY,
  'Content-Type: application/json'
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$response_data = json_decode($response, true);

if ($http_code !== 201) {
  http_response_code($http_code);
  echo json_encode([
    'error' => 'Failed to create table',
    'http_code' => $http_code,
    'details' => $response_data
  ]);
  exit;
}

error_log("✓ Table created successfully");

// Step 3: Add initial admin
error_log("Step 3: Adding initial admin...");

$insert_url = "https://api.airtable.com/v0/$AIRTABLE_BASE_ID/Admins";

$admin_data = json_encode([
  'records' => [
    [
      'fields' => [
        'Email' => 'support@getmybin.com',
        'Password' => 'GetMyBinAdmin2026!',
        'FirstName' => 'Support',
        'LastName' => 'Admin',
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

$response_data = json_decode($response, true);

if ($http_code !== 200) {
  http_response_code($http_code);
  echo json_encode([
    'error' => 'Failed to add admin record',
    'http_code' => $http_code,
    'details' => $response_data
  ]);
  exit;
}

error_log("✓ Admin added successfully");

// Success
http_response_code(200);
echo json_encode([
  'success' => true,
  'message' => 'Setup complete! Admins table created and initial admin added.',
  'credentials' => [
    'email' => 'support@getmybin.com',
    'password' => 'GetMyBinAdmin2026!'
  ],
  'next_steps' => [
    '1. Login at https://agentrocketman.com/admin-login.html',
    '2. Manage admins in Airtable "Admins" table',
    '3. Delete this setup script for security: rm /api/admin-auth/setup-admins-table.php'
  ]
]);
?>
