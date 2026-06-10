const functions = require('firebase-functions');
const admin = require('firebase-admin');
const stripe = require('stripe')(process.env.STRIPE_SECRET_KEY);

admin.initializeApp();
const db = admin.firestore();

// Process payment and store booking
exports.processPayment = functions.https.onCall(async (data, context) => {
  try {
    const {
      paymentMethodId,
      amount,
      customerEmail,
      customerName,
      customerPhone,
      address,
      serviceType,
      frequency
    } = data;

    // Validate input
    if (!amount || amount < 100) {
      throw new functions.https.HttpsError('invalid-argument', 'Invalid amount');
    }

    // Create Stripe Payment Intent
    const paymentIntent = await stripe.paymentIntents.create({
      amount: amount, // in cents
      currency: 'cad',
      payment_method: paymentMethodId,
      confirm: true,
      return_url: 'https://agentrocketman.github.io/toronto-bins/',
      description: `${serviceType} - ${frequency}`
    });

    if (paymentIntent.status !== 'succeeded') {
      throw new functions.https.HttpsError('payment-failed', 'Payment could not be processed');
    }

    // Save booking to Firestore
    const bookingRef = db.collection('bookings').doc();
    await bookingRef.set({
      id: bookingRef.id,
      customerName,
      customerEmail,
      customerPhone,
      address,
      serviceType,
      frequency,
      amount,
      stripePaymentId: paymentIntent.id,
      status: 'completed',
      createdAt: admin.firestore.FieldValue.serverTimestamp()
    });

    // TODO: Send confirmation email via SendGrid or Firebase Extensions
    // sendConfirmationEmail(customerEmail, bookingRef.id);

    return {
      success: true,
      bookingId: bookingRef.id,
      confirmationUrl: `https://agentrocketman.github.io/toronto-bins/?booking=${bookingRef.id}`
    };
  } catch (error) {
    console.error('Payment processing error:', error);
    throw new functions.https.HttpsError('internal', error.message);
  }
});

// Get booking details
exports.getBooking = functions.https.onCall(async (data, context) => {
  try {
    const { bookingId } = data;
    const doc = await db.collection('bookings').doc(bookingId).get();
    
    if (!doc.exists) {
      throw new functions.https.HttpsError('not-found', 'Booking not found');
    }

    return { success: true, booking: doc.data() };
  } catch (error) {
    throw new functions.https.HttpsError('internal', error.message);
  }
});
