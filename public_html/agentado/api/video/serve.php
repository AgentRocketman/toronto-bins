<?php
/**
 * Video proxy — serves generated videos through PHP to bypass Hostinger restrictions
 * GET /api/video/serve.php?id=prev_xxx
 */
$id = basename($_GET['id'] ?? '');
if (!$id || !preg_match('/^prev_[a-f0-9]{16}$/', $id)) {
    http_response_code(404);
    exit('Not found');
}

$file = __DIR__ . '/../../output/previews/' . $id . '.mp4';
if (!file_exists($file)) {
    http_response_code(404);
    exit('Not found');
}

$size = filesize($file);
$range = $_SERVER['HTTP_RANGE'] ?? null;

header('Content-Type: video/mp4');
header('Accept-Ranges: bytes');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=3600');

if ($range) {
    // Handle range request (for seeking in video)
    preg_match('/bytes=(\d+)-(\d*)/', $range, $matches);
    $start = intval($matches[1]);
    $end = !empty($matches[2]) ? intval($matches[2]) : $size - 1;
    $length = $end - $start + 1;

    header('Content-Length: ' . $length);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    http_response_code(206);

    $fp = fopen($file, 'rb');
    fseek($fp, $start);
    $buffer = 8192;
    while ($length > 0) {
        $read = min($buffer, $length);
        echo fread($fp, $read);
        $length -= $read;
    }
    fclose($fp);
} else {
    header('Content-Length: ' . $size);
    readfile($file);
}