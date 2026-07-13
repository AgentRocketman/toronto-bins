<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$url = $input['url'] ?? '';

if (!$url) {
    http_response_code(400);
    echo json_encode(['error' => 'No URL provided']);
    exit;
}

if (!preg_match('#^https?://(www\.)?realtor\.ca/real-estate/\d+#i', $url)) {
    http_response_code(400);
    echo json_encode(['error' => 'Must be a realtor.ca listing URL']);
    exit;
}

// ── Oxylabs API ──────────────────────────────────────────────
$oxKey = defined('OXYLABS_API_KEY') ? OXYLABS_API_KEY : '';
if (!$oxKey) {
    http_response_code(500);
    echo json_encode(['error' => 'Oxylabs API key not configured.']);
    exit;
}

define('OX_BASE', 'https://api-aistudio.oxylabs.io');
define('OX_CREATE', OX_BASE . '/scrape');
define('OX_POLL', OX_BASE . '/scrape/run/data');
define('OX_MAX_POLLS', 20);
define('OX_POLL_MS', 2500000); // 2.5s

/**
 * Call Oxylabs scrape API — two-step: create run, then poll for results.
 */
function oxylabsScrape(string $url): string {
    $headers = [
        'Content-Type: application/json',
        'x-api-key: ' . (defined('OXYLABS_API_KEY') ? OXYLABS_API_KEY : ''),
    ];

    // Step 1: Create scrape run
    $ch = curl_init(OX_CREATE);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['url' => $url]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) throw new Exception("Oxylabs create: $err");
    $data = json_decode($res, true);
    if ($code >= 400 || isset($data['error'])) {
        throw new Exception("Oxylabs create error ($code): " . ($data['message'] ?? substr($res, 0, 200)));
    }
    $runId = $data['run_id'] ?? null;
    if (!$runId) throw new Exception('No run_id from Oxylabs. Response: ' . substr($res, 0, 300));

    // Step 2: Poll for results
    for ($i = 0; $i < OX_MAX_POLLS; $i++) {
        usleep(OX_POLL_MS);
        $pollUrl = OX_POLL . '?run_id=' . urlencode($runId);
        $ch = curl_init($pollUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $pollRes = curl_exec($ch);
        $pollCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($pollCode >= 400) continue; // transient

        $pollData = json_decode($pollRes, true);
        if (!$pollData) continue;

        $status = strtolower($pollData['status'] ?? '');
        if ($status === 'failed' || $status === 'error') {
            throw new Exception('Oxylabs scrape run failed');
        }
        if ($status === 'completed' || $status === 'done') {
            $content = $pollData['data'] ?? '';
            if (is_array($content)) $content = json_encode($content);
            return (string) $content;
        }
    }
    throw new Exception('Oxylabs scrape timed out after ' . OX_MAX_POLLS . ' polls');
}

/**
 * Parse realtor.ca listing markdown and extract structured data.
 */
function parseMarkdown(string $md, string $url): array {
    $result = [
        'images' => [],
        'title' => '',
        'address' => '',
        'price' => '',
        'beds' => '',
        'baths' => '',
        'description' => '',
        'agentName' => '',
        'agentRole' => '',
        'agentPhone' => '',
        'agentWebsite' => '',
        'brokerageName' => '',
        'brokerageAddr' => '',
    ];

    // ── Images: cdn.realtor.ca highres ──
    preg_match_all('#https://cdn\.realtor\.ca/listings/[^\s]+?/highres/\d+/[^\s]+?\.jpg#i', $md, $imgMatches);
    $seen = [];
    foreach ($imgMatches[0] as $img) {
        $img = rtrim($img, ')'); // strip trailing markdown paren
        if (!isset($seen[$img])) {
            $seen[$img] = true;
            $result['images'][] = $img;
        }
    }

    // ── Title: first "# " heading IS the full address ──
    if (preg_match('/^#\s+(.+)/m', $md, $tm)) {
        $result['title'] = trim($tm[1]);
        $result['address'] = $result['title'];
    }

    // Alternate: "For sale:" title in <title> tag
    if (empty($result['title']) && preg_match('/^For sale:\s*(.+)/m', $md, $tm2)) {
        $result['title'] = trim(preg_replace('/\s*\|\s*REALTOR\.ca\s*$/i', '', $tm2[1]));
        $result['address'] = $result['title'];
    }

    // ── Price ──
    if (preg_match('/\$[\d,]+/', $md, $pm)) {
        $result['price'] = $pm[0];
    }

    // ── Beds / Baths ──
    if (preg_match('/(\d+)\s*Bedrooms?/i', $md, $bd)) {
        $result['beds'] = $bd[1];
    } elseif (preg_match('/(\d+)\s+Bed\b/i', $md, $bd)) {
        $result['beds'] = $bd[1];
    }
    if (preg_match('/(\d+)\s*Bathrooms?/i', $md, $bt)) {
        $result['baths'] = $bt[1];
    } elseif (preg_match('/(\d+)\s+Bath\b/i', $md, $bt)) {
        $result['baths'] = $bt[1];
    }

    // ── Description: block after "## Listing Description" until next "## " ──
    if (preg_match('/## Listing Description\s*\n+(.+?)(?=\n##\s)/s', $md, $dm)) {
        $result['description'] = trim(preg_replace('/\s+/', ' ', $dm[1]));
    }

    // ── Agent info: markdown link pattern [Name\nRole](/agent/...) ──
    // The agent section appears at the bottom, followed by phone + website
    if (preg_match('/\[([^\]]+)\]\((\/agent\/\d+\/[^\)]+)\)/', $md, $agentMd)) {
        $agentText = trim($agentMd[1]);
        // Split by newline — first line is name, second is role
        $agentLines = preg_split('/\s*\n\s*/', $agentText);
        if (count($agentLines) >= 2) {
            $result['agentName'] = trim($agentLines[0]);
            $result['agentRole'] = trim($agentLines[1]);
        } elseif (count($agentLines) === 1) {
            $result['agentName'] = trim($agentLines[0]);
        }
        // Normalize ALL-CAPS names to title case
        if ($result['agentName'] && preg_match('/^[A-Z]{2,}(?:\s+[A-Z]{2,})+$/', $result['agentName'])) {
            $result['agentName'] = mb_convert_case(mb_strtolower($result['agentName']), MB_CASE_TITLE);
        }
    }

    // ── Agent phone: after the agent link block ──
    if (preg_match('/\[.*?\]\(\/agent\/\d+[^\)]+\)\s*\n+\s*(\d{3}-\d{3}-\d{4})/', $md, $phoneMd)) {
        $result['agentPhone'] = $phoneMd[1];
    } elseif (preg_match('/(\d{3}-\d{3}-\d{4})/', $md, $pm1)) {
        // Generic fallback — just grab any phone
        $result['agentPhone'] = $pm1[1];
    }

    // ── Agent website: REALTOR® Website link ──
    if (preg_match('/\[R?EALTOR®?\s*Website\]\((https?:\/\/[^\)]+)\)/i', $md, $wm)) {
        $result['agentWebsite'] = $wm[1];
    }

    // ── Brokerage: nested markdown link near /office/firm/ ──
    // Format: [![LOGO](img_url)\n\nNAME\n\nBrokerage\n\nADDR](/office/firm/...)
    if (preg_match('/\/office\/firm\/\d+\/[^)\s]+/', $md, $firmMatch, PREG_OFFSET_CAPTURE)) {
        $firmPos = $firmMatch[0][1];
        // Backtrack to the OUTER [ of the markdown link
        // strrpos finds the LAST [ — but that's the inner ![, not the outer one.
        // Keep walking backward past any [ that follows a !
        $searchEnd = $firmPos;
        $beforePos = false;
        while ($searchEnd > 0) {
            $candidate = strrpos(substr($md, 0, $searchEnd), '[');
            if ($candidate === false) break;
            // Is this a [! grouping (image inside link)? If so, skip to find the outer [
            if ($candidate > 0 && substr($md, $candidate - 1, 1) === '!') {
                $searchEnd = $candidate;
                continue;
            }
            $beforePos = $candidate;
            break;
        }
        // The outer link closes after the /office/firm/ URL
        $afterPos = strpos($md, ')', $firmPos);
        if ($beforePos !== false && $afterPos !== false) {
            // Extract text between outer [ and ] (right before /office/firm/)
            $inner = substr($md, $beforePos + 1, $firmPos - $beforePos - 1);
            // Strip ![alt](...) image markdown from extracted inner text
            $imgStart = strpos($inner, '![');
            if ($imgStart !== false) {
                $imgClose = strpos($inner, ')', $imgStart);
                if ($imgClose !== false) {
                    $inner = substr_replace($inner, '', $imgStart, $imgClose - $imgStart + 1);
                }
            }
            // Clean up stray quotes and URL fragments
            $inner = preg_replace('/["\]]\s*\(/', '', $inner);
            $inner = str_replace('"', '', $inner);
            $lines = array_values(array_filter(array_map('trim', explode("\n", $inner)), function($l) {
                return $l !== '' && !preg_match('/^(?:logo|\.jpg|\.png)/i', $l);
            }));
            if (count($lines) >= 1) {
                $result['brokerageName'] = $lines[0];
            }
            // Address: everything after "Brokerage" label
            $addrParts = [];
            $afterLabel = false;
            foreach ($lines as $line) {
                if (strcasecmp($line, 'Brokerage') === 0) {
                    $afterLabel = true;
                    continue;
                }
                if ($afterLabel && $line !== '') {
                    $addrParts[] = $line;
                }
            }
            $result['brokerageAddr'] = implode(', ', $addrParts);
        }
    }

    return $result;
}

// ═══════════════════════════════════════════════════════════
// Main
// ═══════════════════════════════════════════════════════════
try {
    $markdown = oxylabsScrape($url);
    $result = parseMarkdown($markdown, $url);

    if (empty($result['images'])) throw new Exception('No images found.');

    $photos = array_slice($result['images'], 0, 33);
    $proxyBase = 'https://agentrocketman.com/agentado/api/img-proxy.php?u=';
    $photos = array_map(function($u) use ($proxyBase) {
        return $proxyBase . urlencode($u);
    }, $photos);

    echo json_encode([
        'success' => true,
        'title' => $result['title'],
        'address' => $result['address'],
        'price' => $result['price'],
        'beds' => $result['beds'],
        'baths' => $result['baths'],
        'description' => $result['description'],
        'agent' => [
            'name' => $result['agentName'],
            'role' => $result['agentRole'],
            'phone' => $result['agentPhone'],
            'website' => $result['agentWebsite'],
        ],
        'brokerage' => [
            'name' => $result['brokerageName'],
            'address' => $result['brokerageAddr'],
        ],
        'count' => count($photos),
        'photos' => $photos,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}