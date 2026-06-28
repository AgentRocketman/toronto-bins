# TESTER OUTPUT — Tester-1

# 🧪 TEST SUITE SPECIFICATION: URL Shortener

**Testing Against:** Architect's design (index.html → api/shorten.php → data/urls.json + redirect.php flows)

---

## 🧪 TEST SUITE OUTLINE

**Total Cases: 32**

- **A. CORE FUNCTIONALITY:** 12 cases
- **B. EDGE CASES:** 8 cases  
- **C. ERROR HANDLING:** 5 cases
- **D. SECURITY:** 5 cases
- **E. PERFORMANCE/CONCURRENCY:** 2 cases

---

## A. CORE FUNCTIONALITY (12 cases)

### TC-A01: Basic URL Shortening
**Steps:** POST valid URL (https://example.com/test) to /api/shorten.php  
**Expected:** Returns 200, JSON with `shortUrl` field, 6-char code in `[a-zA-Z0-9]`, entry in urls.json  
**Pass Criteria:** Response matches schema, urls.json contains new entry with correct timestamp

### TC-A02: Short URL Redirect
**Steps:** Visit generated short URL (e.g., /aB3xQ9)  
**Expected:** HTTP 302 redirect to original URL, stats.json count increments to 1  
**Pass Criteria:** Browser redirects correctly, stats.json shows `{"aB3xQ9": {"count": 1}}`

### TC-A03: Custom Alias Creation
**Steps:** POST URL with `customAlias: "promo2024"` (valid format)  
**Expected:** Returns short URL with custom code, urls.json shows `customAlias: true`  
**Pass Criteria:** Can access via /promo2024, urls.json field matches

### TC-A04: QR Code Generation
**Steps:** Shorten URL, verify response contains `qrCode` field  
**Expected:** Base64-encoded PNG data, decodes to 200x200px image, contains correct short URL  
**Pass Criteria:** QR decode (manual or library) returns matching short URL

### TC-A05: Analytics Data Retrieval
**Steps:** GET /api/stats.php after creating 3 short URLs  
**Expected:** Returns JSON array with 3 objects, each containing {code, url, created, count, lastAccess}  
**Pass Criteria:** Array length = 3, all fields present, sorted by creation date descending

### TC-A06: Click Count Increment
**Steps:** Visit short URL 5 times, check stats.json  
**Expected:** Count increments from 0→5, lastAccess updates to most recent timestamp  
**Pass Criteria:** stats.json shows `"count": 5`, lastAccess within 1 second of final click

### TC-A07: Multiple URLs from Same IP
**Steps:** Create 3 different short URLs from same IP within 10 minutes  
**Expected:** All succeed (under rate limit), 3 entries in urls.json  
**Pass Criteria:** No 429 errors, 3 distinct short codes exist

### TC-A08: Analytics Dashboard Rendering
**Steps:** Load analytics.html, verify table displays existing URLs  
**Expected:** HTML table with columns: Short Code, Original URL, Clicks, Created, Last Access  
**Pass Criteria:** Table populated with data from stats.php, copy buttons present

### TC-A09: Copy to Clipboard
**Steps:** Click "Copy" button in index.html after shortening URL  
**Expected:** Short URL copied to clipboard, button shows "Copied!" feedback  
**Pass Criteria:** Paste operation yields correct short URL, button text changes

### TC-A10: Mobile Layout (320px)
**Steps:** Load index.html in Chrome DevTools iPhone SE emulator (320px width)  
**Expected:** Single column layout, touch targets ≥44px, no horizontal scroll  
**Pass Criteria:** Visual inspection confirms responsive design, form usable

### TC-A11: HTTPS Redirect
**Steps:** Access site via HTTP (if .htaccess deployed)  
**Expected:** Automatic redirect to HTTPS version  
**Pass Criteria:** Browser URL changes to https://, no certificate warnings

### TC-A12: Empty State Handling
**Steps:** Access analytics.html with no shortened URLs  
**Expected:** Displays empty state message ("No URLs yet")  
**Pass Criteria:** No JavaScript errors, graceful UI handling

---

## B. EDGE CASES (8 cases)

### TC-B01: Maximum URL Length
**Steps:** POST URL with exactly 2048 characters  
**Expected:** Accepts and shortens successfully  
**Pass Criteria:** No validation errors, urls.json stores full URL

### TC-B02: URL Length Exceeds Limit
**Steps:** POST URL with 2049 characters  
**Expected:** Returns 400 error, message: "URL too long (max 2048)"  
**Pass Criteria:** Rejected before storage, urls.json unchanged

### TC-B03: Short Code Collision (Simulated)
**Steps:** Manually edit urls.json to fill 50% of namespace, POST new URL  
**Expected:** Generates code not in existing set (retries up to 5 times if needed)  
**Pass Criteria:** New code unique, no overwrite of existing entry

### TC-B04: Custom Alias Already Taken
**Steps:** Create alias "test", attempt second URL with same alias  
**Expected:** Returns 409 error, message: "Alias already in use"  
**Pass Criteria:** Second request rejected, urls.json unchanged

### TC-B05: Invalid Custom Alias Format
**Steps:** POST `customAlias: "test@123"` (contains invalid char)  
**Expected:** Returns 400 error, message matches validation rule  
**Pass Criteria:** Rejected per regex `/^[a-zA-Z0-9]{3,10}$/`

### TC-B06: Unicode in URL
**Steps:** POST URL with Unicode characters (e.g., https://example.com/café)  
**Expected:** Stores and redirects correctly (URL-encoded internally)  
**Pass Criteria:** Redirect works, original URL preserved byte-for-byte

### TC-B07: Very Short URL
**Steps:** POST URL: "https://a.b"  
**Expected:** Accepts (valid per FILTER_VALIDATE_URL)  
**Pass Criteria:** Shortens successfully, redirect works

### TC-B08: 10,000 URL Limit
**Steps:** Attempt to create 10,001st URL (after seeding 10K entries)  
**Expected:** Returns 507 error, message: "Storage limit reached"  
**Pass Criteria:** Hard limit enforced per Architect spec

---

## C. ERROR HANDLING (5 cases)

### TC-C01: Nonexistent Short Code
**Steps:** Visit /zzZZzz (code not in urls.json)  
**Expected:** HTTP 404, custom error page displayed  
**Pass Criteria:** Not raw PHP error, user-friendly message shown

### TC-C02: Malformed URL Submission
**Steps:** POST `url: "not-a-url"`  
**Expected:** Returns 400, message: "Invalid URL format"  
**Pass Criteria:** filter_var validation catches, returns before storage attempt

### TC-C03: Empty URL Field
**Steps:** POST empty string or whitespace-only URL  
**Expected:** Returns 400, message: "URL required"  
**Pass Criteria:** Client-side validation catches (app.js), server validates as backup

### TC-C04: File Lock Timeout
**Steps:** Simulate flock() failure (manually hold lock in separate process)  
**Expected:** Returns 503 after retry attempts, message: "Service temporarily unavailable"  
**Pass Criteria:** Retry logic executes 3 times, fails gracefully

### TC-C05: Corrupted JSON File
**Steps:** Manually corrupt urls.json (invalid JSON syntax), attempt shorten  
**Expected:** Returns 500, logs error, does not overwrite file  
**Pass Criteria:** Error caught by json_decode, file integrity preserved

---

## D. SECURITY (5 cases)

### TC-D01: XSS in URL Parameter
**Steps:** POST URL containing `<script>alert('XSS')</script>`  
**Expected:** Rejected by URL validation (invalid protocol/format)  
**Pass Criteria:** Returns 400, script tag never stored or rendered

### TC-D02: Open Redirect via JavaScript Protocol
**Steps:** POST `url: "javascript:alert(1)"`  
**Expected:** Rejected (not in protocol whitelist)  
**Pass Criteria:** Validation blocks non-http/https schemes

### TC-D03: Path Traversal in Custom Alias
**Steps:** POST `customAlias: "../../../etc/passwd"`  
**Expected:** Rejected by regex validation (contains `/`)  
**Pass Criteria:** Alias validation prevents filesystem access

### TC-D04: Rate Limit Enforcement
**Steps:** Send 11 POST requests from same IP within 1 hour  
**Expected:** First 10 succeed, 11th returns 429 with `Retry-After` header  
**Pass Criteria:** ratelimit.json shows count=10, subsequent requests blocked

### TC-D05: SQL Injection Attempt (Defensive)
**Steps:** POST URL with SQL payload (e.g., `' OR '1'='1`)  
**Expected:** Treated as part of URL string, no code execution (no SQL used)  
**Pass Criteria:** Stores literally in JSON, no injection possible (baseline check)

---

## E. PERFORMANCE/CONCURRENCY (2 cases)

### TC-E01: Concurrent Shorten Requests
**Steps:** Send 5 simultaneous POST requests (different URLs) using parallel curl  
**Expected:** All 5 succeed, urls.json contains 5 distinct entries, no overwrites  
**Pass Criteria:** flock() prevents race conditions, all codes unique

### TC-E02: QR Generation Latency
**Steps:** Measure response time for shorten request with QR enabled  
**Expected:** Total response time <500ms (per Architect estimate: 20ms QR + network)  
**Pass Criteria:** p95 latency under threshold in 10 requests

---

## ✅ ACCEPTANCE CRITERIA

### Functional Requirements
- **95% of test cases must pass** (30/32 minimum)
- All CORE FUNCTIONALITY cases (TC-A01 through TC-A12) must pass
- At least 4/5 SECURITY cases must pass
- Concurrency test (TC-E01) must pass with zero data corruption

### Performance Thresholds
- Short URL generation: <200ms (p95)
- Redirect latency: <50ms (p95)  
- Analytics page load: <1s for 100 URLs

### Security Gates (Zero Tolerance)
- No XSS vulnerabilities (TC-D01 must pass)
- No open redirect exploits (TC-D02 must pass)
- Rate limiting functional (TC-D04 must pass)

### Mobile Compatibility
- TC-A10 must pass on iPhone SE (320px), Pixel 5 (393px), iPad (768px)
- No horizontal scroll, all buttons ≥44px touch targets

### Data Integrity
- Zero file corruption after 100 consecutive operations
- flock() prevents all race conditions in concurrency test
- JSON schema validation passes for all stored data

---

## 🎯 RECOMMENDATION

**PRIORITY EXECUTION ORDER:**

1. **Phase 1 (Blocking):** TC-A01, TC-A02, TC-C02, TC-D02 — Core flow + critical security
2. **Phase 2 (High):** TC-A03-A09, TC-D01, TC-D04 — Full feature coverage + remaining security
3. **Phase 3 (Medium):** TC-B01-B08, TC-E01 — Edge cases + concurrency
4. **Phase 4 (Low):** TC-C01-C05, TC-A10-A12, TC-E02 — Error handling + UX polish

**CRITICAL DEPENDENCIES:**
- Cannot test TC-A02 (redirect) until TC-A01 (shorten) passes
- Cannot test TC-A05 (analytics API) until TC-A01 creates data
- TC-E01 (concurrency) requires flock() implementation complete

**TESTING ENVIRONMENT:**
- PHP 8.2 server (match production)
- Chrome 120+ for mobile emulation
- curl/Postman for API testing
- Manual JSON inspection tools

**AUTOMATION NOTES:**  
TC-D01, TC-D02, TC-D03 (security) should be re-run on every code change. TC-E01 (concurrency) requires bash script with parallel execution. TC-A10 (mobile) requires manual visual QA.