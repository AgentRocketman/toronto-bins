# GetMyBin E2E Test Suite

Playwright end-to-end tests covering the full customer journey from landing page to payment + database verification.

## Quick Start

```bash
npm run test:e2e              # against dev (agentrocketman.com)
npm run test:e2e:prod          # against production (getmybin.com)

# Run individual scenarios
npx playwright test full-checkout-flow.spec.js -g "Ad Hoc Roll Out"
npx playwright test full-checkout-flow.spec.js --project=desktop

# View test report
npm run test:e2e:report
```

## Test Scenarios

| # | Scenario | Service | Plan | Status |
|---|----------|---------|------|--------|
| 1 | Ad Hoc Roll Out | roll-out | ad-hoc | ✅ |
| 2 | Ad Hoc Roll In | roll-in | ad-hoc | ✅ |
| 3 | Ad Hoc Both | both | ad-hoc | ✅ |
| 4 | Recurring Roll Out | roll-out | recurring | ⚠️ backend |
| 5 | Recurring Both | both | recurring | ⚠️ backend |
| 6 | Invalid Address | — | — | ✅ |
| 7 | Mobile Checkout | roll-out | ad-hoc | ✅ |
| 8 | FAQ Accordion | — | — | ✅ |

**Each checkout test:**
1. Opens the landing page
2. Types an address, selects from autocomplete
3. Selects service type + plan
4. Picks dates (ad-hoc) or confirms subscription (recurring)
5. Opens checkout modal → fills name/email → proceeds to Stripe payment
6. Fills test card (4242…) in Stripe iframes
7. Clicks Pay → waits for confirmation
8. Verifies Booking ID in DOM
9. Queries Airtable to confirm the record was created with correct fields

**Known issue:** Recurring (subscription) payments return "Payment failed" from the backend. The tests detect this and log it without blocking the suite.

## Architecture

```
tests/e2e/
├── playwright.config.js          # Desktop + mobile projects
├── helpers.js                    # Shared utilities (address, payment, Airtable)
└── full-checkout-flow.spec.js    # All test scenarios
```

Key design decisions:
- **Stripe Elements** are handled via frame locators (`frameLocator('iframe[title*="card" i]')`)
- **Airtable verification** uses the REST API directly (key in helpers.js)
- **Mobile** uses 390×844 viewport per Chris's preference
- Tests use **real API endpoints** (City of Toronto geocoder, Stripe test mode, Airtable)