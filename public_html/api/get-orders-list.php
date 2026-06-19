<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Airtable credentials
$AIRTABLE_BASE_ID = 'apptYNRJTXwItvied';
$AIRTABLE_API_KEY = 'patxbDkv88pOMXmYx.c7e5fd7974954e3a674087090835d11dd69504f3912f0ef86c3c59f1e91febdd';
$AIRTABLE_TABLE = 'Orders';

// Get date range from query params
$fromDate = $_GET['from'] ?? null;
$toDate = $_GET['to'] ?? null;

if (!$fromDate || !$toDate) {
  http_response_code(400);
  echo json_encode(['error' => 'Missing date parameters']);
  exit;
}

// Parse dates
$fromTimestamp = strtotime($fromDate . ' 00:00:00');
$toTimestamp = strtotime($toDate . ' 23:59:59');

if (!$fromTimestamp || !$toTimestamp) {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid date format']);
  exit;
}

// Fetch all orders from Airtable
$url = "https://api.airtable.com/v0/$AIRTABLE_BASE_ID/$AIRTABLE_TABLE";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
  'Authorization: Bearer ' . $AIRTABLE_API_KEY
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

if ($http_code !== 200) {
  http_response_code(500);
  echo json_encode([
    'error' => 'Failed to fetch from Airtable',
    'http_code' => $http_code
  ]);
  exit;
}

$data = json_decode($response, true);
if (!$data) {
  http_response_code(500);
  echo json_encode(['error' => 'Invalid response from Airtable']);
  exit;
}

$records = $data['records'] ?? [];
$orders = [];

// Process records - filter by date range
foreach ($records as $record) {
  $fields = $record['fields'] ?? [];
  $serviceDateStr = $fields['Service Date'] ?? null;

  if ($serviceDateStr) {
    $serviceDateTimestamp = strtotime($serviceDateStr . ' 00:00:00');
    
    // Check if date is within range
    if ($serviceDateTimestamp >= $fromTimestamp && $serviceDateTimestamp <= $toTimestamp) {
      $orders[] = [
        'id' => $record['id'],
        'bookingId' => $fields['Booking ID'] ?? '-',
        'createdAt' => $fields['Created At'] ?? '-',
        'address' => $fields['Booking ID'] ?? '-', // Note: We'll need to fetch the booking details to get address
        'email' => '-', // We'll need to fetch booking details
        'serviceType' => $fields['Service Type'] ?? '-',
        'serviceDate' => $fields['Service Date'] ?? '-',
        'status' => $fields['Status'] ?? 'New'
      ];
    }
  }
}

// Now we need to fetch booking details for address and email
// Create a map of booking IDs to fetch
$bookingIds = [];
foreach ($orders as &$order) {
  $bid = $order['bookingId'];
  if ($bid !== '-' && !isset($bookingIds[$bid])) {
    $bookingIds[$bid] = true;
  }
}

// Fetch bookings to get address and email
if (!empty($bookingIds)) {
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
  
  if ($bookingHttp === 200) {
    $bookingData = json_decode($bookingResponse, true);
    $bookingRecords = $bookingData['records'] ?? [];
    
    // Create lookup map
    $bookingMap = [];
    foreach ($bookingRecords as $booking) {
      $bookingMap[$booking['fields']['Booking ID'] ?? ''] = $booking['fields'];
    }
    
    // Update orders with booking details
    foreach ($orders as &$order) {
      if (isset($bookingMap[$order['bookingId']])) {
        $booking = $bookingMap[$order['bookingId']];
        $order['address'] = $booking['Address'] ?? '-';
        $order['email'] = $booking['Email'] ?? '-';
      }
    }
  }
}

http_response_code(200);
echo json_encode([
  'success' => true,
  'orders' => $orders
]);
?>
