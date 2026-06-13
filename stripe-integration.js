// Stripe Integration
const STRIPE_PK = 'pk_test_51SFgOXRoaqSc6FkpZuQmOv1ZMOqaAI2L6rkyusqya3XHNp7BQZgFlWuxoF0sARpqKZG5rGxRimiD3ANPMLrd1lsB00ww4XnrwL';

let stripeInstance = null;
let stripeElements = null;
let cardNumber = null;
let cardExpiry = null;
let cardCvc = null;
let isPaymentProcessing = false;

function getStripe() {
  if (!stripeInstance) {
    stripeInstance = Stripe(STRIPE_PK);
  }
  return stripeInstance;
}

// Expose to global scope
window.getStripe = getStripe;

function initStripeForm() {
  try {
    const numberContainer = document.getElementById('card-number');
    const expiryContainer = document.getElementById('card-expiry');
    const cvcContainer = document.getElementById('card-cvc');

    if (!numberContainer || !expiryContainer || !cvcContainer) {
      console.warn('Card containers not found');
      return;
    }

    // Always create fresh elements to avoid remount issues on iOS Safari
    if (cardNumber) { try { cardNumber.destroy(); } catch(e) {} }
    if (cardExpiry) { try { cardExpiry.destroy(); } catch(e) {} }
    if (cardCvc) { try { cardCvc.destroy(); } catch(e) {} }

    const stripe = getStripe();
    stripeElements = stripe.elements();

    cardNumber = stripeElements.create('cardNumber', { placeholder: 'Card number' });
    cardExpiry = stripeElements.create('cardExpiry', { placeholder: 'MM / YY' });
    cardCvc = stripeElements.create('cardCvc', { placeholder: 'CVC' });

    // Clear containers before mounting
    numberContainer.innerHTML = '';
    expiryContainer.innerHTML = '';
    cvcContainer.innerHTML = '';

    cardNumber.mount('#card-number');
    cardExpiry.mount('#card-expiry');
    cardCvc.mount('#card-cvc');

    // Expose globally for payment processing
    window.cardNumber = cardNumber;
    window.cardExpiry = cardExpiry;
    window.cardCvc = cardCvc;

    [cardNumber, cardExpiry, cardCvc].forEach(el => {
      el.addEventListener('change', (event) => {
        const displayError = document.getElementById('card-errors');
        if (displayError) {
          displayError.textContent = event.error ? event.error.message : '';
        }
      });
    });

    console.log('Stripe card elements mounted');
  } catch (e) {
    console.error('Error mounting Stripe elements:', e);
  }
}

function showPaymentError(message) {
  const displayError = document.getElementById('card-errors');
  if (displayError) {
    displayError.textContent = `Error: ${message}`;
    displayError.style.color = '#fa755a';
  }
}

async function processBookingPayment(bookingData) {
  if (!bookingData || !bookingData.amount) {
    throw new Error('Invalid booking data: ' + JSON.stringify(bookingData));
  }

  try {
    showPaymentError('Processing booking...'); // Show progress
    
    // Demo mode: skip Stripe tokenization, stripePaymentId already set in index.html
    // bookingData.stripePaymentId should already be set to 'demo_<timestamp>'

    let bookingResult;
    let savedLocation = 'unknown';
    
    // Always use localStorage for now (Airtable API key requirement removed for demo)
    try {
      bookingResult = saveBookingLocally(bookingData);
      savedLocation = 'Browser Storage (Demo Mode)';
    } catch (storageErr) {
      console.error('Local storage save failed:', storageErr);
      showPaymentError('Storage error: ' + storageErr.message);
      throw storageErr;
    }

    return {
      success: true,
      bookingId: bookingResult.bookingId,
      savedLocation: savedLocation,
      warning: bookingResult.warning
    };
  } catch (err) {
    console.error('Payment processing error:', err);
    const errorMsg = 'ERROR: ' + (err.message || 'Unknown payment error');
    showPaymentError(errorMsg);
    return false;
  }
}

// Expose to global scope for payment processing
window.processBookingPayment = processBookingPayment;
window.initStripeForm = initStripeForm;
window.showPaymentError = showPaymentError;
