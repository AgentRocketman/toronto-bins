<?php
/**
 * CurbIn — Manage Service Request
 * Receives pause/cancel/resume requests and notifies support
 */
require_once __DIR__ . '/config.php';
corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

$bookingId   = trim($body['bookingId'] ?? '');
$email       = trim($body['email'] ?? '');
$requestType = $body['requestType'] ?? ''; // pause | cancel | resume
$reason      = $body['reason'] ?? '';
$message     = trim($body['message'] ?? '');
$timestamp   = date('Y-m-d H:i:s T');

if (!$bookingId || !$email || !$requestType) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

$typeLabel = ['pause' => 'Pause Service', 'cancel' => 'Cancel Service', 'resume' => 'Resume Service'][$requestType] ?? $requestType;

$reasonLabels = [
    'travelling'  => 'Travelling / away temporarily',
    'expensive'   => 'Too expensive',
    'unhappy'     => 'Not happy with the service',
    'not-needed'  => 'No longer need the service',
    'other'       => 'Other',
    ''            => 'Not specified',
];
$reasonLabel = $reasonLabels[$reason] ?? $reason;

// Email to support
$subject = "[CurbIn] $typeLabel Request — Booking $bookingId";
$html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f5f5f5;margin:0;padding:20px;">
  <div style="max-width:600px;margin:0 auto;background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.1);overflow:hidden;">
    <div style="background:linear-gradient(135deg,#0a5c56 0%,#0d9488 100%);color:#fff;padding:24px 20px;">
      <h1 style="margin:0;font-size:22px;">⚙️ Service Request — $typeLabel</h1>
      <p style="margin:6px 0 0;opacity:.85;font-size:14px;">Received: $timestamp</p>
    </div>
    <div style="padding:24px 20px;">
      <table style="width:100%;border-collapse:collapse;font-size:15px;">
        <tr><td style="padding:10px 0;color:#64748b;width:160px;font-weight:600;">Booking ID</td><td style="padding:10px 0;color:#0f172a;font-weight:700;font-size:17px;">$bookingId</td></tr>
        <tr style="border-top:1px solid #f1f5f9;"><td style="padding:10px 0;color:#64748b;font-weight:600;">Customer Email</td><td style="padding:10px 0;color:#0f172a;">$email</td></tr>
        <tr style="border-top:1px solid #f1f5f9;"><td style="padding:10px 0;color:#64748b;font-weight:600;">Request Type</td><td style="padding:10px 0;"><span style="background:#0d9488;color:#fff;padding:4px 12px;border-radius:99px;font-size:13px;font-weight:700;">$typeLabel</span></td></tr>
        <tr style="border-top:1px solid #f1f5f9;"><td style="padding:10px 0;color:#64748b;font-weight:600;">Reason</td><td style="padding:10px 0;color:#0f172a;">$reasonLabel</td></tr>
HTML;

if ($message) {
    $safeMsg = htmlspecialchars($message);
    $html .= "<tr style='border-top:1px solid #f1f5f9;'><td style='padding:10px 0;color:#64748b;font-weight:600;vertical-align:top;'>Message</td><td style='padding:10px 0;color:#0f172a;'>$safeMsg</td></tr>";
}

$html .= <<<HTML
      </table>
      <div style="margin-top:24px;padding:16px;background:#fef3c7;border-left:4px solid #f59e0b;border-radius:4px;font-size:14px;color:#92400e;">
        <strong>Action required:</strong> Process this request in Stripe and update Airtable within 24 hours. Remember the 48-hour cutoff rule — if next collection is within 48hrs, that collection still proceeds.
      </div>
    </div>
    <div style="background:#f9f9f9;padding:16px 20px;text-align:center;font-size:12px;color:#999;border-top:1px solid #eee;">
      <p style="margin:0;">CurbIn · Toronto Bin Collection Service · <a href="https://agentrocketman.com" style="color:#0d9488;">agentrocketman.com</a></p>
    </div>
  </div>
</body>
</html>
HTML;

// Send via SMTP (same as confirmation email)
$SMTP_HOST = 'smtp.hostinger.com';
$SMTP_PORT = 465;
$SMTP_USER = 'support@agentrocketman.com';
$SMTP_PASS = 'AgentEmail1!';
$FROM      = 'support@agentrocketman.com';
$TO        = 'support@agentrocketman.com';

$boundary = md5(uniqid());
$headers  = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";

$context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
$sock = @stream_socket_client("ssl://$SMTP_HOST:$SMTP_PORT", $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);

if (!$sock) {
    echo json_encode(['success' => false, 'error' => 'Mail server unavailable']);
    exit;
}

$read = function() use ($sock) { return fgets($sock, 512); };
$send = function($cmd) use ($sock) { fwrite($sock, "$cmd\r\n"); };

$read();
$send("EHLO localhost");
while (($line = $read()) && substr($line, 3, 1) === '-') {}
$send("AUTH LOGIN");
$read();
$send(base64_encode($SMTP_USER));
$read();
$send(base64_encode($SMTP_PASS));
$read();
$send("MAIL FROM: <$FROM>");
$read();
$send("RCPT TO: <$TO>");
$read();
$send("DATA");
$read();

$msg  = "From: CurbIn <$FROM>\r\n";
$msg .= "To: CurbIn Support <$TO>\r\n";
$msg .= "Subject: $subject\r\n";
$msg .= "MIME-Version: 1.0\r\n";
$msg .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
$msg .= $html . "\r\n.\r\n";
$send($msg);
$read();
$send("QUIT");
fclose($sock);

echo json_encode(['success' => true, 'message' => 'Request received']);
