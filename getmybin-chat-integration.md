# GetMyBin AI Chat Widget - Integration Guide

## Overview

The GetMyBin AI chat widget is a production-ready, standalone HTML file that provides customer support via OpenAI's ChatGPT API. It features a sticky chat icon, beautiful modal interface, and intelligent responses based on GetMyBin service knowledge.

## Quick Start

### 1. Get Your OpenAI API Key

1. Go to https://platform.openai.com/account/api-keys
2. Log in or create an account
3. Create a new API key
4. Keep it safe (you'll need it in the next step)

### 2. Add the Widget to Your Website

Add this code to your website (we recommend adding it just before the closing `</body>` tag):

```html
<!-- GetMyBin Chat Widget -->
<script src="/getmybin-chat-widget.html"></script>
<script>
    // Initialize the chat widget with your OpenAI API key
    window.getMyBinSetApiKey('YOUR_API_KEY_HERE');
</script>
```

**Replace `YOUR_API_KEY_HERE` with your actual OpenAI API key.**

### 3. That's It!

The chat widget will appear as a green sticky icon in the bottom-right corner of your website. Users can click it to open the chat interface.

## Features

✅ **Beautiful Design**
- Matches GetMyBin brand colors (#71b80c green)
- Smooth animations and modern UI
- Responsive on desktop and mobile

✅ **Smart AI Responses**
- Trained on GetMyBin service knowledge
- Answers questions about pricing, service, FAQ
- Helpful and friendly tone
- Suggests contacting support for urgent issues

✅ **Real-Time Streaming**
- Live message updates as AI responds
- Typing indicator for visual feedback
- Smooth message animations

✅ **Full-Featured**
- Conversation history (per session)
- Error handling
- Mobile-optimized
- No external dependencies

## Customization

### Change the Chat Icon

Open `getmybin-chat-widget.html` and find this line:

```html
<button class="getmybin-chat-icon" id="getmybinChatIcon" aria-label="Open chat">
    💬
</button>
```

Replace `💬` with any emoji you prefer, e.g., `🤖`, `❓`, `💡`, etc.

### Change the Welcome Message

Find this line in the messages container:

```html
<div class="getmybin-chat-bubble">
    Hey! 👋 I'm the GetMyBin assistant. Got questions about our bin collection service? I'm here to help!
</div>
```

Edit it to whatever greeting you prefer.

### Adjust Colors

Search for `#71b80c` in the CSS and replace with your preferred color code. This is the primary green color used throughout.

### Change the AI Model

In the `CHAT_CONFIG` section, you can switch models:

```javascript
model: 'gpt-3.5-turbo', // More affordable
// OR
model: 'gpt-4o',        // More powerful (higher cost)
```

## API Key Security

⚠️ **Important:** Your API key will be visible in the browser's network requests. Consider these approaches:

### Option A: Backend Proxy (Recommended)
Instead of exposing your API key, create a simple backend endpoint that handles API calls:

```php
<?php
// /api/chat.php
$apiKey = getenv('OPENAI_API_KEY'); // Store key in environment variable
$request = json_decode(file_get_contents('php://input'), true);

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ],
    CURLOPT_POSTFIELDS => json_encode($request),
    CURLOPT_RETURNTRANSFER => true
]);

$response = curl_exec($ch);
curl_close($ch);

echo $response;
?>
```

Then update the widget to call `/api/chat.php` instead of the OpenAI API directly.

### Option B: OpenAI API Rate Limiting
OpenAI allows you to set usage limits and track costs in your account dashboard. Monitor your usage to avoid unexpected charges.

### Option C: Token Allowlist
You can use OpenAI's organization features to restrict API key usage to specific IP addresses.

## Monitoring & Costs

**Typical Costs:**
- GPT-3.5-Turbo: ~$0.001-0.005 per chat (very affordable)
- GPT-4: ~$0.01-0.03 per chat (more powerful)

**Monitor Usage:**
1. Go to https://platform.openai.com/account/billing/overview
2. Set up usage alerts if desired
3. Review costs monthly

## Troubleshooting

### Chat widget doesn't appear
- Check browser console for errors (F12 → Console)
- Make sure the API key is set correctly
- Try a different browser

### API key error
- Verify the key is correct and hasn't been regenerated
- Check that your OpenAI account has credits
- Ensure the key has chat completions permissions

### Slow responses
- This is normal - GPT responses take 2-5 seconds
- Consider using gpt-3.5-turbo for faster (cheaper) responses

### Widget not responding
- Check network tab (F12 → Network) for failed requests
- Verify API key format (should be `sk-...`)
- Check OpenAI API status: https://status.openai.com

## Deployment

The widget is already in your public_html folder:
- **File:** `/data/.openclaw/workspace/public_html/getmybin-chat-widget.html`

To deploy to agentrocketman.com, the file is ready. Just add the initialization code to your main pages (especially `index.html`).

### Add to index.html

Before the closing `</body>` tag in `index.html`, add:

```html
<!-- GetMyBin Chat Widget -->
<script src="/getmybin-chat-widget.html"></script>
<script>
    window.getMyBinSetApiKey('sk-proj-YOUR_API_KEY_HERE');
</script>
```

## File Structure

- **getmybin-chat-widget.html** - Complete standalone widget (no dependencies)
  - All CSS is inline
  - All JavaScript is inline
  - No external libraries required
  - ~19KB file size

## Support

If you encounter issues:
1. Check the browser console (F12 → Console)
2. Review the troubleshooting section above
3. Test with a simple message first
4. Contact OpenAI support if it's an API issue

---

**Ready to launch?** Add the initialization code to your website and watch customer inquiries get handled by AI! 🚀
