// Stripe Integration
// Initialize Stripe with your publishable key

const stripe = Stripe('pk_test_51SFgOXRoaqSc6FkpZuQmOv1ZMOqaAI2L6rkyusqya3XHNp7BQZgFlWuxoF0sARpqKZG5rGxRimiD3ANPMLrd1lsB00ww4XnrwL');
const elements = stripe.elements();

// Use individual card elements to avoid "save card" option
const cardNumber = elements.create('cardNumber', {
  placeholder: 'Card number'
});
const cardExpiry = elements.create('cardExpiry', {
  placeholder: 'MM / YY'
});
const cardCvc = elements.create('cardCvc', {
  placeholder: 'CVC'
});

// Export globally for access in main script
window.cardNumber = cardNumber;
window.cardExpiry = cardExpiry;
window.cardCvc = cardCvc;

let isPaymentProcessing = false;

function initStripeForm() {
  // Mount individual card elements
  try {
    const numberContainer = document.getElementById('card-number');
    const expiryContainer = document.getElementById('card-expiry');
    const cvcContainer = document.getElementById('card-cvc');
    
    if (numberContainer && window.cardNumber) {
      window.cardNumber.mount('#card-number');
    }
    if (expiryContainer && window.cardExpiry) {
      window.cardExpiry.mount('#card-expiry');
    }
    if (cvcContainer && window.cardCvc) {
      window.cardCvc.mount('#card-cvc');
    }
    
    // Handle card errors
    [window.cardNumber, window.cardExpiry, window.cardCvc].forEach(element => {
      if (element) {
        element.addEventListener('change', (event) => {
          const displayError = document.getElementById('card-errors');
          if (displayError && event.error) {
            displayError.textContent = event.error.message;
          } else if (displayError) {
            displayError.textContent = '';
          }
        });
      }
    });
    
    console.log('Card elements mounted');
  } catch (e) {
    console.error('Error mounting card elements:', e);
  }
}

function showPaymentError(message) {
  const displayError = document.getElementById('card-errors');
  if (displayError) {
    displayError.textContent = `Error: ${message}`;
    displayError.style.color = '#fa755a';
  }
}

function clearCardElement() {
  // Individual elements don't have clear, but we can reset if needed
}

async function processBookingPayment(bookingData) {
  if (!bookingData || !bookingData.amount) {
    throw new Error('Invalid booking data');
  }

  try {
    // Create payment method from individual card elements
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

    // Payment method created successfully
    // Save booking data with Stripe payment ID
    bookingData.stripePaymentId = paymentMethod.id;

    let bookingResult;
    let savedLocation = 'unknown';
    try {
      // Try to save to Airtable
      console.log('Attempting to save booking to Airtable...');
      bookingResult = await saveBookingToAirtable(bookingData);
      savedLocation = 'Airtable';
      console.log('✅ Booking saved to Airtable:', bookingResult);
    } catch (airtableErr) {
      console.warn('Airtable save failed, saving to localStorage instead:', airtableErr);
      // Fall back to local storage
      bookingResult = saveBookingLocally(bookingData);
      savedLocation = 'Browser Storage (localStorage)';
      console.log('✅ Booking saved to localStorage:', bookingResult);
    }

    return {
      success: true,
      bookingId: bookingResult.bookingId,
      savedLocation: savedLocation,
      warning: bookingResult.warning
    };
  } catch (err) {
    console.error('Payment processing error:', err);
    showPaymentError(err.message);
    return false;
  }
}

// Initialize on page load
function initializeStripe() {
  console.log('Initializing Stripe...');
  if (typeof Stripe === 'undefined') {
    console.error('Stripe SDK not loaded');
    return;
  }
  console.log('Stripe SDK loaded successfully');
  initStripeForm();
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initializeStripe);
} else {
  initializeStripe();
}
