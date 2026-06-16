<?php
require_once __DIR__ . '/../config.php';
corsHeaders();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false]); exit; }

$body     = json_decode(file_get_contents('php://input'), true);
$adminKey = $body['adminKey'] ?? '';
$adminPw  = $body['adminPassword'] ?? '';

if ($adminKey !== ADMIN_KEY || $adminPw !== ADMIN_PASSWORD) {
    http_response_code(403);
    echo json_encode(['success'=>false,'error'=>'Unauthorized']); exit;
}

$firstName = trim($body['firstName'] ?? '');
$lastName  = trim($body['lastName'] ?? '');
$email     = strtolower(trim($body['email'] ?? ''));
$password  = $body['password'] ?? '';
$phone     = trim($body['phone'] ?? '');
$carMake   = trim($body['carMake'] ?? '');
$carModel  = trim($body['carModel'] ?? '');
$carColor  = trim($body['carColor'] ?? '');
$plate     = strtoupper(trim($body['plate'] ?? ''));

if (!$firstName || !$lastName || !$email || !$password) {
    echo json_encode(['success'=>false,'error'=>'First name, last name, email and password are required']); exit;
}

// Check email not already taken
$existing = airtableRequest('GET', AIRTABLE_EMPLOYEES, ['filterByFormula'=>"LOWER({Email})='".addslashes($email)."'",'maxRecords'=>1]);
if (!empty($existing['body']['records'])) {
    echo json_encode(['success'=>false,'error'=>'An employee with this email already exists']); exit;
}

$hash = password_hash($password, PASSWORD_BCRYPT);
$empId = 'EMP-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

$result = airtableRequest('POST', AIRTABLE_EMPLOYEES, ['fields'=>[
    'Employee ID'   => $empId,
    'First Name'    => $firstName,
    'Last Name'     => $lastName,
    'Email'         => $email,
    'Password Hash' => $hash,
    'Phone'         => $phone,
    'Car Make'      => $carMake,
    'Car Model'     => $carModel,
    'Car Color'     => $carColor,
    'Plate Number'  => $plate,
    'Status'        => 'Active',
    'Created At'    => date('Y-m-d'),
]]);

if ($result['code'] >= 400) {
    echo json_encode(['success'=>false,'error'=>'Failed to create employee']); exit;
}

echo json_encode(['success'=>true,'employeeId'=>$empId]);
