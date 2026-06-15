<?php
/**
 * CurbIn - Send Booking Confirmation Email
 * Sends confirmation email to customer via Hostinger SMTP after successful booking
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'error'=>'POST required']); exit; }

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) { echo json_encode(['success'=>false,'error'=>'Invalid JSON']); exit; }

$toEmail      = $body['customerEmail'] ?? '';
$customerName = $body['customerName'] ?? 'Valued Customer';
$address      = $body['address'] ?? '';
$serviceType  = $body['serviceType'] ?? 'rollout';
$frequency    = $body['frequency'] ?? 'adhoc';
$amount       = $body['amount'] ?? 0; // in cents
$bookingId    = $body['bookingId'] ?? '';
$serviceDates = $body['serviceDates'] ?? '';
$phone        = $body['customerPhone'] ?? '';

if (!$toEmail) { echo json_encode(['success'=>false,'error'=>'No customer email']); exit; }

// SMTP config
$SMTP_HOST = 'smtp.hostinger.com';
$SMTP_PORT = 465;
$SMTP_USER = 'support@agentrocketman.com';
$SMTP_PASS = 'AgentEmail1!';

// Format service type
if ($serviceType === 'both') {
    $serviceLabel = 'Roll Out + Roll In';
} elseif (stripos($serviceType, 'in') !== false) {
    $serviceLabel = 'Roll In';
} else {
    $serviceLabel = 'Roll Out';
}

// Format amount
$amountDisplay = number_format($amount / 100, 2);

// Format frequency
$freqLabel = ($frequency === 'recurring') ? 'Weekly (Recurring)' : 'One-Time';

// Build email HTML — matches completion email style (GFL Green → Blue gradient)
$html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>
<body style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f5f5f5;margin:0;padding:20px;">
  <div style="max-width:600px;margin:0 auto;background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.1);overflow:hidden;">
    <div style="background:linear-gradient(135deg,#A4D233 0%,#3b82f6 100%);color:#fff;padding:30px 20px;text-align:center;">
      <h1 style="margin:0;font-size:28px;font-weight:600;">📋 Booking Confirmed!</h1>
      <p style="margin:8px 0 0;opacity:0.9;font-size:15px;">CurbIn Bin Collection Service</p>
    </div>
    <div style="padding:30px 20px;">
      <p style="font-size:16px;color:#333;margin-bottom:20px;">Hi $customerName,</p>
      <p style="color:#555;line-height:1.6;">Thank you for your order! Your CurbIn bin service has been booked successfully. Here are your details:</p>
      <div style="background:#f9f9f9;border-left:4px solid #A4D233;padding:15px;margin:20px 0;border-radius:4px;">
        <div style="margin-bottom:10px;font-size:14px;"><span style="font-weight:600;color:#555;display:inline-block;min-width:130px;">🆔 Booking ID:</span><span style="color:#333;">$bookingId</span></div>
        <div style="margin-bottom:10px;font-size:14px;"><span style="font-weight:600;color:#555;display:inline-block;min-width:130px;">📍 Address:</span><span style="color:#333;">$address</span></div>
        <div style="margin-bottom:10px;font-size:14px;"><span style="font-weight:600;color:#555;display:inline-block;min-width:130px;">🔄 Service:</span><span style="color:#333;">$serviceLabel</span></div>
        <div style="margin-bottom:10px;font-size:14px;"><span style="font-weight:600;color:#555;display:inline-block;min-width:130px;">📅 Schedule:</span><span style="color:#333;">$freqLabel</span></div>
        <div style="margin-bottom:10px;font-size:14px;"><span style="font-weight:600;color:#555;display:inline-block;min-width:130px;">💰 Amount Paid:</span><span style="color:#333;">\$$amountDisplay CAD</span></div>
HTML;

if ($serviceDates) {
    $html .= "        <div style=\"font-size:14px;\"><span style=\"font-weight:600;color:#555;display:inline-block;min-width:130px;\">🗓️ Service Dates:</span><span style=\"color:#333;\">$serviceDates</span></div>\n";
}

$html .= <<<HTML
      </div>
      <div style="background:#f0fdf4;border-radius:8px;padding:16px;margin:20px 0;text-align:center;">
        <p style="color:#065f46;font-weight:600;margin:0;font-size:15px;">✅ You're all set!</p>
        <p style="color:#059669;margin:6px 0 0;font-size:13px;">We'll handle your bins on collection day. No action needed from you.</p>
      </div>
      <p style="color:#555;line-height:1.6;">If you have any questions, just reply to this email or contact us at <a href="mailto:support@agentrocketman.com" style="color:#3b82f6;">support@agentrocketman.com</a></p>
    </div>
    <div style="background:#f9f9f9;padding:20px;text-align:center;font-size:12px;color:#999;border-top:1px solid #eee;">
      <p style="margin:0;">© 2026 CurbIn · Toronto Bin Collection Service</p>
      <p style="margin:4px 0 0;"><a href="https://agentrocketman.com" style="color:#A4D233;text-decoration:none;">agentrocketman.com</a></p>
    </div>
  </div>
</body>
</html>
HTML;

// Raw SMTP send (same as send-email.php)
function smtpSend($host, $port, $user, $pass, $from, $to, $subject, $html) {
    $context = stream_context_create(['ssl' => [
        'verify_peer'       => false,
        'verify_peer_name'  => false,
        'allow_self_signed' => true,
    ]]);
    $sock = stream_socket_client("ssl://$host:$port", $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
    if (!$sock) return ["ok" => false, "error" => "Connect failed: $errstr ($errno)"];

    $read = function() use ($sock) { return fgets($sock, 512); };
    $send = function($cmd) use ($sock) { fwrite($sock, "$cmd\r\n"); };

    $read();
    $send("EHLO localhost");
    while (($line = $read()) && substr($line, 3, 1) === '-') {}

    $send("AUTH LOGIN");
    $read();
    $send(base64_encode($user));
    $read();
    $send(base64_encode($pass));
    $r = $read();
    if (strpos($r, '235') === false) {
        fclose($sock);
        return ["ok" => false, "error" => "Auth failed: $r"];
    }

    $send("MAIL FROM:<$from>");  $read();
    $send("RCPT TO:<$to>");      $read();
    $send("DATA");               $read();

    $msg  = "From: CurbIn <$from>\r\n";
    $msg .= "To: $to\r\n";
    $msg .= "Subject: $subject\r\n";
    $msg .= "MIME-Version: 1.0\r\n";
    $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
    $msg .= "\r\n";
    $msg .= $html . "\r\n";
    $msg .= ".\r\n";

    fwrite($sock, $msg);
    $r = $read();
    $send("QUIT");
    fclose($sock);
    return ["ok" => strpos($r, '250') !== false, "response" => trim($r)];
}

$result = smtpSend(
    $SMTP_HOST, $SMTP_PORT, $SMTP_USER, $SMTP_PASS,
    $SMTP_USER,
    $toEmail,
    "📋 Booking Confirmed — CurbIn #$bookingId",
    $html
);

if ($result['ok']) {
    echo json_encode(['success' => true, 'message' => "Confirmation sent to $toEmail"]);
} else {
    echo json_encode(['success' => false, 'error' => $result['error'] ?? $result['response']]);
}
