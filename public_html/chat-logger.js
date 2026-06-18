/**
 * Chat Logger — Logs questions and answers to backend
 * Called by getmybin-chat.js
 */

(function () {
  'use strict';

  // Generate or retrieve session ID (stored in localStorage)
  function getOrCreateSessionId() {
    let sessionId = localStorage.getItem('gmb-session-id');
    if (!sessionId) {
      sessionId = 'session_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
      localStorage.setItem('gmb-session-id', sessionId);
    }
    return sessionId;
  }

  // Log a message to backend
  async function logMessage(messageType, content) {
    const sessionId = getOrCreateSessionId();
    const timestamp = new Date().toISOString();

    try {
      const response = await fetch('/api/log-chat.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          sessionId: sessionId,
          messageType: messageType, // 'question' or 'answer'
          message: content,
          timestamp: timestamp
        })
      });

      if (!response.ok) {
        console.warn('Chat log failed:', response.status);
      }
    } catch (err) {
      console.warn('Chat log error:', err);
      // Don't block chat if logging fails
    }
  }

  // Expose globally for chat widget to call
  window.gmbLogMessage = logMessage;
  window.gmbGetSessionId = getOrCreateSessionId;

})();
