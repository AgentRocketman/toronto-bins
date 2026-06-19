<?php
require_once __DIR__ . '/config.php';
corsHeaders();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  http_response_code(405);
  echo json_encode(['error' => 'GET required']);
  exit;
}

$bookingId = $_GET['bookingId'] ?? null;
$orderId = $_GET['orderId'] ?? null;

if (!$bookingId) {
  http_response_code(400);
  echo json_encode(['error' => 'Missing bookingId']);
  exit;
}

// Fetch booking details using airtableRequest helper
$formula = "{Booking ID}='" . addslashes($bookingId) . "'";
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

// Fetch all orders for this booking
$ordersFormula = "{Booking ID}='" . addslashes($bookingId) . "'";
$ordersResult = airtableRequest('GET', AIRTABLE_ORDERS, [
  'filterByFormula' => $ordersFormula,
  'fields[]' => ['Service Date', 'Frequency', 'Status']
]);

$allOrders = $ordersResult['body']['records'] ?? [];
$targetOrder = null;

if ($orderId) {
  foreach ($allOrders as $order) {
    if ($order['id'] === $orderId) {
      $targetOrder = $order;
      break;
    }
  }
}

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
  } else {
    $refundAmount = 0;
    $refundDates = 0;
  }
} else {
  // BOOKING-LEVEL REFUND: Refund proportional to future orders
  foreach ($allOrders as $order) {
    $serviceDate = $order['fields']['Service Date'] ?? '';
    $freq = $order['fields']['Frequency'] ?? '';
    
    if ($freq === 'Recurring' || $serviceDate > $cutoffDate) {
      $refundDates++;
    }
  }

  if ($refundDates > 0 && $totalDates > 0 && $bookingAmount > 0) {
    $perEvent = $bookingAmount / $totalDates;
    $refundAmount = round($perEvent * $refundDates, 2);
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
  'isOrderLevel' => !empty($orderId)
]);
?>
