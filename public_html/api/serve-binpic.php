<?php
/**
 * Serves bin-pics images from external storage (outside public_html).
 * Proxied via .htaccess rewrite so existing URLs like /bin-pics/abc.jpg still work.
 * Images live in /data/.openclaw/workspace/bin-pics-data/ — survives deploys.
 */

$file = $_GET['f'] ?? '';
if (empty($file)) {
    http_response_code(400);
    exit('Missing file');
}

// External storage: goes up from public_html/ to hosting account root
// Docker: /data/.openclaw/workspace/bin-pics-data/
// Hostinger: /home/uXXXXXX/bin-pics-data/
$externalRoot = dirname(__DIR__, 2) . '/bin-pics-data';
$safeName = basename($file); // strip any path traversal
$realPath = realpath($externalRoot . '/' . $safeName);

// Security: must be inside our external dir
if (!$realPath || strpos($realPath, realpath($externalRoot)) !== 0) {
    http_response_code(404);
    exit('Not found');
}

if (!file_exists($realPath)) {
    http_response_code(404);
    exit('Not found');
}

// Determine mime type
$ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
$mimeMap = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
];
$mime = $mimeMap[$ext] ?? mime_content_type($realPath);

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($realPath));
header('Cache-Control: public, max-age=86400, immutable');
header('X-Content-Type-Options: nosniff');
readfile($realPath);
