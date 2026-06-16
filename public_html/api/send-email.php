<?php
/**
 * GetMyBin - Send Completion Email
 * Looks up customer by address in Airtable, sends completion email via Hostinger SMTP
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
    exit;
}

$address         = $body['address'] ?? '';
$serviceType     = $body['serviceType'] ?? 'rollout';
$workerName      = $body['workerName'] ?? 'Driver';
$completedDateTime = $body['completedDateTime'] ?? date('Y-m-d H:i');
$imageUrl        = $body['imageUrl'] ?? null;
$bookingId       = $body['bookingId'] ?? '';

// ── Airtable config ──────────────────────────────────────────────────────────
$AIRTABLE_KEY      = 'patxbDkv88pOMXmYx.c7e5fd7974954e3a674087090835d11dd69504f3912f0ef86c3c59f1e91febdd';
$AIRTABLE_BASE     = 'apptYNRJTXwItvied';
$BOOKINGS_TABLE    = 'tblKMhGnYjsH0z7Lj';

// ── SMTP config ──────────────────────────────────────────────────────────────
$SMTP_HOST = 'smtp.hostinger.com';
$SMTP_PORT = 465;
$SMTP_USER = 'support@agentrocketman.com';
$SMTP_PASS = 'AgentEmail1!';

// ── Look up customer by address in Airtable ──────────────────────────────────
function lookupCustomer($address, $key, $base, $table) {
    $filter = "LOWER(TRIM({Address}))=LOWER(TRIM(\"$address\"))";
    $url    = "https://api.airtable.com/v0/$base/$table?filterByFormula=" . urlencode($filter) . "&maxRecords=1";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $key"],
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) return null;
    $data = json_decode($res, true);
    if (empty($data['records'])) return null;
    $fields = $data['records'][0]['fields'];
    return [
        'email'        => $fields['Email'] ?? null,
        'customerName' => $fields['Customer Name'] ?? 'Valued Customer',
    ];
}

$customer = lookupCustomer($address, $AIRTABLE_KEY, $AIRTABLE_BASE, $BOOKINGS_TABLE);

if (!$customer || empty($customer['email'])) {
    echo json_encode(['success' => false, 'error' => 'No customer found for that address']);
    exit;
}

$toEmail      = $customer['email'];
$customerName = $customer['customerName'];
$serviceLabel = (stripos($serviceType, 'roll') !== false && stripos($serviceType, 'in') !== false)
    ? 'Roll In' : 'Roll Out';

// ── Build email HTML ─────────────────────────────────────────────────────────
$imageBlock = '';
if ($imageUrl) {
    // Make absolute if relative
    if (strpos($imageUrl, 'http') !== 0) {
        $imageUrl = 'https://agentrocketman.com' . $imageUrl;
    }
    $imageBlock = "
      <div style='text-align:center;margin:24px 0;'>
        <img src='$imageUrl' alt='Service photo'
             style='max-width:100%;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.15);'>
      </div>";
}

$html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>
<body style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f5f5f5;margin:0;padding:20px;">
  <div style="max-width:600px;margin:0 auto;background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.1);overflow:hidden;">
    <div style="background:linear-gradient(135deg,#71b80c 0%,#3b82f6 100%);color:#fff;padding:30px 20px;text-align:center;">
      <h1 style="margin:0;font-size:28px;font-weight:600;">✅ Service Completed</h1>
      <p style="margin:8px 0 0;opacity:0.9;font-size:15px;">GetMyBin Bin Collection Service</p>
    </div>
    <div style="padding:30px 20px;">
      <p style="font-size:16px;color:#333;margin-bottom:20px;">Hi $customerName,</p>
      <p style="color:#555;line-height:1.6;">Your bin service has been completed. Here are the details:</p>
      <div style="background:#f9f9f9;border-left:4px solid #71b80c;padding:15px;margin:20px 0;border-radius:4px;">
        <div style="margin-bottom:10px;font-size:14px;"><span style="font-weight:600;color:#555;display:inline-block;min-width:130px;">🆔 Booking ID:</span><span style="color:#333;font-weight:700;">$bookingId</span></div>
        <div style="margin-bottom:10px;font-size:14px;"><span style="font-weight:600;color:#555;display:inline-block;min-width:130px;">📍 Address:</span><span style="color:#333;">$address</span></div>
        <div style="margin-bottom:10px;font-size:14px;"><span style="font-weight:600;color:#555;display:inline-block;min-width:130px;">🔄 Service:</span><span style="color:#333;">$serviceLabel</span></div>
        <div style="margin-bottom:10px;font-size:14px;"><span style="font-weight:600;color:#555;display:inline-block;min-width:130px;">👷 Worker:</span><span style="color:#333;">$workerName</span></div>
        <div style="font-size:14px;"><span style="font-weight:600;color:#555;display:inline-block;min-width:130px;">🕐 Completed:</span><span style="color:#333;">$completedDateTime</span></div>
      </div>
      $imageBlock
      <p style="color:#555;line-height:1.6;">Thank you for choosing GetMyBin! Your bins are taken care of.</p>
    </div>
    <div style="background:#f9f9f9;padding:20px;text-align:center;font-size:12px;color:#999;border-top:1px solid #eee;">
      <p style="margin:0;">© 2026 GetMyBin · Toronto Bin Collection Service</p>
      <p style="margin:4px 0 0;"><a href="https://agentrocketman.com" style="color:#71b80c;text-decoration:none;">agentrocketman.com</a></p>
    </div>
  </div>
</body>
</html>
HTML;

// ── Send via SMTP using raw socket + SSL ─────────────────────────────────────
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

    $read(); // banner
    $send("EHLO localhost");
    while (($line = $read()) && substr($line, 3, 1) === '-') {} // drain

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

    $boundary = md5(uniqid());
    $msg  = "From: GetMyBin <$from>\r\n";
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
    "✅ Your GetMyBin service at $address is complete",
    $html
);

if ($result['ok']) {
    echo json_encode(['success' => true, 'message' => "Email sent to $toEmail"]);
} else {
    echo json_encode(['success' => false, 'error' => $result['error'] ?? $result['response']]);
}
