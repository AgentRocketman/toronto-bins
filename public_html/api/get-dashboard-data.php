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

// Parse dates
$fromTimestamp = strtotime($fromDate . ' 00:00:00');
$toTimestamp = strtotime($toDate . ' 23:59:59');

if (!$fromTimestamp || !$toTimestamp) {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid date format']);
  exit;
}

// Fetch all bookings from Airtable (no filter parameters to avoid validation issues)
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
    'http_code' => $http_code,
    'curl_error' => $curl_error
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

// Process records - separate by status
$ordersByDateAndStatus = [];
$totalOrders = 0;
$newOrders = 0;
$completedOrders = 0;
$pendingOrders = 0;

foreach ($records as $record) {
  $fields = $record['fields'] ?? [];
  // Use "Created At" field instead of "Date"
  $dateStr = $fields['Created At'] ?? null;
  $status = $fields['Status'] ?? 'New'; // Default to 'New' if no status

  if ($dateStr) {
    // Parse the date from Airtable (it comes as YYYY-MM-DD)
    $dateTimestamp = strtotime($dateStr . ' 00:00:00');
    
    // Check if date is within range
    if ($dateTimestamp >= $fromTimestamp && $dateTimestamp <= $toTimestamp) {
      $dateKey = date('Y-m-d', $dateTimestamp);
      
      // Initialize if not exists
      if (!isset($ordersByDateAndStatus[$dateKey])) {
        $ordersByDateAndStatus[$dateKey] = [
          'new' => 0,
          'pending' => 0,
          'completed' => 0
        ];
      }
      
      $totalOrders++;
      
      if ($status === 'Completed') {
        $ordersByDateAndStatus[$dateKey]['completed']++;
        $completedOrders++;
      } else if ($status === 'New') {
        $ordersByDateAndStatus[$dateKey]['new']++;
        $newOrders++;
      } else {
        $ordersByDateAndStatus[$dateKey]['pending']++;
        $pendingOrders++;
      }
    }
  }
}

// Generate chart data for date range
$chartDates = [];
$chartNewCounts = [];
$chartPendingCounts = [];
$chartCompletedCounts = [];
$current = $fromTimestamp;

while ($current <= $toTimestamp) {
  $dateKey = date('Y-m-d', $current);
  $chartDates[] = date('M d', $current);
  
  if (isset($ordersByDateAndStatus[$dateKey])) {
    $chartNewCounts[] = $ordersByDateAndStatus[$dateKey]['new'];
    $chartPendingCounts[] = $ordersByDateAndStatus[$dateKey]['pending'];
    $chartCompletedCounts[] = $ordersByDateAndStatus[$dateKey]['completed'];
  } else {
    $chartNewCounts[] = 0;
    $chartPendingCounts[] = 0;
    $chartCompletedCounts[] = 0;
  }
  
  $current = strtotime('+1 day', $current);
}

// Calculate average
$daysCount = count($chartDates);
$avgOrders = $daysCount > 0 ? ceil($totalOrders / $daysCount) : 0;

http_response_code(200);
echo json_encode([
  'success' => true,
  'chartDates' => $chartDates,
  'chartNewCounts' => $chartNewCounts,
  'chartPendingCounts' => $chartPendingCounts,
  'chartCompletedCounts' => $chartCompletedCounts,
  'totalOrders' => $totalOrders,
  'avgOrders' => $avgOrders,
  'newOrders' => $newOrders,
  'completedOrders' => $completedOrders,
  'pendingOrders' => $pendingOrders
]);
?>
