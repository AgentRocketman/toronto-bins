<?php
require_once __DIR__ . '/../config.php';
corsHeaders();
$token = '';
$auth  = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (str_starts_with($auth, 'Bearer ')) $token = substr($auth, 7);
if (!$token) $token = $_GET['token'] ?? '';
if (!$token) { http_response_code(401); echo json_encode(['valid'=>false,'error'=>'No token']); exit; }
$data = verifyJWT($token);
if (!$data) { http_response_code(401); echo json_encode(['valid'=>false,'error'=>'Invalid or expired token']); exit; }
echo json_encode(['valid'=>true,'firstName'=>$data['firstName'],'lastName'=>$data['lastName'],'email'=>$data['email']]);
