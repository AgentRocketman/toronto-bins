<?php
require_once __DIR__ . '/../config.php';
corsHeaders();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false]); exit; }
$body     = json_decode(file_get_contents('php://input'), true);
$token    = trim($body['token'] ?? '');
$password = $body['password'] ?? '';
if (!$token || strlen($password) < 8) { echo json_encode(['success'=>false,'error'=>'Invalid request']); exit; }

$formula = "{Reset Token}='" . addslashes($token) . "'";
$result  = airtableRequest('GET', AIRTABLE_EMPLOYEES, ['filterByFormula'=>$formula,'maxRecords'=>1]);
$records = $result['body']['records'] ?? [];
if (empty($records)) { echo json_encode(['success'=>false,'error'=>'Invalid or expired reset link']); exit; }

$record  = $records[0];
$expires = $record['fields']['Reset Expires'] ?? '';
if (!$expires || strtotime($expires) < time()) {
    echo json_encode(['success'=>false,'error'=>'This reset link has expired. Please request a new one.']); exit;
}

$hash = password_hash($password, PASSWORD_BCRYPT);
airtableRequest('PATCH', AIRTABLE_EMPLOYEES, ['fields'=>['Password Hash'=>$hash,'Reset Token'=>'','Reset Expires'=>'']], $record['id']);
echo json_encode(['success'=>true]);
