<?php
require_once __DIR__ . '/../config.php';
corsHeaders();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false]); exit; }
$body  = json_decode(file_get_contents('php://input'), true);
$email = strtolower(trim($body['email'] ?? ''));
if (!$email) { echo json_encode(['success'=>false,'error'=>'Email required']); exit; }

$formula = "LOWER({Email})='" . addslashes($email) . "'";
$result  = airtableRequest('GET', AIRTABLE_EMPLOYEES, ['filterByFormula'=>$formula,'maxRecords'=>1]);
$records = $result['body']['records'] ?? [];

// Always return success to prevent email enumeration
if (empty($records)) { echo json_encode(['success'=>true]); exit; }

$recordId  = $records[0]['id'];
$firstName = $records[0]['fields']['First Name'] ?? 'there';
$token     = bin2hex(random_bytes(32));
$expires   = date('Y-m-d H:i:s', strtotime('+1 hour'));

// Store reset token in Airtable
airtableRequest('PATCH', AIRTABLE_EMPLOYEES, ['fields'=>['Reset Token'=>$token,'Reset Expires'=>$expires]], $recordId);

$resetLink = 'https://agentrocketman.com/reset-password.html?token=' . $token;
$html = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="font-family:-apple-system,sans-serif;background:#f5f5f5;padding:20px;">
  <div style="max-width:560px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)">
    <div style="background:linear-gradient(135deg,#0a5c56,#0d9488);color:#fff;padding:28px 24px;text-align:center">
      <h1 style="margin:0;font-size:22px">Password Reset</h1>
      <p style="margin:6px 0 0;opacity:.85;font-size:14px">CurbIn Employee Portal</p>
    </div>
    <div style="padding:28px 24px">
      <p style="color:#333;font-size:15px">Hi $firstName,</p>
      <p style="color:#555;line-height:1.7">We received a request to reset your CurbIn employee password. Click the button below to set a new password. This link expires in <strong>1 hour</strong>.</p>
      <div style="text-align:center;margin:28px 0">
        <a href="$resetLink" style="background:#0d9488;color:#fff;padding:14px 32px;border-radius:10px;text-decoration:none;font-weight:700;font-size:15px;display:inline-block">Reset My Password</a>
      </div>
      <p style="color:#94a3b8;font-size:13px">If you didn't request this, you can safely ignore this email. Your password won't change.</p>
    </div>
  </div>
</body></html>
HTML;

sendSmtpEmail($email, $firstName, 'Reset your CurbIn password', $html);
echo json_encode(['success'=>true]);
