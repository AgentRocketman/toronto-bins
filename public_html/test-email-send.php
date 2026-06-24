<?php
// Test sending email via Hostinger SMTP
$to = 'chris@rental4u.ca';
$subject = 'Test Email from GetMyBin Support';
$message = "Hello Chris,\n\nThis is a test email from support@getmybin.com\n\nBest regards,\nGetMyBin Support Team";

$headers = "From: support@getmybin.com\r\n";
$headers .= "Reply-To: support@getmybin.com\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

if (mail($to, $subject, $message, $headers)) {
    echo "✅ Email sent successfully to $to\n";
    http_response_code(200);
} else {
    echo "❌ Failed to send email\n";
    http_response_code(500);
}
?>
