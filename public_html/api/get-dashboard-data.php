<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Airtable credentials
$AIRTABLE_BASE_ID = 'apptYNRJTXwItvied';
$AIRTABLE_API_KEY = 'patxbDkv88pOMXmYx.c7e5fd7974954e3a674087090835d11dd69504f3912f0ef86c3c59f1e91febdd';
$AIRTABLE_TABLE = 'Bookings';

// Get date range from query params
$fromDate = $_GET['from'] ?? null;
$toDate = $_GET['to'] ?? null;

if (!$fromDate || !$toDate) {
  http_response_code(400);
  echo json_encode(['error' => 'Missing date parameters']);
  exit;
}

// Fetch all bookings from Airtable
$url = "https://api.airtable.com/v0/$AIRTABLE_BASE_ID/$AIRTABLE_TABLE?maxRecords=10000&fields=Date,Status";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
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
    'details' => $curl_error
  ]);
  exit;
}

$data = json_decode($response, true);
$records = $data['records'] ?? [];

// Process records
$ordersByDate = [];
$totalOrders = 0;
$completedOrders = 0;
$pendingOrders = 0;

$fromTimestamp = strtotime($fromDate);
$toTimestamp = strtotime($toDate);

foreach ($records as $record) {
  $fields = $record['fields'] ?? [];
  $dateStr = $fields['Date'] ?? null;
  $status = $fields['Status'] ?? 'Pending';

  if ($dateStr) {
    $dateTimestamp = strtotime($dateStr);
    
    // Check if date is within range
    if ($dateTimestamp >= $fromTimestamp && $dateTimestamp <= $toTimestamp) {
      $dateKey = date('Y-m-d', $dateTimestamp);
      $ordersByDate[$dateKey] = ($ordersByDate[$dateKey] ?? 0) + 1;
      $totalOrders++;
      
      if ($status === 'Completed') {
        $completedOrders++;
      } else {
        $pendingOrders++;
      }
    }
  }
}

// Generate chart data for date range
$chartDates = [];
$chartCounts = [];
$current = $fromTimestamp;

while ($current <= $toTimestamp) {
  $dateKey = date('Y-m-d', $current);
  $chartDates[] = date('M d', $current);
  $chartCounts[] = $ordersByDate[$dateKey] ?? 0;
  $current = strtotime('+1 day', $current);
}

// Calculate average
$daysCount = count($chartDates);
$avgOrders = $daysCount > 0 ? ceil($totalOrders / $daysCount) : 0;

http_response_code(200);
echo json_encode([
  'success' => true,
  'chartDates' => $chartDates,
  'chartCounts' => $chartCounts,
  'totalOrders' => $totalOrders,
  'avgOrders' => $avgOrders,
  'completedOrders' => $completedOrders,
  'pendingOrders' => $pendingOrders
]);
?>
