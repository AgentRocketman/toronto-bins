<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$bin_pics_dir = __DIR__ . '/../bin-pics';
$bin_pics_exists = is_dir($bin_pics_dir);
$bin_pics_writable = $bin_pics_exists && is_writable($bin_pics_dir);

http_response_code(200);
echo json_encode([
    'status' => 'ok',
    'timestamp' => date('Y-m-d H:i:s'),
    'php_version' => phpversion(),
    'curl_enabled' => function_exists('curl_init'),
    'bin_pics_folder' => [
        'exists' => $bin_pics_exists,
        'writable' => $bin_pics_writable
    ]
]);
?>
