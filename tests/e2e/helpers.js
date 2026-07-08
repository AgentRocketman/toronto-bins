/**
 * Shared E2E test helpers for GetMyBin checkout flow.
 */

const TEST_CARD = { number: '4242424242424242', exp: '1234', cvc: '123' };
const TEST_EMAIL = 'e2e-test@getmybin.com';
const BASE = process.env.BASE_URL || 'https://agentrocketman.com';

// Airtable verification config
const AIRTABLE_KEY = 'patxbDkv88pOMXmYx.c7e5fd7974954e3a674087090835d11dd69504f3912f0ef86c3c59f1e91febdd';
const AIRTABLE_BASE = 'apptYNRJTXwItvied';
const AIRTABLE_BOOKINGS = 'tblKMhGnYjsH0z7Lj';

/**
 * Navigate, enter an address, and wait for the booking section to appear.
 * @param {import('@playwright/test').Page} page
 * @param {string} query - partial address to type (e.g. "30 Woodbury")
 * @returns {Promise<{address: string, day: string}>}
 */
async function selectAddress(page, query = '30 Woodbury Rd') {
  await page.goto('/');
  await page.waitForSelector('#address', { state: 'visible' });

  // Type the address
  const input = page.locator('#address');
  await input.fill(query);

  // Wait for suggestion dropdown
  const suggestion = page.locator('.suggestion-item').first();
  await suggestion.waitFor({ state: 'visible', timeout: 10000 });
  await suggestion.click();

  // Wait for booking section to reveal
  const booking = page.locator('#booking-section');
  await booking.waitFor({ state: 'attached', timeout: 15000 });
  await page.waitForTimeout(1000); // transition

  // Read back displayed address
  const addr = await page.locator('#ac-address-text').textContent();
  const day = await page.locator('#ac-day-text').textContent();
  return { address: addr.trim(), day: day.trim() };
}

/**
 * Select a service type and plan.
 */
async function selectService(page, serviceType, planType) {
  // Click service type
  await page.locator(`#service-toggle [data-val="${serviceType}"]`).click();
  await page.waitForTimeout(200);

  // Click plan type
  await page.locator(`#plan-toggle [data-val="${planType}"]`).click();
  await page.waitForTimeout(200);
}

/**
 * Select ad hoc dates (clicks the next available date buttons).
 */
async function selectAdHocDate(page, count = 1) {
  const dateBtns = page.locator('.date-card:not(.cutoff)');
  const available = await dateBtns.count();
  if (available === 0) throw new Error('No available date buttons found');
  const toClick = Math.min(count, available);
  for (let i = 0; i < toClick; i++) {
    await dateBtns.nth(i).click();
    await page.waitForTimeout(150);
  }
  return toClick;
}

/**
 * Click Go to Checkout and wait for the modal.
 */
async function openCheckout(page) {
  await page.locator('#btn-checkout').click();
  await page.locator('#modal-overlay.open').waitFor({ state: 'visible', timeout: 10000 });
}

/**
 * Fill the Stripe card form (handles iframes).
 */
async function fillStripeCard(page) {
  // Card number iframe
  const cardFrame = page.frameLocator('iframe[title*="card number" i]').first();
  await cardFrame.locator('[placeholder="Card number"]').fill(TEST_CARD.number);

  // Expiry iframe
  const expFrame = page.frameLocator('iframe[title*="expir" i]').first();
  await expFrame.locator('[placeholder="MM / YY"]').fill(TEST_CARD.exp);

  // CVC iframe
  const cvcFrame = page.frameLocator('iframe[title*="CVC" i]').first();
  await cvcFrame.locator('[placeholder="CVC"]').fill(TEST_CARD.cvc);
}

/**
 * Fill email field in the modal (if present).
 */
async function fillEmail(page, email = TEST_EMAIL) {
  const emailInput = page.locator('#modal-body input[type="email"]');
  if (await emailInput.isVisible({ timeout: 2000 }).catch(() => false)) {
    await emailInput.fill(email);
  }
}

/**
 * Click the Pay/Confirm button in the modal and wait for success.
 */
async function submitPayment(page) {
  const payBtn = page.locator('#modal-body .btn-confirm');
  await payBtn.click();

  // Wait for thank-you message or Booking ID
  const thankyou = page.locator('.thankyou');
  await thankyou.waitFor({ state: 'visible', timeout: 30000 });
  await page.waitForTimeout(500);
}

/**
 * Extract the booking ID from the confirmation modal.
 */
async function getBookingId(page) {
  const body = await page.locator('.thankyou').innerText();
  const match = body.match(/Booking ID:\s*(\S+)/);
  return match ? match[1] : null;
}

/**
 * Query Airtable for the booking record by booking ID.
 */
async function verifyAirtableBooking(bookingId) {
  // Wait for Airtable to process the webhook/create the record
  await new Promise(r => setTimeout(r, 3000));
  
  const url = `https://api.airtable.com/v0/${AIRTABLE_BASE}/${AIRTABLE_BOOKINGS}?filterByFormula=%7BBooking+ID%7D%3D%22${encodeURIComponent(bookingId)}%22`;
  const res = await fetch(url, {
    headers: { Authorization: `Bearer ${AIRTABLE_KEY}` },
  });
  const json = await res.json();
  if (!json.records || json.records.length === 0) {
    console.log('  ⚠️  No Airtable record found for', bookingId, '(may need more time to sync)');
    return null;
  }
  return json.records[0];
}

/**
 * Verify booking field values match what was ordered.
 */
function verifyBookingFields(record, expected) {
  const failures = [];
  const fields = record.fields || record;

  // Log what fields actually exist for debugging
  console.log('  📋 Airtable field names:', JSON.stringify(Object.keys(fields)));

  // Map of expected keys → possible Airtable field names
  const fieldMap = {
    serviceType: ['Service Type', 'Service', 'serviceType', 'service_type'],
    planType: ['Plan', 'Frequency', 'planType', 'frequency', 'plan'],
    address: ['Address', 'address'],
    total: ['Total', 'total', 'Amount', 'amount'],
    email: ['Email', 'email', 'Customer Email', 'customerEmail'],
  };

  function findField(key) {
    if (fields[key] !== undefined) return fields[key];
    for (const alias of (fieldMap[key] || [])) {
      if (fields[alias] !== undefined) return fields[alias];
    }
    return undefined;
  }

  if (expected.serviceType) {
    const val = findField('serviceType');
    if (val && val !== expected.serviceType) {
      failures.push(`Service Type: expected "${expected.serviceType}", got "${val}"`);
    }
  }
  if (expected.planType) {
    const val = findField('planType');
    if (val && val !== expected.planType) {
      failures.push(`Plan: expected "${expected.planType}", got "${val}"`);
    }
  }
  if (expected.address) {
    const val = findField('address');
    if (val && val !== expected.address) {
      failures.push(`Address: expected "${expected.address}", got "${val}"`);
    }
  }
  if (expected.total !== undefined) {
    const val = findField('total');
    if (val !== undefined && val !== expected.total) {
      failures.push(`Total: expected ${expected.total}, got ${val}`);
    }
  }
  if (expected.email) {
    const val = findField('email');
    if (val && val !== expected.email) {
      failures.push(`Email: expected "${expected.email}", got "${val}"`);
    }
  }

  return { passed: failures.length === 0, failures, record: fields };
}

/**
 * Get the subtotal displayed in the modal summary.
 */
async function getModalSubtotal(page) {
  await page.locator('.mr-value').first().waitFor({ state: 'visible', timeout: 5000 });
  const text = await page.locator('.mr-value').first().innerText();
  const match = text.match(/\$([\d.]+)/);
  return match ? parseFloat(match[1]) : null;
}

module.exports = {
  TEST_CARD,
  TEST_EMAIL,
  BASE,
  selectAddress,
  selectService,
  selectAdHocDate,
  openCheckout,
  fillStripeCard,
  fillEmail,
  submitPayment,
  getBookingId,
  verifyAirtableBooking,
  verifyBookingFields,
  getModalSubtotal,
};