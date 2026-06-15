<?php
/**
 * CurbIn — Verify Booking
 * Checks booking ID + email exist in Airtable before proceeding with cancellation
 */
require_once __DIR__ . '/config.php';
corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['found' => false, 'error' => 'POST required']); exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$bookingId = strtoupper(trim($body['bookingId'] ?? ''));
$email     = strtolower(trim($body['email'] ?? ''));

if (!$bookingId || !$email) {
    echo json_encode(['found' => false, 'error' => 'Missing fields']); exit;
}

$formula = "AND({Booking ID}='" . addslashes($bookingId) . "',LOWER({Email})='" . addslashes($email) . "')";
$result  = airtableRequest('GET', AIRTABLE_BOOKINGS, ['filterByFormula' => $formula, 'maxRecords' => 1]);

$records = $result['body']['records'] ?? [];

if (empty($records)) {
    echo json_encode(['found' => false, 'error' => 'No booking found with that ID and email. Please double-check and try again.']);
    exit;
}

$fields = $records[0]['fields'];
$status = $fields['Status'] ?? '';

if ($status === 'Cancelled') {
    echo json_encode(['found' => false, 'error' => 'This booking has already been cancelled.']);
    exit;
}

echo json_encode([
    'found'       => true,
    'billingType' => $fields['Billing Type'] ?? 'One-Time Charge',
    'status'      => $status,
]);
