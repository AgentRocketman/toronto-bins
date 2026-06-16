<?php
require_once __DIR__ . '/../config.php';
corsHeaders();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'error'=>'POST required']); exit; }
$body  = json_decode(file_get_contents('php://input'), true);
$email = strtolower(trim($body['email'] ?? ''));
$pass  = $body['password'] ?? '';
if (!$email || !$pass) { echo json_encode(['success'=>false,'error'=>'Email and password required']); exit; }

// Look up employee by email
$formula = "LOWER({Email})='" . addslashes($email) . "'";
$result  = airtableRequest('GET', AIRTABLE_EMPLOYEES, ['filterByFormula'=>$formula,'maxRecords'=>1]);
$records = $result['body']['records'] ?? [];
if (empty($records)) { echo json_encode(['success'=>false,'error'=>'Invalid email or password']); exit; }

$emp    = $records[0]['fields'];
$hash   = $emp['Password Hash'] ?? '';
$status = $emp['Status'] ?? 'Active';

if ($status !== 'Active') { echo json_encode(['success'=>false,'error'=>'Account is inactive. Contact your manager.']); exit; }
if (!password_verify($pass, $hash)) { echo json_encode(['success'=>false,'error'=>'Invalid email or password']); exit; }

$token = generateJWT([
    'sub'       => $records[0]['id'],
    'firstName' => $emp['First Name'] ?? '',
    'lastName'  => $emp['Last Name'] ?? '',
    'email'     => $emp['Email'] ?? $email,
    'exp'       => time() + JWT_EXPIRY,
    'iat'       => time(),
]);

echo json_encode(['success'=>true,'token'=>$token,'firstName'=>$emp['First Name'] ?? '']);
