<?php
/**
 * GetMyBin - Send Booking Confirmation Email
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
$subtotal     = $body['subtotal'] ?? null;
$hstAmount    = $body['hstAmount'] ?? null;
$totalWithTax = $body['totalWithTax'] ?? null;
$bookingId    = $body['bookingId'] ?? '';
$scheduleLines = $body['scheduleLines'] ?? [];
$phone         = $body['customerPhone'] ?? '';
$isNightZone   = $body['isNightZone'] ?? false;

if (!$toEmail) { echo json_encode(['success'=>false,'error'=>'No customer email']); exit; }

// SMTP config
$SMTP_HOST = 'smtp.hostinger.com';
$SMTP_PORT = 465;
$SMTP_USER = 'support@getmybin.com';
$SMTP_PASS = 'd133-xzus-dhae-h2au';

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

// Build tax breakdown if available
$taxBreakdownHtml = '';
if ($subtotal !== null && $hstAmount !== null && $totalWithTax !== null) {
    $subtotalDisplay = number_format($subtotal, 2);
    $hstDisplay = number_format($hstAmount, 2);
    $totalDisplay = number_format($totalWithTax, 2);
    $taxBreakdownHtml = '<div style="background:#f0fdf9;border-left:4px solid #14b8a6;padding:15px;margin:15px 0;border-radius:4px;font-size:14px;">' .
        '<div style="display:flex;justify-content:space-between;margin-bottom:8px;">' .
            '<span style="color:#555;">Subtotal:</span>' .
            '<span style="color:#333;font-weight:600;">$' . $subtotalDisplay . '</span>' .
        '</div>' .
        '<div style="display:flex;justify-content:space-between;margin-bottom:8px;">' .
            '<span style="color:#555;">HST (13%):</span>' .
            '<span style="color:#333;font-weight:600;">$' . $hstDisplay . '</span>' .
        '</div>' .
        '<div style="border-top:1px solid #b2f5ea;padding-top:8px;display:flex;justify-content:space-between;">' .
            '<span style="color:#0f766e;font-weight:700;">Total Amount Paid:</span>' .
            '<span style="color:#0f766e;font-weight:700;font-size:16px;">$' . $totalDisplay . '</span>' .
        '</div>' .
    '</div>';
}

// Format frequency
$freqLabel = ($frequency === 'recurring') ? 'Weekly (Recurring)' : 'One-Time';

// Build schedule HTML block from lines array
$scheduleHtml = '';
if (!empty($scheduleLines)) {
    $scheduleHtml = '<div style="background:#f0fdf4;border-left:4px solid #3b82f6;padding:15px;margin:20px 0;border-radius:4px;">';
    $scheduleHtml .= '<div style="font-weight:700;color:#334155;margin-bottom:10px;font-size:15px;">📅 Your Service Schedule</div>';
    foreach ($scheduleLines as $line) {
        $scheduleHtml .= '<div style="margin-bottom:6px;font-size:14px;color:#333;">' . htmlspecialchars($line) . '</div>';
    }
    $scheduleHtml .= '</div>';
}

// Build email HTML — matches completion email style (GFL Green → Blue gradient)
$html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>
<body style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f5f5f5;margin:0;padding:20px;">
  <div style="max-width:600px;margin:0 auto;background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.1);overflow:hidden;">
    <div style="background:linear-gradient(135deg,#71b80c 0%,#3b82f6 100%);color:#fff;padding:30px 20px;text-align:center;">
      <h1 style="margin:0;font-size:28px;font-weight:600;">📋 Booking Confirmed!</h1>
      <p style="margin:8px 0 0;opacity:0.9;font-size:15px;">GetMyBin Bin Collection Service</p>
    </div>
    <div style="padding:30px 20px;">
      <p style="font-size:16px;color:#333;margin-bottom:20px;">Hi $customerName,</p>
      <p style="color:#555;line-height:1.6;">Thank you for your order! Your GetMyBin bin service has been booked successfully. Here are your details:</p>
      <div style="background:#f9f9f9;border-left:4px solid #71b80c;padding:15px;margin:20px 0;border-radius:4px;">
        <div style="margin-bottom:10px;font-size:14px;"><span style="font-weight:600;color:#555;display:inline-block;min-width:130px;">🆔 Booking ID:</span><span style="color:#333;">$bookingId</span></div>
        <div style="margin-bottom:10px;font-size:14px;"><span style="font-weight:600;color:#555;display:inline-block;min-width:130px;">📍 Address:</span><span style="color:#333;">$address</span></div>
        <div style="margin-bottom:10px;font-size:14px;"><span style="font-weight:600;color:#555;display:inline-block;min-width:130px;">🔄 Service:</span><span style="color:#333;">$serviceLabel</span></div>
        <div style="margin-bottom:10px;font-size:14px;"><span style="font-weight:600;color:#555;display:inline-block;min-width:130px;">📅 Schedule:</span><span style="color:#333;">$freqLabel</span></div>
        <div style="margin-bottom:10px;font-size:14px;"><span style="font-weight:600;color:#555;display:inline-block;min-width:130px;">💰 Amount Paid:</span><span style="color:#333;">\$$amountDisplay CAD</span></div>
HTML;

// Insert the detailed schedule block after the summary
$html .= $scheduleHtml;

// Insert tax breakdown if available
if (!empty($taxBreakdownHtml)) {
    $html .= $taxBreakdownHtml;
}

$html .= <<<HTML
      </div>
      <div style="background:#f0fdf4;border-radius:8px;padding:16px;margin:20px 0;text-align:center;">
        <p style="color:#065f46;font-weight:600;margin:0;font-size:15px;">✅ You're all set!</p>
        <p style="color:#059669;margin:6px 0 0;font-size:13px;"><?php echo $isNightZone ? 'Your area has overnight collection. We\'ll roll your bins to the curb early evening on your collection day. No action needed from you.' : 'We\'ll roll your bins out the evening before collection and return them the same afternoon. No action needed from you.'; ?></p>
      </div>
      <p style="color:#555;line-height:1.6;">If you have any questions, just reply to this email or contact us at <a href="mailto:support@getmybin.com" style="color:#3b82f6;">support@getmybin.com</a></p>
    </div>
    <div style="background:#f9f9f9;padding:20px;text-align:center;font-size:12px;color:#999;border-top:1px solid #eee;">
      <p style="margin:0;">© 2026 GetMyBin · Toronto Bin Collection Service</p>
      <p style="margin:4px 0 0;"><a href="https://getmybin.com" style="color:#71b80c;text-decoration:none;">GetMyBin</a></p>
      <p style="margin:8px 0 0;"><a href="https://getmybin.com/manage.html" style="color:#94a3b8;text-decoration:none;font-size:11px;">Need to cancel? Visit getmybin.com/manage</a></p>
    </div>
  </div>
</body>
</html>
HTML;

// Raw SMTP send (same as send-email.php)
function smtpSend($host, $port, $user, $pass, $from, $to, $subject, $html) {
    $logFile = '/tmp/smtp-debug-' . date('YmdHis') . '-' . uniqid() . '.log';
    file_put_contents($logFile, "SMTP Send Attempt\n");
    file_put_contents($logFile, "Host: $host:$port\n", FILE_APPEND);
    file_put_contents($logFile, "From: $from\n", FILE_APPEND);
    file_put_contents($logFile, "To: $to\n", FILE_APPEND);
    
    $context = stream_context_create(['ssl' => [
        'verify_peer'       => false,
        'verify_peer_name'  => false,
        'allow_self_signed' => true,
    ]]);
    $sock = stream_socket_client("ssl://$host:$port", $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
    if (!$sock) {
        file_put_contents($logFile, "Connection FAILED: $errstr ($errno)\n", FILE_APPEND);
        return ["ok" => false, "error" => "Connect failed: $errstr ($errno)", "logFile" => basename($logFile)];
    }
    file_put_contents($logFile, "Connection OK\n", FILE_APPEND);

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
    file_put_contents($logFile, "Auth response: $r\n", FILE_APPEND);
    if (strpos($r, '235') === false) {
        fclose($sock);
        file_put_contents($logFile, "AUTH FAILED\n", FILE_APPEND);
        return ["ok" => false, "error" => "Auth failed: $r", "logFile" => basename($logFile)];
    }
    file_put_contents($logFile, "Auth OK\n", FILE_APPEND);

    $send("MAIL FROM:<$from>");  $read();
    $send("RCPT TO:<$to>");      $read();
    $send("DATA");               $read();

    $msg  = "Date: " . date('r') . "\r\n";
    $msg .= "From: GetMyBin <$from>\r\n";
    $msg .= "To: $to\r\n";
    $msg .= "Subject: $subject\r\n";
    $msg .= "MIME-Version: 1.0\r\n";
    $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
    $msg .= "Content-Transfer-Encoding: 8bit\r\n";
    $msg .= "\r\n";
    $msg .= $html . "\r\n";
    $msg .= ".\r\n";

    fwrite($sock, $msg);
    $r = $read();
    file_put_contents($logFile, "Send response: $r\n", FILE_APPEND);
    $send("QUIT");
    fclose($sock);
    $ok = strpos($r, '250') !== false;
    file_put_contents($logFile, "Result: " . ($ok ? "SUCCESS" : "FAILED") . "\n", FILE_APPEND);
    return ["ok" => $ok, "response" => trim($r), "logFile" => basename($logFile)];
}

$result = smtpSend(
    $SMTP_HOST, $SMTP_PORT, $SMTP_USER, $SMTP_PASS,
    $SMTP_USER,
    $toEmail,
    "📋 Booking Confirmed — GetMyBin #$bookingId",
    $html
);

if ($result['ok']) {
    echo json_encode(['success' => true, 'message' => "Confirmation sent to $toEmail", 'logFile' => $result['logFile'] ?? null]);
} else {
    echo json_encode(['success' => false, 'error' => $result['error'] ?? $result['response'], 'logFile' => $result['logFile'] ?? null]);
}
