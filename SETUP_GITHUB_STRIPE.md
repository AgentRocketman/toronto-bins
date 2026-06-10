# CurbIn Stripe + GitHub Bookings Setup

Simple checkout flow: **Stripe for payments** + **GitHub API for storing bookings**.

## What's Already Set Up

✅ **Stripe Integration** — Stripe SDK loaded, card payment ready
✅ **GitHub Bookings** — Saves to `bookings.csv` in your repo
✅ **Fallback Storage** — Browser localStorage if GitHub API fails

## What You Need

### 1. Stripe Publishable Key (Already Added ✅)
Your publishable key is configured in `stripe-integration.js`.

**Test it with card:** `4242 4242 4242 4242` (any future expiry, any CVC)

### 2. GitHub Personal Access Token (Required for Bookings)

To save bookings to your repository:

1. Go to https://github.com/settings/tokens
2. Click **"Generate new token"** → **"Generate new token (classic)"**
3. Give it a name: `CurbIn Bookings`
4. Set expiration: 90 days (or longer)
5. Check scopes:
   - ✅ `repo` (full control of private repositories)
   - ✅ `workflow` (optional, for automation later)
6. Click **"Generate token"**
7. **Copy the token immediately** — you won't see it again

**Security Note:** This token is requested in the browser via `prompt()` and stored in `sessionStorage` (cleared when browser closes). It's never sent anywhere except GitHub API.

## How It Works

1. **Customer fills checkout form** — Name, Email, Phone
2. **Clicks "Proceed to Payment"**
3. **Stripe card form appears** — Enter test card
4. **After payment succeeds:**
   - Browser prompts for your GitHub token (first time only, then stored for session)
   - Booking is saved to `bookings.csv` in your repo
   - Fallback: If GitHub API fails, booking is saved to browser localStorage

## Testing

1. Go to: **https://agentrocketman.github.io/toronto-bins/**
2. Search an address
3. Select a service and date(s)
4. Click **"Go to Checkout"**
5. Fill form + select test card `4242 4242 4242 4242`
6. Click **"Pay"**
7. When prompted, paste your GitHub token
8. Check **https://github.com/AgentRocketman/toronto-bins/blob/main/bookings.csv** for your booking!

## File Structure

```
toronto-bins/
├── index.html                    (main app, checkout modal)
├── stripe-integration.js         (Stripe card handling)
├── github-bookings.js           (GitHub API + local storage)
├── logo.jpg                     (branding)
├── bookings.csv                 (auto-generated, bookings list)
└── SETUP_GITHUB_STRIPE.md       (this file)
```

## Bookings CSV Format

```
Timestamp,Booking ID,Name,Email,Phone,Address,Service,Frequency,Amount,Stripe Payment ID
2026-06-10T15:35:00Z,"BK-ABC123D","Chris D","chris@example.com","416-555-1234","123 Queen St W, Toronto","Roll Out","Ad Hoc","19.95","pm_1234567890"
```

Each booking gets:
- Unique ID (e.g., `BK-ABC123D`)
- Full timestamp
- Customer details
- Service choice
- Amount (in CAD)
- Stripe payment method reference

## Next Steps

1. **Generate your GitHub token** (above)
2. **Test checkout** on the live site
3. **Review bookings** in your repo's `bookings.csv`
4. **Automation options** (coming later):
   - GitHub Actions → send confirmation emails
   - Google Sheets sync
   - Zapier integration

## Troubleshooting

**"Token required" prompt keeps appearing:**
- Your token might have expired
- Or browser cleared sessionStorage
- Just paste a fresh token

**Booking saved locally, not on GitHub:**
- GitHub token was rejected (likely invalid or expired)
- Check your token: https://github.com/settings/tokens
- Look in browser DevTools console for error details

**No bookings appearing:**
- Check `bookings.csv` doesn't exist in main branch yet (will be created on first booking)
- Verify your Stripe test card worked (should see success message)

---

**Questions?** Check browser console (F12 → Console) for detailed logs.
