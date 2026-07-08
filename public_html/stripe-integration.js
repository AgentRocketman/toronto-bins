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
    // Step 1: Tokenize card
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
      return { success: false, error: error.message };
    }

    const pmId = paymentMethod.id;

    // Step 2: Charge or subscribe based on frequency
    if (bookingData.frequency === 'recurring') {
      // --- Recurring: create Stripe Subscription ---
      const subRes = await fetch('/api/create-subscription.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          paymentMethodId: pmId,
          weeklyAmount: bookingData.amount,
          customerName: bookingData.customerName,
          customerEmail: bookingData.customerEmail,
          serviceType: bookingData.serviceType,
          bookingId: 'PENDING'
        })
      });
      const subData = await subRes.json();
      if (!subData.success) {
        const errMsg = subData.error || 'Subscription failed';
        console.error('❌ Subscription API error:', JSON.stringify(subData));
        return { success: false, error: errMsg };
      }

      if (subData.prepaid) {
        // Payment succeeded immediately (no 3D Secure needed)
        bookingData.stripeSubscriptionId = subData.subscriptionId;
        bookingData.stripeCustomerId     = subData.customerId;
        bookingData.stripePaymentId      = subData.paymentIntentId;
        console.log('✅ Stripe subscription created (prepaid):', subData.subscriptionId);
      } else if (subData.clientSecret) {
        // 3D Secure or confirmation needed
        const { paymentIntent, error: confirmError } = await stripe.confirmCardPayment(subData.clientSecret);
        if (confirmError) {
          return { success: false, error: confirmError.message };
        }
        if (paymentIntent.status !== 'succeeded') {
          return { success: false, error: 'Payment not completed. Status: ' + paymentIntent.status };
        }
        bookingData.stripeSubscriptionId = subData.subscriptionId;
        bookingData.stripeCustomerId     = subData.customerId;
        bookingData.stripePaymentId      = paymentIntent.id;
        console.log('✅ Stripe subscription created (confirmed):', subData.subscriptionId);
      } else {
        return { success: false, error: 'No client secret and payment not prepaid' };
      }

    } else {
      // --- Ad hoc: create one-time PaymentIntent ---
      const chargeRes = await fetch('/api/charge-payment.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          paymentMethodId: pmId,
          amount: bookingData.amount,
          customerName: bookingData.customerName,
          customerEmail: bookingData.customerEmail,
          bookingId: 'PENDING'
        })
      });
      const chargeData = await chargeRes.json();

      if (chargeData.requiresAction) {
        // 3D Secure required
        const { paymentIntent, error: confirmError } = await stripe.confirmCardPayment(chargeData.clientSecret);
        if (confirmError) {
          return { success: false, error: confirmError.message };
        }
        if (paymentIntent.status !== 'succeeded') {
          return { success: false, error: 'Payment not completed. Status: ' + paymentIntent.status };
        }
        bookingData.stripePaymentId = paymentIntent.id;
      } else if (chargeData.success) {
        bookingData.stripePaymentId = chargeData.paymentIntentId;
      } else {
        return { success: false, error: chargeData.error || 'Payment failed' };
      }
      console.log('✅ Ad hoc charge succeeded:', bookingData.stripePaymentId);
    }

    // Step 3: Save to Airtable
    let bookingResult;
    let savedLocation = 'unknown';
    try {
      bookingResult = await saveBookingToAirtable(bookingData);
      savedLocation = 'Airtable';
      console.log('✅ Booking saved to Airtable:', bookingResult);
    } catch (airtableErr) {
      console.warn('Airtable save failed, falling back to localStorage:', airtableErr);
      bookingResult = saveBookingLocally(bookingData);
      savedLocation = 'Browser Storage (localStorage)';
    }

    return {
      success: true,
      bookingId: bookingResult.bookingId,
      savedLocation: savedLocation,
      warning: bookingResult.warning
    };

  } catch (err) {
    console.error('Payment processing error:', err);
    return { success: false, error: err.message };
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
