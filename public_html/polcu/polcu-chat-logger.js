/**
 * POLCU Chat Logger — Logs questions and answers to backend
 * Called by polcu-chat.js
 */

(function () {
  'use strict';

  // Generate or retrieve session ID (stored in localStorage)
  function getOrCreateSessionId() {
    let sessionId = localStorage.getItem('polcu-session-id');
    if (!sessionId) {
      sessionId = 'session_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
      localStorage.setItem('polcu-session-id', sessionId);
    }
    return sessionId;
  }

  // Log a message to backend
  async function logMessage(messageType, content) {
    const sessionId = getOrCreateSessionId();
    const timestamp = new Date().toISOString();

    try {
      const response = await fetch(atob('aHR0cHM6Ly9hZ2VudHJvY2tldG1hbi5jb20vcG9sY3UvYXBpL2xvZy1jaGF0LnBocA=='), {
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
        console.warn('POLCU chat log failed:', response.status);
      }
    } catch (err) {
      console.warn('POLCU chat log error:', err);
      // Don't block chat if logging fails
    }
  }

  // Expose globally for chat widget to call
  window.polcuLogMessage = logMessage;
  window.polcuGetSessionId = getOrCreateSessionId;

})();
