(() => {
  const API_BASE = './api';
  const AUTH_TOKEN = 'curbin-assistant-dev-2026';
  const POLL_INTERVAL_MS = 3000;
  const MAX_RECORD_SECONDS = 90;

  const micBtn = document.getElementById('micBtn');
  const recordingRing = document.getElementById('recordingRing');
  const statusEl = document.getElementById('status');
  const messagesEl = document.getElementById('messages');
  const player = document.getElementById('player');
  const settingsBtn = document.getElementById('settingsBtn');
  const settingsPanel = document.getElementById('settingsPanel');
  const voiceSelect = document.getElementById('voiceSelect');

  const VOICES = ['alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer'];

  let mediaRecorder = null;
  let audioChunks = [];
  let recordingMimeType = '';
  let recordTimer = null;
  let isRecording = false;
  let pollTimer = null;
  let currentAudioUrl = null;
  let audioUnlocked = false;

  // iOS Safari requires a user gesture before audio can play via JS.
  // We prime the persistent player with a silent frame so later async
  // TTS playback (after the network roundtrip) still works.
  function unlockAudioOnce() {
    if (audioUnlocked) return;
    audioUnlocked = true;
    try {
      const silentWav = 'data:audio/wav;base64,UklGRiQAAABXQVZFZm10IBAAAAABAAEARKwAAIhYAQACABAAZGF0YQAAAAA=';
      player.src = silentWav;
      player.volume = 0;
      player.play().catch(() => { /* still counts as an attempt */ });
    } catch {}
  }
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

  function getSupportedMimeType() {
    const candidates = [
      'audio/mp4',
      'audio/webm;codecs=opus',
      'audio/webm',
      'audio/ogg;codecs=opus',
      'audio/ogg',
      'audio/wav',
      'audio/aac',
    ];
    for (const type of candidates) {
      if (MediaRecorder.isTypeSupported(type)) {
        return type;
      }
    }
    return '';
  }

  function getExtensionForMime(mime) {
    if (mime.includes('mp4')) return 'm4a';
    if (mime.includes('webm')) return 'webm';
    if (mime.includes('ogg')) return 'ogg';
    if (mime.includes('wav')) return 'wav';
    if (mime.includes('aac')) return 'aac';
    return 'bin';
  }

  function setStatus(text, cls = '') {
    statusEl.textContent = text;
    statusEl.className = 'status ' + cls;
  }

  function addMessage(text, who, type = 'text') {
    const div = document.createElement('div');
    div.className = `message ${who}`;
    if (type === 'text') {
      div.textContent = text;
    } else if (type === 'audio') {
      const audio = document.createElement('audio');
      audio.controls = true;
      audio.src = text;
      audio.preload = 'auto';
      div.appendChild(audio);
      // Try autoplay assistant replies
      if (who === 'assistant') {
        audio.play().catch(() => {});
      }
    }
    messagesEl.appendChild(div);
    messagesEl.scrollTop = messagesEl.scrollHeight;
  }

  function showError(msg) {
    setStatus(msg, 'error');
    console.error(msg);
  }

  async function startRecording() {
    if (isRecording) return;

    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      recordingMimeType = getSupportedMimeType();
      if (!recordingMimeType) {
        showError('Your browser does not support audio recording.');
        stream.getTracks().forEach(t => t.stop());
        return;
      }

      audioChunks = [];
      mediaRecorder = new MediaRecorder(stream, { mimeType: recordingMimeType });

      mediaRecorder.ondataavailable = (e) => {
        if (e.data && e.data.size > 0) audioChunks.push(e.data);
      };

      mediaRecorder.onstop = () => {
        stream.getTracks().forEach(t => t.stop());
        if (audioChunks.length > 0) {
          sendRecording();
        } else {
          setStatus('No audio captured. Try again.');
          resetUI();
        }
      };

      mediaRecorder.onerror = (e) => {
        showError('Recording error: ' + e.message);
        stopRecording();
      };

      mediaRecorder.start(200); // collect in 200ms chunks
      isRecording = true;
      micBtn.classList.add('recording');
      recordingRing.classList.add('active');
      setStatus('Recording… release to send', 'recording');

      recordTimer = setTimeout(() => {
        setStatus('Max recording time reached');
        stopRecording();
      }, MAX_RECORD_SECONDS * 1000);
    } catch (err) {
      showError('Mic access denied or unavailable: ' + err.message);
    }
  }

  function stopRecording() {
    if (!isRecording || !mediaRecorder) return;
    clearTimeout(recordTimer);
    if (mediaRecorder.state !== 'inactive') {
      mediaRecorder.stop();
    }
    isRecording = false;
    micBtn.classList.remove('recording');
    recordingRing.classList.remove('active');
  }

  function resetUI() {
    micBtn.classList.remove('recording');
    recordingRing.classList.remove('active');
    setStatus('Tap and hold the mic to talk');
  }

  async function sendRecording() {
    setStatus('Sending…', 'sending');

    const blob = new Blob(audioChunks, { type: recordingMimeType });
    const ext = getExtensionForMime(recordingMimeType);
    const filename = `recording_${Date.now()}.${ext}`;
    const formData = new FormData();
    formData.append('audio', blob, filename);

    try {
      const res = await fetch(`${API_BASE}/send.php`, {
        method: 'POST',
        headers: { 'X-Auth-Token': AUTH_TOKEN },
        body: formData,
      });
      const data = await res.json();
      if (!data.ok) {
        throw new Error(data.error || 'Send failed');
      }

      addMessage(data.text || 'You sent a voice message', 'you', 'text');
      setStatus('Waiting for reply…');
      startPolling(data.request_id);
    } catch (err) {
      showError('Failed to send: ' + err.message);
      resetUI();
    }
  }

  function startPolling(requestId) {
    if (pollTimer) clearInterval(pollTimer);

    const poll = async () => {
      try {
        const res = await fetch(`${API_BASE}/poll.php?request_id=${requestId}`, {
          headers: { 'X-Auth-Token': AUTH_TOKEN },
        });
        const data = await res.json();
        if (!data.ok) {
          console.error('Poll error:', data.error);
          return;
        }

        if (data.status === 'replied') {
          clearInterval(pollTimer);
          handleReply(data.reply);
          resetUI();
        }
      } catch (err) {
        console.error('Poll network error:', err);
      }
    };

    poll();
    pollTimer = setInterval(poll, POLL_INTERVAL_MS);
  }

  async function handleReply(reply) {
    if (reply.type !== 'text' || !reply.text) return;

    addMessage(reply.text, 'assistant', 'text');

    // Speak reply with OpenAI TTS
    try {
      const res = await fetch(`${API_BASE}/tts.php`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Auth-Token': AUTH_TOKEN,
        },
        body: JSON.stringify({ text: reply.text, voice: getSelectedVoice() }),
      });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'TTS failed');

      await playBase64Audio(data.audio_base64);
    } catch (err) {
      console.error('TTS/playback error:', err);
      speak(reply.text);
    }
  }

  function stopAudio() {
    try { player.pause(); } catch {}
    if (currentAudioUrl) {
      try { URL.revokeObjectURL(currentAudioUrl); } catch {}
      currentAudioUrl = null;
    }
  }

  async function playBase64Audio(base64) {
    stopAudio();
    const blob = base64ToBlob(base64, 'audio/mpeg');
    const url = URL.createObjectURL(blob);
    currentAudioUrl = url;
    player.src = url;
    player.volume = 1;
    player.preload = 'auto';
    try {
      await player.play();
    } catch (err) {
      console.warn('Audio playback failed:', err);
      throw err;
    }
  }

  function speak(text) {
    if ('speechSynthesis' in window) {
      const u = new SpeechSynthesisUtterance(text);
      window.speechSynthesis.speak(u);
    }
  }

  function loadVoiceSetting() {
    const saved = localStorage.getItem('curbin_voice');
    if (saved && VOICES.includes(saved)) {
      voiceSelect.value = saved;
    }
  }

  function getSelectedVoice() {
    const v = voiceSelect.value;
    return VOICES.includes(v) ? v : 'alloy';
  }

  settingsBtn.addEventListener('click', () => {
    settingsPanel.classList.toggle('hidden');
  });

  voiceSelect.addEventListener('change', () => {
    localStorage.setItem('curbin_voice', getSelectedVoice());
  });

  loadVoiceSetting();

  // Load the persistent conversation from the server so context is shared across devices.
  async function loadConversation() {
    try {
      const res = await fetch(`${API_BASE}/get-conversation.php`, {
        headers: { 'X-Auth-Token': AUTH_TOKEN },
      });
      const data = await res.json();
      if (!data.ok) {
        console.error('Conversation load error:', data.error);
        return;
      }

      messagesEl.innerHTML = '';
      data.messages.forEach((item) => {
        if (!item || typeof item.text !== 'string') return;
        const who = item.role === 'user' ? 'you' : 'assistant';
        addMessage(item.text, who, 'text');
      });
    } catch (err) {
      console.error('Failed to load conversation:', err);
    }
  }

  async function clearConversation() {
    try {
      const res = await fetch(`${API_BASE}/clear-conversation.php`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Auth-Token': AUTH_TOKEN,
        },
      });
      const data = await res.json();
      if (!data.ok) {
        console.error('Clear conversation error:', data.error);
        return;
      }
      messagesEl.innerHTML = '';
    } catch (err) {
      console.error('Failed to clear conversation:', err);
    }
  }

  function renderClearHistoryButton() {
    const btn = document.createElement('button');
    btn.className = 'clear-history';
    btn.textContent = 'Clear chat history';
    btn.addEventListener('click', clearConversation);
    settingsPanel.appendChild(btn);
  }
  renderClearHistoryButton();

  // Touch / mouse handlers for press-and-hold
  micBtn.addEventListener('pointerdown', (e) => {
    e.preventDefault();
    startRecording();
  });

  const stopHandler = (e) => {
    e.preventDefault();
    if (isRecording) stopRecording();
  };

  micBtn.addEventListener('pointerup', stopHandler);
  micBtn.addEventListener('pointerleave', stopHandler);
  micBtn.addEventListener('pointercancel', stopHandler);

  // Prevent context menu on long press
  micBtn.addEventListener('contextmenu', (e) => e.preventDefault());

  // Register service worker for PWA
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').catch(console.error);
  }

  loadConversation();
  setStatus('Tap and hold the mic to talk');
})();
