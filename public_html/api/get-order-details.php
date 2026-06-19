<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Airtable credentials
$AIRTABLE_BASE_ID = 'apptYNRJTXwItvied';
$AIRTABLE_API_KEY = 'patxbDkv88pOMXmYx.c7e5fd7974954e3a674087090835d11dd69504f3912f0ef86c3c59f1e91febdd';

// Get order ID from query params
$orderId = $_GET['orderId'] ?? null;

if (!$orderId) {
  http_response_code(400);
  echo json_encode(['error' => 'Missing orderId parameter']);
  exit;
}

// Fetch order from Orders table
$url = "https://api.airtable.com/v0/$AIRTABLE_BASE_ID/Orders/$orderId";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
  'Authorization: Bearer ' . $AIRTABLE_API_KEY
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200) {
  http_response_code(404);
  echo json_encode(['error' => 'Order not found']);
  exit;
}

$orderData = json_decode($response, true);
if (!$orderData) {
  http_response_code(500);
  echo json_encode(['error' => 'Invalid response from Airtable']);
  exit;
}

$orderFields = $orderData['fields'] ?? [];
$bookingId = $orderFields['Booking ID'] ?? null;

if (!$bookingId) {
  http_response_code(400);
  echo json_encode(['error' => 'No Booking ID in order']);
  exit;
}

// Fetch booking details
$bookingUrl = "https://api.airtable.com/v0/$AIRTABLE_BASE_ID/Bookings";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $bookingUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
  'Authorization: Bearer ' . $AIRTABLE_API_KEY
]);

$bookingResponse = curl_exec($ch);
$bookingHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$bookingData = [];
if ($bookingHttp === 200) {
  $data = json_decode($bookingResponse, true);
  $records = $data['records'] ?? [];
  
  // Find matching booking
  foreach ($records as $record) {
    if (($record['fields']['Booking ID'] ?? '') === $bookingId) {
      $bookingData = $record['fields'];
      break;
    }
  }
}

// Fetch all related orders for this booking
$ordersUrl = "https://api.airtable.com/v0/$AIRTABLE_BASE_ID/Orders";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $ordersUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
  'Authorization: Bearer ' . $AIRTABLE_API_KEY
]);

$ordersResponse = curl_exec($ch);
$ordersHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$relatedOrders = [];
if ($ordersHttp === 200) {
  $data = json_decode($ordersResponse, true);
  $records = $data['records'] ?? [];
  
  // Find all orders with same booking ID
  foreach ($records as $record) {
    if (($record['fields']['Booking ID'] ?? '') === $bookingId) {
      $relatedOrders[] = [
        'airtableId' => $record['id'],
        'orderId' => $record['fields']['Order ID'] ?? '-',
        'serviceDate' => $record['fields']['Service Date'] ?? '-',
        'serviceType' => $record['fields']['Service Type'] ?? '-',
        'status' => $record['fields']['Status'] ?? 'New'
      ];
    }
  }
}

// Fetch completion images from ServiceStops (if order is completed)
$completionImages = [];
if ($orderFields['Status'] === 'Completed') {
  $serviceStopsUrl = "https://api.airtable.com/v0/$AIRTABLE_BASE_ID/ServiceStops";
  
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $serviceStopsUrl);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 10);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $AIRTABLE_API_KEY
  ]);
  
  $stopsResponse = curl_exec($ch);
  $stopsHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  
  if ($stopsHttp === 200) {
    $data = json_decode($stopsResponse, true);
    $records = $data['records'] ?? [];
    
    // Find ServiceStops for this address that have images
    // Deduplicate by image URL to avoid showing same image multiple times
    $address = $bookingData['Address'] ?? '';
    $seenUrls = [];
    foreach ($records as $record) {
      $fields = $record['fields'] ?? [];
      if (($fields['Address'] ?? '') === $address && isset($fields['Image URL'])) {
        $imageUrl = $fields['Image URL'];
        // Only add if we haven't seen this URL before
        if (!in_array($imageUrl, $seenUrls)) {
          $completionImages[] = [
            'url' => $imageUrl,
            'date' => $fields['Date'] ?? '-',
            'worker' => $fields['Worker Name'] ?? 'Driver'
          ];
          $seenUrls[] = $imageUrl;
        }
      }
    }
  }
}

http_response_code(200);
echo json_encode([
  'success' => true,
  'order' => [
    'id' => $orderId,
    'orderId' => $orderFields['Order ID'] ?? '-',
    'serviceDate' => $orderFields['Service Date'] ?? '-',
    'serviceType' => $orderFields['Service Type'] ?? '-',
    'status' => $orderFields['Status'] ?? 'New',
    'frequency' => $orderFields['Frequency'] ?? '-',
    'binPlacement' => $orderFields['Bin Placement'] ?? null,
    'lat' => $orderFields['Lat'] ?? null,
    'lng' => $orderFields['Lng'] ?? null
  ],
  'booking' => [
    'bookingId' => $bookingId,
    'customerName' => $bookingData['Customer Name'] ?? '-',
    'email' => $bookingData['Email'] ?? '-',
    'address' => $bookingData['Address'] ?? '-',
    'amount' => $bookingData['Amount'] ?? 0,
    'createdAt' => $bookingData['Created At'] ?? '-',
    'stripePaymentId' => $bookingData['Stripe Payment ID'] ?? '-',
    'stripeSubscriptionId' => $bookingData['Stripe Subscription ID'] ?? '-'
  ],
  'relatedOrders' => $relatedOrders,
  'completionImages' => $completionImages
]);
?>
