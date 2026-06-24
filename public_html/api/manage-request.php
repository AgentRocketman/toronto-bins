<?php
/**
 * GetMyBin — Manage Service Request
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
$subject = "[GetMyBin] $typeLabel Request — Booking $bookingId";
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
      <p style="margin:0;">GetMyBin · Toronto Bin Collection Service · <a href="https://agentrocketman.com" style="color:#0d9488;">agentrocketman.com</a></p>
    </div>
  </div>
</body>
</html>
HTML;

// Customer confirmation email content
$customerSubjects = [
    'pause'  => 'Your GetMyBin service has been paused',
    'cancel' => 'Your GetMyBin service cancellation is confirmed',
    'resume' => 'Your GetMyBin service is back on!',
];
$customerMessages = [
    'pause'  => "We've received your pause request for booking <strong>$bookingId</strong>. Your service will be paused after your next scheduled collection (subject to the 48-hour rule). No charges will occur while paused.<br><br>Ready to restart anytime? <a href='https://agentrocketman.com/manage' style='color:#0d9488;'>Visit your service page &rarr;</a>",
    'cancel' => "We've confirmed your cancellation for booking <strong>$bookingId</strong>. If your next collection is within 48 hours, that final service will proceed as scheduled.<br><br>We're sorry to see you go — you're always welcome back at <a href='https://agentrocketman.com' style='color:#0d9488;'>agentrocketman.com</a>.",
    'resume' => "Great news — your GetMyBin service has been reactivated for booking <strong>$bookingId</strong>. We'll pick up from your next scheduled collection day and weekly billing resumes automatically.<br><br>Welcome back! If you have any questions, reply to this email.",
];
$customerSubject = $customerSubjects[$requestType] ?? 'Your GetMyBin service request';
$customerMsg     = $customerMessages[$requestType] ?? "We've received your request for booking $bookingId and will process it within 24 hours.";

$customerHtml = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f5f5f5;margin:0;padding:20px;">
  <div style="max-width:600px;margin:0 auto;background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.1);overflow:hidden;">
    <div style="background:linear-gradient(135deg,#0a5c56 0%,#0d9488 100%);color:#fff;padding:30px 20px;text-align:center;">
      <h1 style="margin:0;font-size:24px;font-weight:700;">$customerSubject</h1>
      <p style="margin:8px 0 0;opacity:.85;font-size:14px;">GetMyBin Bin Collection Service</p>
    </div>
    <div style="padding:30px 20px;">
      <p style="font-size:16px;color:#333;margin-bottom:16px;">Hi there,</p>
      <p style="color:#555;line-height:1.7;font-size:15px;">$customerMsg</p>
      <div style="margin-top:24px;padding:16px;background:#f0fdf9;border-left:4px solid #0d9488;border-radius:4px;font-size:14px;color:#334155;">
        <strong>Booking ID:</strong> $bookingId<br>
        <strong>Request processed:</strong> $timestamp
      </div>
      <p style="margin-top:24px;color:#64748b;font-size:14px;line-height:1.6;">Questions? Reply to this email or contact us at <a href="mailto:support@getmybin.com" style="color:#0d9488;">support@getmybin.com</a></p>
    </div>
    <div style="background:#f9f9f9;padding:16px 20px;text-align:center;font-size:12px;color:#999;border-top:1px solid #eee;">
      <p style="margin:0;">&copy; 2026 GetMyBin &middot; Toronto Bin Collection Service</p>
      <p style="margin:4px 0 0;"><a href="https://agentrocketman.com/manage" style="color:#94a3b8;text-decoration:none;">Manage your service</a></p>
    </div>
  </div>
</body>
</html>
HTML;

// SMTP helper
$SMTP_HOST = 'smtp.hostinger.com';
$SMTP_PORT = 465;
$SMTP_USER = 'support@getmybin.com';
$SMTP_PASS = 'AgentEmail1!';
$FROM      = 'support@getmybin.com';
$TO        = 'support@getmybin.com';

function sendEmail($host, $port, $user, $pass, $from, $to, $toName, $subject, $html) {
    $context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
    $sock = @stream_socket_client("ssl://$host:$port", $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
    if (!$sock) return false;
    $read = function() use ($sock) { return fgets($sock, 512); };
    $send = function($cmd) use ($sock) { fwrite($sock, "$cmd\r\n"); };
    $read();
    $send("EHLO localhost");
    while (($line = $read()) && substr($line, 3, 1) === '-') {}
    $send("AUTH LOGIN"); $read();
    $send(base64_encode($user)); $read();
    $send(base64_encode($pass)); $read();
    $send("MAIL FROM: <$from>"); $read();
    $send("RCPT TO: <$to>"); $read();
    $send("DATA"); $read();
    $msg  = "From: GetMyBin <$from>\r\n";
    $msg .= "To: $toName <$to>\r\n";
    $msg .= "Subject: $subject\r\n";
    $msg .= "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n";
    $msg .= $html . "\r\n.\r\n";
    $send($msg); $read();
    $send("QUIT");
    fclose($sock);
    return true;
}

// Send internal notification to support
$sent = sendEmail($SMTP_HOST, $SMTP_PORT, $SMTP_USER, $SMTP_PASS, $FROM, 'support@getmybin.com', 'GetMyBin Support', $subject, $html);
if (!$sent) {
    echo json_encode(['success' => false, 'error' => 'Mail server unavailable']);
    exit;
}

// Send confirmation to customer (non-blocking — don't fail if this fails)
if ($email) {
    sendEmail($SMTP_HOST, $SMTP_PORT, $SMTP_USER, $SMTP_PASS, $FROM, $email, 'Valued Customer', $customerSubject, $customerHtml);
}

echo json_encode(['success' => true, 'message' => 'Request received']);
