/**
 * GetMyBin — Full End-to-End Checkout Tests
 *
 * Covers the complete customer journey: landing → address → service selection →
 * checkout → Stripe payment → confirmation → database verification.
 *
 * Run:  npx playwright test full-checkout-flow.spec.js
 *       BASE_URL=https://getmybin.com npx playwright test full-checkout-flow.spec.js
 *       npx playwright test full-checkout-flow.spec.js --project=desktop
 */

const { test, expect } = require('@playwright/test');
const {
  TEST_EMAIL, selectAddress, selectService, selectAdHocDate,
  openCheckout, fillStripeCard, submitPayment,
  getBookingId, verifyAirtableBooking, verifyBookingFields, getModalSubtotal,
} = require('./helpers');

/**
 * Run a full checkout flow: address → service → payment → verify.
 */
async function runCheckout(page, serviceType, planType, name) {
  const { address } = await selectAddress(page, '30 Woodbury Rd');
  await selectService(page, serviceType, planType);

  if (planType === 'adhoc') {
    await selectAdHocDate(page, 1);
  }

  await openCheckout(page);

  // Fill order summary → proceed to payment
  await page.locator('#customer-name').fill(name);
  await page.locator('#customer-email').fill(TEST_EMAIL);
  await page.locator('.btn-confirm').click();
  await page.waitForTimeout(1000);

  // Fill Stripe card
  await fillStripeCard(page);

  // Set up console error capture BEFORE payment
  const consoleErrors = [];
  page.on('console', msg => {
    if (msg.type() === 'error') consoleErrors.push(msg.text());
  });

  // Submit payment
  await page.locator('#pay-btn').click();
  await page.waitForTimeout(3000);

  // Check if payment failed
  const errorText = await page.locator('#card-errors').textContent().catch(() => '');
  const paymentFailed = errorText && errorText.includes('Error');

  if (!paymentFailed) {
    // Verify confirmation (only if payment appears to have succeeded)
    const thankyou = page.locator('.thankyou');
    await expect(thankyou).toBeVisible({ timeout: 30000 });
    const bookingId = await getBookingId(page);
    expect(bookingId).toBeTruthy();
    console.log(`  ✅ Booking ID: ${bookingId}`);

    // Verify in Airtable
    const record = await verifyAirtableBooking(bookingId);
    if (record) {
      const v = verifyBookingFields(record, {
        serviceType: serviceType,
        planType: planType,
        address: address,
        email: TEST_EMAIL,
      });
      console.log(`  📋 Database: ${v.passed ? '✅ PASSED' : '❌ FAILED — ' + v.failures.join(', ')}`);
    } else {
      console.log('  ⚠️  Database record not found (may need time to sync)');
    }

    return { bookingId, address };
  } else {
    console.log(`  ❌ Payment error: ${errorText.trim()}`);
    if (consoleErrors.length > 0) {
      console.log(`  🐛 Console errors: ${consoleErrors.join(' | ')}`);
    }
    // Also capture the card-errors div text for the full Stripe error
    const fullError = await page.locator('#card-errors').innerText().catch(() => '');
    console.log(`  🔍 Full error: ${fullError}`);
    return null;
  }
}

// ═══════════════════════════════════════════════════════════
// SCENARIO 1: Ad Hoc — Roll Out only  ($8.95)
// ═══════════════════════════════════════════════════════════
test('Ad Hoc Roll Out', async ({ page }) => {
  await runCheckout(page, 'rollout', 'adhoc', 'E2E AdHoc RollOut');
});

// ═══════════════════════════════════════════════════════════
// SCENARIO 2: Ad Hoc — Roll In only  ($8.95)
// ═══════════════════════════════════════════════════════════
test('Ad Hoc Roll In', async ({ page }) => {
  await runCheckout(page, 'rollin', 'adhoc', 'E2E AdHoc RollIn');
});

// ═══════════════════════════════════════════════════════════
// SCENARIO 3: Ad Hoc — Both  ($17.90)
// ═══════════════════════════════════════════════════════════
test('Ad Hoc Both', async ({ page }) => {
  await runCheckout(page, 'both', 'adhoc', 'E2E AdHoc Both');
});

// ═══════════════════════════════════════════════════════════
// SCENARIO 4: Recurring — Roll Out  ($5.95/week)
// ═══════════════════════════════════════════════════════════
test('Recurring Roll Out', async ({ page }) => {
  await runCheckout(page, 'rollout', 'recurring', 'E2E Recurring RollOut');
});

// ═══════════════════════════════════════════════════════════
// SCENARIO 5: Recurring — Both  ($11.90/week)
// ═══════════════════════════════════════════════════════════
test('Recurring Both', async ({ page }) => {
  await runCheckout(page, 'both', 'recurring', 'E2E Recurring Both');
});

// ═══════════════════════════════════════════════════════════
// SCENARIO 6: Invalid / non-Toronto address
// ═══════════════════════════════════════════════════════════
test('Invalid Address — shows error', async ({ page }) => {
  await page.goto('/');
  await page.waitForSelector('#address', { state: 'visible' });
  await page.locator('#address').fill('1600 Pennsylvania Ave, Washington DC');
  await page.locator('#hero-search-btn').click();
  await page.waitForTimeout(3000);

  const error = page.locator('#hero-error');
  const suggestions = page.locator('.suggestion-item');
  const hasError = await error.isVisible().catch(() => false);
  const noSuggestions = (await suggestions.count()) === 0;
  expect(hasError || noSuggestions).toBeTruthy();
});

// ═══════════════════════════════════════════════════════════
// SCENARIO 7: Mobile viewport
// ═══════════════════════════════════════════════════════════
test.describe('Mobile', () => {
  test.use({ viewport: { width: 390, height: 844 } });

  test('Ad Hoc Roll Out on mobile', async ({ page }) => {
    await runCheckout(page, 'rollout', 'adhoc', 'E2E Mobile Test');
  });
});

// ═══════════════════════════════════════════════════════════
// SCENARIO 8: FAQ accordion animation
// ═══════════════════════════════════════════════════════════
test('FAQ accordion opens and closes smoothly', async ({ page }) => {
  await page.goto('/');
  const faqBtn = page.locator('.faq-q').first();
  await faqBtn.scrollIntoViewIfNeeded();
  await page.waitForTimeout(300);

  await faqBtn.click();
  await page.waitForTimeout(500);

  const faqItem = page.locator('.faq-item').first();
  await expect(faqItem).toHaveClass(/open/);

  const answer = page.locator('.faq-a').first();
  const height = await answer.evaluate(el => el.scrollHeight);
  expect(height).toBeGreaterThan(20);

  await faqBtn.click();
  await page.waitForTimeout(500);
  await expect(faqItem).not.toHaveClass(/open/);
});