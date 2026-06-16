<?php
/**
 * GetMyBin — Automated Cancellation
 * Full automation: Stripe cancel/refund + Airtable update + customer & support emails
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

$bookingId = strtoupper(trim($body['bookingId'] ?? ''));
$email     = strtolower(trim($body['email'] ?? ''));
$reason    = $body['reason'] ?? '';
$message   = trim($body['message'] ?? '');

if (!$bookingId || !$email) {
    echo json_encode(['success' => false, 'error' => 'Booking ID and email are required']); exit;
}

// ─── Step 1: Look up booking in Airtable ────────────────────────────────────
$formula = "AND({Booking ID}='" . addslashes($bookingId) . "',LOWER({Email})='" . addslashes($email) . "')";
$lookup  = airtableRequest('GET', AIRTABLE_BOOKINGS, ['filterByFormula' => $formula]);

if ($lookup['code'] >= 400 || empty($lookup['body']['records'])) {
    echo json_encode(['success' => false, 'error' => 'Booking not found. Please check your Booking ID and email address.']); exit;
}

$record   = $lookup['body']['records'][0];
$recordId = $record['id'];
$fields   = $record['fields'];

$billingType    = $fields['Billing Type'] ?? 'One-Time Charge';
$stripeSubId    = $fields['Stripe Subscription ID'] ?? '';
$stripePayId    = $fields['Stripe Payment ID'] ?? '';
$bookingAmount  = (float)($fields['Amount'] ?? 0); // in dollars
$customerName   = $fields['Customer Name'] ?? 'Valued Customer';
$status         = $fields['Status'] ?? 'Active';

// ─── Step 2: 48-hour cutoff (Toronto time) ──────────────────────────────────
$now    = new DateTime('now', new DateTimeZone('America/Toronto'));
$cutoff = clone $now;
$cutoff->modify('+48 hours');
$cutoffDate = $cutoff->format('Y-m-d');
$timestamp  = $now->format('Y-m-d H:i:s T');

// ─── Step 3: Handle Stripe ──────────────────────────────────────────────────
$stripeAction   = 'none';
$refundAmount   = 0;
$refundDates    = 0;
$stripeError    = '';

if ($billingType === 'Recurring Subscription' && $stripeSubId) {
    // Cancel subscription immediately — no refund (service runs to end of paid week)
    $cancelResult = stripeRequest('DELETE', '/subscriptions/' . $stripeSubId);
    if ($cancelResult['code'] < 400) {
        $stripeAction = 'subscription_cancelled';
    } else {
        $stripeError = $cancelResult['body']['error']['message'] ?? 'Stripe cancellation failed';
    }

} elseif ($billingType === 'One-Time Charge' && $stripePayId) {
    // Ad hoc: count future orders beyond 48hr cutoff to calculate refund
    $ordersResult = airtableRequest('GET', AIRTABLE_ORDERS, [
        'filterByFormula' => "{Booking ID}='" . addslashes($bookingId) . "'",
        'fields[]'        => ['Service Date', 'Status', 'Order ID']
    ]);

    $allOrders    = $ordersResult['body']['records'] ?? [];
    $totalOrders  = count($allOrders);
    $futureOrders = [];

    foreach ($allOrders as $order) {
        $svcDate = $order['fields']['Service Date'] ?? '';
        if ($svcDate > $cutoffDate) {
            $futureOrders[] = $order;
        }
    }

    $refundDates = count($futureOrders);

    if ($refundDates > 0 && $totalOrders > 0 && $bookingAmount > 0) {
        // Proportional refund: (future dates / total dates) × total paid
        $perEvent     = $bookingAmount / $totalOrders;
        $refundAmount = round($perEvent * $refundDates, 2);
        $refundCents  = (int)round($refundAmount * 100);

        $refundResult = stripeRequest('POST', '/refunds', [
            'payment_intent' => $stripePayId,
            'amount'         => $refundCents,
            'reason'         => 'requested_by_customer',
        ]);

        if ($refundResult['code'] < 400) {
            $stripeAction = 'refund_issued';
        } else {
            $stripeError = $refundResult['body']['error']['message'] ?? 'Refund failed';
        }
    } else {
        $stripeAction = 'no_refund_due'; // all dates within 48hr window
    }
}

// ─── Step 4: Update Airtable booking status ──────────────────────────────────
airtableRequest('PATCH', AIRTABLE_BOOKINGS, [
    'fields' => [
        'Status'            => 'Cancelled',
        'Cancellation Date' => $now->format('Y-m-d'),
    ]
], $recordId);

// ─── Step 5: Cancel future orders in Airtable ───────────────────────────────
$ordersToCancel = [];
$allOrdersResult = airtableRequest('GET', AIRTABLE_ORDERS, [
    'filterByFormula' => "AND({Booking ID}='" . addslashes($bookingId) . "',{Status}!='Cancelled')",
    'fields[]'        => ['Service Date', 'Day of Week', 'Frequency', 'Status']
]);

$allOrders = $allOrdersResult['body']['records'] ?? [];
foreach ($allOrders as $order) {
    $svcDate  = $order['fields']['Service Date'] ?? '';
    $freq     = $order['fields']['Frequency'] ?? '';
    // Cancel: recurring orders always, ad hoc only if beyond 48hr cutoff
    if ($freq === 'Recurring' || $svcDate > $cutoffDate) {
        $ordersToCancel[] = ['id' => $order['id'], 'fields' => ['Status' => 'Cancelled']];
    }
}

// Batch cancel (Airtable allows up to 10 per request)
foreach (array_chunk($ordersToCancel, 10) as $batch) {
    airtableRequest('PATCH', AIRTABLE_ORDERS, ['records' => $batch]);
}

// ─── Step 6: Build email content ─────────────────────────────────────────────
$reasonLabels = [
    'travelling'  => 'Travelling / away temporarily',
    'expensive'   => 'Too expensive',
    'unhappy'     => 'Not happy with the service',
    'not-needed'  => 'No longer need the service',
    'other'       => 'Other / not specified',
];
$reasonLabel = $reasonLabels[$reason] ?? ($reason ?: 'Not specified');

// Refund line for customer email
$refundLine = '';
if ($stripeAction === 'refund_issued') {
    $refundLine = "<p style='margin-top:16px;padding:14px;background:#f0fdf4;border-left:4px solid #22c55e;border-radius:4px;font-size:14px;color:#166534;line-height:1.6;'>"
        . "✅ <strong>Refund processed:</strong> \$$refundAmount CAD has been refunded to your original payment method for $refundDates upcoming date" . ($refundDates > 1 ? 's' : '') . " that will no longer be serviced. Please allow 5–10 business days for it to appear.</p>";
} elseif ($stripeAction === 'no_refund_due') {
    $refundLine = "<p style='margin-top:16px;padding:14px;background:#fef3c7;border-left:4px solid #f59e0b;border-radius:4px;font-size:14px;color:#92400e;line-height:1.6;'>"
        . "⏱ <strong>Note:</strong> All your remaining dates fall within the 48-hour service window and will proceed as scheduled. No refund is applicable for these dates.</p>";
} elseif ($stripeAction === 'subscription_cancelled') {
    $refundLine = "<p style='margin-top:16px;padding:14px;background:#f0fdf4;border-left:4px solid #22c55e;border-radius:4px;font-size:14px;color:#166534;line-height:1.6;'>"
        . "✅ <strong>Subscription cancelled:</strong> No further charges will be made to your payment method. Any service within the 48-hour window will still be completed.</p>";
}

// Customer email
$customerHtml = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f5f5f5;margin:0;padding:20px;">
  <div style="max-width:600px;margin:0 auto;background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.1);overflow:hidden;">
    <div style="background:linear-gradient(135deg,#0a5c56 0%,#0d9488 100%);color:#fff;padding:30px 20px;text-align:center;">
      <h1 style="margin:0;font-size:24px;font-weight:700;">Your GetMyBin service has been cancelled</h1>
      <p style="margin:8px 0 0;opacity:.85;font-size:14px;">Booking ID: $bookingId</p>
    </div>
    <div style="padding:30px 20px;">
      <p style="font-size:16px;color:#333;margin-bottom:16px;">Hi $customerName,</p>
      <p style="color:#555;line-height:1.7;font-size:15px;">We've processed your cancellation request. Here's a summary of what happened:</p>
      <div style="background:#f9f9f9;border-left:4px solid #0d9488;padding:16px;margin:20px 0;border-radius:4px;font-size:14px;line-height:1.8;">
        <div><strong>Booking ID:</strong> $bookingId</div>
        <div><strong>Cancellation date:</strong> $timestamp</div>
        <div><strong>Service type:</strong> $billingType</div>
      </div>
      $refundLine
      <p style="color:#64748b;font-size:14px;line-height:1.7;margin-top:20px;">
        Any collection scheduled within the next 48 hours will still be completed as planned. After that, no further services will be carried out.
      </p>
      <p style="color:#64748b;font-size:14px;line-height:1.7;margin-top:12px;">
        We're sorry to see you go. If you'd like to return in the future, you're always welcome — just book again at <a href="https://agentrocketman.com" style="color:#0d9488;">agentrocketman.com</a>.
      </p>
      <p style="color:#64748b;font-size:14px;margin-top:20px;">Questions? Reply to this email or reach us at <a href="mailto:support@agentrocketman.com" style="color:#0d9488;">support@agentrocketman.com</a></p>
    </div>
    <div style="background:#f9f9f9;padding:16px 20px;text-align:center;font-size:12px;color:#999;border-top:1px solid #eee;">
      <p style="margin:0;">© 2026 GetMyBin · Toronto Bin Collection Service</p>
      <p style="margin:4px 0 0;"><a href="https://agentrocketman.com" style="color:#94a3b8;text-decoration:none;">agentrocketman.com</a></p>
    </div>
  </div>
</body></html>
HTML;

// Support notification email
$ordersNote    = count($ordersToCancel) . ' order(s) cancelled in Airtable.';
$stripeNote    = $stripeError ? "⚠️ Stripe error: $stripeError" : "✅ Stripe action: $stripeAction";
$refundNote    = $stripeAction === 'refund_issued' ? "Refund issued: \$$refundAmount CAD for $refundDates date(s)." : 'No refund issued.';

$supportHtml = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f5f5f5;margin:0;padding:20px;">
  <div style="max-width:600px;margin:0 auto;background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.1);overflow:hidden;">
    <div style="background:linear-gradient(135deg,#0a5c56 0%,#0d9488 100%);color:#fff;padding:24px 20px;">
      <h1 style="margin:0;font-size:20px;">🚫 Cancellation Processed — $bookingId</h1>
      <p style="margin:6px 0 0;opacity:.85;font-size:13px;">Automated at $timestamp</p>
    </div>
    <div style="padding:24px 20px;font-size:14px;line-height:1.8;color:#334155;">
      <table style="width:100%;border-collapse:collapse;">
        <tr><td style="color:#64748b;width:180px;padding:8px 0;font-weight:600;">Booking ID</td><td style="font-weight:700;font-size:16px;">$bookingId</td></tr>
        <tr style="border-top:1px solid #f1f5f9;"><td style="color:#64748b;padding:8px 0;font-weight:600;">Customer Email</td><td>$email</td></tr>
        <tr style="border-top:1px solid #f1f5f9;"><td style="color:#64748b;padding:8px 0;font-weight:600;">Billing Type</td><td>$billingType</td></tr>
        <tr style="border-top:1px solid #f1f5f9;"><td style="color:#64748b;padding:8px 0;font-weight:600;">Reason</td><td>$reasonLabel</td></tr>
        <tr style="border-top:1px solid #f1f5f9;"><td style="color:#64748b;padding:8px 0;font-weight:600;">Stripe</td><td>$stripeNote</td></tr>
        <tr style="border-top:1px solid #f1f5f9;"><td style="color:#64748b;padding:8px 0;font-weight:600;">Refund</td><td>$refundNote</td></tr>
        <tr style="border-top:1px solid #f1f5f9;"><td style="color:#64748b;padding:8px 0;font-weight:600;">Orders</td><td>$ordersNote</td></tr>
HTML;

if ($message) {
    $safeMsg = htmlspecialchars($message);
    $supportHtml .= "<tr style='border-top:1px solid #f1f5f9;'><td style='color:#64748b;padding:8px 0;font-weight:600;vertical-align:top;'>Customer note</td><td>$safeMsg</td></tr>";
}

$supportHtml .= <<<HTML
      </table>
      <div style="margin-top:20px;padding:14px;background:#fef3c7;border-left:4px solid #f59e0b;border-radius:4px;font-size:13px;color:#92400e;">
        All actions above were applied automatically. No manual steps required unless there is a Stripe error noted above.
      </div>
    </div>
  </div>
</body></html>
HTML;

// ─── Step 7: Send emails ─────────────────────────────────────────────────────
sendSmtpEmail($email, $customerName, 'Your GetMyBin service has been cancelled', $customerHtml);
sendSmtpEmail(SUPPORT_EMAIL, 'GetMyBin Support', "🚫 Cancellation Processed — Booking $bookingId", $supportHtml);

// ─── Done ────────────────────────────────────────────────────────────────────
echo json_encode([
    'success'      => true,
    'stripeAction' => $stripeAction,
    'refundAmount' => $refundAmount,
    'ordersCancelledCount' => count($ordersToCancel),
    'stripeError'  => $stripeError ?: null,
]);
