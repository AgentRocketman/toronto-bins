<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

require_once __DIR__ . '/config.php';

$AIRTABLE_BASE_ID = 'apptYNRJTXwItvied';
$AIRTABLE_API_KEY = 'patxbDkv88pOMXmYx.c7e5fd7974954e3a674087090835d11dd69504f3912f0ef86c3c59f1e91febdd';

$bookingId = $_GET['bookingId'] ?? null;
$orderId = $_GET['orderId'] ?? null;

if (!$bookingId) {
  http_response_code(400);
  echo json_encode(['error' => 'Missing bookingId']);
  exit;
}

// Fetch booking details
$bookingsUrl = "https://api.airtable.com/v0/$AIRTABLE_BASE_ID/Bookings";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $bookingsUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
  'Authorization: Bearer ' . $AIRTABLE_API_KEY
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200) {
  http_response_code(500);
  echo json_encode(['error' => 'Failed to fetch booking']);
  exit;
}

$data = json_decode($response, true);
$bookingRecord = null;

foreach ($data['records'] ?? [] as $record) {
  if (($record['fields']['Booking ID'] ?? '') === $bookingId) {
    $bookingRecord = $record;
    break;
  }
}

if (!$bookingRecord) {
  http_response_code(404);
  echo json_encode(['error' => 'Booking not found']);
  exit;
}

$bookingAmount = (float)($bookingRecord['fields']['Amount'] ?? 0);
$billingType = $bookingRecord['fields']['Billing Type'] ?? 'One-Time Charge';

// Calculate 48-hour cutoff
$now = new DateTime('now', new DateTimeZone('America/Toronto'));
$cutoff = clone $now;
$cutoff->modify('+48 hours');
$cutoffDate = $cutoff->format('Y-m-d');

// Fetch all orders for this booking
$ordersUrl = "https://api.airtable.com/v0/$AIRTABLE_BASE_ID/Orders";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $ordersUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
  'Authorization: Bearer ' . $AIRTABLE_API_KEY
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$allOrders = [];
$targetOrder = null;

if ($http_code === 200) {
  $data = json_decode($response, true);
  foreach ($data['records'] ?? [] as $record) {
    if (($record['fields']['Booking ID'] ?? '') === $bookingId) {
      $allOrders[] = $record;
      if ($orderId && $record['id'] === $orderId) {
        $targetOrder = $record;
      }
    }
  }
}

// Calculate refund amount
$refundAmount = 0;
$refundDates = 0;
$totalDates = 0;

if ($orderId && $targetOrder) {
  // ORDER-LEVEL REFUND: Refund just this order's portion
  $serviceDate = $targetOrder['fields']['Service Date'] ?? '';
  if ($serviceDate > $cutoffDate) {
    // Only refund if beyond 48-hour cutoff
    $perEvent = $bookingAmount / count($allOrders);
    $refundAmount = round($perEvent, 2);
    $refundDates = 1;
  }
} else {
  // BOOKING-LEVEL REFUND: Refund proportional to future orders
  foreach ($allOrders as $order) {
    $serviceDate = $order['fields']['Service Date'] ?? '';
    $freq = $order['fields']['Frequency'] ?? '';
    $totalDates++;
    
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
