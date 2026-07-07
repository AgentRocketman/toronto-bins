(() => {
  const CONFIG = {
    authToken: 'curbin-chat-dev-2026',
    apiBase: '/chat/api',
    // Polling: 1s initial, backs off to 3s after 15s, gives up after 200s
    pollInitialMs: 1000,
    pollBackoffAfterMs: 15000,
    pollBackoffMs: 3000,
    pollTimeoutMs: 200000,
    storageKey: 'openclaw-chat-history-v1',
    maxHistory: 200,
  };

  const els = {
    messages: document.getElementById('messages'),
    input:    document.getElementById('input'),
    sendBtn:  document.getElementById('sendBtn'),
    micBtn:   document.getElementById('micBtn'),
    form:     document.getElementById('composer'),
    status:   document.getElementById('status'),
    clearBtn: document.getElementById('clearBtn'),
  };

  let history = loadHistory();
  let inflight = null; // { requestId, pollTimer, typingEl }

  // Recording state
  let mediaRecorder = null;
  let audioChunks = [];
  let recordingMime = '';
  let recordTimer = null;
  let isRecording = false;
  let recordStartTs = 0;
  const MAX_RECORD_SECONDS = 60;
  const MIN_RECORD_MS = 700; // reject anything shorter — usually silence/hallucination

  // TTS state
  const TTS_VOICE = 'nova';
  let currentAudio = null; // so we can stop the previous playback when a new one starts
  let currentAudioUrl = null; // object URL to revoke when done
  let lastMessageWasVoice = false; // when true, the next bot reply auto-plays TTS
  let audioUnlocked = false;

  // Try to unlock audio playback on the first pointer/tap interaction.
  // iOS Safari requires a user gesture before audio can play from JS.
  function unlockAudioOnce() {
    if (audioUnlocked) return;
    audioUnlocked = true;
    // Play a silent 1-frame WAV to satisfy the gesture requirement.
    try {
      const silentWav = 'data:audio/wav;base64,UklGRiQAAABXQVZFZm10IBAAAAABAAEARKwAAIhYAQACABAAZGF0YQAAAAA=';
      const a = new Audio(silentWav);
      a.volume = 0;
      a.play().catch(() => { /* still counts as an attempt */ });
    } catch {}
  }
  // First user gesture anywhere on the page unlocks audio.
  ['pointerdown', 'touchstart', 'click'].forEach(ev => {
    document.addEventListener(ev, unlockAudioOnce, { once: true, passive: true });
  });

  function base64ToBlob(b64, mime) {
    const bin = atob(b64);
    const len = bin.length;
    const buf = new Uint8Array(len);
    for (let i = 0; i < len; i++) buf[i] = bin.charCodeAt(i);
    return new Blob([buf], { type: mime });
  }

  // ---------- History ----------
  function loadHistory() {
    try {
      const raw = localStorage.getItem(CONFIG.storageKey);
      if (!raw) return [];
      const arr = JSON.parse(raw);
      return Array.isArray(arr) ? arr : [];
    } catch { return []; }
  }
  function saveHistory() {
    try {
      const trimmed = history.slice(-CONFIG.maxHistory);
      localStorage.setItem(CONFIG.storageKey, JSON.stringify(trimmed));
    } catch {}
  }
  function pushHistory(entry) {
    history.push(entry);
    saveHistory();
  }

  // ---------- Rendering ----------
  function fmtTime(ts) {
    const d = new Date(ts);
    return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  }
  function renderMessage({ role, text, ts }) {
    const el = document.createElement('div');
    el.className = 'msg ' + role;
    const body = document.createElement('span');
    body.textContent = text;
    el.appendChild(body);

    // Bot messages get a small "speak" button on hover/tap
    if (role === 'bot') {
      const speakBtn = document.createElement('button');
      speakBtn.className = 'speak-btn';
      speakBtn.type = 'button';
      speakBtn.title = 'Play voice';
      speakBtn.setAttribute('aria-label', 'Play voice');
      speakBtn.innerHTML =
        '<svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true">' +
        '<path d="M3 10v4h4l5 5V5L7 10H3z"/>' +
        '<path d="M14 8.5v7a4 4 0 0 0 0-7z"/>' +
        '</svg>';
      speakBtn.addEventListener('click', async (e) => {
        e.stopPropagation();
        speakBtn.disabled = true;
        try {
          await playTTS(text);
        } finally {
          speakBtn.disabled = false;
        }
      });
      el.appendChild(speakBtn);
    }

    const time = document.createElement('span');
    time.className = 'time';
    time.textContent = fmtTime(ts || Date.now());
    el.appendChild(time);
    els.messages.appendChild(el);
    scrollToBottom();
    return el;
  }

  // ---------- TTS ----------
  async function playTTS(text) {
    if (!text) return;

    // Stop any current playback first (and free the object URL)
    if (currentAudio) {
      try { currentAudio.pause(); currentAudio.src = ''; } catch {}
      currentAudio = null;
    }
    if (currentAudioUrl) {
      try { URL.revokeObjectURL(currentAudioUrl); } catch {}
      currentAudioUrl = null;
    }

    let data;
    try {
      const res = await fetch(CONFIG.apiBase + '/tts.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Auth-Token': CONFIG.authToken,
        },
        body: JSON.stringify({ text, voice: TTS_VOICE }),
      });
      data = await res.json();
      if (!res.ok || !data.ok) throw new Error(data.error || ('http ' + res.status));
    } catch (err) {
      console.warn('TTS fetch failed:', err);
      setStatus('Voice unavailable', 'error');
      if ('speechSynthesis' in window) {
        try {
          const u = new SpeechSynthesisUtterance(text);
          window.speechSynthesis.speak(u);
        } catch {}
      }
      return;
    }

    // Use Blob + object URL (much more reliable across browsers than data: URLs,
    // especially on iOS Safari which flakes on repeated data: audio playback).
    try {
      const blob = base64ToBlob(data.audio_base64, data.mime_type || 'audio/mpeg');
      const url  = URL.createObjectURL(blob);
      const audio = new Audio();
      audio.src = url;
      audio.preload = 'auto';
      currentAudio = audio;
      currentAudioUrl = url;

      const cleanup = () => {
        if (currentAudioUrl === url) {
          try { URL.revokeObjectURL(url); } catch {}
          currentAudioUrl = null;
        }
        if (currentAudio === audio) currentAudio = null;
      };
      audio.addEventListener('ended', cleanup, { once: true });
      audio.addEventListener('error', () => {
        console.warn('Audio playback error:', audio.error);
        cleanup();
      }, { once: true });

      // Ensure gesture unlock has fired
      unlockAudioOnce();

      await audio.play();
    } catch (err) {
      console.warn('TTS playback failed:', err);
      setStatus('Tap the 🔊 on the reply to play', 'error');
      // Fall back to speechSynthesis so Chris still hears something
      if ('speechSynthesis' in window) {
        try {
          const u = new SpeechSynthesisUtterance(text);
          window.speechSynthesis.speak(u);
        } catch {}
      }
    }
  }
  function renderSystem(text) {
    const el = document.createElement('div');
    el.className = 'msg system';
    el.textContent = text;
    els.messages.appendChild(el);
    scrollToBottom();
    return el;
  }
  function showTyping() {
    const el = document.createElement('div');
    el.className = 'typing';
    el.innerHTML = '<span></span><span></span><span></span>';
    els.messages.appendChild(el);
    scrollToBottom();
    return el;
  }
  function scrollToBottom() {
    requestAnimationFrame(() => {
      els.messages.scrollTop = els.messages.scrollHeight;
    });
  }
  function setStatus(text, cls = '') {
    els.status.textContent = text;
    els.status.className = 'status' + (cls ? ' ' + cls : '');
  }

  function renderHistory() {
    els.messages.innerHTML = '';
    for (const m of history) renderMessage(m);
  }

  // ---------- Networking ----------
  async function sendMessage(text) {
    const res = await fetch(CONFIG.apiBase + '/send.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Auth-Token': CONFIG.authToken,
      },
      body: JSON.stringify({ text }),
    });
    if (!res.ok) {
      const errText = await res.text().catch(() => '');
      throw new Error('Send failed: ' + res.status + ' ' + errText);
    }
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'Send failed');
    return data.request_id;
  }

  async function pollOnce(requestId, since) {
    const url = CONFIG.apiBase
      + '/poll.php?request_id=' + encodeURIComponent(requestId)
      + '&since=' + encodeURIComponent(since);
    const res = await fetch(url, { headers: { 'X-Auth-Token': CONFIG.authToken } });
    if (!res.ok) throw new Error('Poll failed: ' + res.status);
    return await res.json();
  }

  function renderStatusChunk(text) {
    const el = document.createElement('div');
    el.className = 'msg status-chunk';
    el.textContent = '• ' + text;
    els.messages.appendChild(el);
    scrollToBottom();
    return el;
  }

  function startPolling(requestId, typingEl, startTs) {
    const started = startTs || Date.now();
    let seenSeq = -1;         // highest chunk seq already rendered
    let finalText = null;     // set when a non-status chunk arrives

    async function tick() {
      if (!inflight || inflight.requestId !== requestId) return; // superseded

      try {
        const data = await pollOnce(requestId, seenSeq);

        // Render any new chunks (both status and final)
        if (Array.isArray(data.chunks) && data.chunks.length > 0) {
          for (const c of data.chunks) {
            if (typeof c.seq === 'number' && c.seq > seenSeq) seenSeq = c.seq;
            if (c.status) {
              // Interim progress line
              renderStatusChunk(c.text);
            } else {
              // Final reply chunk (or an additional non-status append)
              finalText = c.text;
            }
          }
        }

        if (data.done) {
          typingEl.remove();
          // Fall back to data.reply if for some reason no non-status chunk came through
          const finalReply = finalText || data.reply || '';
          if (finalReply) {
            const msg = { role: 'bot', text: finalReply, ts: Date.now() };
            pushHistory(msg);
            renderMessage(msg);

            if (lastMessageWasVoice) {
              lastMessageWasVoice = false;
              playTTS(finalReply).catch(() => {});
            }
          }
          setStatus('Ready');
          inflight = null;
          return;
        }
      } catch (err) {
        console.warn('Poll error', err);
        // keep trying — Telegram might be slow
      }

      const elapsed = Date.now() - started;
      if (elapsed > CONFIG.pollTimeoutMs) {
        typingEl.remove();
        renderSystem('No reply yet — OpenClaw might be busy. Your message was delivered; check Telegram.');
        setStatus('Timed out', 'error');
        inflight = null;
        return;
      }

      const delay = elapsed > CONFIG.pollBackoffAfterMs
        ? CONFIG.pollBackoffMs
        : CONFIG.pollInitialMs;
      inflight.pollTimer = setTimeout(tick, delay);
    }

    inflight.pollTimer = setTimeout(tick, CONFIG.pollInitialMs);
  }

  // ---------- Submit handler ----------
  async function handleSubmit(e) {
    e.preventDefault();
    if (inflight) return; // wait for current reply

    const text = els.input.value.trim();
    if (!text) return;

    // Render user message
    const userMsg = { role: 'user', text, ts: Date.now() };
    pushHistory(userMsg);
    renderMessage(userMsg);

    // Reset composer
    els.input.value = '';
    els.input.style.height = 'auto';
    els.sendBtn.disabled = true;
    setStatus('Sending…', 'thinking');

    const typingEl = showTyping();

    try {
      const requestId = await sendMessage(text);
      setStatus('Waiting for OpenClaw…', 'thinking');
      inflight = { requestId, pollTimer: null, typingEl };
      startPolling(requestId, typingEl, Date.now());
    } catch (err) {
      console.error(err);
      typingEl.remove();
      renderSystem('Failed to send: ' + err.message);
      setStatus('Error', 'error');
      inflight = null;
    }

    els.sendBtn.disabled = false;
    els.input.focus();
  }

  // ---------- Input UX ----------
  function autosize() {
    els.input.style.height = 'auto';
    els.input.style.height = Math.min(els.input.scrollHeight, 140) + 'px';
  }
  els.input.addEventListener('input', autosize);
  els.input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
      // On desktop, Enter sends. On touch devices, use the button.
      if (!('ontouchstart' in window)) {
        e.preventDefault();
        els.form.requestSubmit();
      }
    }
  });

  // ---------- Voice recording ----------
  function getSupportedMimeType() {
    if (!window.MediaRecorder) return '';
    const candidates = [
      'audio/mp4',
      'audio/webm;codecs=opus',
      'audio/webm',
      'audio/ogg;codecs=opus',
      'audio/ogg',
      'audio/wav',
    ];
    for (const t of candidates) {
      if (MediaRecorder.isTypeSupported(t)) return t;
    }
    return '';
  }
  function extForMime(m) {
    if (m.includes('mp4')) return 'm4a';
    if (m.includes('webm')) return 'webm';
    if (m.includes('ogg')) return 'ogg';
    if (m.includes('wav')) return 'wav';
    return 'bin';
  }

  async function startRecording() {
    if (isRecording || inflight) return;
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      recordingMime = getSupportedMimeType();
      if (!recordingMime) {
        stream.getTracks().forEach(t => t.stop());
        renderSystem('Your browser does not support audio recording.');
        return;
      }
      audioChunks = [];
      mediaRecorder = new MediaRecorder(stream, { mimeType: recordingMime });
      mediaRecorder.ondataavailable = (e) => {
        if (e.data && e.data.size > 0) audioChunks.push(e.data);
      };
      mediaRecorder.onstop = () => {
        stream.getTracks().forEach(t => t.stop());
        if (audioChunks.length > 0) sendRecording();
        else { setStatus('No audio captured', 'error'); resetMicUI(); }
      };
      mediaRecorder.onerror = (e) => {
        renderSystem('Recording error: ' + (e.error?.message || e.message || 'unknown'));
        stopRecording();
      };
      mediaRecorder.start(200);
      isRecording = true;
      recordStartTs = Date.now();
      els.micBtn.classList.add('recording');
      setStatus('Recording… release to send', 'thinking');
      recordTimer = setTimeout(() => stopRecording(), MAX_RECORD_SECONDS * 1000);
    } catch (err) {
      renderSystem('Mic access denied or unavailable: ' + err.message);
    }
  }

  function stopRecording() {
    if (!isRecording || !mediaRecorder) return;
    clearTimeout(recordTimer);
    const duration = Date.now() - recordStartTs;
    if (duration < MIN_RECORD_MS) {
      // Too short — abort without sending to avoid Whisper hallucinations.
      try { mediaRecorder.stream.getTracks().forEach(t => t.stop()); } catch {}
      try { if (mediaRecorder.state !== 'inactive') mediaRecorder.stop(); } catch {}
      audioChunks = [];
      isRecording = false;
      els.micBtn.classList.remove('recording');
      setStatus('Hold the mic longer to record', 'error');
      return;
    }
    if (mediaRecorder.state !== 'inactive') mediaRecorder.stop();
    isRecording = false;
    els.micBtn.classList.remove('recording');
    setStatus('Transcribing…', 'thinking');
  }

  function resetMicUI() {
    els.micBtn.classList.remove('recording');
    isRecording = false;
  }

  async function sendRecording() {
    const blob = new Blob(audioChunks, { type: recordingMime });
    const ext  = extForMime(recordingMime);
    const filename = `chat_${Date.now()}.${ext}`;
    const fd = new FormData();
    fd.append('audio', blob, filename);

    // Show a placeholder user message with a spinner — will get replaced with transcription
    const placeholder = { role: 'user', text: '🎙️ (recording…)', ts: Date.now() };
    const placeholderEl = renderMessage(placeholder);

    const typingEl = showTyping();

    try {
      const res = await fetch(CONFIG.apiBase + '/send-audio.php', {
        method: 'POST',
        headers: { 'X-Auth-Token': CONFIG.authToken },
        body: fd,
      });
      const data = await res.json();
      if (!res.ok || !data.ok) throw new Error(data.error || ('http ' + res.status));

      // Replace placeholder with the actual transcription
      const body = placeholderEl.firstChild;
      body.textContent = data.text;
      const finalized = { role: 'user', text: data.text, ts: Date.now() };
      pushHistory(finalized);

      // Voice input → next reply should auto-play as TTS
      lastMessageWasVoice = true;

      setStatus('Waiting for OpenClaw…', 'thinking');
      inflight = { requestId: data.request_id, pollTimer: null, typingEl };
      startPolling(data.request_id, typingEl, Date.now());
    } catch (err) {
      console.error(err);
      placeholderEl.remove();
      typingEl.remove();
      renderSystem('Voice send failed: ' + err.message);
      setStatus('Error', 'error');
    } finally {
      resetMicUI();
    }
  }

  // Press-and-hold handlers
  els.micBtn.addEventListener('pointerdown', (e) => {
    e.preventDefault();
    startRecording();
  });
  const stopHandler = (e) => {
    e.preventDefault();
    if (isRecording) stopRecording();
  };
  els.micBtn.addEventListener('pointerup', stopHandler);
  els.micBtn.addEventListener('pointerleave', stopHandler);
  els.micBtn.addEventListener('pointercancel', stopHandler);
  els.micBtn.addEventListener('contextmenu', (e) => e.preventDefault());

  els.form.addEventListener('submit', handleSubmit);
  els.clearBtn.addEventListener('click', () => {
    if (!confirm('Clear chat history?')) return;
    history = [];
    saveHistory();
    els.messages.innerHTML = '';
    renderSystem('History cleared.');
  });

  // ---------- Init ----------
  renderHistory();
  if (history.length === 0) {
    renderSystem('Type a message. OpenClaw will reply here.');
  }
  els.input.focus();
})();
