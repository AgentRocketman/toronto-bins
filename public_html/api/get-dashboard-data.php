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
$todayTimestamp = strtotime(date('Y-m-d') . ' 00:00:00');

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

// Process records - categorize by order status based on Service Date
$ordersByDateAndStatus = [];
$totalOrders = 0;
$newOrders = 0;
$pendingOrders = 0;
$completedOrders = 0;
$cancelledOrders = 0;

foreach ($records as $record) {
  $fields = $record['fields'] ?? [];
  $serviceDateStr = $fields['Service Date'] ?? null;
  $status = $fields['Status'] ?? null;

  if ($serviceDateStr) {
    // Parse the service date
    $serviceDateTimestamp = strtotime($serviceDateStr . ' 00:00:00');
    
    // Check if date is within range
    if ($serviceDateTimestamp >= $fromTimestamp && $serviceDateTimestamp <= $toTimestamp) {
      $dateKey = date('Y-m-d', $serviceDateTimestamp);
      
      // Initialize if not exists
      if (!isset($ordersByDateAndStatus[$dateKey])) {
        $ordersByDateAndStatus[$dateKey] = [
          'new' => 0,
          'pending' => 0,
          'completed' => 0,
          'cancelled' => 0
        ];
      }
      
      $totalOrders++;
      
      // Determine order category based on status and service date
      if ($status === 'Completed') {
        $ordersByDateAndStatus[$dateKey]['completed']++;
        $completedOrders++;
      } else if ($status === 'Cancelled' || $status === 'Refunded') {
        // Count cancelled/refunded orders
        $ordersByDateAndStatus[$dateKey]['cancelled']++;
        $cancelledOrders++;
      } else {
        // Not completed, so check if it's pending (today or past) or new (future)
        if ($serviceDateTimestamp > $todayTimestamp) {
          // Service date is in the future = New
          $ordersByDateAndStatus[$dateKey]['new']++;
          $newOrders++;
        } else {
          // Service date is today or in the past and not completed = Pending
          $ordersByDateAndStatus[$dateKey]['pending']++;
          $pendingOrders++;
        }
      }
    }
  }
}

// Calculate workload dates (for Workload tab)
// Group by service date (not work date)
$workloadByDateAndStatus = [];

foreach ($records as $record) {
  $fields = $record['fields'] ?? [];
  $serviceDateStr = $fields['Service Date'] ?? null;
  $status = $fields['Status'] ?? null;
  
  // Skip cancelled/refunded orders for workload
  if ($status === 'Cancelled' || $status === 'Refunded') {
    continue;
  }

  if ($serviceDateStr) {
    $serviceDateTimestamp = strtotime($serviceDateStr . ' 00:00:00');
    
    // Check if service date is within range
    if ($serviceDateTimestamp >= $fromTimestamp && $serviceDateTimestamp <= $toTimestamp) {
      $serviceDateKey = date('Y-m-d', $serviceDateTimestamp);
      
      // Initialize if not exists
      if (!isset($workloadByDateAndStatus[$serviceDateKey])) {
        $workloadByDateAndStatus[$serviceDateKey] = [
          'new' => 0,
          'pending' => 0,
          'completed' => 0
        ];
      }
      
      // Determine order category based on status
      if ($status === 'Completed') {
        $workloadByDateAndStatus[$serviceDateKey]['completed']++;
      } else {
        // Check if this is new or pending based on service date
        if ($serviceDateTimestamp > $todayTimestamp) {
          $workloadByDateAndStatus[$serviceDateKey]['new']++;
        } else {
          $workloadByDateAndStatus[$serviceDateKey]['pending']++;
        }
      }
    }
  }
}

// Fetch bookings and count by Created At date
$bookingsByDate = [];
$BOOKINGS_TABLE = 'Bookings';

$bookingsUrl = "https://api.airtable.com/v0/$AIRTABLE_BASE_ID/$BOOKINGS_TABLE";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $bookingsUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
  'Authorization: Bearer ' . $AIRTABLE_API_KEY
]);

$bookingsResponse = curl_exec($ch);
$bookingsHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($bookingsHttpCode === 200) {
  $bookingsData = json_decode($bookingsResponse, true);
  $bookingsRecords = $bookingsData['records'] ?? [];
  
  foreach ($bookingsRecords as $record) {
    $fields = $record['fields'] ?? [];
    $createdAtStr = $fields['Created At'] ?? null;
    
    // Include ALL bookings regardless of status
    if ($createdAtStr) {
      // Parse created date (format: YYYY-MM-DD or YYYY-MM-DDTHH:MM:SS)
      $createdTimestamp = strtotime(substr($createdAtStr, 0, 10) . ' 00:00:00');
      
      // Check if created date is within range
      if ($createdTimestamp >= $fromTimestamp && $createdTimestamp <= $toTimestamp) {
        $dateKey = date('Y-m-d', $createdTimestamp);
        
        if (!isset($bookingsByDate[$dateKey])) {
          $bookingsByDate[$dateKey] = 0;
        }
        
        $bookingsByDate[$dateKey]++;
      }
    }
  }
}

// Generate chart data for date range
$chartDates = [];
$chartNewCounts = [];
$chartPendingCounts = [];
$chartCompletedCounts = [];
$chartCancelledCounts = [];
$workloadNewCounts = [];
$workloadPendingCounts = [];
$workloadCompletedCounts = [];
$bookingCounts = [];
$current = $fromTimestamp;

while ($current <= $toTimestamp) {
  $dateKey = date('Y-m-d', $current);
  $chartDates[] = date('M d', $current);
  
  // Orders tab data
  if (isset($ordersByDateAndStatus[$dateKey])) {
    $chartNewCounts[] = $ordersByDateAndStatus[$dateKey]['new'];
    $chartPendingCounts[] = $ordersByDateAndStatus[$dateKey]['pending'];
    $chartCompletedCounts[] = $ordersByDateAndStatus[$dateKey]['completed'];
    $chartCancelledCounts[] = $ordersByDateAndStatus[$dateKey]['cancelled'];
  } else {
    $chartNewCounts[] = 0;
    $chartPendingCounts[] = 0;
    $chartCompletedCounts[] = 0;
    $chartCancelledCounts[] = 0;
  }
  
  // Workload tab data (no cancelled)
  if (isset($workloadByDateAndStatus[$dateKey])) {
    $workloadNewCounts[] = $workloadByDateAndStatus[$dateKey]['new'];
    $workloadPendingCounts[] = $workloadByDateAndStatus[$dateKey]['pending'];
    $workloadCompletedCounts[] = $workloadByDateAndStatus[$dateKey]['completed'];
  } else {
    $workloadNewCounts[] = 0;
    $workloadPendingCounts[] = 0;
    $workloadCompletedCounts[] = 0;
  }
  
  // Bookings tab data (count by created date)
  if (isset($bookingsByDate[$dateKey])) {
    $bookingCounts[] = $bookingsByDate[$dateKey];
  } else {
    $bookingCounts[] = 0;
  }
  
  $current = strtotime('+1 day', $current);
}

// Calculate revenue by booking creation date (from Bookings table)
$revenueByDate = [];

if ($bookingsHttpCode === 200) {
  foreach ($bookingsRecords as $record) {
    $fields = $record['fields'] ?? [];
    $createdAtStr = $fields['Created At'] ?? null;
    $totalPrice = $fields['Amount'] ?? 0;
    
    if ($createdAtStr) {
      // Parse created date (format: YYYY-MM-DD or YYYY-MM-DDTHH:MM:SS)
      $createdTimestamp = strtotime(substr($createdAtStr, 0, 10) . ' 00:00:00');
      
      // Check if created date is within range
      if ($createdTimestamp >= $fromTimestamp && $createdTimestamp <= $toTimestamp) {
        $dateKey = date('Y-m-d', $createdTimestamp);
        
        if (!isset($revenueByDate[$dateKey])) {
          $revenueByDate[$dateKey] = 0;
        }
        
        $revenueByDate[$dateKey] += floatval($totalPrice);
      }
    }
  }
}

// Build revenue counts for chart
$revenueByDateCounts = [];
$current = $fromTimestamp;

while ($current <= $toTimestamp) {
  $dateKey = date('Y-m-d', $current);
  
  if (isset($revenueByDate[$dateKey])) {
    $revenueByDateCounts[] = round($revenueByDate[$dateKey], 2);
  } else {
    $revenueByDateCounts[] = 0;
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
  'chartCancelledCounts' => $chartCancelledCounts,
  'workloadNewCounts' => $workloadNewCounts,
  'workloadPendingCounts' => $workloadPendingCounts,
  'workloadCompletedCounts' => $workloadCompletedCounts,
  'bookingCounts' => $bookingCounts,
  'revenueByDateCounts' => $revenueByDateCounts,
  'totalOrders' => $totalOrders,
  'avgOrders' => $avgOrders,
  'newOrders' => $newOrders,
  'completedOrders' => $completedOrders,
  'pendingOrders' => $pendingOrders,
  'cancelledOrders' => $cancelledOrders
]);
?>
