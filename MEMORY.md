# MEMORY.md - Long-Term Memory

## Work Scope Rule (CRITICAL)
**ONLY work on features explicitly requested by Chris.**
- Do NOT refactor unrelated code
- Do NOT "improve" other parts while working on Feature X
- Do NOT rewrite code not mentioned in the current task
- Stay focused on exactly what was asked
- If you see issues elsewhere, ask first before fixing

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

## Deployment Method (FINAL - CORRECTED 2026-06-19)
✅ **Use Hostinger API, NOT FTP/SSH**
- ALWAYS deploy from `/data/.openclaw/workspace/public_html/` — the master folder
- **CRITICAL:** Hostinger does sync-style deploy = DELETES server files not in archive
- To deploy: `cd /data/.openclaw/workspace/public_html && tar -czf /tmp/deploy.tar.gz --exclude='./bin-pics/*.jpg' --exclude='./bin-pics/*.png' .` then call deployStaticWebsite
  - This INCLUDES bin-pics/.gitkeep and bin-pics/.htaccess (preserves directory)
  - This EXCLUDES actual image files (*.jpg, *.png) — they only live on server
  - Driver-uploaded photos stay on server, never deleted during deploy
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

## Bin Placement (Street View pin) — 2026-06-18 ✅

**Customer side** (`index2.html`):
- Replaced "Your Order" summary block with embedded Street View + green crosshair (at 70% down so horizon stays visible)
- Customer aims crosshair at bin spot, hits **Go to Checkout** → pin auto-drops at crosshair (no Drop/Clear buttons)
- **No visible pin marker** on customer side — coords captured silently
- Checkout button starts disabled, enables when adhoc date picked OR recurring chosen
- Friendly fallback when Street View not available for the address
- Pin data flows into `window.currentBooking.binPlacement = { pano, pov, cameraLatLng, binLatLng, hasPin }`

**Driver side** (`service_routing2.html`):
- Each stop with saved pin data gets a 📍 **View Bin Spot** button
- Modal recreates the customer's exact panorama + POV
- Green 🗑️ pin anchored at saved lat/lng (stays glued when driver walks/rotates)
- Locked green crosshair overlay at same 70% position the customer saw
- ESC / X / backdrop closes the modal

**Persistence**: `airtable-bookings.js` writes Bin Pano / Bin POV {Heading,Pitch,Zoom} / Bin Lat/Lng / Camera Lat/Lng / Has Bin Pin to Bookings table. **Graceful fallback**: if those columns don't exist yet, retries without them so the booking still saves. **TODO**: add those columns to the Bookings table in Airtable for the data to actually persist.

**Standalone tools**: `/streetview-test.html` (debug) and `/bin-placement.html` (standalone pin-drop page that can be linked from confirmation emails like `?address=...`).

## Critical Bug Fix — api/config.php (2026-06-18) 🔥

**The chat-logging rewrite in commit `c5b9e50` accidentally overwrote api/config.php**, deleting all the auth/Stripe/SMTP/JWT helpers. As a result, these endpoints have been silently returning HTTP 500 for the past few days:

- `/api/auth/login.php` (employee login broken)
- `/api/charge-payment.php` (ad hoc payments broken)
- `/api/create-subscription.php` (recurring subs broken)
- `/api/send-confirmation.php` (confirmation emails broken)
- `/api/contact.php`, `/api/manage-request.php`, `/api/process-cancellation.php`, `/api/verify-booking.php`

**Fix (commit 3c8b67c)**: Merged both helper styles into one config.php — added back `airtableRequest`, `stripeRequest`, `corsHeaders`, `generateJWT`, `verifyJWT`, `sendSmtpEmail`, plus all `AIRTABLE_*` / `STRIPE_*` / `JWT_*` / `ADMIN_*` / `SMTP_*` constants, while keeping the chat-logging helpers (`airtableCall`, `openaiCall`, `getClientIP`, `getBrowserInfo`). **All endpoints now return 200.**

**Lesson**: any time config.php is touched, smoke-test the auth + payment + email endpoints. ANY of those failing silently in production = lost bookings/customers.

## Employee Login (2026-06-18) ✅

- URL: https://agentrocketman.com/employee-login.html
- Test account: `chris@agentrocketman.com` / `Chris2026!` (Employee ID: EMP-E32E2B)
- After login, routes to driver service routing page
- Admin credentials (for creating new employees via `/api/auth/create-employee.php`):
  - `ADMIN_KEY`: `getmybin-admin-xK9mP2026`
  - `ADMIN_PASSWORD`: `GetMyBinAdmin2026!`

## Admin Panel & Dashboard — UPDATED ✅ (2026-06-19)

### Mobile Navigation (Responsive Design):
- **Desktop (≥769px):**
  - Horizontal navigation bar with all options visible
  - Desktop-optimized spacing and sizing
  - Full user email displayed

- **Mobile (≤768px):**
  - Compact header with hamburger menu (☰) icon
  - Hamburger menu opens full-width dropdown with all nav options
  - Touch-friendly buttons (44px minimum height)
  - Overlay backdrop when menu open
  - Menu closes automatically when option selected or overlay clicked
  - Short user info display
  - All buttons have active state styling

### Dashboard Features:
1. **Order graph:** Stacked bar chart showing orders by day
   - Default view: Next 7 days
   - Date range picker with auto-closing calendars
   - Stacked bars: Blue (new) + Orange (pending) + Green (completed)

2. **Statistics cards:**
   - Total orders, Average per day
   - New, Pending, Completed breakdown

3. **Orders table** (below chart):
   - **Columns:** Booking ID, Created At, Address, Email, Service Type, Service Date, Status
   - **Sortable:** Click any column header to sort ascending/descending
   - **Pagination:** 10 records per page with First/Prev/Next/Last navigation
   - **Search:** Real-time search by Booking ID, Address, or Email address
   - **Status badges:** Color-coded (New=blue, Pending=orange, Completed=green, Cancelled=red)
   - **Data source:** Airtable Orders table + Bookings table for address/email
   - **Clickable rows:** Click any cell to view order details

### Order Details Page (`/admin/order-details.html?orderId=recXXXX`):
- **Authentication required:** JWT token verification before access
- **Displays:**
  - Order information (Order ID, Service Date, Type, Frequency, Status)
    - **Refund This Order button** - cancels just that specific order
    - **DISABLED if order is Cancelled or Refunded** (grayed out with tooltip "This order has already been cancelled")
  - Booking information (Booking ID, Customer Name, Email, Address, Created At)
    - **Refund Entire Booking button** - cancels all orders in the booking
  - Payment information (Amount, Stripe Payment ID, Stripe Subscription ID)
  - Location info with coordinates
  - **Smart fallback:** If Street View unavailable, shows coordinates + link to open in Google Maps
  - **Completion photos** (if order is completed) - gallery with employee name + date for each photo
  - **Related orders** from same booking (clickable - navigate to their order details page)
    - **Individual Refund buttons** for each order (🗑️) - cancels just that specific order
    - **DISABLED for Cancelled/Refunded orders** (grayed out, opacity 0.6, cursor not-allowed)
- **Refund Logic:**
  - **Booking-level refund:** Cancels entire booking + all future orders, processes Stripe refund
  - **Order-level refund:** Cancels only that specific order, requires manual Stripe refund via dashboard
  - **Button State:** Both order section and related orders refund buttons are disabled if status is 'Cancelled' or 'Refunded'

### Order Cancellation Page (`/admin/cancel-order.html`):
- **Refund Summary Card:**
  - Displays calculated refund amount in large green text
  - Shows details: e.g. "2 out of 3 dates beyond 48-hour cutoff"
  - Shows **Already Refunded amount** (if any orders already refunded)
  - Shows **Max Available Refund** (if booking has partial refunds)
  - If no refund applies: shows yellow warning "No refund applicable - all dates fall within 48-hour window"
- **Form fields:**
  - Reason dropdown (Customer Request, Payment Failed, Service Issue, Duplicate, Other)
  - Additional notes textarea
- **On submission:**
  - Calls `/api/process-cancellation.php` endpoint
  - **Booking-level refund:** Issues Stripe refund automatically + cancels all orders
  - **Order-level refund:** Cancels specific order only (manual Stripe refund required)
  - Updates Airtable status to Cancelled
  - Sends confirmation emails to customer and support
  - Redirects to admin panel on success
- **Refund calculation:** `/api/calculate-refund.php`
  - **Max Available Refund Logic (NEW):**
    - If any order in booking is already Cancelled/Refunded: max refund = (total booking amount - sum of already refunded amounts)
    - Example: Booking $40, Order 1 refunded $10 → max refund = $30
  - **No 48-Hour Restriction:** All orders eligible for refund, regardless of age
    - Shows warning: "⚠️ This booking was placed more than 48 hours ago, but refund will still be processed"
  - Proportional refund based on all active (non-cancelled) orders
  - Formula: min((booking amount / total orders) × (active orders), max available refund)

### Order Status Logic (Date-Based):
- **New (blue):** Service Date is in the future AND not completed
- **Pending (orange):** Service Date is today or in the past AND not completed (includes overdue)
- **Completed (green):** Status = "Completed"
- **Cancelled:** Excluded from dashboard

### Refund Window (48-Hour Rule) — UPDATED ✅
- **Previous:** Orders within 48 hours of cutoff could not be refunded
- **Current:** All orders eligible for refund regardless of age
- **Warning Display:** If booking/order is >48 hours old, shows: "⚠️ This booking was placed more than 48 hours ago, but refund will still be processed as requested."
- **Max Refund Cap:** Still respects (total booking amount - already refunded amounts)

## Admin Panel Login System — UPDATED ✅ (2026-06-19)
- **URL:** https://agentrocketman.com/admin-login.html
- **Admin Email:** support@getmybin.com
- **Admin Password:** GetMyBinAdmin2026! (stored in Airtable now)
- **Two-pane layout:**
  - **Top pane:** Navigation (Dashboard, Service Schedule, Chat Analytics, Change Password), user greeting, logout button
  - **Bottom pane:** Iframe with selected content
- **Authentication:** JWT tokens (7-day expiry)
- **Airtable Integration:**
  - **Table:** `Admins` (in base apptYNRJTXwItvied)
  - **Fields:** Email, Password, FirstName, LastName, Active, CreatedAt, LastPasswordChange
  - Initial admin: support@getmybin.com / GetMyBinAdmin2026!
- **Files:**
  - `/admin-login.html` — Login form (queries Airtable)
  - `/admin-panel.html` — Main admin interface
  - `/api/admin-auth/login.php` — Token generation (Airtable lookup)
  - `/api/admin-auth/verify.php` — Token validation
  - `/api/admin-auth/change-password.php` — Change password (updates Airtable)
  - `/api/admin-auth/create-admin.php` — Create new admin account
  - `/api/admin-auth/setup-admins-table.php` — Setup script (run once)
  - `/admin/dashboard.html` — Dashboard
  - `/admin/change-password.html` — Change password page

## Hostinger Credentials
- **API token:** B4V2bxKyjkRgso0JS9CkiCqkqUZ32PhAzA16cxcB87d7b57e
- **FTP Username:** u686706869
- **FTP Password:** FTPAgentPassword1!
- **Email Account:** support@agentrocketman.com / Yuserbsme (password: AgentEmail1!)

**See PROJECT_LOG.md for full session history & commits**

## GetMyBin Rebrand — COMPLETE ✅ (2026-06-16)
- **Old brand:** CurbIn → **New brand:** GetMyBin
- **Domain:** agentrocketman.com (now active with new branding, will migrate getmybin.com later)
- **New primary color:** #71b80c (vibrant green from bin logo)
- **Live:** https://agentrocketman.com ✅
- **What was done:**
  - ✅ Complete text rebrand: 0 "CurbIn" → 98 "GetMyBin" references
  - ✅ Color rebrand: 0 old #A4D233 → 56 instances of #71b80c
  - ✅ All logos integrated (bin logo in hero, favicon, transparent/white versions)
  - ✅ Homepage beautifully redesigned with modern gradient hero, premium styling
  - ✅ All email templates updated (send-email.php, send-confirmation.php)
  - ✅ API endpoints updated (config, charge-payment, create-subscription, manage-request)
  - ✅ All secondary pages rebranded (contact, terms, privacy, manage, etc.)
  - ✅ Full static deployment to agentrocketman.com
- **Deployment time:** ~15 seconds ✓
- **Verification:** All files deployed, rebrand verified 100%

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

## GetMyBin AI Chat Widget — COMPLETE ✅ (2026-06-17)
- **Feature:** AI customer support chatbot on website
- **Stack:** Vanilla JS, OpenAI ChatGPT API (gpt-3.5-turbo), 100% self-contained
- **Design:** Sticky green icon (💬) bottom-right, beautiful modal interface
- **Icon Position:** bottom: 120px (desktop), bottom: 100px (mobile) — above address bar
- **Mobile:** Fully optimized for iPhone using visualViewport API
  - Full-screen slide-up modal on mobile
  - Input field always visible above keyboard (no hidden fields)
  - Smart keyboard detection & layout adjustment
  - Proper safe area support (notch, home indicator)
- **Desktop:** 400px × 600px modal, smooth animations
- **Knowledge Base:** Complete GetMyBin service info embedded in system prompt
  - Pricing ($5.95/week, $8.95 ad-hoc)
  - $1 promo details
  - HST tax info (13%)
  - Service area: **Toronto + districts (Old Toronto, North York, Scarborough, Etobicoke, East York, York) — NOT GTA (Mississauga, Brampton, etc.)**
  - Contact support@agentrocketman.com
- **Tone:** Short, concise responses (1-3 sentences max)
- **Status:** ✅ Live at https://agentrocketman.com (Opus-optimized, works great on mobile & desktop)
- **Files:** `/getmybin-chat.js` (complete widget)

## Chat Logging & Analytics System — COMPLETE ✅ (2026-06-18)
- **Architecture:** All Q&As logged to Airtable, analyzed server-side
- **Logging Flow:**
  1. User sends message in chat widget
  2. `chat-logger.js` captures sessionId (localStorage) + timestamp + IP/browser/device
  3. `log-chat.php` backend writes to Airtable ChatLogs table
  4. Logging is async (non-blocking to chat UX)
- **Airtable ChatLogs Table:** `tblatXRj8Ka7hyGyZ`
  - Fields: sessionId, timestamp, date, ipAddress, browser, deviceType, messageType (question/answer), message
  - One row per message (not per pair)
- **Analytics Page:** `/admin/analytics.html`
  - Date range pickers (top) — defaults to last 7 days
  - Summary stats: session count, message count
  - Top 5 issues summary (generated by GPT-3.5-turbo server-side)
  - Harmonica/accordion list of sessions
    - Each session shows: timestamp, message count, AI-generated summary
    - Expandable to show session metadata (IP, browser, device)
- **Backend Endpoints:**
  - `POST /api/log-chat.php` — receives Q&A, auto-detects IP/browser/device, writes to Airtable
  - `GET /api/summarize-chats.php?fromDate=YYYY-MM-DD&toDate=YYYY-MM-DD` — queries Airtable, summarizes via OpenAI
- **Security:**
  - OpenAI API key stored **server-side only** in `/api/config.php` (never exposed to client)
  - CORS headers restrict API calls to agentrocketman.com
  - No password protection yet (to be added later)
- **Files:**
  - `/chat-logger.js` — session tracking + logging
  - `/api/config.php` — shared backend config (Airtable + OpenAI keys, helpers)
  - `/api/log-chat.php` — chat message logging
  - `/api/summarize-chats.php` — analytics + summaries
  - `/admin/analytics.html` — dashboard UI
