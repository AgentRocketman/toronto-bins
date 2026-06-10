# CurbIn Firebase + Stripe Setup Guide

## Prerequisites
- Firebase project (free tier OK)
- Stripe account (test mode for development)
- Node.js 18+

## Step 1: Firebase Setup

1. Go to [Firebase Console](https://console.firebase.google.com/)
2. Create a new project or select existing
3. Enable Firestore Database (Start in test mode for development)
4. Go to Project Settings → Service Accounts
5. Copy your Firebase config and replace values in `firebase-config.js`:
   ```javascript
   const firebaseConfig = {
     apiKey: "YOUR_API_KEY",
     authDomain: "your-project.firebaseapp.com",
     projectId: "your-project-id",
     storageBucket: "your-project.appspot.com",
     messagingSenderId: "YOUR_SENDER_ID",
     appId: "YOUR_APP_ID"
   };
   ```

## Step 2: Stripe Setup

1. Go to [Stripe Dashboard](https://dashboard.stripe.com)
2. Get your **Publishable Key** (test mode)
   - Replace `pk_test_YOUR_PUBLISHABLE_KEY` in `stripe-integration.js`
3. Get your **Secret Key** (test mode)
   - Store in `.env` as `STRIPE_SECRET_KEY` (for Cloud Functions)

## Step 3: Deploy Cloud Functions

```bash
cd functions
npm install
firebase login
firebase init functions  # if not already initialized
firebase deploy --only functions
```

During deploy, Firebase will prompt for environment variables. Set:
```
STRIPE_SECRET_KEY=sk_test_your_secret_key
```

## Step 4: Update Firestore Rules (Development)

In Firebase Console → Firestore → Rules, set:
```
rules_version = '2';
service cloud.firestore {
  match /databases/{database}/documents {
    match /bookings/{document=**} {
      allow read, write: if true;  // DEVELOPMENT ONLY - restrict in production
    }
  }
}
```

## Step 5: Include Scripts in HTML

In `index.html`, add before closing `</body>`:
```html
<!-- Firebase SDK -->
<script src="https://www.gstatic.com/firebasejs/10.3.0/firebase-app.js"></script>
<script src="https://www.gstatic.com/firebasejs/10.3.0/firebase-firestore.js"></script>
<script src="https://www.gstatic.com/firebasejs/10.3.0/firebase-functions.js"></script>

<!-- Stripe SDK -->
<script src="https://js.stripe.com/v3/"></script>

<!-- Custom scripts -->
<script src="firebase-config.js"></script>
<script src="stripe-integration.js"></script>
```

## Step 6: Test Stripe Payment

Use test card: **4242 4242 4242 4242**
- Expiry: Any future date
- CVC: Any 3 digits

## Step 7: Production Checklist

- [ ] Switch Stripe to live keys
- [ ] Update Firestore security rules (restrict writes)
- [ ] Set up email confirmations (SendGrid or Firebase Extensions)
- [ ] Add payment confirmation page
- [ ] SSL/HTTPS enabled on GitHub Pages (automatic)
- [ ] Error tracking (Sentry, LogRocket)
- [ ] Test end-to-end payment flow

## Database Schema (Firestore)

Collection: `bookings`

Document fields:
```
{
  id: string,
  customerName: string,
  customerEmail: string,
  customerPhone: string,
  address: string,
  serviceType: "rollOut" | "rollIn" | "other",
  frequency: "adHoc" | "recurring",
  amount: number (in cents),
  stripePaymentId: string,
  status: "completed" | "failed" | "pending",
  createdAt: timestamp
}
```

## Troubleshooting

**"Invalid API Key" error:**
- Check Firebase config values are correct
- Ensure Firestore is enabled in Firebase project

**"Payment failed" error:**
- Verify Stripe secret key is set in Cloud Functions
- Check Stripe test mode is enabled

**"Card element not mounting" error:**
- Ensure Stripe SDK is loaded before `stripe-integration.js`
- Check browser console for errors

## Next Steps

1. Update checkout modal in `index.html` with new Stripe card element
2. Wire up form submission to `processBookingPayment()`
3. Add success/error feedback to UI
4. Set up email confirmations
5. Add booking history/account page

---

For questions, see:
- [Firebase Documentation](https://firebase.google.com/docs)
- [Stripe Documentation](https://stripe.com/docs)
- [Firebase Cloud Functions Guide](https://firebase.google.com/docs/functions)
