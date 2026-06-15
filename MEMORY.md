# MEMORY.md - Long-Term Memory

## Token Optimization Strategy (ACTIVE)
- **Planning/design:** Nexos (Opus) — keep brief
- **Coding:** Claude Code CLI (`claude -p "..."`) — Anthropic billing
- **Deploy/git:** Direct — minimal cost
- **Background:** DISABLED (heartbeat off, no cron, no auto-updates)

## Current Projects

### CurbIn v2 (LIVE — Production Ready)
- **GitHub:** https://github.com/AgentRocketman/toronto-bins
- **Live:** https://agentrocketman.github.io/toronto-bins/
- **LIVE:** https://agentrocketman.com (deployed 2026-06-13)
- **Final status:** All UX issues resolved, v2 deployed to production ✅
- **Details:** See PROJECT_LOG.md

### Infrastructure
- **Domain:** agentrocketman.com (Hostinger Business)
- **Email:** support@agentrocketman.com (AgentEmail1!)
- **IMAP:** imap.hostinger.com:993 | SMTP: 465
- **Order ID:** 1009510349 | Datacenter: Boston
- **Status:** DNS live, website provisioning, email pending IMAP activation

## Airtable Credentials
- **Base ID:** apptYNRJTXwItvied
- **API Key:** patxbDkv88pOMXmYx.c7e5fd7974954e3a674087090835d11dd69504f3912f0ef86c3c59f1e91febdd
- **Bookings Table:** tblKMhGnYjsH0z7Lj
- **ServiceStops Table:** (to be confirmed)

## CurbIn Email System - COMPLETE ✅
- ✅ Completion emails working (inline photo embedding)
- ✅ GFL Green (#A4D233) → Blue gradient in emails
- ✅ Image compression (6MB → ~250KB) frontend
- ✅ SPF/DMARC DNS records configured
- ✅ Photo attachment inline (no "Show Images" needed)
- ✅ Hostinger SMTP integration working

## Deployment Method (FINAL)
✅ **Use Hostinger API, NOT FTP/SSH**
- ALWAYS deploy from `/data/.openclaw/workspace/public_html/` — the master folder
- NEVER deploy a partial archive (it wipes other files on the server)
- To deploy: `cd /data/.openclaw/workspace/public_html && tar -czf /tmp/full-deploy.tar.gz .` then call deployStaticWebsite
- Before ANY deploy: copy changed file(s) into public_html/ first, then tar the whole folder
- Deploy completes in ~15 seconds
- This is the ONLY reliable method

## public_html/ Master Folder (SOURCE OF TRUTH)
- `/data/.openclaw/workspace/public_html/index.html` ← curbin-v2-final.html (homepage)
- `/data/.openclaw/workspace/public_html/service-routing.html` ← service routing page
- `/data/.openclaw/workspace/public_html/api/health.php`
- `/data/.openclaw/workspace/public_html/api/upload.php`
- `/data/.openclaw/workspace/public_html/api/save-service.php`
- `/data/.openclaw/workspace/public_html/api/services.php`
- `/data/.openclaw/workspace/public_html/api/optimize-route.php`
- `/data/.openclaw/workspace/public_html/api/send-email.php`

## Dynamic Service Routing - COMPLETE ✅
- ✅ Airtable integration fully working (client-side date filtering)
- ✅ Car icon tracking visible on map
- ✅ Color-coded markers (pending vs completed)
- ✅ Deployed to production at https://agentrocketman.com/service-routing.html

## Homepage (index.html) - COMPLETE ✅
- ✅ Accordion/harmonica (collapsible "Upcoming Schedule")
- ✅ "Save 34%" gold badge positioned **outside button, to the right** (position: absolute, right: -55px)
- ✅ Pricing shows "/week" for recurring (ALL 5 instances fixed)
- ✅ Pricing calculations correct ($11.90 for "Both" service)
- ✅ Scroll-to-green-bar working (uses `transitionend` event + fallback timeout)
- ✅ Dynamic recurring info text updates
- ✅ Deployed FINAL version (curbin-v2-final.html with transitionend fix)

## Hostinger Credentials
- **API token:** B4V2bxKyjkRgso0JS9CkiCqkqUZ32PhAzA16cxcB87d7b57e
- **FTP Username:** u686706869
- **FTP Password:** FTPAgentPassword1!
- **Email Account:** support@agentrocketman.com / Yuserbsme (password: AgentEmail1!)

**See PROJECT_LOG.md for full session history & commits**

## CurbIn HST Tax Implementation — COMPLETE ✅ (2026-06-15)
- **Choice:** Option A — Tax added at checkout (standard Canadian practice)
- **Tax Rate:** 13% HST (Ontario)
- **Pricing Display:** Base prices shown ($8.95 Ad Hoc, $5.95 Recurring)
- **Tax Note:** "Plus applicable taxes (13% HST added at checkout)" — added under pricing cards
- **Checkout Flow:**
  - Customer sees Subtotal + HST (13%) = Total breakdown BEFORE card entry
  - Stripe charges full amount (base + tax)
  - Confirmation email shows tax breakdown
- **Files Updated:**
  - `index.html` — Added HST_RATE const, updated tax display, added tax breakdown in openCheckout()
  - `email-service.js` — Pass tax data to confirmation email endpoint
  - `api/send-confirmation.php` — Display tax breakdown in email receipt
  - `stripe-integration.js` — Restored proven working version
- **Stripe Card Input Fix (2026-06-15 04:30 UTC):**
  - Problem: Card fields wouldn't accept keyboard input + background page scrolling through modal
  - Root cause: Overly complex initialization logic + wrong container sizing
  - Solution: Restored `stripe-integration.js` from git history (working version)
  - Fixed container sizing (`height: 48px` instead of `min-height`)
  - Fixed background scrolling: added `body.modal-open { overflow: hidden }`
  - Simplified initialization (removed over-engineered retry logic)
- **Status:** ✅ Live at https://agentrocketman.com (all features working)

## Email Confirmation System — VERIFIED WORKING ✅ (2026-06-15)
- **Issue:** Emails appeared to send but weren't arriving
- **Root cause:** IMAP/SMTP on email account needed to be re-enabled in hPanel
- **Resolution:** Added detailed SMTP debug logging to identify connection issues
- **Verification:** Sent test confirmation for booking FP2SG — 2 emails received successfully
- **Current status:** ✅ Email confirmations working reliably
- **Next:** Monitor for any future failures; debug logs will help diagnose
