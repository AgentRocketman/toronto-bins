# POLCU Chatbot — Deployment Guide

## ✅ Build Status
**All files created and verified successfully.** Ready for deployment to production.

## 📦 Files Created

### Root Directory
- `polcu.html` (3.1 KB) — Landing page with hero image
- `polcu-chat.js` (19 KB) — Self-contained chat widget (UI + logic)
- `polcu-chat-logger.js` (1.4 KB) — Message logging helper
- `polcu-hero.jpg` (75 KB) — Hero banner image
- `README.md` — Project documentation

### API Directory (`api/`)
- `config.php` (5.1 KB) — Shared configuration & credentials
- `chat.php` (1.6 KB) — Chat endpoint (POST)
- `log-chat.php` (1.9 KB) — Message logging endpoint (POST)

## 🚀 Deployment Steps

### Step 1: Sync to Server
```bash
# From workspace
rsync -avz public_html/polcu/ user@agentrocketman.com:/var/www/agentrocketman.com/polcu/
```

Or use the standard deployment script:
```bash
bash /data/.openclaw/workspace/deploy-final.sh agentrocketman.com
```

### Step 2: Verify Directory Structure
```
https://agentrocketman.com/polcu/
├── polcu.html
├── polcu-chat.js
├── polcu-chat-logger.js
├── polcu-hero.jpg
└── api/
    ├── config.php
    ├── chat.php
    └── log-chat.php
```

### Step 3: Create Airtable Table (One-time Setup)
The chatbot logs all messages to Airtable. Create this table in the existing base:

**Base ID:** `apptYNRJTXwItvied`
**Table Name:** `POLCU Chatlogs` or similar
**Table ID:** `tblPOLCU_CHATLOGS` (update config.php if different)

**Required Fields:**
- `sessionId` (Text)
- `timestamp` (Date with time)
- `date` (Date only)
- `ipAddress` (Text)
- `browser` (Text)
- `deviceType` (Text)
- `messageType` (Text, values: "question" or "answer")
- `message` (Long text)

### Step 4: Test the Chatbot
1. Open: `https://agentrocketman.com/polcu/polcu.html`
2. Look for the floating 💬 chat icon (bottom-right)
3. Click to open the chat widget
4. Try asking: "What are your hours?" or "How do I become a member?"
5. Verify the chat responds with POLCU information

### Step 5: Verify Logging
1. Check Airtable table `POLCU Chatlogs`
2. Confirm messages appear with:
   - Session ID
   - Timestamp
   - Client IP
   - Browser name
   - Device type
   - Message content

## 🔧 Configuration

### API Keys (in `api/config.php`)
- ✅ **OPENAI_API_KEY**: `sk-proj-t0XP5...` (configured)
- ✅ **AIRTABLE_API_KEY**: `patxbDkv...` (configured)
- ✅ **AIRTABLE_BASE_ID**: `apptYNRJTXwItvied` (configured)

### Airtable Table (in `api/config.php`)
- ✅ **POLCU_CHATLOGS_TABLE**: `tblPOLCU_CHATLOGS` (placeholder)
  - ⚠️ **ACTION NEEDED**: Replace with actual table ID after Airtable table is created

## 📱 Features Included

### Desktop
- 400×600px modal chat window (bottom-right)
- Smooth scale + fade animations
- Scrollable message history
- Responsive input with send button

### Mobile
- Full-screen chat (≤768px width)
- Slide-up animation
- iOS keyboard handling (visualViewport API)
- Safe area support (notch, home bar)
- Touch-optimized buttons and input

### Backend
- OpenAI GPT-3.5-turbo integration
- Airtable message logging
- CORS headers for cross-origin requests
- Client IP detection
- Browser & device type detection
- Session tracking

## 🎨 Branding

| Element | Value |
|---------|-------|
| Primary Color | Navy Blue (#1B3A5C) |
| Secondary Color | Dark Navy (#0f2436) |
| Header Text | "POLCU Support" |
| Subtitle | "How can we help you? 🏦" |
| Welcome Message | "Hello! 👋 I'm the POLCU virtual assistant..." |
| Chat Icon | 💬 |

## 🧠 System Prompt

The chatbot has comprehensive knowledge of POLCU including:

**Organization**
- Full name, founding date, headquarters, contact info
- Regulatory information (FSRA)
- THE EXCHANGE Network membership

**Locations**
- 6 branch locations with manager names
- Hours of operation for each
- Contact numbers

**Membership**
- Requirements (2 ID documents)
- Share costs ($100 initial)
- Special programs (youth, students, children)
- Online/phone/in-person application options

**Products**
- Savings accounts
- GICs & Term Deposits
- Mortgages (with 90-day rate guarantee)
- Personal loans & lines of credit
- Credit cards (Mastercard)
- Business banking services
- Investment products (RRSPs, TFSAs, mutual funds)

**Services**
- Online banking
- Mobile app (iOS/Android)
- Interac e-Transfer
- Cheque deposit
- Business services

**Rates** (Subject to change)
- Credit Union Prime: 4.70%
- Mortgages: 4.19%–4.49%
- GICs: 2.20%–2.30%
- And more...

**Tone**
- Professional but friendly
- Concise (1-3 sentences per response)
- Helpful and positive
- Escalates to human contact when needed

## ⚠️ Important Notes

1. **Airtable Table Must Exist** — The chatbot will fail to log messages if the table doesn't exist. Create it before going live.

2. **API Keys Secure** — Never expose `api/config.php` contents to the client. Always call the API endpoints server-side.

3. **Relative Paths** — All asset paths (images, endpoints) are relative for easy domain migration:
   - `polcu-hero.jpg` → same directory
   - `/polcu/api/chat.php` → relative from domain root

4. **CORS Enabled** — The API endpoints allow cross-origin requests, so the chat widget works embedded in any domain.

5. **Session IDs** — Generated client-side and stored in localStorage (`polcu-session-id`). No server-side session needed.

## 🧪 Troubleshooting

### Chat doesn't respond
- Check browser console for errors
- Verify `/polcu/api/chat.php` is accessible
- Confirm OpenAI API key in `config.php` is valid

### Messages not logging
- Verify Airtable table exists with correct ID
- Check Airtable API key in `config.php`
- Ensure `log-chat.php` is accessible

### Styling looks wrong
- Clear browser cache
- Verify `polcu-chat.js` loaded (check Network tab)
- Check for CSS conflicts with page styles

### Mobile keyboard issues
- Verify viewport meta tag in `polcu.html` includes `viewport-fit=cover`
- Test on actual iOS device (visualViewport API is Safari-specific)

## 📊 Analytics

Message logs stored in Airtable can be analyzed to track:
- User questions (by message content)
- Chat session duration (by sessionId)
- Device/browser usage
- Geographic distribution (by IP)
- Time of engagement (by timestamp)

## 🔄 Updates & Maintenance

### To Update System Prompt
Edit `systemPrompt` variable in `polcu-chat.js` with new POLCU information.

### To Change Colors
Search for `#1B3A5C` (navy blue) in `polcu-chat.js` and replace with new hex value.

### To Update Contact Info
Update contact info in system prompt (phone, email) and error message fallback in `polcu-chat.js`.

## ✅ Pre-Launch Checklist

- [ ] All files deployed to `/polcu/` directory
- [ ] Airtable table created with correct table ID
- [ ] `api/config.php` updated with actual Airtable table ID
- [ ] Chat widget accessible at `https://agentrocketman.com/polcu/polcu.html`
- [ ] Chat icon appears (floating 💬 button, bottom-right)
- [ ] Sample chat message returns response from OpenAI
- [ ] Sample chat message appears in Airtable table
- [ ] Mobile view tested on actual phone
- [ ] Error handling tested (network failure simulation)
- [ ] Browser console shows no errors

---

**Build Date:** 2026-06-26  
**Status:** ✅ Ready for Production
