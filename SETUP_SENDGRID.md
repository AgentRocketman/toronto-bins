# SendGrid Setup for CurbIn

## Quick Start

**Cost:** Free tier = 100 emails/day (more than enough for a growing service)

### Step 1: Create SendGrid Account
1. Go to https://sendgrid.com
2. Sign up for free
3. Verify your email

### Step 2: Create API Key
1. In SendGrid dashboard, go to **Settings > API Keys**
2. Click **Create API Key**
3. Name it: `CurbIn` (or whatever you like)
4. Give it **Mail Send** permission
5. Copy the key (starts with `SG.`)
6. ⚠️ **Save it somewhere safe** — you can't see it again

### Step 3: Verify Sender Email
Before you can send emails, SendGrid needs to verify the sender:

1. In dashboard, go to **Settings > Sender Authentication**
2. Click **Create New Sender**
3. Enter:
   - **From Email:** support@curbin.ca
   - **From Name:** CurbIn
   - **Address:** (your address)
   - Fill out the rest of the form
4. Check your email for verification link from SendGrid
5. Click the link to verify

### Step 4: Configure in App
When you make the first payment:
1. Website asks: "Enter your SendGrid API key"
2. Paste the key you created in Step 2
3. Key is stored securely in your browser (cleared when you close browser)
4. Emails now send automatically! 📧

## How It Works

### When Payment Succeeds:
1. **Customer Email** → Booking confirmation with details (address, dates, amount)
2. **Admin Email** → Notification to support@curbin.ca with order summary

### Email Templates
- Both emails are HTML-formatted and look professional
- Includes booking ID, service details, amount paid
- Admin email links to Airtable for easy follow-up

## Troubleshooting

**"Email not received"**
- Check spam folder
- Make sure sender email (support@curbin.ca) is verified in SendGrid
- Check browser console for errors

**"API key rejected"**
- Make sure key starts with `SG.`
- Verify you copied it completely
- Try re-generating a new key in SendGrid

**Free tier limits**
- 100 emails/day (enough for ~400 bookings/month)
- If you hit limits, upgrade plan ($20/month for 10,000 emails)

## Cost

- **Free tier:** 100 emails/day = $0/month
- **Pro:** $20/month for 10,000+ emails
- **Pay-as-you-go:** After 100/day, $0.10 per email

For a growing startup, free tier → $20/month is the natural progression.
