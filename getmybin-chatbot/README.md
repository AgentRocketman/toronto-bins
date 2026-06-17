# GetMyBin AI Chat Widget

A beautiful, production-ready AI chatbot widget for [agentrocketman.com](https://agentrocketman.com).

## Files

| File | Purpose |
|------|---------|
| `chatbot-widget.html` | HTML snippet to paste into your site |
| `chatbot-widget.css` | All widget styles |
| `chatbot-widget.js` | Chat logic, API integration, streaming |
| `demo.html` | Standalone demo page |

## Quick Setup (3 steps)

### 1. Add your OpenAI API key

Open `chatbot-widget.js` and replace:
```js
apiKey: 'YOUR_OPENAI_API_KEY',
```

### 2. Upload the files

Upload `chatbot-widget.css` and `chatbot-widget.js` to your site (e.g., `/assets/chat/`).

### 3. Paste the HTML snippet

Add this before `</body>` on every page (or your layout template):

```html
<!-- GetMyBin Chat Widget -->
<div id="gmb-chat-widget">
  <button id="gmb-chat-toggle" aria-label="Chat with GetMyBin AI" title="Chat with us!">
    <svg id="gmb-icon-chat" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
    </svg>
    <svg id="gmb-icon-close" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none">
      <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
    </svg>
    <span id="gmb-unread-badge" style="display:none">1</span>
  </button>
  <div id="gmb-chat-modal" class="gmb-hidden">
    <div id="gmb-chat-header">
      <div id="gmb-header-info">
        <div id="gmb-avatar">
          <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
        </div>
        <div>
          <div id="gmb-header-title">GetMyBin Assistant</div>
          <div id="gmb-header-status"><span id="gmb-status-dot"></span><span id="gmb-status-text">Online</span></div>
        </div>
      </div>
      <button id="gmb-chat-close" aria-label="Close chat">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div id="gmb-chat-messages"></div>
    <div id="gmb-quick-actions">
      <button class="gmb-quick-btn" data-msg="How does GetMyBin work?">🗑️ How it works</button>
      <button class="gmb-quick-btn" data-msg="What are your prices?">💰 Pricing</button>
      <button class="gmb-quick-btn" data-msg="What areas do you cover?">📍 Coverage area</button>
      <button class="gmb-quick-btn" data-msg="Tell me about the $1 promo">🎉 $1 Promo</button>
    </div>
    <div id="gmb-chat-input-area">
      <div id="gmb-input-wrapper">
        <textarea id="gmb-chat-input" placeholder="Ask about our bin service..." rows="1" maxlength="500"></textarea>
        <button id="gmb-send-btn" aria-label="Send message" disabled>
          <svg viewBox="0 0 24 24" fill="currentColor"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
        </button>
      </div>
      <div id="gmb-powered-by">Powered by GetMyBin AI</div>
    </div>
  </div>
</div>
<link rel="stylesheet" href="/assets/chat/chatbot-widget.css">
<script src="/assets/chat/chatbot-widget.js"></script>
```

Update the CSS/JS paths to match where you uploaded them.

---

## Configuration

All config lives in the `CONFIG` object at the top of `chatbot-widget.js`:

| Option | Default | Description |
|--------|---------|-------------|
| `apiKey` | `YOUR_OPENAI_API_KEY` | Your OpenAI key |
| `apiUrl` | OpenAI endpoint | Change to backend proxy for production |
| `model` | `gpt-4o-mini` | ChatGPT model (fast & cheap) |
| `maxTokens` | `500` | Max response length |
| `temperature` | `0.7` | Creativity (0=focused, 1=creative) |
| `welcomeMessage` | Intro text | First message shown to user |
| `greeting` | `👋 Need bin help?` | Floating bubble on first visit |
| `greetingDelay` | `3000` | ms before greeting appears |
| `maxHistory` | `10` | Messages sent to API (cost control) |

---

## ⚠️ Production Security

**Do NOT expose your OpenAI API key in client-side JavaScript.**

For production, create a simple backend proxy:

```js
// Example: Node.js/Express proxy
app.post('/api/chat', async (req, res) => {
  const response = await fetch('https://api.openai.com/v1/chat/completions', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${process.env.OPENAI_API_KEY}`,
    },
    body: JSON.stringify({
      model: 'gpt-4o-mini',
      messages: req.body.messages,
      max_tokens: 500,
      temperature: 0.7,
      stream: true,
    }),
  });
  // Pipe stream to client
  response.body.pipe(res);
});
```

Then update `chatbot-widget.js`:
```js
apiUrl: '/api/chat',
apiKey: '', // Not needed with proxy
```

---

## System Prompt (Knowledge Base)

The AI's knowledge is embedded in the `SYSTEM_PROMPT` constant in `chatbot-widget.js`. It includes:

- **Service explanation** — How bin rollout/collection works
- **Pricing** — $5.95/week recurring, $8.95 ad-hoc, 13% HST
- **$1 promo** — First rollout $1 for new recurring subscribers
- **Coverage** — Toronto/GTA
- **Subscription management** — One-click cancel, no contracts
- **Contact** — support@agentrocketman.com
- **Personality** — Warm, friendly, concise, emoji-appropriate

Edit `SYSTEM_PROMPT` to update the AI's knowledge anytime.

---

## Features

- 🎨 **Brand-matched design** — #71b80c green throughout
- ⚡ **Real-time streaming** — Responses appear word-by-word
- 📱 **Fully responsive** — Works on mobile, tablet, desktop
- 💬 **Quick action buttons** — Common questions one-tap away
- 💾 **Session persistence** — Chat survives page refresh
- ⌨️ **Keyboard shortcuts** — Enter to send, Escape to close
- 🎯 **Smart greeting** — First-visit bubble, auto-hides
- 📝 **Markdown formatting** — Bold, links, lists in responses
- ♿ **Accessible** — ARIA labels, reduced-motion support
- 🌙 **Dark mode ready** — Uncomment CSS block to enable

---

## Estimated Costs

Using `gpt-4o-mini` with 500 max tokens:
- ~$0.0002 per message exchange
- ~1,000 conversations/day ≈ $0.20/day
- Very cost-effective for a small business
