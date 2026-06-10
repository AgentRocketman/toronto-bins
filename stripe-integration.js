// Stripe Integration
// Initialize Stripe with your publishable key

const stripe = Stripe('pk_test_51SFgOXRoaqSc6FkpZuQmOv1ZMOqaAI2L6rkyusqya3XHNp7BQZgFlWuxoF0sARpqKZG5rGxRimiD3ANPMLrd1lsB00ww4XnrwL');
const elements = stripe.elements();
const cardElement = elements.create('card');

let isPaymentProcessing = false;

function initStripeForm() {
  const cardContainer = document.getElementById('card-element');
  if (cardContainer) {
    cardElement.mount('#card-element');
    
    // Handle card errors
    cardElement.addEventListener('change', (event) => {
      const displayError = document.getElementById('card-errors');
      if (event.error) {
        displayError.textContent = event.error.message;
      } else {
        displayError.textContent = '';
      }
    });
  }
}

async function processBookingPayment(bookingData) {
  if (isPaymentProcessing) return false;
  
  isPaymentProcessing = true;
  
  try {
    // Create payment method from card
    const { paymentMethod, error } = await stripe.createPaymentMethod({
      type: 'card',
      card: cardElement,
      billing_details: {
        name: bookingData.customerName,
        email: bookingData.customerEmail,
        phone: bookingData.customerPhone
      }
    });

    if (error) {
      showPaymentError(error.message);
      isPaymentProcessing = false;
      return false;
    }

    // Call Cloud Function to process payment
    const response = await window.processPayment({
      paymentMethodId: paymentMethod.id,
      amount: bookingData.amount,
      customerEmail: bookingData.customerEmail,
      customerName: bookingData.customerName,
      customerPhone: bookingData.customerPhone,
      address: bookingData.address,
      serviceType: bookingData.serviceType,
      frequency: bookingData.frequency
    });

    if (response.data.success) {
      return {
        success: true,
        bookingId: response.data.bookingId,
        confirmationUrl: response.data.confirmationUrl
      };
    } else {
      showPaymentError(response.data.error || 'Payment failed');
      isPaymentProcessing = false;
      return false;
    }
  } catch (err) {
    showPaymentError(err.message);
    isPaymentProcessing = false;
    return false;
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
  cardElement.clear();
}

// Initialize on page load
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initStripeForm);
} else {
  initStripeForm();
}
