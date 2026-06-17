/**
 * GetMyBin AI Chat Widget
 * ========================
 * Beautiful, self-contained chatbot widget for agentrocketman.com
 * Uses OpenAI ChatGPT API with streaming responses
 * 
 * SETUP: Replace OPENAI_API_KEY below with your key.
 * For production, proxy through your backend to avoid exposing the key.
 */

(function () {
  'use strict';

  // ============================================
  // CONFIGURATION — Edit these values
  // ============================================
  const CONFIG = {
    // Replace with your OpenAI API key (or better: proxy URL)
    apiKey: 'YOUR_OPENAI_API_KEY',
    
    // API endpoint — change to your backend proxy for production
    apiUrl: 'https://api.openai.com/v1/chat/completions',
    
    // Model to use
    model: 'gpt-4o-mini',
    
    // Max tokens per response
    maxTokens: 500,
    
    // Temperature (0-1, lower = more focused)
    temperature: 0.7,
    
    // Welcome message shown when chat opens
    welcomeMessage: `Hey there! 👋 I'm the GetMyBin assistant.

I can help you with:
• **How our bin service works**
• **Pricing & plans**
• **Our $1 first rollout promo** 🎉
• **Coverage areas in Toronto**

What would you like to know?`,
    
    // Greeting shown on the floating button (first visit)
    greeting: '👋 Need bin help? Ask me!',
    
    // Time before greeting appears (ms)
    greetingDelay: 3000,
    
    // Max conversation history to send (keeps API costs low)
    maxHistory: 10,
  };

  // ============================================
  // SYSTEM PROMPT — GetMyBin knowledge base
  // ============================================
  const SYSTEM_PROMPT = `You are the GetMyBin AI assistant — a friendly, helpful customer service chatbot for GetMyBin, a Toronto-based bin collection and rollout service.

## About GetMyBin
GetMyBin takes the hassle out of garbage day. We roll your bins to the curb for collection, wait for the pickup, then roll them back to where they belong — so you never have to think about it.

## How It Works
1. **Schedule** — Sign up and tell us your collection day
2. **We roll out** — Early morning, we roll your bins to the curb
3. **Collection happens** — The city picks up your waste as usual  
4. **We roll back** — After collection, we return your bins to their spot

## Pricing
- **Recurring subscription:** $5.95/week (billed weekly, cancel anytime with one click)
- **Ad-hoc service:** $8.95 per rollout (one-time, no commitment)
- **Tax:** 13% HST is added at checkout on all prices

## Current Promotion 🎉
- **$1 First Rollout** — New recurring subscribers get their first rollout for just $1!
- After the first week, the plan auto-converts to the regular $5.95/week rate
- This promo is for recurring subscriptions only (not ad-hoc)
- Cancel anytime — no lock-in

## Coverage Area
- Currently serving the **Greater Toronto Area (GTA)**
- Primarily focused on **Toronto** neighbourhoods
- Expanding to more areas soon — ask us if we cover your address!

## Subscription Management
- **Cancel anytime** with one-click cancellation
- No contracts, no hidden fees
- Pause and resume as needed
- Manage everything from your account dashboard

## Contact & Support
- **Email:** support@agentrocketman.com
- **Website:** https://agentrocketman.com

## Your Personality & Guidelines
- Be warm, friendly, and conversational — like a helpful neighbour
- Keep answers concise but thorough (2-4 sentences unless more detail is needed)
- Always mention the $1 promo when pricing comes up naturally
- If asked about something outside your knowledge, say: "That's a great question! I'd recommend reaching out to our team at support@agentrocketman.com for the most up-to-date info."
- Use emojis sparingly but naturally (1-2 per message max)
- Format important info with **bold** when helpful
- Never make up information you don't have
- Encourage sign-ups naturally without being pushy
- If someone asks about coverage outside Toronto, be honest and let them know they can check back as we expand`;

  // ============================================
  // STATE
  // ============================================
  let isOpen = false;
  let isStreaming = false;
  let conversationHistory = [];
  let abortController = null;

  // ============================================
  // DOM REFERENCES
  // ============================================
  const widget = document.getElementById('gmb-chat-widget');
  const toggle = document.getElementById('gmb-chat-toggle');
  const modal = document.getElementById('gmb-chat-modal');
  const messagesEl = document.getElementById('gmb-chat-messages');
  const inputEl = document.getElementById('gmb-chat-input');
  const sendBtn = document.getElementById('gmb-send-btn');
  const closeBtn = document.getElementById('gmb-chat-close');
  const quickActions = document.getElementById('gmb-quick-actions');
  const statusText = document.getElementById('gmb-status-text');
  const statusDot = document.getElementById('gmb-status-dot');

  // ============================================
  // INITIALIZATION
  // ============================================
  function init() {
    bindEvents();
    showGreeting();
    
    // Restore conversation from session
    const saved = sessionStorage.getItem('gmb-chat-history');
    if (saved) {
      try {
        conversationHistory = JSON.parse(saved);
        restoreMessages();
      } catch (e) {
        conversationHistory = [];
      }
    }
  }

  function bindEvents() {
    toggle.addEventListener('click', toggleChat);
    closeBtn.addEventListener('click', toggleChat);
    sendBtn.addEventListener('click', sendMessage);
    
    inputEl.addEventListener('input', handleInput);
    inputEl.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
      }
    });

    // Quick action buttons
    document.querySelectorAll('.gmb-quick-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const msg = btn.getAttribute('data-msg');
        inputEl.value = msg;
        handleInput();
        sendMessage();
      });
    });

    // Close on Escape
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && isOpen) toggleChat();
    });

    // Close on outside click
    document.addEventListener('click', (e) => {
      if (isOpen && !widget.contains(e.target)) {
        toggleChat();
      }
    });
  }

  // ============================================
  // CHAT TOGGLE
  // ============================================
  function toggleChat() {
    isOpen = !isOpen;
    modal.classList.toggle('gmb-hidden', !isOpen);
    toggle.classList.toggle('gmb-open', isOpen);
    
    // Hide greeting bubble when opened
    toggle.classList.remove('gmb-show-greeting');
    
    if (isOpen) {
      // Show welcome message if first open
      if (messagesEl.children.length === 0) {
        appendBotMessage(CONFIG.welcomeMessage);
      }
      
      // Focus input
      setTimeout(() => inputEl.focus(), 350);
      scrollToBottom();
    } else {
      // Cancel any streaming
      if (abortController) abortController.abort();
    }
  }

  // ============================================
  // GREETING BUBBLE
  // ============================================
  function showGreeting() {
    const shown = sessionStorage.getItem('gmb-greeting-shown');
    if (shown) return;
    
    toggle.setAttribute('data-greeting', CONFIG.greeting);
    
    setTimeout(() => {
      if (!isOpen) {
        toggle.classList.add('gmb-show-greeting');
        sessionStorage.setItem('gmb-greeting-shown', '1');
        
        // Auto-hide after 8 seconds
        setTimeout(() => {
          toggle.classList.remove('gmb-show-greeting');
        }, 8000);
      }
    }, CONFIG.greetingDelay);
  }

  // ============================================
  // INPUT HANDLING
  // ============================================
  function handleInput() {
    const hasText = inputEl.value.trim().length > 0;
    sendBtn.disabled = !hasText || isStreaming;
    
    // Auto-resize textarea
    inputEl.style.height = 'auto';
    inputEl.style.height = Math.min(inputEl.scrollHeight, 100) + 'px';
  }

  // ============================================
  // SEND MESSAGE
  // ============================================
  async function sendMessage() {
    const text = inputEl.value.trim();
    if (!text || isStreaming) return;

    // Hide quick actions after first message
    if (quickActions) {
      quickActions.style.display = 'none';
    }

    // Add user message
    appendUserMessage(text);
    conversationHistory.push({ role: 'user', content: text });
    saveHistory();

    // Clear input
    inputEl.value = '';
    inputEl.style.height = 'auto';
    sendBtn.disabled = true;

    // Show typing indicator
    const typingEl = showTyping();
    setStatus('typing');

    try {
      isStreaming = true;
      abortController = new AbortController();

      // Build messages array
      const messages = [
        { role: 'system', content: SYSTEM_PROMPT },
        ...conversationHistory.slice(-CONFIG.maxHistory)
      ];

      const response = await fetch(CONFIG.apiUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${CONFIG.apiKey}`,
        },
        body: JSON.stringify({
          model: CONFIG.model,
          messages: messages,
          max_tokens: CONFIG.maxTokens,
          temperature: CONFIG.temperature,
          stream: true,
        }),
        signal: abortController.signal,
      });

      if (!response.ok) {
        throw new Error(`API error: ${response.status}`);
      }

      // Remove typing indicator
      removeTyping(typingEl);

      // Stream the response
      const botMsgEl = createBotBubble();
      let fullText = '';

      const reader = response.body.getReader();
      const decoder = new TextDecoder();
      let buffer = '';

      while (true) {
        const { value, done } = await reader.read();
        if (done) break;

        buffer += decoder.decode(value, { stream: true });
        const lines = buffer.split('\n');
        buffer = lines.pop(); // Keep incomplete line

        for (const line of lines) {
          if (!line.startsWith('data: ')) continue;
          const data = line.slice(6);
          if (data === '[DONE]') break;

          try {
            const json = JSON.parse(data);
            const delta = json.choices?.[0]?.delta?.content;
            if (delta) {
              fullText += delta;
              botMsgEl.querySelector('.gmb-msg-text').innerHTML = formatMessage(fullText);
              scrollToBottom();
            }
          } catch (e) {
            // Skip malformed chunks
          }
        }
      }

      // Add timestamp
      addTimestamp(botMsgEl);

      // Save to history
      conversationHistory.push({ role: 'assistant', content: fullText });
      saveHistory();

    } catch (err) {
      removeTyping(typingEl);
      
      if (err.name === 'AbortError') {
        // User closed chat during streaming, that's fine
      } else {
        console.error('GetMyBin Chat Error:', err);
        appendErrorMessage(
          'Oops! Something went wrong. Please try again or email us at support@agentrocketman.com'
        );
      }
    } finally {
      isStreaming = false;
      abortController = null;
      setStatus('online');
      handleInput();
    }
  }

  // ============================================
  // MESSAGE RENDERING
  // ============================================
  function appendUserMessage(text) {
    const el = document.createElement('div');
    el.className = 'gmb-msg gmb-msg-user';
    el.innerHTML = `
      <div class="gmb-msg-text">${escapeHtml(text)}</div>
      <span class="gmb-msg-time">${getTime()}</span>
    `;
    messagesEl.appendChild(el);
    scrollToBottom();
  }

  function appendBotMessage(text) {
    const el = document.createElement('div');
    el.className = 'gmb-msg gmb-msg-bot';
    el.innerHTML = `
      <div class="gmb-msg-text">${formatMessage(text)}</div>
      <span class="gmb-msg-time">${getTime()}</span>
    `;
    messagesEl.appendChild(el);
    scrollToBottom();
  }

  function createBotBubble() {
    const el = document.createElement('div');
    el.className = 'gmb-msg gmb-msg-bot';
    el.innerHTML = `<div class="gmb-msg-text"></div>`;
    messagesEl.appendChild(el);
    scrollToBottom();
    return el;
  }

  function addTimestamp(el) {
    const timeEl = document.createElement('span');
    timeEl.className = 'gmb-msg-time';
    timeEl.textContent = getTime();
    el.appendChild(timeEl);
  }

  function appendErrorMessage(text) {
    const el = document.createElement('div');
    el.className = 'gmb-msg-error';
    el.textContent = text;
    messagesEl.appendChild(el);
    scrollToBottom();
  }

  function showTyping() {
    const el = document.createElement('div');
    el.className = 'gmb-typing';
    el.id = 'gmb-typing-indicator';
    el.innerHTML = `
      <div class="gmb-typing-dot"></div>
      <div class="gmb-typing-dot"></div>
      <div class="gmb-typing-dot"></div>
    `;
    messagesEl.appendChild(el);
    scrollToBottom();
    return el;
  }

  function removeTyping(el) {
    if (el && el.parentNode) {
      el.parentNode.removeChild(el);
    }
  }

  // ============================================
  // RESTORE SAVED MESSAGES
  // ============================================
  function restoreMessages() {
    conversationHistory.forEach(msg => {
      if (msg.role === 'user') {
        appendUserMessage(msg.content);
      } else if (msg.role === 'assistant') {
        appendBotMessage(msg.content);
      }
    });
  }

  // ============================================
  // STATUS
  // ============================================
  function setStatus(status) {
    if (status === 'typing') {
      statusText.textContent = 'Typing...';
      statusDot.style.background = '#fbbf24';
      statusDot.style.boxShadow = '0 0 6px rgba(251, 191, 36, 0.6)';
    } else {
      statusText.textContent = 'Online';
      statusDot.style.background = '#a3f06a';
      statusDot.style.boxShadow = '0 0 6px rgba(163, 240, 106, 0.6)';
    }
  }

  // ============================================
  // UTILITIES
  // ============================================
  function formatMessage(text) {
    // Simple markdown-like formatting
    let html = escapeHtml(text);
    
    // Bold: **text**
    html = html.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
    
    // Italic: *text*
    html = html.replace(/(?<!\*)\*(?!\*)(.*?)(?<!\*)\*(?!\*)/g, '<em>$1</em>');
    
    // Links: [text](url)
    html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
    
    // Bare URLs
    html = html.replace(
      /(?<!")(?<!=)(https?:\/\/[^\s<]+)/g,
      '<a href="$1" target="_blank" rel="noopener">$1</a>'
    );
    
    // Bullet points: lines starting with • or -
    html = html.replace(/^[•\-]\s+(.+)$/gm, '<li>$1</li>');
    html = html.replace(/(<li>.*<\/li>\n?)+/g, '<ul>$&</ul>');
    
    // Line breaks
    html = html.replace(/\n/g, '<br>');
    
    return html;
  }

  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  function getTime() {
    return new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  }

  function scrollToBottom() {
    requestAnimationFrame(() => {
      messagesEl.scrollTop = messagesEl.scrollHeight;
    });
  }

  function saveHistory() {
    try {
      sessionStorage.setItem(
        'gmb-chat-history',
        JSON.stringify(conversationHistory.slice(-CONFIG.maxHistory))
      );
    } catch (e) {
      // Storage full, clear old data
      sessionStorage.removeItem('gmb-chat-history');
    }
  }

  // ============================================
  // BOOT
  // ============================================
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();
