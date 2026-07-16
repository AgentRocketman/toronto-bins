<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Video Manager — Agentado</title>
<style>
:root {
  --bg: #0f0f13;
  --card: #1a1a24;
  --border: #2a2a3a;
  --accent: #7c5ce7;
  --accent2: #ff6b6b;
  --text: #e0e0e0;
  --muted: #888;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
  background: var(--bg); color: var(--text);
  min-height: 100vh; padding: 24px;
}
header {
  display: flex; justify-content: space-between; align-items: center;
  flex-wrap: wrap; gap: 12px; margin-bottom: 24px;
  max-width: 900px; margin-left: auto; margin-right: auto;
}
header h1 { font-size: 1.5rem; }
header .stats { color: var(--muted); font-size: 0.85rem; }
.loading { text-align: center; padding: 80px; color: var(--muted); }
.loading .spinner {
  display: inline-block; width: 32px; height: 32px;
  border: 3px solid var(--border); border-top-color: var(--accent);
  border-radius: 50%; animation: spin 0.8s linear infinite; margin-bottom: 16px;
}
@keyframes spin { to { transform: rotate(360deg); } }
.error-box {
  text-align: center; padding: 40px; color: var(--accent2);
  max-width: 500px; margin: 0 auto;
}
.video-list {
  display: flex; flex-direction: column; gap: 12px;
  max-width: 900px; margin: 0 auto;
}
.video-card {
  background: var(--card); border: 1px solid var(--border);
  border-radius: 12px; padding: 16px;
  display: flex; gap: 16px; align-items: flex-start;
}
.video-thumb {
  width: 200px; min-width: 200px; height: 120px; border-radius: 8px;
  background: #000; overflow: hidden;
}
.video-thumb video { width: 100%; height: 100%; object-fit: cover; }
.video-info { flex: 1; min-width: 0; }
.video-info .name { font-weight: 600; margin-bottom: 4px; }
.video-info .meta { font-size: 0.8rem; color: var(--muted); margin-bottom: 8px; }
.video-info .tags { display: flex; gap: 6px; flex-wrap: wrap; }
.tag {
  font-size: 0.7rem; padding: 2px 8px; border-radius: 12px;
  background: rgba(124,92,231,0.15); color: var(--accent);
  border: 1px solid rgba(124,92,231,0.3);
}
.tag.warn { background: rgba(255,107,107,0.12); color: var(--accent2); border-color: rgba(255,107,107,0.25); }
.video-actions { display: flex; flex-direction: column; gap: 8px; align-items: flex-end; min-width: 100px; }
.btn {
  padding: 8px 16px; border-radius: 8px; border: none;
  font-size: 0.82rem; cursor: pointer; text-decoration: none;
  display: inline-flex; align-items: center; gap: 6px;
}
.btn-accent { background: var(--accent); color: #fff; }
.btn-danger { background: var(--accent2); color: #fff; }
.btn-outline { background: transparent; border: 1px solid var(--border); color: var(--text); }
.delete-confirming { animation: pulse 0.8s ease-in-out infinite; }
@keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.5; } }
.empty { text-align: center; padding: 60px 20px; color: var(--muted); }
.empty .emoji { font-size: 3rem; margin-bottom: 12px; }
.status-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 6px; }
.status-dot.online { background: #4ade80; }
.status-dot.offline { background: var(--accent2); }

@media (max-width: 640px) {
  .video-card { flex-direction: column; }
  .video-thumb { width: 100%; min-width: 100%; }
  .video-actions { flex-direction: row; width: 100%; justify-content: flex-end; }
  header { flex-direction: column; align-items: flex-start; }
}
</style>
</head>
<body>

<header>
  <div>
    <h1>🎥 Video Manager</h1>
    <div class="stats" id="stats">Loading…</div>
  </div>
  <div style="font-size:0.8rem; color: var(--muted);">
    <span class="status-dot" id="statusDot"></span>
    <span id="statusText">Connecting…</span>
  </div>
</header>

<div id="loading" class="loading">
  <div class="spinner"></div>
  <p>Loading videos…</p>
</div>

<div class="video-list" id="videoList"></div>

<script>
const fmtSize = b => b >= 1e9 ? (b/1e9).toFixed(1)+' GB' : b >= 1e6 ? (b/1e6).toFixed(1)+' MB' : b >= 1e3 ? (b/1e3).toFixed(1)+' KB' : b+' B';
const fmtDate = ts => new Date(ts*1000).toLocaleString('en-US', { month:'short', day:'numeric', hour:'numeric', minute:'2-digit' });
const typeLabel = t => t==='job'?'🎬 3D Cinematic' : t==='video'?'📹 Ken Burns' : '🔬 Preview';

let tunnelBase = null;
let allVideos = [];

async function init() {
  // Get tunnel URL
  try {
    const r = await fetch('/agentado/api/tunnel-url.php');
    const d = await r.json();
    tunnelBase = d.url + '/agentado/api';
    document.getElementById('statusDot').className = 'status-dot online';
    document.getElementById('statusText').textContent = 'Connected';
  } catch(e) {
    document.getElementById('loading').innerHTML = '<div class="error-box"><p>❌ Cannot reach Docker container.</p><p style="margin-top:8px;color:var(--muted);">Check that the tunnel is active.</p></div>';
    document.getElementById('statusDot').className = 'status-dot offline';
    document.getElementById('statusText').textContent = 'Offline';
    return;
  }

  await loadVideos();
}

async function loadVideos() {
  try {
    const r = await fetch(tunnelBase + '/list-videos.php');
    const data = await r.json();
    if (!data.ok) throw new Error(data.error);
    allVideos = data.videos || [];
    render();
  } catch(e) {
    document.getElementById('loading').innerHTML = '<div class="error-box"><p>❌ Failed to load video list</p><p style="color:var(--muted);">'+e.message+'</p></div>';
  }
}

function render() {
  document.getElementById('loading').style.display = 'none';
  const totalSize = allVideos.reduce((s,v) => s+v.size, 0);
  document.getElementById('stats').textContent = allVideos.length + ' videos · ' + fmtSize(totalSize) + ' total';

  if (!allVideos.length) {
    document.getElementById('videoList').innerHTML = '<div class="empty"><div class="emoji">📭</div><p>No videos found</p></div>';
    return;
  }

  document.getElementById('videoList').innerHTML = allVideos.map(v => `
    <div class="video-card" id="card-${v.id}">
      <div class="video-thumb">
        <video src="${tunnelBase.replace('/api','') + v.url}" preload="metadata" muted playsinline></video>
      </div>
      <div class="video-info">
        <div class="name">${v.name}</div>
        <div class="meta">${fmtDate(v.date)} · ${fmtSize(v.size)}${v.photo_count ? ' · '+v.photo_count+' photos' : ''}</div>
        <div class="tags">
          <span class="tag">${typeLabel(v.type)}</span>
          ${v.voice ? '<span class="tag">🎙️ Voice</span>' : ''}
          ${v.subtitles ? '<span class="tag">📝 Subtitles</span>' : ''}
        </div>
      </div>
      <div class="video-actions">
        <a href="${tunnelBase.replace('/api','') + v.url}" download class="btn btn-outline">⬇</a>
        <button class="btn btn-danger" onclick="deleteVideo(this, '${v.id}', '${v.type}')">🗑</button>
      </div>
    </div>
  `).join('');
}

async function deleteVideo(btn, id, type) {
  if (btn.classList.contains('delete-confirming')) {
    btn.textContent = '…'; btn.disabled = true;
    try {
      const form = new FormData();
      form.append('job_id', id);
      form.append('type', type);
      const r = await fetch(tunnelBase + '/delete-video.php', { method: 'POST', body: form });
      const data = await r.json();
      if (data.ok) {
        document.getElementById('card-'+id)?.remove();
        allVideos = allVideos.filter(v => v.id !== id);
        if (!allVideos.length) {
          document.getElementById('videoList').innerHTML = '<div class="empty"><div class="emoji">📭</div><p>No videos found</p></div>';
        }
        const totalSize = allVideos.reduce((s,v) => s+v.size, 0);
        document.getElementById('stats').textContent = allVideos.length + ' videos · ' + fmtSize(totalSize) + ' total';
      } else {
        btn.textContent = '❌'; btn.classList.remove('delete-confirming'); btn.disabled = false;
      }
    } catch(e) {
      btn.textContent = '🗑'; btn.classList.remove('delete-confirming'); btn.disabled = false;
    }
  } else {
    btn.textContent = 'Sure?'; btn.classList.add('delete-confirming');
    setTimeout(() => {
      if (btn.classList.contains('delete-confirming')) { btn.textContent = '🗑'; btn.classList.remove('delete-confirming'); }
    }, 4000);
  }
}

init();
</script>
</body>
</html>