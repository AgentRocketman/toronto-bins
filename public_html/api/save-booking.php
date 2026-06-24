<?php
/**
 * GetMyBin — Save Booking (with optional bin placement data)
 *
 * Writes a booking record to the Airtable Bookings table, including the
 * customer's saved Street View POV + dropped bin pin (if provided).
 *
 * Expected payload (JSON):
 * {
 *   bookingId: "BK-12345",
 *   customerName, customerEmail, customerPhone,
 *   address, serviceType, frequency,
 *   subtotal, hstAmount, totalWithTax,
 *   selectedDates: ["2026-06-22", ...],
 *   isNightZone: false,
 *   binPlacement: {
 *     pano: "CAoSLEFGMVFp...",
 *     pov: { heading, pitch, zoom },
 *     cameraLatLng: { lat, lng },
 *     binLatLng: { lat, lng },
 *     hasPin: true
 *   }
 * }
 */
require_once __DIR__ . '/config.php';
corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

$AIRTABLE_TOKEN = 'patxbDkv88pOMXmYx.c7e5fd7974954e3a674087090835d11dd69504f3912f0ef86c3c59f1e91febdd';
$AIRTABLE_BASE  = 'apptYNRJTXwItvied';
$BOOKINGS_TABLE = 'tblKMhGnYjsH0z7Lj';

$bp = $body['binPlacement'] ?? null;

// Build Airtable fields. The Bookings table may not yet have these columns —
// if the API call fails because of unknown fields, we'll retry with a smaller set.
$fields = [
    'Booking ID'      => $body['bookingId']     ?? '',
    'Customer Name'   => $body['customerName']  ?? '',
    'Email'           => $body['customerEmail'] ?? '',
    'Phone'           => $body['customerPhone'] ?? '',
    'Address'         => $body['address']       ?? '',
    'Service Type'    => $body['serviceType']   ?? '',
    'Frequency'       => $body['frequency']     ?? '',
    'Subtotal'        => (float)($body['subtotal']     ?? 0),
    'HST'             => (float)($body['hstAmount']    ?? 0),
    'Total'           => (float)($body['totalWithTax'] ?? 0),
    'Selected Dates'  => is_array($body['selectedDates'] ?? null) ? implode(', ', $body['selectedDates']) : '',
    'Night Zone'      => !empty($body['isNightZone']),
];

if ($bp && is_array($bp)) {
    if (!empty($bp['pano']))  $fields['Bin Pano']      = (string)$bp['pano'];
    if (!empty($bp['pov']))   {
        $fields['Bin POV Heading'] = (float)($bp['pov']['heading'] ?? 0);
        $fields['Bin POV Pitch']   = (float)($bp['pov']['pitch']   ?? 0);
        $fields['Bin POV Zoom']    = (float)($bp['pov']['zoom']    ?? 0);
    }
    if (!empty($bp['binLatLng'])) {
        $fields['Bin Lat'] = (float)($bp['binLatLng']['lat'] ?? 0);
        $fields['Bin Lng'] = (float)($bp['binLatLng']['lng'] ?? 0);
    }
    if (!empty($bp['cameraLatLng'])) {
        $fields['Camera Lat'] = (float)($bp['cameraLatLng']['lat'] ?? 0);
        $fields['Camera Lng'] = (float)($bp['cameraLatLng']['lng'] ?? 0);
    }
    $fields['Has Bin Pin'] = !empty($bp['hasPin']);
}

// Which bins to physically roll out (schedule auto-detect + customer override).
// Optional "rich" fields — if the columns don't exist yet the fallback below drops
// them (they're intentionally NOT in the legacy whitelist) so the booking still saves.
$binsByDate    = $body['binsByDate']    ?? null;
$binsRecurring = $body['binsRecurring'] ?? null;

function binsRollSummary($binsByDate, $binsRecurring) {
    $L = ['g'=>'Green','b'=>'Garbage','r'=>'Recycling','y'=>'Yard','c'=>'Christmas Tree'];
    $labels = function ($obj) use ($L) {
        $out = [];
        foreach (['g','b','r','y','c'] as $k) { if (!empty($obj[$k])) $out[] = $L[$k]; }
        return $out;
    };
    if (is_array($binsRecurring)) {
        $l = $labels($binsRecurring);
        return $l ? implode(', ', $l) . ' (weekly)' : '';
    }
    if (is_array($binsByDate) && count($binsByDate)) {
        $dates = array_keys($binsByDate);
        sort($dates);
        if (count($dates) === 1) return implode(', ', $labels($binsByDate[$dates[0]]));
        $parts = [];
        foreach ($dates as $d) { $parts[] = $d . ': ' . implode(', ', $labels($binsByDate[$d])); }
        return implode(' | ', $parts);
    }
    return '';
}

$binsToRoll = binsRollSummary($binsByDate, $binsRecurring);
if ($binsToRoll !== '') $fields['Bins To Roll'] = $binsToRoll;
$binsJSON = is_array($binsRecurring)
    ? json_encode($binsRecurring)
    : ((is_array($binsByDate) && count($binsByDate)) ? json_encode($binsByDate) : '');
if ($binsJSON !== '') $fields['Bins JSON'] = $binsJSON;

function airtableCreate($baseId, $tableId, $token, $fields) {
    $url = "https://api.airtable.com/v0/$baseId/$tableId";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['fields' => $fields, 'typecast' => true]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode($response, true), 'err' => $err];
}

$result = airtableCreate($AIRTABLE_BASE, $BOOKINGS_TABLE, $AIRTABLE_TOKEN, $fields);

// If the table doesn't have some of the bin-placement columns yet, retry with only
// the legacy fields so we don't lose the booking record.
if ($result['code'] >= 400) {
    $msg = $result['body']['error']['message'] ?? '';
    if (stripos($msg, 'UNKNOWN_FIELD_NAME') !== false || stripos($msg, 'unknown field') !== false) {
        $legacy = array_intersect_key($fields, array_flip([
            'Booking ID', 'Customer Name', 'Email', 'Phone', 'Address',
            'Service Type', 'Frequency', 'Subtotal', 'HST', 'Total',
            'Selected Dates', 'Night Zone'
        ]));
        $result = airtableCreate($AIRTABLE_BASE, $BOOKINGS_TABLE, $AIRTABLE_TOKEN, $legacy);
        if ($result['code'] >= 400) {
            echo json_encode([
                'success' => false,
                'error'   => 'Airtable error after fallback: ' . ($result['body']['error']['message'] ?? 'unknown'),
            ]);
            exit;
        }
        echo json_encode([
            'success'  => true,
            'recordId' => $result['body']['id'] ?? null,
            'warning'  => 'Bin placement fields were not saved (add Bin Pano/POV/Lat/Lng columns to Bookings table).',
        ]);
        exit;
    }
    echo json_encode([
        'success' => false,
        'error'   => $result['body']['error']['message'] ?? 'Airtable error',
    ]);
    exit;
}

echo json_encode([
    'success'  => true,
    'recordId' => $result['body']['id'] ?? null,
]);
