<?php
// Proxy to serve cross-origin images with CORS headers
// Usage: /api/img-proxy.php?u=<url_encoded_url>

$url = $_GET['u'] ?? '';
if (!$url) { http_response_code(400); exit('Missing ?u='); }

// Only allow realtor.ca CDN URLs
if (!preg_match('#^https://cdn\.realtor\.ca/#i', $url)) {
    http_response_code(403);
    exit('Blocked URL');
}

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
]);
$data = curl_exec($ch);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || !$data) {
    http_response_code(502);
    exit('Failed to fetch image');
}

// Cache for 24 hours
header('Cache-Control: public, max-age=86400');
header('Access-Control-Allow-Origin: *');
header('Content-Type: ' . ($contentType ?: 'image/jpeg'));
header('Content-Length: ' . strlen($data));
echo $data;