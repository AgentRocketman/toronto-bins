<?php
/**
 * Agentado tunnel config — dual-failover architecture.
 * 
 * PRIMARY: Preview/generate server (port 18900, agentado-preview-server.py)
 *   → Handles /agentado/api/preview.php and /agentado/api/generate.php
 *   → URL updated by tunnel-preview-keepalive.sh via tunnel-update.php?type=preview
 *   → Stored in tunnel-preview-url.txt
 * 
 * BACKUP: PHP server (port 9000, php -S)
 *   → Serves static files and API endpoints
 *   → URL updated by php-tunnel-keepalive.sh via tunnel-update.php?type=php
 *   → Stored in tunnel-url.txt
 * 
 * AGENTADO_TUNNEL: Primary URL  (preview server, always tried first)
 * AGENTADO_TUNNEL_BAK: Backup URL (PHP server, used if primary fails)
 */

$tunnelDir = __DIR__;

// Primary tunnel URL — preview/generate server (port 18900)
$tunnelFile = "$tunnelDir/tunnel-preview-url.txt";
if (file_exists($tunnelFile)) {
    $AGENTADO_TUNNEL = trim(file_get_contents($tunnelFile));
} else {
    // Fallback: try the old tunnel-url.txt (port 9000 PHP server)
    $fallbackFile = "$tunnelDir/tunnel-url.txt";
    if (file_exists($fallbackFile)) {
        $AGENTADO_TUNNEL = trim(file_get_contents($fallbackFile));
    } else {
        $AGENTADO_TUNNEL = 'https://answering-knowledge-housewives-convenience.trycloudflare.com';
    }
}

// Backup tunnel URL — PHP server (port 9000) as redundant failover
$tunnelFile2 = "$tunnelDir/tunnel-url.txt";
if (file_exists($tunnelFile2)) {
    $AGENTADO_TUNNEL_BAK = trim(file_get_contents($tunnelFile2));
} else {
    $AGENTADO_TUNNEL_BAK = null;
}

/**
 * Call the Docker container API with automatic tunnel failover.
 * Tries primary, then backup, returns the first successful response.
 * 
 * @param string $endpoint Path like '/agentado/api/preview.php'
 * @param array  $postData POST fields
 * @param int    $timeout  Request timeout in seconds
 * @param int    $connectTimeout connection timeout
 * @return array{response: string|null, code: int, error: string|null, used_backup: bool}
 */
function tunnelRequest($endpoint, $postData = [], $timeout = 90, $connectTimeout = 5) {
    global $AGENTADO_TUNNEL, $AGENTADO_TUNNEL_BAK;
    
    $urls = [$AGENTADO_TUNNEL];
    if ($AGENTADO_TUNNEL_BAK) {
        $urls[] = $AGENTADO_TUNNEL_BAK;
    }

    foreach ($urls as $i => $baseUrl) {
        $url = rtrim($baseUrl, '/') . $endpoint;
        $isBackup = ($i > 0);
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $timeout,
        ]);
        
        if (!empty($postData)) {
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $postData,
            ]);
        }
        
        // Health check: send a quick OPTIONS request first
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Tunnel-Try: ' . ($isBackup ? 'backup' : 'primary')]);
        
        $result = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        // curl_close is deprecated in PHP 8.5
        
        if ($code === 200 && $result) {
            return [
                'response' => $result,
                'code' => $code,
                'error' => null,
                'used_backup' => $isBackup,
            ];
        }
        
        // If primary failed, log and try backup
        if (!$isBackup) {
            error_log("Agentado tunnel: primary failed ($error, HTTP $code), trying backup…");
        }
    }
    
    // Both failed
    return [
        'response' => null,
        'code' => 0,
        'error' => 'All tunnels unreachable',
        'used_backup' => false,
    ];
}