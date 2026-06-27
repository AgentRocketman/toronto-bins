# POLCU Customer Service Chatbot

A complete customer service chatbot for St. Stanislaus - St. Casimir's Polish Parishes Credit Union Limited (POLCU).

## Project Structure

```
polcu/
├── polcu.html                    # Main landing page (shows hero image + loads chat widget)
├── polcu-chat.js                 # Chat widget (self-contained IIFE with styles & logic)
├── polcu-chat-logger.js          # Chat logging helper (logs messages to backend)
├── polcu-hero.jpg                # Hero image (already provided)
└── api/
    ├── config.php                # Shared configuration (API keys, helpers)
    ├── chat.php                  # Chat endpoint (POST /polcu/api/chat.php)
    └── log-chat.php              # Logging endpoint (POST /polcu/api/log-chat.php)
```

## How It Works

1. **Frontend (polcu.html)**
   - Clean landing page with centered hero image
   - Loads `polcu-chat-logger.js` and `polcu-chat.js` scripts

2. **Chat Widget (polcu-chat.js)**
   - Self-contained IIFE that injects CSS and builds the chat DOM
   - Floating chat icon (bottom-right, navy blue #1B3A5C)
   - Mobile-responsive (full-screen on ≤768px width)
   - Handles keyboard on iOS (visualViewport API)
   - Sends user messages to `/polcu/api/chat.php`

3. **Chat Logging (polcu-chat-logger.js)**
   - Manages session ID in localStorage (key: `polcu-session-id`)
   - Logs questions and answers to `/polcu/api/log-chat.php`
   - Exposes `window.polcuLogMessage()` and `window.polcuGetSessionId()`

4. **Backend API**
   - **config.php** — Shared configuration, OpenAI & Airtable credentials, helper functions
   - **chat.php** — Receives messages, calls OpenAI GPT-3.5-turbo, returns response
   - **log-chat.php** — Logs messages to Airtable (POLCU_CHATLOGS_TABLE)

## System Prompt

The chatbot's system prompt includes comprehensive knowledge of POLCU:

- **About POLCU**: Founded 1945, head office, phone, email, website
- **6 Branches**: Toronto (2), Mississauga (2), Milton, Chesley + hours
- **Membership**: Requirements, share costs, age groups
- **Rates**: Prime, mortgages, loans, GICs, daily savings
- **Products**: Savings, borrowing, investing, spending, business banking
- **Digital Banking**: Online, mobile app, e-Transfer, cheque deposit
- **Key Contacts**: CEO, CFO, VP IT, lending specialist, mobile specialist
- **Tone**: Professional but friendly, short responses (1-3 sentences max), helpful

## Branding

- **Color**: Navy blue (#1B3A5C) — matches POLCU logo
- **Header**: "POLCU Support" with subtitle "How can we help you? 🏦"
- **Welcome**: "Hello! 👋 I'm the POLCU virtual assistant..."
- **Error Fallback**: Direct to phone (1-855-765-2822) and email (memberrelations@polcu.com)

## Airtable Integration

Messages are logged to Airtable base `apptYNRJTXwItvied` in the table `tblPOLCU_CHATLOGS` with fields:
- `sessionId`: Unique session identifier
- `timestamp`: ISO 8601 timestamp
- `date`: YYYY-MM-DD
- `ipAddress`: Client IP
- `browser`: Chrome, Safari, Firefox, etc.
- `deviceType`: desktop, mobile, tablet
- `messageType`: question or answer
- `message`: Text content

## Deployment

1. Copy all files to `agentrocketman.com/polcu/`
2. Ensure `polcu/api/` directory exists with full read/write permissions
3. Verify Airtable table `tblPOLCU_CHATLOGS` exists (create if needed)
4. Test by opening `https://agentrocketman.com/polcu/polcu.html`

## Mobile Behavior

- **Desktop (>768px)**: Chat appears as 400x600px modal in bottom-right
- **Mobile (≤768px)**: Chat is full-screen with slide-up animation
- **Keyboard**: iOS keyboard handling via visualViewport API ensures input stays visible
- **Safe areas**: Notch and home-bar aware (via CSS `env()` functions)

## Security

- API keys (OpenAI, Airtable) stored server-side in `config.php`
- CORS headers allow cross-origin requests
- CSRF/XSS protection via proper JSON encoding
- No sensitive data exposed to client

## Customization

### Change Colors
Edit `polcu-chat.js`:
```javascript
background: linear-gradient(135deg, #1B3A5C, #0f2436);  // Change these hex values
```

### Update System Prompt
Edit the `systemPrompt` in `polcu-chat.js` with new POLCU information.

### Change Chat Header
Edit the `.polcu-header` section in `polcu-chat.js` or the `innerHTML` in `buildUI()`.

## Notes

- All paths are relative (e.g., `polcu-hero.jpg`, `api/chat.php`) for easy domain migration
- Hero image is already provided at deployment time
- Session IDs are generated client-side and stored in localStorage
- No user authentication required (public chatbot)
