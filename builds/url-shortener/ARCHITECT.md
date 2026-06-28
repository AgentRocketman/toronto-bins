# ARCHITECT OUTPUT — Architect-1

# 🏗️ ARCHITECT SPECIFICATION: URL Shortener

---

## 🔧 BLOCKER RESOLUTION

**Addressing Scout's flagged risks:**

### 1. Concurrency Handling
- **Solution**: PHP `flock()` with `LOCK_EX` on all JSON write operations
- **Implementation**: Wrapper functions `readUrlData()` and `writeUrlData()` with file locking
- **Fallback**: Retry mechanism (3 attempts, 100ms delay) if lock acquisition fails
- **Accepted Risk**: ~50ms write latency under high concurrency (acceptable for this use case)

### 2. Scalability Ceiling
- **Position**: This is a prototype/small business tool, NOT enterprise-scale
- **Hard Limit**: 10,000 URLs maximum (enforced in code)
- **Mitigation**: JSON file split into two files: `urls.json` (mappings) + `stats.json` (click counts)
- **Future Path**: Migration guide to SQLite in documentation (out of scope for v1)

### 3. Security Gaps
- **URL Validation**: Regex + `filter_var(FILTER_VALIDATE_URL)` + protocol whitelist (http/https only)
- **CSRF Protection**: Not required (no authenticated sessions, public API)
- **Input Sanitization**: `htmlspecialchars()` on all user input before display
- **Open Redirect Prevention**: Validate stored URLs before redirect (re-check on retrieval)
- **HTTPS**: Enforce via `.htaccess` redirect (document in deployment guide)

### 4. Short Code Uniqueness
- **Strategy**: Pure random generation with collision detection
- **Character Set**: `[a-zA-Z0-9]` (62 chars) = 56.8B possible combinations
- **Collision Handling**: Regenerate up to 5 times, abort with error if all fail
- **Rationale**: Hash-based truncation risks collisions; random + check is simpler and sufficient

### 5. Mobile Responsiveness Scope
- **Definition**: Functional + optimized
- **Requirements**: Min 44px touch targets, single-column layout <768px, viewport meta tag
- **Testing**: Chrome DevTools mobile emulation (iPhone SE, Pixel 5, iPad)

### 6. Analytics Persistence
- **Solution**: Separate `stats.json` file to isolate write-heavy operations
- **Structure**: `{"shortCode": {"count": 123, "lastAccess": "2025-06-15T10:30:00Z"}}`
- **Performance**: Click tracking uses append-log pattern (not rewrite-entire-file)

### 7. URL Length Limits
- **Max Length**: 2048 characters (browser standard)
- **Validation**: Reject longer URLs with error message
- **JSON Impact**: Assuming avg 100 chars/URL, 10K URLs = ~1MB file (acceptable)

---

## 🏗️ TECH STACK

| Layer | Technology | Rationale |
|-------|-----------|-----------|
| **Frontend** | Vanilla HTML5/CSS3/ES6 JS | Matches existing conventions, no build step, CDN-free |
| **Backend** | PHP 8.2 | Available on Hostinger, native JSON handling, file locking support |
| **Storage** | JSON files (2 files) | No DB setup, human-readable, version-controllable |
| **Server** | Apache (Hostinger default) | `.htaccess` for URL rewriting, HTTPS redirect |
| **QR Codes** | [phpqrcode](https://github.com/t0k4rt/phpqrcode) library (single file) | No Composer, self-contained, 30KB overhead |
| **Rate Limiting** | IP tracking in `ratelimit.json` | Simple file-based, auto-prune old entries |

**No external dependencies** except phpqrcode (single PHP file, no composer).

---

## 📁 FILE STRUCTURE

```
url-shortener/
│
├── index.html                 # Main UI (shorten form)
├── analytics.html             # Dashboard (all URLs + stats)
├── redirect.php               # Handles /abc123 → original URL
│
├── api/
│   ├── shorten.php           # POST endpoint: create short URL
│   ├── stats.php             # GET endpoint: fetch analytics data
│   └── helpers.php           # Shared functions (file I/O, validation)
│
├── assets/
│   ├── css/
│   │   └── style.css         # Single stylesheet (mobile-first)
│   ├── js/
│   │   ├── app.js            # Main page logic (shorten, copy)
│   │   └── analytics.js      # Dashboard logic (fetch, render table)
│   └── lib/
│       └── phpqrcode.php     # QR code generation library
│
├── data/
│   ├── urls.json             # {shortCode: {url, created, customAlias}}
│   ├── stats.json            # {shortCode: {count, lastAccess}}
│   └── ratelimit.json        # {ip: {count, resetTime}}
│
├── .htaccess                 # URL rewriting, HTTPS redirect
├── config.php                # Constants (BASE_URL, MAX_URLS, etc.)
└── README.md                 # Setup + deployment guide
```

---

## 🗄️ DATA SCHEMAS

### urls.json
```json
{
  "aB3xQ9": {
    "url": "https://example.com/very/long/path",
    "created": "2025-06-15T14:30:00Z",
    "customAlias": false
  },
  "promo": {
    "url": "https://example.com/sale",
    "created": "2025-06-16T09:00:00Z",
    "customAlias": true
  }
}
```

**Fields:**
- `url` (string, 2048 max): Original URL (validated)
- `created` (ISO 8601): Timestamp for analytics sorting
- `customAlias` (bool): Distinguishes user-chosen codes

### stats.json
```json
{
  "aB3xQ9": {
    "count": 42,
    "lastAccess": "2025-06-20T11:45:00Z"
  }
}
```

**Fields:**
- `count` (int): Total redirects
- `lastAccess` (ISO 8601): Most recent click

### ratelimit.json
```json
{
  "192.168.1.100": {
    "count": 3,
    "resetTime": "2025-06-15T15:00:00Z"
  }
}
```

**Fields:**
- `count` (int): Requests in current window
- `resetTime` (ISO 8601): When counter resets (1 hour windows)

---

## 🔄 CRITICAL FLOWS

### Flow 1: Shorten URL
```
1. User pastes URL in index.html textbox, clicks "Shorten"
2. app.js validates URL client-side (basic format check)
3. POST /api/shorten.php with {"url": "https://...", "customAlias": ""}
4. shorten.php validates:
   - Rate limit check (10/hour per IP)
   - URL format (filter_var + regex)
   - Length (<2048 chars)
   - Custom alias availability (if provided)
5. Generate short code:
   - Random: 6 chars from [a-zA-Z0-9]
   - Collision check against urls.json
   - Retry up to 5 times
6. flock() LOCK_EX on urls.json
7. Write new entry to urls.json
8. Initialize stats.json entry {count: 0}
9. flock() LOCK_UN, return JSON:
   {"success": true, "shortUrl": "https://yourdomain.com/aB3xQ9", "qrCode": "data:image/png;base64,..."}
10. app.js displays short URL + QR code, enables copy button
```

### Flow 2: Redirect
```
1. User visits https://yourdomain.com/aB3xQ9
2. .htaccess rewrites to /redirect.php?code=aB3xQ9
3. redirect.php:
   - flock() LOCK_SH on urls.json (read lock)
   - Look up "aB3xQ9" in urls.json
   - Validate URL still well-formed (re-check)
   - flock() LOCK_EX on stats.json
   - Increment count, update lastAccess
   - flock() LOCK_UN on both files
   - HTTP 302 redirect to original URL
4. If code not found: HTTP 404 with custom error page
```

### Flow 3: Analytics Dashboard
```
1. User visits /analytics.html
2. analytics.js sends GET /api/stats.php
3. stats.php:
   - flock() LOCK_SH on urls.json + stats.json
   - Merge data: {code, url, created, count, lastAccess}
   - Sort by creation date (desc)
   - Return JSON array
4. analytics.js renders sortable HTML table
   - Columns: Short Code, Original URL, Clicks, Created, Last Access
   - Copy button per row
```

---

## 🔒 SECURITY

### Input Validation
- **URL Validation**:
  ```php
  filter_var($url, FILTER_VALIDATE_URL) &&
  preg_match('/^https?:\/\/.+/', $url) &&
  strlen($url) <= 2048
  ```
- **Custom Alias Validation**: `preg_match('/^[a-zA-Z0-9]{3,10}$/', $alias)`
- **Short Code Sanitization**: Already constrained to `[a-zA-Z0-9]`

### Output Encoding
- All user-generated content (URLs, aliases) passed through `htmlspecialchars()` before HTML rendering
- JSON responses use `json_encode()` with `JSON_HEX_TAG | JSON_HEX_AMP`

### Rate Limiting
- **Limit**: 10 shortens per IP per hour
- **Implementation**: Check `ratelimit.json` on each POST
- **Cleanup**: Auto-delete expired entries (>24h old) on each check
- **Response**: HTTP 429 with `Retry-After` header

### Open Redirect Prevention
- Validate URLs against protocol whitelist on BOTH storage AND redirect
- Reject `javascript:`, `data:`, `file:` schemes
- No domain whitelisting (would break use case)

### File Access
- `data/` directory: `.htaccess` with `Deny from all`
- JSON files: `chmod 644` (writable by PHP process only)
- No user-uploaded files (XSS risk avoided)

---

## ⚡ PERFORMANCE

### File I/O Optimization
- **Read Operations**: `file_get_contents()` + `json_decode()` with `flock(LOCK_SH)`
- **Write Operations**: In-memory merge + single `file_put_contents()` with `LOCK_EX`
- **Expected Latency**: <10ms reads, <50ms writes (on Hostinger SSD)

### Caching Strategy
- **Not Implemented**: No opcode cache assumptions (Hostinger may disable)
- **Client-Side**: `Cache-Control: no-cache` on API responses (stats must be fresh)
- **Future**: Add APCu/Redis caching layer if >5K URLs

### Analytics Performance
- **Lazy Loading**: Dashboard fetches data on page load (not inline)
- **Pagination**: Not implemented (assume <500 URLs displayed at once)
- **Sorting**: Client-side JavaScript (array sort) — acceptable for <10K rows

### QR Code Generation
- **On-Demand**: Generated during shorten request (not pre-cached)
- **Format**: PNG, 200x200px, embedded as Base64 in API response
- **Overhead**: ~10KB per QR code, ~20ms generation time

---

## 💰 REVISED BUILDER ESTIMATE

| Phase | Task | Tokens | Notes |
|-------|------|--------|-------|
| **Frontend** | index.html + style.css (mobile-first) | 15,000 | Single page form, responsive grid |
| | app.js (shorten, copy, QR display) | 12,000 | Fetch API, clipboard API, error handling |
| | analytics.html + analytics.js | 10,000 | Table rendering, sorting, copy buttons |
| **Backend** | config.php + helpers.php (file I/O) | 8,000 | flock wrappers, validation functions |
| | api/shorten.php | 18,000 | Main logic, rate limiting, collision handling |
| | redirect.php | 8,000 | Lookup, stats increment, redirect |
| | api/stats.php | 5,000 | Merge urls + stats, return JSON |
| **.htaccess** | URL rewriting + security headers | 3,000 | Redirect rules, HTTPS enforcement |
| **Integration** | QR library integration | 4,000 | phpqrcode.php wrapper function |
| **Testing** | Edge cases, concurrency, mobile | 25,000 | 15 test scenarios (see Tester stage) |
| **Documentation** | README, deployment, troubleshooting | 12,000 | Hostinger-specific setup guide |
| **Buffer** | Overruns, refinements | 10,000 | Collision retry logic, error pages |
| **TOTAL** | | **130,000** | 70K tokens under budget |

**Confidence**: HIGH. JSON storage + vanilla stack = low integration risk. Main complexity is concurrency testing.

---

## 🎯 RECOMMENDATION

**PROCEED TO BUILD.**

All blockers resolved. Spec is deterministic enough for parallel Test/Build execution:

### For Tester:
- Test collision handling (simulate concurrent writes)
- Test rate limiting (rapid-fire requests from same IP)
- Test malformed URLs (XSS payloads, invalid protocols)
- Test mobile layouts (viewport widths 320px, 768px, 1024px)
- Test QR code generation (valid PNG, correct URL encoding)

### For Builder:
- Start with `config.php` + `helpers.php` (file I/O foundation)
- Build `api/shorten.php` next (core logic)
- Frontend (`index.html`, `app.js`) in parallel
- `redirect.php` + analytics last (depends on data layer)

### Critical Path:
1. File locking helpers (all backend depends on this)
2. Shorten API (frontend blocks on this)
3. Redirect logic (can test without analytics)
4. Analytics dashboard (independent, can ship later)

**Risk Mitigation**: Builder should implement retry logic in file locking immediately — this is the #1 concurrency failure point. Use `flock()` with `LOCK_NB` + manual retry loop (not `LOCK_EX` blocking).

**Deployment Checklist** (for Documenter):
- Upload files, set `data/` permissions to 755
- Configure `BASE_URL` in config.php
- Test `.htaccess` rewrite rules
- Verify HTTPS redirect
- Seed empty JSON files (`{}` in urls/stats/ratelimit)

No external dependencies beyond phpqrcode (single file, no composer). No Python needed. Fully within Hostinger shared hosting constraints. **Greenlight to build.**