<?php
/**
 * Background worker to check domain availability
 * Run this script via cron every minute or use a process manager
 */

require_once '../config.php';

// Prevent browser access
if (php_sapi_name() !== 'cli' && !isset($_GET['manual_run'])) {
    http_response_code(403);
    die('This script can only be run from command line');
}

$maxBatchSize = 10;
$maxRunTime = 50; // seconds
$startTime = time();

echo "Starting domain availability worker...\n";

try {
    $pdo = getDB();

    // Find pending domains that need checking
    $stmt = $pdo->prepare("
        SELECT gd.id, gd.generation_id, gd.domain
        FROM generated_domains gd
        INNER JOIN generation_queue gq ON gd.generation_id = gq.generation_id
        WHERE gd.status = 'pending'
        AND gd.checked_at IS NULL
        ORDER BY gd.created_at ASC
        LIMIT ?
    ");
    $stmt->execute([$maxBatchSize]);
    $pendingDomains = $stmt->fetchAll();

    if (empty($pendingDomains)) {
        echo "No pending domains to check.\n";
        exit(0);
    }

    echo "Found " . count($pendingDomains) . " domains to check\n";

    foreach ($pendingDomains as $domainRecord) {
        if (time() - $startTime > $maxRunTime) {
            echo "Max run time reached, exiting\n";
            break;
        }

        $domain = $domainRecord['domain'];
        $generationId = $domainRecord['generation_id'];

        echo "Checking $domain... ";

        // Check cache first
        $cached = getCachedStatus($domain);
        if ($cached !== null) {
            echo "cached ($cached)\n";
            $status = $cached;
        } else {
            // Perform live check
            $status = performAvailabilityCheckWorker($domain);
            echo "$status\n";
        }

        // Update domain status
        $stmt = $pdo->prepare("
            UPDATE generated_domains
            SET status = ?, checked_at = NOW()
            WHERE generation_id = ? AND domain = ?
        ");
        $stmt->execute([$status, $generationId, $domain]);

        // Update generation status
        updateGenerationStatus($generationId);

        // Small delay to avoid rate limiting
        usleep(500000); // 0.5 seconds
    }

    echo "Worker completed successfully\n";

} catch (Exception $e) {
    error_log("Worker error: " . $e->getMessage());
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

function getCachedStatus($domain) {
    $pdo = getDB();

    $stmt = $pdo->prepare("
        SELECT status
        FROM domain_cache
        WHERE domain = ?
        AND checked_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
        LIMIT 1
    ");
    $stmt->execute([$domain, CACHE_DURATION]);
    $cached = $stmt->fetch();

    return $cached ? $cached['status'] : null;
}

function performAvailabilityCheckWorker($domain) {
    $pdo = getDB();

    try {
        $available = checkNamecheapAvailability($domain);
        $status = $available ? 'available' : 'taken';

        // Cache the result
        $stmt = $pdo->prepare("
            INSERT INTO domain_cache (domain, status, checked_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE status = ?, checked_at = NOW()
        ");
        $stmt->execute([$domain, $status, $status]);

        return $status;

    } catch (Exception $e) {
        error_log("Availability check error for $domain: " . $e->getMessage());

        // Cache the error
        $stmt = $pdo->prepare("
            INSERT INTO domain_cache (domain, status, checked_at)
            VALUES (?, 'error', NOW())
            ON DUPLICATE KEY UPDATE status = 'error', checked_at = NOW()
        ");
        $stmt->execute([$domain]);

        return 'error';
    }
}

function checkNamecheapAvailability($domain) {
    $params = [
        'ApiUser' => NAMECHEAP_API_USER,
        'ApiKey' => NAMECHEAP_API_KEY,
        'UserName' => NAMECHEAP_USERNAME,
        'ClientIp' => NAMECHEAP_CLIENT_IP,
        'Command' => 'namecheap.domains.check',
        'DomainList' => $domain
    ];

    $url = NAMECHEAP_API_URL . '?' . http_build_query($params);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception("Namecheap API error: HTTP $httpCode");
    }

    $xml = simplexml_load_string($response);

    if (!$xml) {
        throw new Exception("Invalid XML response");
    }

    if ((string)$xml->attributes()->Status !== 'OK') {
        $error = isset($xml->Errors->Error) ? (string)$xml->Errors->Error : 'Unknown error';
        throw new Exception("Namecheap API error: $error");
    }

    $domainCheck = $xml->CommandResponse->DomainCheckResult;
    $available = strtolower((string)$domainCheck->attributes()->Available) === 'true';

    return $available;
}

function updateGenerationStatus($generationId) {
    $pdo = getDB();

    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total,
               SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
        FROM generated_domains
        WHERE generation_id = ?
    ");
    $stmt->execute([$generationId]);
    $counts = $stmt->fetch();

    if ($counts['pending'] == 0) {
        $stmt = $pdo->prepare("
            UPDATE generation_queue
            SET status = 'complete', completed_at = NOW()
            WHERE generation_id = ?
        ");
        $stmt->execute([$generationId]);
    }
}
