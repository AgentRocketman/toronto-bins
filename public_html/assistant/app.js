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

  let mediaRecorder = null;
  let audioChunks = [];
  let recordingMimeType = '';
  let recordTimer = null;
  let isRecording = false;
  let pollTimer = null;

  // AudioContext for reliable playback (bypasses autoplay restrictions after first gesture)
  let audioCtx = null;
  let audioCtxUnlocked = false;

  function getAudioContext() {
    if (!audioCtx) {
      audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    }
    return audioCtx;
  }

  function unlockAudio() {
    if (audioCtxUnlocked) return;
    const ctx = getAudioContext();
    if (ctx.state === 'suspended') {
      ctx.resume().then(() => { audioCtxUnlocked = true; });
    } else {
      audioCtxUnlocked = true;
    }
    // Play a brief silent buffer to fully unlock on iOS
    const buf = ctx.createBuffer(1, 1, 22050);
    const src = ctx.createBufferSource();
    src.buffer = buf;
    src.connect(ctx.destination);
    src.start(0);
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
        body: JSON.stringify({ text: reply.text, voice: 'alloy' }),
      });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'TTS failed');

      playBase64Audio(data.audio_base64);
    } catch (err) {
      console.error('TTS error:', err);
      speak(reply.text);
    }
  }

  async function playBase64Audio(base64) {
    // Try <audio> element first — most reliable on iOS Safari for dynamic TTS content
    // (AudioContext.resume() requires a direct user gesture, which we don't have at poll time)
    try {
      const audio = new Audio('data:audio/mpeg;base64,' + base64);
      audio.volume = 1.0;
      await audio.play();
      return;
    } catch (err) {
      console.warn('Audio element playback failed, trying AudioContext:', err);
    }

    // Fallback: AudioContext (desktop browsers with unlocked context)
    try {
      const ctx = getAudioContext();
      if (ctx.state === 'suspended') await ctx.resume();
      const binary = atob(base64);
      const bytes = new Uint8Array(binary.length);
      for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
      const audioBuffer = await ctx.decodeAudioData(bytes.buffer);
      const src = ctx.createBufferSource();
      src.buffer = audioBuffer;
      src.connect(ctx.destination);
      src.start(0);
    } catch (err) {
      console.error('All audio playback methods failed:', err);
    }
  }

  function speak(text) {
    if ('speechSynthesis' in window) {
      const u = new SpeechSynthesisUtterance(text);
      window.speechSynthesis.speak(u);
    }
  }

  // Touch / mouse handlers for press-and-hold
  micBtn.addEventListener('pointerdown', (e) => {
    e.preventDefault();
    unlockAudio(); // Unlock AudioContext on first user gesture
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

  setStatus('Tap and hold the mic to talk');
})();
