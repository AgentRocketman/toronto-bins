<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

$JWT_SECRET = 'getmybin-jwt-secret-key-2026';
$ADMIN_EMAIL = 'support@getmybin.com';

// Get Authorization header
$authHeader = getallheaders()['Authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? null;

if (!$authHeader || strpos($authHeader, 'Bearer ') !== 0) {
  http_response_code(401);
  echo json_encode(['valid' => false, 'error' => 'No token provided']);
  exit;
}

$token = substr($authHeader, 7);
$parts = explode('.', $token);

if (count($parts) !== 3) {
  http_response_code(401);
  echo json_encode(['valid' => false, 'error' => 'Invalid token format']);
  exit;
}

list($header, $payload, $signature) = $parts;

// Verify signature
$expectedSignature = base64_encode(hash_hmac('sha256', "$header.$payload", $JWT_SECRET, true));

// Compare signatures (basic comparison)
if ($signature !== $expectedSignature) {
  http_response_code(401);
  echo json_encode(['valid' => false, 'error' => 'Invalid signature']);
  exit;
}

// Decode payload
$decodedPayload = json_decode(base64_decode($payload), true);

if (!$decodedPayload) {
  http_response_code(401);
  echo json_encode(['valid' => false, 'error' => 'Invalid payload']);
  exit;
}

// Check expiration
if ($decodedPayload['exp'] < time()) {
  http_response_code(401);
  echo json_encode(['valid' => false, 'error' => 'Token expired']);
  exit;
}

// Verify email matches
$input = json_decode(file_get_contents('php://input'), true);
$requestEmail = $input['email'] ?? null;

if ($requestEmail !== $ADMIN_EMAIL || $decodedPayload['email'] !== $ADMIN_EMAIL) {
  http_response_code(401);
  echo json_encode(['valid' => false, 'error' => 'Email mismatch']);
  exit;
}

http_response_code(200);
echo json_encode(['valid' => true, 'email' => $decodedPayload['email']]);
?>
