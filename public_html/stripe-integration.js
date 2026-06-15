// Stripe Integration
const stripe = Stripe('pk_test_51SFgOXRoaqSc6FkpZuQmOv1ZMOqaAI2L6rkyusqya3XHNp7BQZgFlWuxoF0sARpqKZG5rGxRimiD3ANPMLrd1lsB00ww4XnrwL');
const elements = stripe.elements();

let cardNumber = null;
let cardExpiry = null;
let cardCvc = null;

function initStripeForm() {
  // Check containers FIRST — never create elements before the form is in the DOM.
  // On page load the modal body hasn't been injected yet, so we bail out cleanly
  // without creating any Stripe elements. Creating then destroying never-mounted
  // elements corrupts the elements instance and breaks subsequent mounts.
  const numberContainer = document.getElementById('card-number');
  const expiryContainer = document.getElementById('card-expiry');
  const cvcContainer    = document.getElementById('card-cvc');

  if (!numberContainer || !expiryContainer || !cvcContainer) {
    return; // containers not in DOM yet — called again when form opens
  }

  // Destroy any previously mounted elements
  if (cardNumber) { try { cardNumber.destroy(); } catch(e) {} cardNumber = null; }
  if (cardExpiry) { try { cardExpiry.destroy(); } catch(e) {} cardExpiry = null; }
  if (cardCvc)    { try { cardCvc.destroy();    } catch(e) {} cardCvc    = null; }

  const style = {
    base: {
      fontSize: '16px',
      color: '#32325d',
      '::placeholder': { color: '#aab7c4' }
    },
    invalid: { color: '#fa755a' }
  };

  // Create fresh elements from the clean instance
  cardNumber = elements.create('cardNumber', { style });
  cardExpiry = elements.create('cardExpiry', { style });
  cardCvc    = elements.create('cardCvc',    { style });

  window.cardNumber = cardNumber;
  window.cardExpiry = cardExpiry;
  window.cardCvc    = cardCvc;

  // Mount to DOM elements directly (more reliable than CSS selector strings)
  cardNumber.mount(numberContainer);
  cardExpiry.mount(expiryContainer);
  cardCvc.mount(cvcContainer);

  [cardNumber, cardExpiry, cardCvc].forEach(el => {
    el.addEventListener('change', (event) => {
      const displayError = document.getElementById('card-errors');
      if (displayError) {
        displayError.textContent = event.error ? event.error.message : '';
      }
    });
  });

  console.log('Stripe card elements mounted');
}

function showPaymentError(message) {
  const displayError = document.getElementById('card-errors');
  if (displayError) {
    displayError.textContent = `Error: ${message}`;
    displayError.style.color = '#fa755a';
  }
}

function clearCardElement() {}

async function processBookingPayment(bookingData) {
  if (!bookingData || !bookingData.amount) throw new Error('Invalid booking data');
  if (!window.cardNumber) {
    showPaymentError('Card form not initialized. Please refresh and try again.');
    return false;
  }

  try {
    const { paymentMethod, error } = await stripe.createPaymentMethod({
      type: 'card',
      card: window.cardNumber,
      billing_details: {
        name: bookingData.customerName,
        email: bookingData.customerEmail,
        phone: bookingData.customerPhone
      }
    });

    if (error) {
      showPaymentError(error.message);
      return false;
    }

    bookingData.stripePaymentId = paymentMethod.id;

    let bookingResult, savedLocation;
    try {
      bookingResult = await saveBookingToAirtable(bookingData);
      savedLocation = 'Airtable';
    } catch (airtableErr) {
      console.warn('Airtable save failed, using localStorage:', airtableErr);
      bookingResult = saveBookingLocally(bookingData);
      savedLocation = 'Browser Storage (localStorage)';
    }

    return { success: true, bookingId: bookingResult.bookingId, savedLocation, warning: bookingResult.warning };
  } catch (err) {
    console.error('Payment error:', err);
    showPaymentError(err.message);
    return false;
  }
}

// initStripeForm is called by showStripeForm() in index.html when the payment
// step opens. Calling it here on DOMContentLoaded is harmless — the containers
// won't exist so it returns immediately without creating anything.
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initStripeForm);
} else {
  initStripeForm();
}
