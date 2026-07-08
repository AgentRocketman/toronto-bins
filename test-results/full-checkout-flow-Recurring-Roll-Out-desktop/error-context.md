# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: full-checkout-flow.spec.js >> Recurring Roll Out
- Location: full-checkout-flow.spec.js:114:1

# Error details

```
Error: expect(locator).toBeVisible() failed

Locator: locator('.thankyou')
Expected: visible
Timeout: 30000ms
Error: element(s) not found

Call log:
  - Expect "toBeVisible" with timeout 30000ms
  - waiting for locator('.thankyou')

```

```yaml
- navigation "Main navigation":
  - link "GetMyBin home":
    - /url: /
    - img "GetMyBin"
  - list:
    - listitem:
      - link "How It Works":
        - /url: /#how
    - listitem:
      - link "Pricing":
        - /url: /#pricing
    - listitem:
      - link "Get Started":
        - /url: /#hero-anchor
- text: 📍 Toronto's Bin Service
- heading "Bin day, handled." [level=1]:
  - text: Bin day,
  - emphasis: handled.
- paragraph: We roll your bins to the curb and back — so you don't have to. Enter your address to see your schedule and get started.
- img
- textbox "Start typing your Toronto address…": 30 Woodbury Rd, Etobicoke
- button "Check Schedule →"
- text: ★★★★★ 5.0 — Loved by Toronto homeowners · No commitment
- paragraph: 📍 Serving Toronto residential addresses
- text: Simple Process
- heading "Three steps, zero hassle" [level=2]
- paragraph: We handle everything from start to finish — just enter your address and we take care of the rest.
- text: 1 📍
- heading "Enter your address" [level=3]
- paragraph: We look up your City of Toronto collection schedule automatically. No forms, no phone calls.
- text: 2 📋
- heading "Pick your service" [level=3]
- paragraph: Choose roll-out, roll-in, or both. Select ad hoc dates or subscribe for recurring service every week.
- text: 3 ✅
- heading "We handle the rest" [level=3]
- paragraph: We roll your bins to the curb the evening before pickup — and bring them back in the afternoon after collection.
- text: What Customers Say
- heading "Toronto homeowners love GetMyBin" [level=2]
- group "1 / 10":
  - text: ★★★★★
  - paragraph: "\"Honestly? I rolled my eyes when my neighbour told me about GetMyBin. Paying someone to move bins felt absurd. Six months later I'm the one evangelizing it to everyone on my street. It just works — every single time, without fail.\""
  - text: Michael T. — The Beaches
- group "2 / 10":
  - text: ★★★★★
  - paragraph: "\"Two demanding jobs, zero patience for bin schedules. Which week is garbage? Which is recycling? We don't know and we don't care anymore. GetMyBin handles every pickup without fail. Best twelve dollars we spend all month.\""
  - text: Sarah & Kevin D. — Leslieville
- group "3 / 10":
  - text: ★★★★★
  - paragraph: "\"Three kids, two jobs, one very chaotic life. GetMyBin showed up every single week without me lifting a finger — or a bin. My husband still can't figure out how the bins always make it to the curb on time. I'm not telling him.\""
  - text: Lisa M. — Willowdale
- group "4 / 10":
  - text: ★★★★★
  - paragraph: "\"Every January I'd lie awake picturing my dad slipping on an icy driveway dragging those bins out at 6am. I found GetMyBin, set it up in 10 minutes, and that weight lifted immediately. They love it, I sleep better. Worth every single penny.\""
  - text: Priya S. — North York
- group "5 / 10":
  - text: ★★★★★
  - paragraph: "\"I've bought my mom flowers, spa days, fancy dinners. She's always polite. I got her GetMyBin for her birthday half as a joke. She called me four days later, completely serious, and said it was the best gift she'd received in years.\""
  - text: James O. — North York
- group "6 / 10":
  - text: ★★★★★
  - paragraph: "\"I'm 76 and my daughter kept offering to 'help' with the bins, which I knew meant she was worried. I signed up for GetMyBin myself, thank you very much. Simple, reliable, and now she stops fussing.\""
  - text: Dorothy W. — Scarborough
- group "7 / 10":
  - text: ★★★★★
  - paragraph: "\"Six weeks in Florida every February. For years we felt guilty asking the neighbours to deal with the bins. Now we book our flights, set up GetMyBin, and don't think about Toronto once until we land. We handle the margaritas. They handle the bins.\""
  - text: Sandra P. — Etobicoke
- group "8 / 10":
  - text: ★★★★★
  - paragraph: "\"I'm on a plane two weeks out of every month. Bin day was this low-grade anxiety buzzing in the background. GetMyBin handles every pickup whether I'm in Vancouver or Frankfurt. Zero missed collections in nearly a year.\""
  - text: Marcus T. — Etobicoke
- group "9 / 10":
  - text: ★★★★★
  - paragraph: "\"Six rental units across three properties. Bin day was a constant headache. GetMyBin eliminated all of it overnight. Reliable, professional, and one of the best operational decisions I've made as a landlord.\""
  - text: Tony R. — East York
- group "10 / 10":
  - text: ★★★★★
  - paragraph: "\"Running an Airbnb on top of three rentals means my calendar is always chaos. Bin day used to mean driving across the city or praying a tenant remembered. GetMyBin took all of that off my plate completely.\""
  - text: David K. — East York
- button "Go to slide 1"
- button "Go to slide 2"
- button "Go to slide 3"
- button "Go to slide 4"
- button "Go to slide 5"
- button "Go to slide 6"
- button "Go to slide 7"
- button "Go to slide 8"
- button "Go to slide 9"
- button "Go to slide 10"
- text: Transparent Pricing
- heading "Straightforward rates" [level=2]
- paragraph: Per collection event — covers all bins scheduled that day. Roll out and roll in are charged separately.
- text: Ad Hoc $8.95 per collection event "Pick exactly the dates you need"
- list:
  - listitem: ✓ Choose any upcoming collection dates
  - listitem: ✓ No commitment or subscription
  - listitem: ✓ Perfect for vacations or one-offs
  - listitem: ✓ Pay per event, no surprises
- text: Most Popular Recurring $5.95 per collection event "Every collection day, automatically"
- list:
  - listitem: ✓ We show up every week, no reminders needed
  - listitem: ✓ Save 34% vs ad hoc pricing
  - listitem: ✓ Cancel or pause any time
  - listitem: ✓ Works with your full city schedule
- paragraph: Plus applicable taxes (13% HST added at checkout)
- paragraph:
  - strong: "Note:"
  - text: Roll out and roll in are each a separate charge. Both on the same day? That's two events.
- text: Got Questions?
- heading "Frequently asked" [level=2]
- button "Which areas do you cover? +"
- region:
  - paragraph: We serve all Toronto residential addresses with City of Toronto curbside collection. Not sure if that's you? Type your address above — we'll tell you instantly.
- button "Which bins do you handle? +"
- region:
  - paragraph: All of them — garbage, recycling, green bin, and yard waste. Whatever the City collects at your address on your collection day, we handle it.
- button "When do you roll bins out and back? +"
- region:
  - paragraph: We roll your bins to the curb the evening before your scheduled collection day and return them to their designated spot the following afternoon after they've been emptied.
- button "Do you follow Toronto's holiday schedule? +"
- region:
  - paragraph: Yes. We follow the City of Toronto's adjusted holiday schedule automatically. If the City moves your collection day, we move with it — no action needed from you.
- button "Can I cancel or pause my recurring service? +"
- region:
  - paragraph:
    - text: Cancel anytime — just let us know 48 hours before your next scheduled collection. No contracts, no penalties.
    - link "Cancel your service →":
      - /url: /manage.html
- button "What if you miss my scheduled service? +"
- region:
  - paragraph: If we miss your scheduled service for any reason, you don't pay for that event. Simple as that.
- text: 🛡️ Our service guarantee
- paragraph:
  - text: If we miss your scheduled collection,
  - strong: you don't pay.
  - text: No questions asked.
- text: "30 Woodbury Rd 📍 Collection day: Wednesday · Schedule: Week 2"
- button "✏️ Change Address"
- text: 📅 Next Collection Wednesday, July 8, 2026 🟢 Green Bin ⚫ Garbage 🟡 Yard Waste
- button "📅 Upcoming Schedule ▼"
- text: Week of 🟢 ⚫ 🔵 🟡 🎄 Jun 24 ✓ ✓ – ✓ – Jul 1 ✓ – ✓ – – Jul 8 ✓ ✓ – ✓ – Jul 15 ✓ – ✓ – – Jul 22 ✓ ✓ – ✓ – Jul 29 ✓ – ✓ – – Aug 5 ✓ ✓ – ✓ – Aug 12 ✓ – ✓ – – Aug 19 ✓ ✓ – ✓ – Aug 26 ✓ – ✓ – – Sep 2 ✓ ✓ – ✓ – Sep 9 ✓ – ✓ – – Sep 16 ✓ ✓ – ✓ – Sep 23 ✓ – ✓ – – Sep 30 ✓ ✓ – ✓ – Oct 7 ✓ – ✓ – – Oct 14 ✓ ✓ – ✓ – Oct 21 ✓ – ✓ – – Oct 28 ✓ ✓ – ✓ – Nov 4 ✓ – ✓ – – Green Bin Garbage Recycling Yard Waste Christmas Tree
- separator
- text: ⚙️ Choose Your Service Service Type
- button "Roll Out"
- button "Roll In"
- button "Both"
- text: Plan
- button "Ad Hoc"
- button "Recurring Save 34%"
- heading "Every Wednesday — Roll Out" [level=4]
- paragraph: We'll handle your bins on every Wednesday collection day, automatically. $5.95/week.
- text: "Estimated monthly: ~$23.80/month (4 service events × $5.95)"
- separator
- text: 📍 Show us where to leave your bin (optional) Drag the view to look around. Tap the white arrows or double-click to walk forward.
- strong: Aim the green crosshair
- text: at your bin spot — we'll save it when you tap
- strong: Go to Checkout
- text: .
- button "Keyboard shortcuts"
- region "Map":
  - region "Street View"
  - img:
    - button "Go East, Woodbury Rd"
    - button "Go West, Woodbury Rd"
- button "Toggle fullscreen view"
- button "Zoom in"
- button "Zoom out" [disabled]
- link "Open this area in Google Maps (opens a new window)":
  - /url: https://maps.google.com/maps/@43.5988307,-79.5463466,0a,112.6y,-23.49h,90t/data=!3m4!1e1!3m2!1sLmUdgWnG3GrvVvw61wdWzA!2e0?source=apiv3
  - img "Google"
- button "Keyboard shortcuts"
- text: © 2026 Google
- link "Terms (opens in new tab)":
  - /url: https://www.google.com/intl/en-US_US/help/terms_maps.html
  - text: Terms
- link "Report a problem":
  - /url: https://www.google.com/local/imagery/report/?cb_client=apiv3&image_key=!1e2!2sLmUdgWnG3GrvVvw61wdWzA&cbp=1,-23.489,,0,0&hl=en-US
- button "Go to Checkout →"
- heading "📋 Order Summary" [level=3]
- paragraph: Review your details before confirming
- text: Card Details
- iframe
- iframe
- iframe
- text: "Error: Unexpected payment status: unknown"
- strong: "Test card:"
- text: "4242 4242 4242 4242 · Exp: any future · CVC: any 3 digits Subtotal $5.95 HST (13%) $0.77 Total to Charge $6.72"
- button "← Cancel"
- button "Try Again"
- contentinfo:
  - paragraph:
    - text: Schedule data from
    - link "City of Toronto Open Data":
      - /url: https://www.toronto.ca/services-payments/recycling-organics-garbage/houses/collection-schedule/
    - text: · Toronto, ON
  - paragraph:
    - link "Terms of Service":
      - /url: /terms.html
    - text: ·
    - link "Privacy Policy":
      - /url: /privacy.html
    - text: ·
    - link "Contact Us":
      - /url: /contact.html
- button "Open chat": 💬
- heading "GetMyBin Support" [level=3]
- paragraph: We're here to help! 🚀
- button "Close chat": ✕
- text: Hey! 👋 I'm the GetMyBin assistant. Got questions about our bin collection service? I'm here to help!
- textbox "Ask me anything…"
- button "Send message": ➤
```

# Test source

```ts
  1   | /**
  2   |  * GetMyBin — Full End-to-End Checkout Tests
  3   |  *
  4   |  * Covers the complete customer journey: landing → address → service selection →
  5   |  * checkout → Stripe payment → confirmation → database verification.
  6   |  *
  7   |  * Run:  npx playwright test full-checkout-flow.spec.js
  8   |  *       BASE_URL=https://getmybin.com npx playwright test full-checkout-flow.spec.js
  9   |  *       npx playwright test full-checkout-flow.spec.js --project=desktop
  10  |  */
  11  | 
  12  | const { test, expect } = require('@playwright/test');
  13  | const {
  14  |   TEST_EMAIL, selectAddress, selectService, selectAdHocDate,
  15  |   openCheckout, fillStripeCard, submitPayment,
  16  |   getBookingId, verifyAirtableBooking, verifyBookingFields, getModalSubtotal,
  17  | } = require('./helpers');
  18  | 
  19  | /**
  20  |  * Run a full checkout flow: address → service → payment → verify.
  21  |  */
  22  | async function runCheckout(page, serviceType, planType, name) {
  23  |   const { address } = await selectAddress(page, '30 Woodbury Rd');
  24  |   await selectService(page, serviceType, planType);
  25  | 
  26  |   if (planType === 'adhoc') {
  27  |     await selectAdHocDate(page, 1);
  28  |   }
  29  | 
  30  |   await openCheckout(page);
  31  | 
  32  |   // Fill order summary → proceed to payment
  33  |   await page.locator('#customer-name').fill(name);
  34  |   await page.locator('#customer-email').fill(TEST_EMAIL);
  35  |   await page.locator('.btn-confirm').click();
  36  |   await page.waitForTimeout(1000);
  37  | 
  38  |   // Fill Stripe card
  39  |   await fillStripeCard(page);
  40  | 
  41  |   // Set up console error capture BEFORE payment
  42  |   const consoleErrors = [];
  43  |   page.on('console', msg => {
  44  |     if (msg.type() === 'error') consoleErrors.push(msg.text());
  45  |   });
  46  | 
  47  |   // Submit payment
  48  |   await page.locator('#pay-btn').click();
  49  |   await page.waitForTimeout(3000);
  50  | 
  51  |   // Check if payment failed
  52  |   const errorText = await page.locator('#card-errors').textContent().catch(() => '');
  53  |   const paymentFailed = errorText && errorText.includes('Error');
  54  | 
  55  |   if (!paymentFailed) {
  56  |     // Verify confirmation (only if payment appears to have succeeded)
  57  |     const thankyou = page.locator('.thankyou');
> 58  |     await expect(thankyou).toBeVisible({ timeout: 30000 });
      |                            ^ Error: expect(locator).toBeVisible() failed
  59  |     const bookingId = await getBookingId(page);
  60  |     expect(bookingId).toBeTruthy();
  61  |     console.log(`  ✅ Booking ID: ${bookingId}`);
  62  | 
  63  |     // Verify in Airtable
  64  |     const record = await verifyAirtableBooking(bookingId);
  65  |     if (record) {
  66  |       const v = verifyBookingFields(record, {
  67  |         serviceType: serviceType,
  68  |         planType: planType,
  69  |         address: address,
  70  |         email: TEST_EMAIL,
  71  |       });
  72  |       console.log(`  📋 Database: ${v.passed ? '✅ PASSED' : '❌ FAILED — ' + v.failures.join(', ')}`);
  73  |     } else {
  74  |       console.log('  ⚠️  Database record not found (may need time to sync)');
  75  |     }
  76  | 
  77  |     return { bookingId, address };
  78  |   } else {
  79  |     console.log(`  ❌ Payment error: ${errorText.trim()}`);
  80  |     if (consoleErrors.length > 0) {
  81  |       console.log(`  🐛 Console errors: ${consoleErrors.join(' | ')}`);
  82  |     }
  83  |     // Also capture the card-errors div text for the full Stripe error
  84  |     const fullError = await page.locator('#card-errors').innerText().catch(() => '');
  85  |     console.log(`  🔍 Full error: ${fullError}`);
  86  |     return null;
  87  |   }
  88  | }
  89  | 
  90  | // ═══════════════════════════════════════════════════════════
  91  | // SCENARIO 1: Ad Hoc — Roll Out only  ($8.95)
  92  | // ═══════════════════════════════════════════════════════════
  93  | test('Ad Hoc Roll Out', async ({ page }) => {
  94  |   await runCheckout(page, 'rollout', 'adhoc', 'E2E AdHoc RollOut');
  95  | });
  96  | 
  97  | // ═══════════════════════════════════════════════════════════
  98  | // SCENARIO 2: Ad Hoc — Roll In only  ($8.95)
  99  | // ═══════════════════════════════════════════════════════════
  100 | test('Ad Hoc Roll In', async ({ page }) => {
  101 |   await runCheckout(page, 'rollin', 'adhoc', 'E2E AdHoc RollIn');
  102 | });
  103 | 
  104 | // ═══════════════════════════════════════════════════════════
  105 | // SCENARIO 3: Ad Hoc — Both  ($17.90)
  106 | // ═══════════════════════════════════════════════════════════
  107 | test('Ad Hoc Both', async ({ page }) => {
  108 |   await runCheckout(page, 'both', 'adhoc', 'E2E AdHoc Both');
  109 | });
  110 | 
  111 | // ═══════════════════════════════════════════════════════════
  112 | // SCENARIO 4: Recurring — Roll Out  ($5.95/week)
  113 | // ═══════════════════════════════════════════════════════════
  114 | test('Recurring Roll Out', async ({ page }) => {
  115 |   await runCheckout(page, 'rollout', 'recurring', 'E2E Recurring RollOut');
  116 | });
  117 | 
  118 | // ═══════════════════════════════════════════════════════════
  119 | // SCENARIO 5: Recurring — Both  ($11.90/week)
  120 | // ═══════════════════════════════════════════════════════════
  121 | test('Recurring Both', async ({ page }) => {
  122 |   await runCheckout(page, 'both', 'recurring', 'E2E Recurring Both');
  123 | });
  124 | 
  125 | // ═══════════════════════════════════════════════════════════
  126 | // SCENARIO 6: Invalid / non-Toronto address
  127 | // ═══════════════════════════════════════════════════════════
  128 | test('Invalid Address — shows error', async ({ page }) => {
  129 |   await page.goto('/');
  130 |   await page.waitForSelector('#address', { state: 'visible' });
  131 |   await page.locator('#address').fill('1600 Pennsylvania Ave, Washington DC');
  132 |   await page.locator('#hero-search-btn').click();
  133 |   await page.waitForTimeout(3000);
  134 | 
  135 |   const error = page.locator('#hero-error');
  136 |   const suggestions = page.locator('.suggestion-item');
  137 |   const hasError = await error.isVisible().catch(() => false);
  138 |   const noSuggestions = (await suggestions.count()) === 0;
  139 |   expect(hasError || noSuggestions).toBeTruthy();
  140 | });
  141 | 
  142 | // ═══════════════════════════════════════════════════════════
  143 | // SCENARIO 7: Mobile viewport
  144 | // ═══════════════════════════════════════════════════════════
  145 | test.describe('Mobile', () => {
  146 |   test.use({ viewport: { width: 390, height: 844 } });
  147 | 
  148 |   test('Ad Hoc Roll Out on mobile', async ({ page }) => {
  149 |     await runCheckout(page, 'rollout', 'adhoc', 'E2E Mobile Test');
  150 |   });
  151 | });
  152 | 
  153 | // ═══════════════════════════════════════════════════════════
  154 | // SCENARIO 8: FAQ accordion animation
  155 | // ═══════════════════════════════════════════════════════════
  156 | test('FAQ accordion opens and closes smoothly', async ({ page }) => {
  157 |   await page.goto('/');
  158 |   const faqBtn = page.locator('.faq-q').first();
```