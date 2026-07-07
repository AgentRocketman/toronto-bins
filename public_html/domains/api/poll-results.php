<?php
require_once '../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$generationId = isset($_GET['generation_id']) ? sanitizeInput($_GET['generation_id']) : '';

if (empty($generationId)) {
    jsonResponse(['error' => 'Missing generation_id'], 400);
}

$sessionId = getSessionId();

try {
    $pdo = getDB();

    $stmt = $pdo->prepare("
        SELECT status, completed_at
        FROM generation_queue
        WHERE generation_id = ? AND session_id = ?
    ");
    $stmt->execute([$generationId, $sessionId]);
    $generation = $stmt->fetch();

    if (!$generation) {
        jsonResponse(['error' => 'Generation not found'], 404);
    }

    $stmt = $pdo->prepare("
        SELECT domain, status
        FROM generated_domains
        WHERE generation_id = ?
        ORDER BY id
    ");
    $stmt->execute([$generationId]);
    $domains = $stmt->fetchAll();

    // Retry any pending domains that may have failed initial check
    foreach ($domains as $domain) {
        if ($domain['status'] === 'pending') {
            try {
                $cached = checkCacheOnly($domain['domain']);
                if ($cached === null) {
                    // Not in cache - perform check in background
                    // Use ignore_user_abort to continue after response sent
                    $status = performAvailabilityCheckAsync($domain['domain'], $generationId);
                    if ($status !== null) {
                        $domain['status'] = $status;
                    }
                } else {
                    // Update from cache
                    $stmt2 = $pdo->prepare("
                        UPDATE generated_domains
                        SET status = ?, checked_at = NOW()
                        WHERE generation_id = ? AND domain = ?
                    ");
                    $stmt2->execute([$cached, $generationId, $domain['domain']]);
                    $domain['status'] = $cached;
                }
            } catch (Exception $e) {
                error_log("Poll retry error for {$domain['domain']}: " . $e->getMessage());
            }
        }
    }

    $results = array_map(function($domain) {
        return [
            'domain' => $domain['domain'],
            'status' => $domain['status']
        ];
    }, $domains);

    // Update generation status
    updateGenerationStatusIfComplete($generationId, $results);

    jsonResponse([
        'generation_id' => $generationId,
        'status' => $generation['status'],
        'results' => $results
    ]);

} catch (Exception $e) {
    error_log("Poll error: " . $e->getMessage());
    jsonResponse(['error' => 'Failed to retrieve results'], 500);
}

function checkCacheOnly($domain) {
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

function performAvailabilityCheckAsync($domain, $generationId) {
    $pdo = getDB();

    try {
        require_once '../config.php';

        // Check Namecheap API
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

        if (!$xml || (string)$xml->attributes()->Status !== 'OK') {
            throw new Exception("Invalid API response");
        }

        $domainCheck = $xml->CommandResponse->DomainCheckResult;
        $available = strtolower((string)$domainCheck->attributes()->Available) === 'true';
        $status = $available ? 'available' : 'taken';

        // Cache the result
        $stmt = $pdo->prepare("
            INSERT INTO domain_cache (domain, status, checked_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE status = ?, checked_at = NOW()
        ");
        $stmt->execute([$domain, $status, $status]);

        // Update the domain status
        $stmt = $pdo->prepare("
            UPDATE generated_domains
            SET status = ?, checked_at = NOW()
            WHERE generation_id = ? AND domain = ?
        ");
        $stmt->execute([$status, $generationId, $domain]);

        return $status;

    } catch (Exception $e) {
        error_log("Availability check error for $domain: " . $e->getMessage());

        // Mark as error in cache
        $stmt = $pdo->prepare("
            INSERT INTO domain_cache (domain, status, checked_at)
            VALUES (?, 'error', NOW())
            ON DUPLICATE KEY UPDATE status = 'error', checked_at = NOW()
        ");
        $stmt->execute([$domain]);

        // Update domain status
        $stmt = $pdo->prepare("
            UPDATE generated_domains
            SET status = 'error', checked_at = NOW()
            WHERE generation_id = ? AND domain = ?
        ");
        $stmt->execute([$generationId, $domain]);

        return 'error';
    }
}

function updateGenerationStatusIfComplete($generationId, $results) {
    $pdo = getDB();

    $allComplete = true;
    foreach ($results as $result) {
        if ($result['status'] === 'pending') {
            $allComplete = false;
            break;
        }
    }

    if ($allComplete) {
        $stmt = $pdo->prepare("
            UPDATE generation_queue
            SET status = 'complete', completed_at = NOW()
            WHERE generation_id = ?
        ");
        $stmt->execute([$generationId]);
    }
}
