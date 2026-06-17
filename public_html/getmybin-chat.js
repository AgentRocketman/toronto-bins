// GetMyBin AI Chat Widget — Complete Rewrite (mobile-first)
// Self-contained: injects styles, builds DOM, handles chat logic.

(function () {
  'use strict';

  /* ─── CSS ────────────────────────────────────────────────────── */
  const CSS = `
/* Reset for widget elements */
.gmb-chat *, .gmb-chat *::before, .gmb-chat *::after,
.gmb-icon, .gmb-overlay {
  box-sizing: border-box;
  margin: 0;
  padding: 0;
  -webkit-tap-highlight-color: transparent;
}

/* ── Floating icon ── */
.gmb-icon {
  position: fixed;
  bottom: 120px;
  right: 24px;
  width: 60px;
  height: 60px;
  background: linear-gradient(135deg, #71b80c, #5a9409);
  border-radius: 50%;
  border: none;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 4px 14px rgba(113,184,12,.35);
  z-index: 10000;
  font-size: 28px;
  transition: transform .2s, box-shadow .2s;
  -webkit-appearance: none;
}
.gmb-icon:hover  { transform: scale(1.08); box-shadow: 0 6px 18px rgba(113,184,12,.45); }
.gmb-icon:active { transform: scale(.94); }
.gmb-icon.gmb-hidden { display: none; }

/* ── Overlay ── */
.gmb-overlay {
  position: fixed; inset: 0;
  background: rgba(0,0,0,.35);
  z-index: 10001;
  opacity: 0;
  pointer-events: none;
  transition: opacity .25s;
}
.gmb-overlay.gmb-show {
  opacity: 1;
  pointer-events: auto;
}

/* ── Chat modal (desktop default) ── */
.gmb-chat {
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
.gmb-chat.gmb-show {
  transform: scale(1) translateY(0);
  opacity: 1;
  pointer-events: auto;
}

/* ── Header ── */
.gmb-header {
  flex-shrink: 0;
  background: linear-gradient(135deg, #71b80c, #5a9409);
  color: #fff;
  padding: 16px 16px 14px;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.gmb-header-text h3 { font-size: 17px; font-weight: 700; line-height: 1.2; }
.gmb-header-text p  { font-size: 12px; opacity: .88; margin-top: 2px; }
.gmb-close {
  background: none; border: none; color: #fff;
  font-size: 22px; cursor: pointer;
  width: 32px; height: 32px;
  display: flex; align-items: center; justify-content: center;
  border-radius: 50%;
  transition: background .15s;
  flex-shrink: 0;
}
.gmb-close:hover { background: rgba(255,255,255,.18); }

/* ── Messages ── */
.gmb-messages {
  flex: 1 1 auto;
  overflow-y: auto;
  -webkit-overflow-scrolling: touch;
  overscroll-behavior-y: contain;
  padding: 14px 14px 8px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.gmb-messages::-webkit-scrollbar       { width: 5px; }
.gmb-messages::-webkit-scrollbar-track  { background: transparent; }
.gmb-messages::-webkit-scrollbar-thumb  { background: #d4d4d4; border-radius: 3px; }

/* ── Message bubbles ── */
.gmb-msg {
  display: flex;
  animation: gmbSlide .28s ease;
}
@keyframes gmbSlide {
  from { opacity: 0; transform: translateY(8px); }
  to   { opacity: 1; transform: translateY(0); }
}
.gmb-msg.gmb-user      { justify-content: flex-end; }
.gmb-msg.gmb-assistant  { justify-content: flex-start; }
.gmb-bubble {
  max-width: 82%;
  padding: 10px 14px;
  border-radius: 14px;
  font-size: 14px;
  line-height: 1.45;
  word-break: break-word;
}
.gmb-user .gmb-bubble {
  background: #f0f0f0; color: #222;
  border-bottom-right-radius: 4px;
}
.gmb-assistant .gmb-bubble {
  background: #e8f5e0; color: #222;
  border-bottom-left-radius: 4px;
}

/* ── Typing indicator ── */
.gmb-typing { display: flex; gap: 5px; padding: 10px 14px; }
.gmb-dot {
  width: 7px; height: 7px; border-radius: 50%;
  background: #71b80c;
  animation: gmbBounce 1.3s infinite;
}
.gmb-dot:nth-child(2) { animation-delay: .15s; }
.gmb-dot:nth-child(3) { animation-delay: .3s;  }
@keyframes gmbBounce {
  0%,60%,100% { opacity: .25; transform: translateY(0); }
  30%          { opacity: 1;   transform: translateY(-6px); }
}

/* ── Input area ── */
.gmb-input-area {
  flex-shrink: 0;
  display: flex;
  gap: 8px;
  padding: 10px 12px;
  border-top: 1px solid #e8e8e8;
  background: #fff;
}
.gmb-input {
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
.gmb-input:focus { border-color: #71b80c; }
.gmb-send {
  flex-shrink: 0;
  width: 40px; height: 40px;
  background: #71b80c; color: #fff;
  border: none; border-radius: 50%;
  font-size: 18px;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer;
  transition: background .15s, transform .15s;
}
.gmb-send:hover  { background: #5a9409; }
.gmb-send:active { transform: scale(.92); }
.gmb-send:disabled { background: #ccc; cursor: not-allowed; transform: none; }

/* ═══ MOBILE (≤ 768 px) ═══ */
@media (max-width: 768px) {
  .gmb-icon {
    bottom: 100px; right: 16px;
    width: 56px; height: 56px;
    font-size: 26px;
  }

  .gmb-chat {
    /* Full-screen on mobile */
    inset: 0;
    width: 100%;
    height: 100%;
    max-height: 100%;
    border-radius: 0;
    transform: translateY(100%);
    opacity: 1;               /* slide-up instead of scale */
  }
  .gmb-chat.gmb-show {
    transform: translateY(0);
  }

  .gmb-header {
    /* Safe area for notch */
    padding-top: max(16px, env(safe-area-inset-top, 0px));
    min-height: 56px;
  }

  .gmb-input-area {
    /* Safe area for home-bar */
    padding-bottom: max(10px, env(safe-area-inset-bottom, 0px));
  }
}

/* ═══ Keyboard-visible tweaks (visualViewport-driven class) ═══ */
.gmb-chat.gmb-kb-open .gmb-input-area {
  padding-bottom: 6px;        /* keyboard already fills safe area */
}
`;

  /* ─── Configuration ──────────────────────────────────────────── */
  const CFG = {
    apiKey: null,
    endpoint: 'https://api.openai.com/v1/chat/completions',
    model: 'gpt-3.5-turbo',
    maxMessages: 50,
    systemPrompt: `You are the friendly and helpful GetMyBin customer support assistant. You represent a Toronto-based bin collection service.

Service Overview:
- We roll your bins to the curb for collection day and roll them back afterward
- Covers the Greater Toronto Area
- Professional, reliable service - no missed collections

Pricing:
- Recurring subscription: $5.95/week (billed weekly)
- Ad-hoc/one-time service: $8.95 per rollout
- Tax: 13% HST added at checkout (Ontario)

Current Promotion:
- New customers: Get your first rollout for just $1 (recurring subscription only)
- The $1 offer is for the first rollout/roll-in
- After the first week, it automatically converts to regular $5.95/week pricing
- You can cancel anytime with one-click cancellation

How It Works:
1. Check your collection schedule on our website
2. Sign up for recurring or book ad-hoc service
3. On collection day, we roll your bins to the curb
4. After collection, we roll them back to where they were
5. Track everything through your online dashboard

Subscription Management:
- Cancel anytime with one-click (no penalties, no customer service calls needed)
- Change your service plan in your account
- Pause/resume as needed
- Payment is automatic via Stripe

Service Areas:
- City of Toronto and its administrative districts only:
  - Old Toronto
  - North York
  - Scarborough
  - Etobicoke
  - East York
  - York
- We do NOT currently serve Greater Toronto Area cities like Mississauga, Brampton, etc.
- Coverage includes residential and some commercial properties within these districts

Contact & Support:
- Email: support@agentrocketman.com
- For urgent issues, email support directly
- Response time: usually within 24 hours

Payment:
- We accept all major credit cards via Stripe
- Secure, encrypted transactions
- No hidden fees (tax shown before payment)

Tone & Style:
- Be friendly, helpful, and professional
- Use casual language (this isn't corporate)
- **Keep responses SHORT and CONCISE** — aim for 1-3 sentences max
- No long explanations or unnecessary details
- Be direct and to the point
- If you don't know something, suggest they email support@agentrocketman.com
- Always be positive about GetMyBin's service`
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
    icon.className = 'gmb-icon';
    icon.textContent = '💬';
    icon.setAttribute('aria-label', 'Open chat');

    // Overlay
    const overlay = document.createElement('div');
    overlay.className = 'gmb-overlay';

    // Chat container
    const chat = document.createElement('div');
    chat.className = 'gmb-chat';

    chat.innerHTML = `
      <div class="gmb-header">
        <div class="gmb-header-text">
          <h3>GetMyBin Support</h3>
          <p>We're here to help! 🚀</p>
        </div>
        <button class="gmb-close" aria-label="Close chat">✕</button>
      </div>
      <div class="gmb-messages">
        <div class="gmb-msg gmb-assistant">
          <div class="gmb-bubble">Hey! 👋 I'm the GetMyBin assistant. Got questions about our bin collection service? I'm here to help!</div>
        </div>
      </div>
      <div class="gmb-input-area">
        <input class="gmb-input" type="text" placeholder="Ask me anything…" autocomplete="off" enterkeyhint="send" inputmode="text" />
        <button class="gmb-send" aria-label="Send message">➤</button>
      </div>
    `;

    document.body.appendChild(icon);
    document.body.appendChild(overlay);
    document.body.appendChild(chat);

    return {
      icon,
      overlay,
      chat,
      messages: chat.querySelector('.gmb-messages'),
      input:    chat.querySelector('.gmb-input'),
      sendBtn:  chat.querySelector('.gmb-send'),
      closeBtn: chat.querySelector('.gmb-close')
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
    el.chat.classList.add('gmb-show');
    el.overlay.classList.add('gmb-show');
    el.icon.classList.add('gmb-hidden');

    // Prevent body scroll while chat is full-screen on mobile
    document.body.style.overflow = 'hidden';

    scrollToBottom();

    // Focus input after animation settles (avoids iOS keyboard jump)
    setTimeout(() => el.input.focus({ preventScroll: true }), 350);
  }

  function close() {
    isOpen = false;
    el.chat.classList.remove('gmb-show');
    el.overlay.classList.remove('gmb-show');
    el.icon.classList.remove('gmb-hidden');
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
  function addBubble(text, role) {
    const wrap = document.createElement('div');
    wrap.className = 'gmb-msg ' + (role === 'user' ? 'gmb-user' : 'gmb-assistant');
    const bubble = document.createElement('div');
    bubble.className = 'gmb-bubble';
    bubble.textContent = text;
    wrap.appendChild(bubble);
    el.messages.appendChild(wrap);
    scrollToBottom();

    history.push({ role: role === 'user' ? 'user' : 'assistant', content: text });
    if (history.length > CFG.maxMessages) history.splice(0, history.length - CFG.maxMessages);
  }

  /* ─── Typing indicator ──────────────────────────────────────── */
  let typingEl = null;
  function showTyping() {
    typingEl = document.createElement('div');
    typingEl.className = 'gmb-msg gmb-assistant';
    const dots = document.createElement('div');
    dots.className = 'gmb-typing';
    dots.innerHTML = '<div class="gmb-dot"></div><div class="gmb-dot"></div><div class="gmb-dot"></div>';
    typingEl.appendChild(dots);
    el.messages.appendChild(typingEl);
    scrollToBottom();
  }
  function hideTyping() {
    if (typingEl) { typingEl.remove(); typingEl = null; }
  }

  /* ─── Send message ──────────────────────────────────────────── */
  async function send() {
    const text = el.input.value.trim();
    if (!text || waiting) return;

    if (!CFG.apiKey) {
      addBubble('Chat is not configured yet. Please set your API key.', 'assistant');
      return;
    }

    el.input.value = '';
    el.sendBtn.disabled = true;
    waiting = true;

    addBubble(text, 'user');
    showTyping();

    try {
      const payload = {
        model: CFG.model,
        messages: [{ role: 'system', content: CFG.systemPrompt }, ...history],
        temperature: 0.7,
        max_tokens: 500
      };

      const res = await fetch(CFG.endpoint, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Authorization: 'Bearer ' + CFG.apiKey
        },
        body: JSON.stringify(payload)
      });

      if (!res.ok) throw new Error('API ' + res.status);

      const data = await res.json();
      hideTyping();
      addBubble(data.choices[0].message.content, 'assistant');
    } catch (err) {
      hideTyping();
      console.error('GetMyBin chat error:', err);
      addBubble('Sorry, something went wrong. Please try again or email support@agentrocketman.com.', 'assistant');
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

  /* ─── Mobile keyboard handling via visualViewport ────────────── */
  // On iOS Safari, the virtual keyboard shrinks the visual viewport.
  // We listen for resize events and adjust the chat height so the input
  // stays visible above the keyboard — no hacks, no 100vh nonsense.
  if (window.visualViewport) {
    const vv = window.visualViewport;

    function onViewportResize() {
      if (!isOpen) return;

      const kbVisible = window.innerHeight - vv.height > 100;

      if (kbVisible) {
        // Keyboard is up: shrink chat to visual viewport
        el.chat.style.height = vv.height + 'px';
        el.chat.style.top = vv.offsetTop + 'px';
        el.chat.classList.add('gmb-kb-open');
      } else {
        // Keyboard dismissed
        el.chat.style.height = '';
        el.chat.style.top = '';
        el.chat.classList.remove('gmb-kb-open');
      }

      scrollToBottom();
    }

    vv.addEventListener('resize', onViewportResize);
    vv.addEventListener('scroll', onViewportResize);
  }

  /* ─── Public API ─────────────────────────────────────────────── */
  window.getMyBinSetApiKey = function (key) {
    CFG.apiKey = key;
    console.log('✅ GetMyBin Chat: API key set');
  };

  console.log('✅ GetMyBin Chat Widget loaded. Call getMyBinSetApiKey("sk-…") to activate.');
})();
