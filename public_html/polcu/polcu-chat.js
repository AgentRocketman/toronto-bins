// POLCU AI Chat Widget — Complete Rewrite (mobile-first)
// Self-contained: injects styles, builds DOM, handles chat logic.

(function () {
  'use strict';

  /* ─── CSS ────────────────────────────────────────────────────── */
  const CSS = `
/* Reset for widget elements */
.polcu-chat *, .polcu-chat *::before, .polcu-chat *::after,
.polcu-icon, .polcu-overlay {
  box-sizing: border-box;
  margin: 0;
  padding: 0;
  -webkit-tap-highlight-color: transparent;
}

/* ── Floating icon ── */
.polcu-icon {
  position: fixed;
  bottom: 120px;
  right: 24px;
  width: 60px;
  height: 60px;
  background: linear-gradient(135deg, #213c92, #182b68);
  border-radius: 50%;
  border: none;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 4px 14px rgba(27,58,92,.35);
  z-index: 10000;
  font-size: 28px;
  transition: transform .2s, box-shadow .2s;
  -webkit-appearance: none;
}
.polcu-icon:hover  { transform: scale(1.08); box-shadow: 0 6px 18px rgba(27,58,92,.45); }
.polcu-icon:active { transform: scale(.94); }
.polcu-icon.polcu-hidden { display: none; }

/* ── Overlay ── */
.polcu-overlay {
  position: fixed; inset: 0;
  background: rgba(0,0,0,.35);
  z-index: 10001;
  opacity: 0;
  pointer-events: none;
  transition: opacity .25s;
}
.polcu-overlay.polcu-show {
  opacity: 1;
  pointer-events: auto;
}

/* ── Chat modal (desktop default) ── */
.polcu-chat {
  position: fixed;
  bottom: 96px;
  right: 24px;
  width: 400px;
  height: 600px;
  max-height: calc(100vh - 120px);
  background: #fff;
  border-radius: 14px;
  box-shadow: 0 8px 40px rgba(0,0,0,.18);
  z-index: 10002;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  transform: scale(.92) translateY(16px);
  opacity: 0;
  pointer-events: none;
  transition: transform .3s ease, opacity .25s ease;
}
.polcu-chat.polcu-show {
  transform: scale(1) translateY(0);
  opacity: 1;
  pointer-events: auto;
}

/* ── Header ── */
.polcu-header {
  flex-shrink: 0;
  background: linear-gradient(135deg, #213c92, #182b68);
  color: #fff;
  padding: 16px 16px 14px;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.polcu-header-text h3 { font-size: 17px; font-weight: 700; line-height: 1.2; }
.polcu-header-text p  { font-size: 12px; opacity: .88; margin-top: 2px; }
.polcu-close {
  background: none; border: none; color: #fff;
  font-size: 22px; cursor: pointer;
  width: 32px; height: 32px;
  display: flex; align-items: center; justify-content: center;
  border-radius: 50%;
  transition: background .15s;
  flex-shrink: 0;
}
.polcu-close:hover { background: rgba(255,255,255,.18); }

/* ── Messages ── */
.polcu-messages {
  flex: 1 1 auto;
  overflow-y: auto;
  -webkit-overflow-scrolling: touch;
  overscroll-behavior-y: contain;
  padding: 14px 14px 8px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.polcu-messages::-webkit-scrollbar       { width: 5px; }
.polcu-messages::-webkit-scrollbar-track  { background: transparent; }
.polcu-messages::-webkit-scrollbar-thumb  { background: #d4d4d4; border-radius: 3px; }

/* ── Message bubbles ── */
.polcu-msg {
  display: flex;
  animation: polcuSlide .28s ease;
}
@keyframes polcuSlide {
  from { opacity: 0; transform: translateY(8px); }
  to   { opacity: 1; transform: translateY(0); }
}
.polcu-msg.polcu-user      { justify-content: flex-end; }
.polcu-msg.polcu-assistant  { justify-content: flex-start; }
.polcu-bubble {
  max-width: 82%;
  padding: 10px 14px;
  border-radius: 14px;
  font-size: 14px;
  line-height: 1.45;
  word-break: break-word;
}
.polcu-user .polcu-bubble {
  background: #f0f0f0; color: #222;
  border-bottom-right-radius: 4px;
}
.polcu-assistant .polcu-bubble {
  background: #e8f0f8; color: #222;
  border-bottom-left-radius: 4px;
}
.polcu-assistant .polcu-bubble a {
  color: #213c92;
  text-decoration: underline;
  font-weight: 500;
}
.polcu-assistant .polcu-bubble a:hover {
  color: #182b68;
}

/* ── Typing indicator ── */
.polcu-typing { display: flex; gap: 5px; padding: 10px 14px; }
.polcu-dot {
  width: 7px; height: 7px; border-radius: 50%;
  background: #213c92;
  animation: polcuBounce 1.3s infinite;
}
.polcu-dot:nth-child(2) { animation-delay: .15s; }
.polcu-dot:nth-child(3) { animation-delay: .3s;  }
@keyframes polcuBounce {
  0%,60%,100% { opacity: .25; transform: translateY(0); }
  30%          { opacity: 1;   transform: translateY(-6px); }
}

/* ── Input area ── */
.polcu-input-area {
  flex-shrink: 0;
  display: flex;
  gap: 8px;
  padding: 10px 12px;
  border-top: 1px solid #e8e8e8;
  background: #fff;
}
.polcu-input {
  flex: 1;
  border: 1px solid #ddd;
  border-radius: 22px;
  padding: 10px 16px;
  font-size: 16px;          /* ≥16 px prevents iOS auto-zoom */
  font-family: inherit;
  outline: none;
  transition: border-color .2s;
  -webkit-appearance: none;
  appearance: none;
}
.polcu-input:focus { border-color: #213c92; }
.polcu-send {
  flex-shrink: 0;
  width: 40px; height: 40px;
  background: #213c92; color: #fff;
  border: none; border-radius: 50%;
  font-size: 18px;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer;
  transition: background .15s, transform .15s;
}
.polcu-send:hover  { background: #182b68; }
.polcu-send:active { transform: scale(.92); }
.polcu-send:disabled { background: #ccc; cursor: not-allowed; transform: none; }

/* ── Mic button ── */
.polcu-mic {
  flex-shrink: 0;
  width: 40px; height: 40px;
  background: #e8f0f8; color: #213c92;
  border: 1px solid #d0dae8; border-radius: 50%;
  font-size: 18px;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer;
  transition: background .15s, transform .15s, box-shadow .15s;
}
.polcu-mic:hover { background: #d8e4f4; }
.polcu-mic:active { transform: scale(.92); }
.polcu-mic.polcu-recording {
  background: #ff4444; color: #fff; border-color: #ff4444;
  animation: polcuPulse 1s infinite;
}
@keyframes polcuPulse {
  0%, 100% { box-shadow: 0 0 0 0 rgba(255,68,68,.4); }
  50% { box-shadow: 0 0 0 8px rgba(255,68,68,0); }
}

/* ── Speaker button on bubbles ── */
.polcu-speak-btn {
  background: none; border: none; cursor: pointer;
  font-size: 14px; padding: 2px 4px; margin-left: 6px;
  opacity: 0.5; transition: opacity .15s;
  vertical-align: middle;
}
.polcu-speak-btn:hover { opacity: 1; }
.polcu-speak-btn.polcu-playing { opacity: 1; }

/* ═══ MOBILE (≤ 768 px) ═══ */
@media (max-width: 768px) {
  .polcu-icon {
    bottom: 100px; right: 16px;
    width: 56px; height: 56px;
    font-size: 26px;
  }

  .polcu-chat {
    /* Full-screen on mobile */
    inset: 0;
    width: 100%;
    height: 100%;
    max-height: 100%;
    border-radius: 0;
    transform: translateY(100%);
    opacity: 1;               /* slide-up instead of scale */
  }
  .polcu-chat.polcu-show {
    transform: translateY(0);
  }

  .polcu-header {
    /* Safe area for notch */
    padding-top: max(16px, env(safe-area-inset-top, 0px));
    min-height: 56px;
  }

  .polcu-input-area {
    /* Safe area for home-bar */
    padding-bottom: max(10px, env(safe-area-inset-bottom, 0px));
  }
}

/* ═══ Keyboard-visible tweaks (visualViewport-driven class) ═══ */
.polcu-chat.polcu-kb-open .polcu-input-area {
  padding-bottom: 6px;        /* keyboard already fills safe area */
}
`;

  /* ─── Configuration ──────────────────────────────────────────── */
  const CFG = {
    endpoint: atob('aHR0cHM6Ly9hZ2VudHJvY2tldG1hbi5jb20vcG9sY3UvYXBpL2NoYXQucGhw'),
    maxMessages: 50,
    systemPrompt: `You are the friendly and professional POLCU virtual assistant, representing St. Stanislaus - St. Casimir's Polish Parishes Credit Union Limited.

ABOUT POLCU:
- Full name: St. Stanislaus - St. Casimir's Polish Parishes Credit Union Limited
- Founded: August 9, 1945 by Oblate Fathers
- Head Office: 220 Roncesvalles Avenue, Toronto, ON M6R 2L7
- Toll-free: 1-855-765-2822
- Email: memberrelations@polcu.com
- Website: www.polcu.com
- Regulated by FSRA (Financial Services Regulatory Authority of Ontario)
- Part of THE EXCHANGE Network (free ATM access at 3,000+ ATMs)

IMPORTANT — SISTER BRANCHES:
POLCU, Dundalk District Credit Union, and Adjala Savings are ALL the same organization operating under different trade names. They share the same banking system, products, and services. Members from any branch can visit ANY other branch location. All are trade names of St. Stanislaus – St. Casimir's Polish Parishes Credit Union Limited.

BRANCHES (8 locations across 3 brand names):

--- POLCU BRANCHES ---
1. Toronto - Roncesvalles (Head Office): 220 Roncesvalles Ave, Toronto ON M6R 2L7 | Manager: Malgorzata Piorkowska | Tel: 416-537-2181 | Fax: 416-536-6822 | Hours: Mon-Wed 10am-5pm, Thu 12pm-7pm, Fri 10am-5pm
2. Toronto - Lakeshore: 3055 Lake Shore Blvd West, Toronto ON M8V 1K6 | Manager: Danuta Misko | Tel: 416-503-9463 | Fax: 416-503-9459 | Hours: Mon-Wed 10am-5pm, Thu 12pm-7pm, Fri 10am-5pm
3. Mississauga - Dundas West: 3145 Dundas St West, Unit 5, Mississauga ON L5L 5V8 | Manager: Agnieszka Slawinski | Tel: 905-828-7333 | Fax: 905-828-7751 | Hours: Mon-Wed 10am-5pm, Thu 12pm-7pm, Fri 10am-5pm
4. Mississauga - Dixie: 3615 Dixie Rd (WISLA Plaza), Mississauga ON L4Y 4H4 | Manager: Alicja Grela | Tel: 905-629-0365 | Fax: 905-629-9515 | Hours: Mon-Wed 10am-5pm, Thu 12pm-7pm, Fri 10am-5pm
5. Milton: 377 Main St East, Milton ON L9T 1P7 | Manager: Malgorzata Kaznowska | Tel: 905-272-1260 | Fax: 905-636-8010 | Hours: Mon-Wed 10am-5pm, Thu 12pm-7pm, Fri 10am-5pm
6. Chesley: 47-1st Avenue South, Chesley ON N0G 1L0 | Manager: Jeff Vandervoort | Tel: 519-363-7351 | Fax: 519-363-7354 | Hours: Mon-Fri 9am-4pm

SPECIALIZED DEPARTMENTS:
- Loans / Mortgages Department: 12005 Steeles Ave, Georgetown ON L7G 4S6 | Toll Free: 1-855-765-2822 | Fax: 905-878-8536
  - Anna Kornas, Senior Retail Account Manager: Tel 905-629-0365 ext. 1402 / 905-629-6634 | Email: kornasa@polcu.com
- Investment Department: 220 Roncesvalles Ave, Toronto ON M6R 2L7 | Tel: 416-537-2181 ext. 1283/1291
  - Michal Dziedzic, Investment Manager & Mutual Funds Specialist (Aviso Wealth): ext. 1283 | Email: dziedzm@polcu.com
- Wealth Management: Katarzyna Nycz, CCO / VP Branch Services: ext. 1201 | Email: nyczk@polcu.com
- Mobile Financial Specialist: 647-297-5675 (comes to you, 7 days/week)

--- DUNDALK DISTRICT CREDIT UNION (dundalkcu.ca) ---
7. Dundalk: 79 Proton St N, PO Box 340, Dundalk ON N0C 1B0 | Tel: 519-923-2400 | Fax: 519-923-2950 | Transit: 01362 828 | Hours: Tue-Fri 9:30am-4:30pm, Sat 9am-12pm, Mon/Sun Closed | NOTE: Use intercom buttons at front/back door to access office during business hours
   - Board Chair: Dale Pallister | CEO overseen by 7-member elected board
   - Community driven since 1943
   - Former Feversham location was permanently closed in 2023
   - Website: https://dundalkcu.ca
   - Rates: https://dundalkcu.ca/rates/
   - Fees: https://dundalkcu.ca/fees/
   - Agricultural lending: https://dundalkcu.ca/borrowing/agricultural-credit/
   - Commercial lending: https://dundalkcu.ca/borrowing/commercial-credit/
   - Contact: https://dundalkcu.ca/contact-us/

--- ADJALA SAVINGS (adjala.ca) ---
8. Adjala (Colgan): 7320 St. James Lane, Colgan ON L0G 1W0 (3 km west of Tottenham off the 5th Line) | Tel: 905-936-2761 | Fax: 905-936-6391 | Hours: Mon 9am-5pm, Wed 9am-5pm, Fri 9am-5pm, Tue/Thu/Sat/Sun Closed
   - Founded 1946 in Colgan, Ontario — open bond credit union
   - Located in Simcoe County near Honda Canada
   - Website: https://adjala.ca
   - Rates: https://adjala.ca/node/7
   - Location & Hours: https://adjala.ca/node/14
   - About Us: https://adjala.ca/node/4
   - Become a Member: https://adjala.ca/node/6

SHARED SERVICES ACROSS ALL BRANCHES:
- Same Prime Rate: 4.70%
- Same mortgage rates: 3yr 4.19%, 4yr 4.44%, 5yr 4.49%
- Same GIC rates: 1yr 2.20%, 2yr 2.20%, 3yr 2.25%, 4yr 2.25%, 5yr 2.30%
- Agricultural & Commercial lending available through Dundalk and POLCU
- THE EXCHANGE Network ATM access (3,000+ free ATMs)
- Online Banking, Mobile App, e-Transfers
- All regulated by FSRA (Financial Services Regulatory Authority of Ontario)

DUNDALK-SPECIFIC FEES (effective Jan 15, 2022):
- Personal Chequing: $3.00/month | Agricultural/Business Chequing: $7.00/month
- In-branch bill payment: $1.25 | Online bill payment: $1.00 | Senior (59+): Free
- Debit POS: $0.50 | ATM at Dundalk: Free | ATM at other institution: $1.75
- e-Transfer send: $1.00 | e-Transfer receive: Free
- Safety Deposit Boxes: $25-$40/year
- Dormant account (2+ years no activity): lesser of balance or $50/year
- Full fee schedule: https://dundalkcu.ca/fees/

When a member asks about a specific branch, provide the FULL address, postal code, phone, fax, hours, and any relevant details.
When asked about the nearest branch, ask which city/area they are in, then recommend the closest one with full details.
If asked about agricultural or commercial lending, mention Dundalk branch specifically as they have dedicated agricultural & commercial credit officers.
Always clarify that all three brands (POLCU, Dundalk District, Adjala Savings) are the same organization and members can use any branch.

MEMBERSHIP:
- Need 2 pieces of ID (1 with photo): Driver's Licence, Passport, Citizenship Certificate, PR Card, SIN
- Purchase 10 membership shares at $10 each = $100
- Child/Youth (6-15): 1 share at $10, parent/guardian signs
- Students (16-25): 10 shares at $10 each, no parent signature needed
- Apply online, by phone (1-855-765-2822), or in-branch
- Shares refunded if membership cancelled

CURRENT RATES (subject to change):
- Credit Union Prime: 4.70%
- Mortgages: 3yr Closed 4.19% | 4yr Closed 4.44% | 5yr Closed 4.49%
- Personal Loans (variable): From 5.95%
- Overdraft LOC: 15.95% | Personal LOC: From 7.20% | HELOC: Prime + 0.50% | Student LOC: Prime + 1%
- GICs: 1yr 2.20% | 2yr 2.20% | 3yr 2.25% | 4yr 2.25% | 5yr 2.30%
- Daily Savings: 0.05%

PRODUCTS - SAVING:
- Daily Savings, Premium Savings, Silver Savings
- GICs/Term Deposits (30 days to 5 years)
- CYS Accounts (child/youth/student up to age 25)
- Registered Accounts (RRSPs, RRIFs, RESPs, TFSAs)

PRODUCTS - BORROWING:
- Mortgages (open/closed, first/second), 90-day rate guarantee, up to 20% lump sum prepayments
- HELOC (up to 65% home equity, Prime + 0.50%)
- Personal Loans, Line of Credit, Student LOC
- $1,000 Cash Back Mortgages promotion

PRODUCTS - INVESTING:
- GICs, Index-Linked Term Deposits, Mutual Funds (through Aviso Wealth)
- RRSPs, RRIFs, RESPs, TFSAs
- 2026 TFSA Limit: $7,000
- Investment Specialist: Michal Dziedzic (ext. 1283, dziedzm@polcu.com)

PRODUCTS - SPENDING:
- Personal Chequing Accounts, Credit Cards (Mastercard)
- 0% Balance Transfer Offer on credit cards

DIGITAL BANKING:
- Online Banking, Mobile App (iOS/Android - search "polcu")
- Deposit Anywhere (mobile cheque deposit), Interac e-Transfer ($1 fee to send, free to receive)
- 2-Step Verification required for online banking
- Telephone Banking available

INTERAC e-TRANSFER LIMITS:
- Per transaction: Send up to $3,000 | Receive up to $10,000
- Daily: Send $10,000 | Receive $10,000
- Monthly: Send $20,000 | Receive $300,000

BUSINESS BANKING:
- Business accounts ($100 membership), Merchant Services (Moneris)
- Commercial loans (CSBFP - up to $250,000), Agricultural loans
- Business lines of credit, CRA tax payments online

KEY CONTACTS:
- CEO: Andrzej Pitek
- CFO: Tomasz Cudzich
- VP IT: Chris Dicuk
- CCO: Katarzyna Nycz (nyczk@polcu.com)
- Lending: Anna Kornas (kornasa@polcu.com, 905-629-0365 ext 1402)
- Mobile Financial Specialist: 647-297-5675 (comes to you, 7 days/week)
- HR: hr@polcu.com
- General Support: 1-855-765-2822 or memberrelations@polcu.com

⚠️ IMPORTANT — SYSTEM CONVERSION (JULY 17, 2026):
POLCU is upgrading its core operating system starting July 17, 2026. Key details:
- Some services may be temporarily unavailable during the conversion period
- Account numbers, products, and services will NOT change
- Online banking will NOT look significantly different after the conversion
- NEW LOGIN CREDENTIALS after July 17:
  - Username: Your Debit Card Number
  - Temporary Password: AccountNumberLastName! (example: 10001234Smith!)
  - After first login, members must create a new password with: uppercase + lowercase letters, at least 1 special character (! @ # $ %), minimum 9 characters, maximum 128 characters
- Members WITHOUT a debit card must contact their local branch as soon as possible
- Why debit card number for login? It's a common practice among financial institutions, provides unique identification, and enhances account security
- Polish language notice also available (Zawiadomienie dostępne również w języku polskim)

PAGE DIRECTORY — Always include the relevant link when referring members to a page:
- Rates (all rates, GIC, mortgage, loan, savings): https://www.polcu.com/rates
- FAQ (e-Transfer, general questions): https://www.polcu.com/faq
- Become a Member / Join: https://www.polcu.com/becoming-a-member
- Branch Hours: https://www.polcu.com/branch-hours
- Holiday Hours: https://www.polcu.com/about-us/holiday-hours
- Find Branch / ATM: https://www.polcu.com/about-us/find-branch-atm
- Contact Us: https://www.polcu.com/about-us/contact-us
- Savings Accounts: https://www.polcu.com/saving/savings-accounts
- GICs / Term Deposits: https://www.polcu.com/saving/gics
- CYS Youth Accounts: https://www.polcu.com/saving/cys-accounts
- Registered Accounts (RRSP, RRIF, RESP, TFSA): https://www.polcu.com/saving/registered-accounts
- Chequing Accounts: https://www.polcu.com/spending/chequing-accounts
- Mortgages: https://www.polcu.com/borrowing/mortgages
- Home Equity Line of Credit (HELOC): https://www.polcu.com/borrowing/home-equity-line-of-credit
- Personal Loan: https://www.polcu.com/borrowing/personal-loan
- Line of Credit: https://www.polcu.com/borrowing/line-of-credit
- RRSPs: https://www.polcu.com/investing/rrsps
- RRIFs: https://www.polcu.com/investing/rrifs
- RESPs: https://www.polcu.com/investing/resps
- TFSAs: https://www.polcu.com/investing/tfsas
- Mutual Funds: https://www.polcu.com/investing/mutual-funds
- Index-Linked Term Deposits: https://www.polcu.com/investing/index-linked-term-deposits
- Investing Contact (advisor): https://www.polcu.com/investing/contact-us
- Mobile App: https://www.polcu.com/personal/banking/ways-to-bank/mobile-app
- Deposit Anywhere (mobile cheque deposit): https://www.polcu.com/personal/banking/ways-to-bank/deposit-anywhere
- Ways to Bank: https://www.polcu.com/personal/banking/ways-to-bank
- 2-Step Verification: https://www.polcu.com/personal/banking/ways-to-bank/online-banking/two-step-verification
- Online Banking: https://online.polcu.com/
- Business Accounts: https://www.polcu.com/business/banking/accounts
- Business Loans: https://www.polcu.com/business/borrowing/loans
- Business Lines of Credit: https://www.polcu.com/business/borrowing/lines-of-credit
- Agricultural Loans: https://www.polcu.com/business/borrowing/agricultural-loans
- Merchant Services: https://www.polcu.com/business/banking/merchant-services
- Calculators (mortgage, loan, savings): https://www.polcu.com/about-us/calculators
- About Us / History: https://www.polcu.com/about-us/history
- Careers: https://www.polcu.com/about-us/careers
- Member News: https://www.polcu.com/about-us/member-news
- Community: https://www.polcu.com/about-us/community
- Privacy Policy: https://www.polcu.com/privacy
- Legal / Terms & Conditions: https://www.polcu.com/legal
- Internet Security: https://www.polcu.com/internet-security
- Accessibility: https://www.polcu.com/accessibility-statement
- Market Code of Conduct: https://www.polcu.com/assets/pdfs/Market_Code_of_Conduct.pdf
- System Conversion Notice (July 2026): https://www.polcu.com/assets/pdfs/membernews/MemberNotice_Zawiadomienie_2026.pdf
- Dundalk District CU website: https://dundalkcu.ca
- Dundalk Rates: https://dundalkcu.ca/rates/
- Dundalk Fees: https://dundalkcu.ca/fees/
- Dundalk Agricultural Credit: https://dundalkcu.ca/borrowing/agricultural-credit/
- Dundalk Commercial Credit: https://dundalkcu.ca/borrowing/commercial-credit/
- Dundalk Residential Mortgages: https://dundalkcu.ca/borrowing/residential-mortgages/
- Dundalk Personal Credit: https://dundalkcu.ca/borrowing/personal-credit/
- Dundalk Banking: https://dundalkcu.ca/banking/
- Dundalk Investments: https://dundalkcu.ca/investments/
- Dundalk Contact: https://dundalkcu.ca/contact-us/
- Dundalk e-Transfers: https://dundalkcu.ca/banking/e-transfers/
- Dundalk Mobile App: https://dundalkcu.ca/deposit-cheques-using-our-ddcu-mobile-banking-app/
- Adjala Savings website: https://adjala.ca
- Adjala Rates: https://adjala.ca/node/7
- Adjala Location & Hours: https://adjala.ca/node/14
- Adjala About: https://adjala.ca/node/4
- Adjala Become a Member: https://adjala.ca/node/6
- Adjala Mortgages: https://adjala.ca/node/21
- Adjala Personal Loans: https://adjala.ca/node/19
- Adjala Lines of Credit: https://adjala.ca/node/20
- Adjala Term Deposits: https://adjala.ca/node/26
- Adjala News: https://adjala.ca/node/13

LINKING RULES:
- ALWAYS use markdown hyperlinks with natural anchor text: [descriptive text](url)
- Example: "You can view our [current rates](https://www.polcu.com/rates) for details."
- Example: "Find your [nearest branch](https://www.polcu.com/branch-hours) here."
- Example: "Learn more about our [mortgage options](https://www.polcu.com/borrowing/mortgages)."
- NEVER paste raw URLs — always wrap them in a markdown link with short, descriptive text
- Use natural phrases like "learn more [here](url)", "visit our [Mortgages page](url)", "see our [FAQ](url)"
- If a member asks about fees, charges, or schedules, link to the relevant page (Rates, FAQ, or Legal)
- If a topic maps to a specific page above, include that link
- If no specific page exists, direct them to [Contact Us](https://www.polcu.com/about-us/contact-us) or the main website

TONE & STYLE:
- Be professional but friendly and warm
- Keep responses SHORT and CONCISE (1-3 sentences max)
- Be direct and to the point
- Mention Polish language service available at branches if needed
- If you don't know something, suggest contacting 1-855-765-2822 or memberrelations@polcu.com
- Always be positive and helpful about POLCU's services
- If asked about system changes, conversion, or login changes, proactively share the July 17 conversion details`
  };

  /* ─── Inject styles ──────────────────────────────────────────── */
  const sheet = document.createElement('style');
  sheet.textContent = CSS;
  document.head.appendChild(sheet);

  /* ─── Ensure viewport meta handles mobile correctly ──────────── */
  (function ensureViewport() {
    let meta = document.querySelector('meta[name="viewport"]');
    if (!meta) {
      meta = document.createElement('meta');
      meta.name = 'viewport';
      meta.content = 'width=device-width, initial-scale=1, viewport-fit=cover';
      document.head.appendChild(meta);
    } else if (!meta.content.includes('viewport-fit')) {
      meta.content += ', viewport-fit=cover';
    }
  })();

  /* ─── Build DOM ──────────────────────────────────────────────── */
  function buildUI() {
    // Icon
    const icon = document.createElement('button');
    icon.className = 'polcu-icon';
    icon.textContent = '💬';
    icon.setAttribute('aria-label', 'Open chat');

    // Overlay
    const overlay = document.createElement('div');
    overlay.className = 'polcu-overlay';

    // Chat container
    const chat = document.createElement('div');
    chat.className = 'polcu-chat';

    chat.innerHTML = `
      <div class="polcu-header">
        <div class="polcu-header-text">
          <h3>POLCU Support</h3>
          <p>How can we help you? 🏦</p>
        </div>
        <button class="polcu-close" aria-label="Close chat">✕</button>
      </div>
      <div class="polcu-messages">
        <div class="polcu-msg polcu-assistant">
          <div class="polcu-bubble">Hello! 👋 I'm the POLCU virtual assistant. I can help you with information about our accounts, rates, loans, mortgages, and more. How can I help you today?</div>
        </div>
      </div>
      <div class="polcu-input-area">
        <button class="polcu-mic" aria-label="Voice input">🎤</button>
        <input class="polcu-input" type="text" placeholder="Ask me anything…" autocomplete="off" enterkeyhint="send" inputmode="text" />
        <button class="polcu-send" aria-label="Send message">➤</button>
      </div>
    `;

    document.body.appendChild(icon);
    document.body.appendChild(overlay);
    document.body.appendChild(chat);

    return {
      icon,
      overlay,
      chat,
      messages: chat.querySelector('.polcu-messages'),
      input:    chat.querySelector('.polcu-input'),
      sendBtn:  chat.querySelector('.polcu-send'),
      micBtn:   chat.querySelector('.polcu-mic'),
      closeBtn: chat.querySelector('.polcu-close')
    };
  }

  const el = buildUI();

  /* ─── State ──────────────────────────────────────────────────── */
  let isOpen = false;
  let waiting = false;
  const history = []; // { role, content }

  /* ─── Open / Close ───────────────────────────────────────────── */
  function open() {
    isOpen = true;
    el.chat.classList.add('polcu-show');
    el.overlay.classList.add('polcu-show');
    el.icon.classList.add('polcu-hidden');

    // Prevent body scroll while chat is full-screen on mobile
    document.body.style.overflow = 'hidden';

    scrollToBottom();

    // Focus input after animation settles (avoids iOS keyboard jump)
    setTimeout(() => el.input.focus({ preventScroll: true }), 350);
  }

  function close() {
    isOpen = false;
    el.chat.classList.remove('polcu-show');
    el.overlay.classList.remove('polcu-show');
    el.icon.classList.remove('polcu-hidden');
    document.body.style.overflow = '';
    el.input.blur();
  }

  /* ─── Scroll helper ──────────────────────────────────────────── */
  function scrollToBottom() {
    requestAnimationFrame(() => {
      el.messages.scrollTop = el.messages.scrollHeight;
    });
  }

  /* ─── Add a chat bubble ─────────────────────────────────────── */
  /* ─── Markdown link renderer ─────────────────────────────────── */
  function renderMarkdownLinks(text) {
    // Escape HTML first, then convert [text](url) to clickable <a> tags
    const escaped = text.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    return escaped.replace(/\[([^\]]+)\]\((https?:\/\/[^)]+)\)/g,
      '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');
  }

  function addBubble(text, role) {
    const wrap = document.createElement('div');
    wrap.className = 'polcu-msg ' + (role === 'user' ? 'polcu-user' : 'polcu-assistant');
    const bubble = document.createElement('div');
    bubble.className = 'polcu-bubble';
    if (role === 'user') {
      bubble.textContent = text;
    } else {
      bubble.innerHTML = renderMarkdownLinks(text);
      // Add speaker button to assistant messages
      const speakBtn = document.createElement('button');
      speakBtn.className = 'polcu-speak-btn';
      speakBtn.textContent = '🔊';
      speakBtn.title = 'Listen';
      speakBtn.addEventListener('click', function () { speakText(text, speakBtn); });
      bubble.appendChild(speakBtn);
    }
    wrap.appendChild(bubble);
    el.messages.appendChild(wrap);
    scrollToBottom();

    history.push({ role: role === 'user' ? 'user' : 'assistant', content: text });
    if (history.length > CFG.maxMessages) history.splice(0, history.length - CFG.maxMessages);

    // Log message (async, don't block UI)
    if (typeof window.polcuLogMessage === 'function') {
      const messageType = role === 'user' ? 'question' : 'answer';
      window.polcuLogMessage(messageType, text).catch(err => console.warn('Log failed:', err));
    }
  }

  /* ─── Typing indicator ──────────────────────────────────────── */
  let typingEl = null;
  function showTyping() {
    typingEl = document.createElement('div');
    typingEl.className = 'polcu-msg polcu-assistant';
    const dots = document.createElement('div');
    dots.className = 'polcu-typing';
    dots.innerHTML = '<div class="polcu-dot"></div><div class="polcu-dot"></div><div class="polcu-dot"></div>';
    typingEl.appendChild(dots);
    el.messages.appendChild(typingEl);
    scrollToBottom();
  }
  function hideTyping() {
    if (typingEl) { typingEl.remove(); typingEl = null; }
  }

  /* ─── Send message ──────────────────────────────────────────── */
  let voiceTriggered = false; // tracks if last input was via mic

  async function send() {
    const text = el.input.value.trim();
    if (!text || waiting) return;
    const wasVoice = voiceTriggered;
    voiceTriggered = false;

    el.input.value = '';
    el.sendBtn.disabled = true;
    waiting = true;

    addBubble(text, 'user');
    showTyping();

    try {
      const payload = {
        messages: [{ role: 'system', content: CFG.systemPrompt }, ...history]
      };

      const res = await fetch(CFG.endpoint, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify(payload)
      });

      const data = await res.json();
      
      if (!res.ok) throw new Error(data.error || 'API error ' + res.status);

      hideTyping();
      addBubble(data.content, 'assistant');

      // Auto-speak if the input came from voice
      if (wasVoice && data.content) {
        speakText(data.content, null);
      }
    } catch (err) {
      hideTyping();
      console.error('POLCU chat error:', err);
      addBubble('Sorry, something went wrong. Please try again or contact us at 1-855-765-2822 or memberrelations@polcu.com.', 'assistant');
    } finally {
      waiting = false;
      el.sendBtn.disabled = false;
      // Re-focus only if chat is still open
      if (isOpen) el.input.focus({ preventScroll: true });
    }
  }

  /* ─── Events ─────────────────────────────────────────────────── */
  el.icon.addEventListener('click', open);
  el.overlay.addEventListener('click', close);
  el.closeBtn.addEventListener('click', close);
  el.sendBtn.addEventListener('click', send);

  el.input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
  });

  /* ─── Voice Input (Speech-to-Text) ──────────────────────────── */
  const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
  let recognition = null;
  let isRecording = false;

  if (SpeechRecognition) {
    recognition = new SpeechRecognition();
    recognition.continuous = false;
    recognition.interimResults = false;
    recognition.lang = 'en-CA';

    recognition.onresult = function (event) {
      const transcript = event.results[0][0].transcript;
      if (transcript.trim()) {
        el.input.value = transcript;
        voiceTriggered = true;
        send();
      }
      stopRecording();
    };

    recognition.onerror = function (event) {
      console.warn('Speech recognition error:', event.error);
      stopRecording();
      if (event.error === 'not-allowed') {
        addBubble('Please allow microphone access to use voice input.', 'assistant');
      }
    };

    recognition.onend = function () {
      stopRecording();
    };
  } else {
    // Hide mic button if speech recognition not supported
    el.micBtn.style.display = 'none';
  }

  function startRecording() {
    if (!recognition || isRecording) return;
    isRecording = true;
    el.micBtn.classList.add('polcu-recording');
    el.micBtn.textContent = '⏹';
    try { recognition.start(); } catch(e) { stopRecording(); }
  }

  function stopRecording() {
    isRecording = false;
    el.micBtn.classList.remove('polcu-recording');
    el.micBtn.textContent = '🎤';
    try { recognition.stop(); } catch(e) {}
  }

  el.micBtn.addEventListener('click', function () {
    if (isRecording) { stopRecording(); } else { startRecording(); }
  });

  /* ─── Voice Output (Text-to-Speech) ─────────────────────────── */
  const ttsEndpoint = atob('aHR0cHM6Ly9hZ2VudHJvY2tldG1hbi5jb20vcG9sY3UvYXBpL3R0cy5waHA=');
  let currentAudio = null;

  async function speakText(text, btn) {
    // Stop any currently playing audio
    if (currentAudio) {
      currentAudio.pause();
      currentAudio = null;
      document.querySelectorAll('.polcu-speak-btn.polcu-playing').forEach(b => b.classList.remove('polcu-playing'));
    }

    if (btn) btn.classList.add('polcu-playing');

    try {
      // Strip markdown links for speech: [text](url) → text
      const cleanText = text.replace(/\[([^\]]+)\]\([^)]+\)/g, '$1');

      const res = await fetch(ttsEndpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ text: cleanText })
      });

      if (!res.ok) throw new Error('TTS error');

      const blob = await res.blob();
      const url = URL.createObjectURL(blob);
      currentAudio = new Audio(url);
      currentAudio.onended = function () {
        if (btn) btn.classList.remove('polcu-playing');
        URL.revokeObjectURL(url);
        currentAudio = null;
      };
      currentAudio.onerror = function () {
        if (btn) btn.classList.remove('polcu-playing');
        currentAudio = null;
      };
      currentAudio.play();
    } catch (err) {
      console.warn('TTS failed:', err);
      if (btn) btn.classList.remove('polcu-playing');
    }
  }

  /* ─── Mobile keyboard handling via visualViewport ────────────── */
  if (window.visualViewport) {
    const vv = window.visualViewport;

    function onViewportResize() {
      if (!isOpen) return;

      const kbVisible = window.innerHeight - vv.height > 100;

      if (kbVisible) {
        el.chat.style.height = vv.height + 'px';
        el.chat.style.top = vv.offsetTop + 'px';
        el.chat.classList.add('polcu-kb-open');
      } else {
        el.chat.style.height = '';
        el.chat.style.top = '';
        el.chat.classList.remove('polcu-kb-open');
      }

      scrollToBottom();
    }

    vv.addEventListener('resize', onViewportResize);
    vv.addEventListener('scroll', onViewportResize);
  }

  console.log('✅ POLCU Chat Widget loaded.');
})();
