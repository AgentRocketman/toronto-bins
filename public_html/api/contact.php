<?php
/**
 * CurbIn — Contact Form
 * Forwards contact form submissions to support@agentrocketman.com
 */
require_once __DIR__ . '/config.php';
corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST required']); exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']); exit;
}

$name    = htmlspecialchars(trim($body['name'] ?? ''));
$email   = trim($body['email'] ?? '');
$subject = htmlspecialchars(trim($body['subject'] ?? ''));
$message = htmlspecialchars(trim($body['message'] ?? ''));

if (!$name || !$email || !$subject || !$message) {
    echo json_encode(['success' => false, 'error' => 'All fields are required']); exit;
}

$timestamp = date('Y-m-d H:i:s T');

$html = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f5f5f5;margin:0;padding:20px;">
  <div style="max-width:600px;margin:0 auto;background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.1);overflow:hidden;">
    <div style="background:linear-gradient(135deg,#0a5c56 0%,#0d9488 100%);color:#fff;padding:24px 20px;">
      <h1 style="margin:0;font-size:20px;">📬 New Contact Form Submission</h1>
      <p style="margin:6px 0 0;opacity:.85;font-size:13px;">Received: $timestamp</p>
    </div>
    <div style="padding:24px 20px;font-size:14px;color:#334155;">
      <table style="width:100%;border-collapse:collapse;">
        <tr><td style="color:#64748b;width:120px;padding:10px 0;font-weight:600;vertical-align:top;">Name</td><td style="padding:10px 0;font-weight:700;font-size:15px;">$name</td></tr>
        <tr style="border-top:1px solid #f1f5f9;"><td style="color:#64748b;padding:10px 0;font-weight:600;">Email</td><td style="padding:10px 0;"><a href="mailto:$email" style="color:#0d9488;">$email</a></td></tr>
        <tr style="border-top:1px solid #f1f5f9;"><td style="color:#64748b;padding:10px 0;font-weight:600;">Subject</td><td style="padding:10px 0;">$subject</td></tr>
        <tr style="border-top:1px solid #f1f5f9;"><td style="color:#64748b;padding:10px 0;font-weight:600;vertical-align:top;">Message</td><td style="padding:10px 0;line-height:1.7;">$message</td></tr>
      </table>
      <div style="margin-top:20px;padding:14px;background:#f0fdf9;border-left:4px solid #0d9488;border-radius:4px;font-size:13px;color:#0f766e;">
        Reply directly to this email to respond to $name.
      </div>
    </div>
  </div>
</body></html>
HTML;

$sent = sendSmtpEmail(
    SUPPORT_EMAIL,
    'CurbIn Support',
    "📬 Contact: $subject — from $name",
    $html
);

if (!$sent) {
    echo json_encode(['success' => false, 'error' => 'Mail server unavailable']); exit;
}

echo json_encode(['success' => true]);
