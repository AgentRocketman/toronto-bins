<?php
require_once '../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    jsonResponse(['error' => 'Invalid JSON'], 400);
}

$description = isset($input['description']) ? sanitizeInput($input['description'], MAX_DESCRIPTION_LENGTH) : '';
$tld = isset($input['tld']) ? sanitizeInput($input['tld']) : 'com';

if (strlen($description) < MIN_DESCRIPTION_LENGTH) {
    jsonResponse(['error' => 'Description must be at least ' . MIN_DESCRIPTION_LENGTH . ' characters'], 400);
}

if (!in_array($tld, ['com', 'io', 'ai', 'co'])) {
    jsonResponse(['error' => 'Invalid TLD'], 400);
}

$sessionId = getSessionId();

if (!checkRateLimit($sessionId)) {
    jsonResponse(['error' => 'Please wait before generating again'], 429);
}

try {
    $domains = generateDomainNames($description);

    if (count($domains) < DOMAINS_PER_GENERATION) {
        throw new Exception('Failed to generate enough domain names');
    }

    $generationId = saveGeneration($sessionId, $description, $tld, $domains);

    queueAvailabilityChecks($generationId, $domains, $tld);

    jsonResponse([
        'success' => true,
        'generation_id' => $generationId,
        'domains' => $domains,
        'tld' => $tld
    ]);

} catch (Exception $e) {
    error_log("Generation error: " . $e->getMessage());
    jsonResponse(['error' => 'Failed to generate domains. Please try again.'], 500);
}

function generateDomainNames($description) {
    $prompt = "Generate exactly 10 creative, unique, and memorable domain names for a business described as: \"$description\"\n\n";
    $prompt .= "Requirements:\n";
    $prompt .= "- Each name should be 3-12 characters long\n";
    $prompt .= "- Names should be easy to spell and remember\n";
    $prompt .= "- Be creative and brandable\n";
    $prompt .= "- Avoid hyphens and numbers\n";
    $prompt .= "- For each domain, include a marketability rating from 1-10\n";
    $prompt .= "- Format: domainname|rating (e.g., 'brandify|9')\n";
    $prompt .= "- Return ONLY the domain names with ratings, one per line\n";
    $prompt .= "- Do not include any explanations or additional text\n\n";
    $prompt .= "Return exactly 10 names with ratings, each on a new line.";

    $data = [
        'model' => 'gpt-3.5-turbo',
        'messages' => [
            [
                'role' => 'system',
                'content' => 'You are a creative domain name generator. Generate only the requested domain names with marketability ratings without any additional text or formatting.'
            ],
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ],
        'temperature' => 0.9,
        'max_tokens' => 300
    ];

    $ch = curl_init(OPENAI_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENAI_API_KEY
        ],
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        error_log("OpenAI API error: HTTP $httpCode - $response");
        throw new Exception('AI service error');
    }

    $result = json_decode($response, true);

    if (!isset($result['choices'][0]['message']['content'])) {
        throw new Exception('Invalid AI response');
    }

    $content = trim($result['choices'][0]['message']['content']);
    $lines = array_filter(array_map('trim', explode("\n", $content)));
    $domains = [];

    foreach ($lines as $line) {
        $line = preg_replace('/^[\d\.\-\*\s]+/', '', $line);

        $parts = explode('|', $line);
        $domainName = isset($parts[0]) ? trim($parts[0]) : '';
        $rating = isset($parts[1]) ? intval(trim($parts[1])) : 0;

        $domainName = preg_replace('/\.(com|io|ai|co|net|org)$/i', '', $domainName);
        $domainName = strtolower($domainName);
        $domainName = preg_replace('/[^a-z0-9]/', '', $domainName);

        if (strlen($domainName) >= 3 && strlen($domainName) <= 15) {
            if ($rating < 1 || $rating > 10) {
                $rating = rand(5, 8);
            }
            $domains[] = [
                'name' => $domainName,
                'rating' => $rating
            ];
        }
    }

    if (count($domains) < DOMAINS_PER_GENERATION) {
        $fallbackDomains = generateFallbackDomains($domains, DOMAINS_PER_GENERATION - count($domains));
        $domains = array_merge($domains, $fallbackDomains);
    }

    return array_slice($domains, 0, DOMAINS_PER_GENERATION);
}

function generateFallbackDomains($existing, $count) {
    $prefixes = ['get', 'try', 'my', 'the', 'use', 'go', 'app', 'web'];
    $suffixes = ['ly', 'io', 'app', 'hub', 'lab', 'hq', 'pro'];
    $fallbacks = [];

    for ($i = 0; $i < $count; $i++) {
        $name = $prefixes[array_rand($prefixes)] . substr(md5(uniqid()), 0, 5);
        if (rand(0, 1)) {
            $name .= $suffixes[array_rand($suffixes)];
        }
        $fallbacks[] = [
            'name' => $name,
            'rating' => rand(4, 7)
        ];
    }

    return $fallbacks;
}

function saveGeneration($sessionId, $description, $tld, $domains) {
    $pdo = getDB();

    $generationId = bin2hex(random_bytes(16));

    $stmt = $pdo->prepare("
        INSERT INTO generation_queue (generation_id, session_id, description, tld, status, created_at)
        VALUES (?, ?, ?, ?, 'pending', NOW())
    ");
    $stmt->execute([$generationId, $sessionId, $description, $tld]);

    foreach ($domains as $domainData) {
        $domainName = is_array($domainData) ? $domainData['name'] : $domainData;
        $rating = is_array($domainData) ? $domainData['rating'] : 5;
        $fullDomain = "$domainName.$tld";

        $stmt = $pdo->prepare("
            INSERT INTO generated_domains (generation_id, domain, rating, status)
            VALUES (?, ?, ?, 'pending')
        ");
        $stmt->execute([$generationId, $fullDomain, $rating]);
    }

    return $generationId;
}

function queueAvailabilityChecks($generationId, $domains, $tld) {
    $pdo = getDB();

    foreach ($domains as $domainData) {
        $domainName = is_array($domainData) ? $domainData['name'] : $domainData;
        $fullDomain = "$domainName.$tld";

        $cached = checkCache($fullDomain);
        if ($cached !== null) {
            $stmt = $pdo->prepare("
                UPDATE generated_domains
                SET status = ?, checked_at = NOW()
                WHERE generation_id = ? AND domain = ?
            ");
            $stmt->execute([$cached, $generationId, $fullDomain]);
        }
    }

    updateGenerationStatus($generationId);
}

function checkCache($domain) {
    $pdo = getDB();

    $stmt = $pdo->prepare("
        SELECT status, checked_at
        FROM domain_cache
        WHERE domain = ?
        AND checked_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
        LIMIT 1
    ");
    $stmt->execute([$domain, CACHE_DURATION]);
    $cached = $stmt->fetch();

    if ($cached) {
        return $cached['status'];
    }

    performAvailabilityCheck($domain);

    $stmt->execute([$domain, CACHE_DURATION]);
    $cached = $stmt->fetch();

    return $cached ? $cached['status'] : null;
}

function performAvailabilityCheck($domain) {
    $pdo = getDB();

    try {
        $available = checkNamecheapAvailability($domain);
        $status = $available ? 'available' : 'taken';

        $stmt = $pdo->prepare("
            INSERT INTO domain_cache (domain, status, checked_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE status = ?, checked_at = NOW()
        ");
        $stmt->execute([$domain, $status, $status]);

        return $status;

    } catch (Exception $e) {
        error_log("Availability check error for $domain: " . $e->getMessage());

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
