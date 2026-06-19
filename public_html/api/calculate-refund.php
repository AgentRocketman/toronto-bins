<?php
require_once __DIR__ . '/config.php';
corsHeaders();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  http_response_code(405);
  echo json_encode(['error' => 'GET required']);
  exit;
}

$bookingId = trim($_GET['bookingId'] ?? '');
$orderId = trim($_GET['orderId'] ?? '');

if (!$bookingId) {
  http_response_code(400);
  echo json_encode(['error' => 'Missing bookingId']);
  exit;
}

// Fetch booking details using airtableRequest helper
// Important: Airtable formulas use double single quotes to escape quotes, not backslashes
$escapedBookingId = str_replace("'", "''", $bookingId);
$formula = "{Booking ID}='" . $escapedBookingId . "'";
$bookingsResult = airtableRequest('GET', AIRTABLE_BOOKINGS, ['filterByFormula' => $formula]);

if ($bookingsResult['code'] >= 400 || empty($bookingsResult['body']['records'])) {
  http_response_code(404);
  echo json_encode(['error' => 'Booking not found']);
  exit;
}

$bookingRecord = $bookingsResult['body']['records'][0];
$bookingAmount = (float)($bookingRecord['fields']['Amount'] ?? 0);
$billingType = $bookingRecord['fields']['Billing Type'] ?? 'One-Time Charge';

// Calculate 48-hour cutoff
$now = new DateTime('now', new DateTimeZone('America/Toronto'));
$cutoff = clone $now;
$cutoff->modify('+48 hours');
$cutoffDate = $cutoff->format('Y-m-d');

// Fetch all orders for this booking with amounts
$ordersFormula = "{Booking ID}='" . $escapedBookingId . "'";
$ordersResult = airtableRequest('GET', AIRTABLE_ORDERS, [
  'filterByFormula' => $ordersFormula,
  'fields[]' => ['Service Date', 'Frequency', 'Status', 'Amount']
]);

$allOrders = $ordersResult['body']['records'] ?? [];
$targetOrder = null;
$alreadyRefundedAmount = 0;

// Check if any orders have already been refunded and sum their amounts
foreach ($allOrders as $order) {
  $status = $order['fields']['Status'] ?? '';
  if ($status === 'Cancelled' || $status === 'Refunded') {
    // This order was already refunded, add its amount to the "already refunded" total
    $orderAmount = (float)($order['fields']['Amount'] ?? 0);
    $alreadyRefundedAmount += $orderAmount;
  }
  
  if ($orderId && $order['id'] === $orderId) {
    $targetOrder = $order;
  }
}

// Calculate maximum available refund for this booking
$maxAvailableRefund = max(0, $bookingAmount - $alreadyRefundedAmount);

// Calculate refund amount
$refundAmount = 0;
$refundDates = 0;
$totalDates = count($allOrders); // Total orders in booking

if ($orderId && $targetOrder) {
  // ORDER-LEVEL REFUND: Refund just this order's portion
  $serviceDate = $targetOrder['fields']['Service Date'] ?? '';
  if ($serviceDate > $cutoffDate) {
    // Only refund if beyond 48-hour cutoff
    $perEvent = $bookingAmount / $totalDates;
    $refundAmount = round($perEvent, 2);
    $refundDates = 1;
    
    // Cap refund at maximum available for this booking
    $refundAmount = min($refundAmount, $maxAvailableRefund);
  } else {
    $refundAmount = 0;
    $refundDates = 0;
  }
} else {
  // BOOKING-LEVEL REFUND: Refund proportional to future orders
  foreach ($allOrders as $order) {
    $status = $order['fields']['Status'] ?? '';
    // Don't count already cancelled/refunded orders
    if ($status !== 'Cancelled' && $status !== 'Refunded') {
      $serviceDate = $order['fields']['Service Date'] ?? '';
      $freq = $order['fields']['Frequency'] ?? '';
      
      if ($freq === 'Recurring' || $serviceDate > $cutoffDate) {
        $refundDates++;
      }
    }
  }

  if ($refundDates > 0 && $totalDates > 0 && $bookingAmount > 0) {
    $perEvent = $bookingAmount / $totalDates;
    $refundAmount = round($perEvent * $refundDates, 2);
    
    // Cap refund at maximum available for this booking
    $refundAmount = min($refundAmount, $maxAvailableRefund);
  }
}

http_response_code(200);
echo json_encode([
  'success' => true,
  'bookingId' => $bookingId,
  'totalBookingAmount' => $bookingAmount,
  'billingType' => $billingType,
  'refundAmount' => $refundAmount,
  'refundDates' => $refundDates,
  'totalDates' => $totalDates,
  'cutoffDate' => $cutoffDate,
  'isOrderLevel' => !empty($orderId),
  'alreadyRefundedAmount' => $alreadyRefundedAmount,
  'maxAvailableRefund' => $maxAvailableRefund
]);
?>
