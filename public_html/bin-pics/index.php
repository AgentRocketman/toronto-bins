<?php
/**
 * Bin Pics Gallery — browse & view employee photos
 * Images stored externally at /data/.openclaw/workspace/bin-pics-data/
 */
$externalDir = dirname(__DIR__, 2) . '/bin-pics-data';

// Ensure external dir exists
if (!is_dir($externalDir)) {
    @mkdir($externalDir, 0755, true);
}

// Handle single image view
$viewFile = $_GET['f'] ?? null;
if ($viewFile) {
    $safeName = basename($viewFile);
    $realPath = realpath($externalDir . '/' . $safeName);
    if ($realPath && strpos($realPath, realpath($externalDir)) === 0 && file_exists($realPath)) {
        $ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
        $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
        $mimeMap = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp'];
        if ($isImage) {
            header('Content-Type: ' . $mimeMap[$ext]);
            header('Content-Length: ' . filesize($realPath));
            header('Cache-Control: public, max-age=86400, immutable');
            readfile($realPath);
            exit;
        }
    }
    http_response_code(404);
    exit('Not found');
}

// Scan for image files
$files = [];
if (is_dir($externalDir)) {
    $items = scandir($externalDir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            $path = $externalDir . '/' . $item;
            $files[] = [
                'name' => $item,
                'size' => filesize($path),
                'modified' => filemtime($path),
                'ext' => $ext,
            ];
        }
    }
}
// Sort newest first
usort($files, fn($a, $b) => $b['modified'] - $a['modified']);

function formatSize($bytes) {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Bin Pics Gallery</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f8fafc;color:#1e293b;min-height:100vh}
.header{background:#fff;border-bottom:1px solid #e2e8f0;padding:16px 24px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:10}
.header h1{font-size:1.25rem;font-weight:700}
.header .count{font-size:.85rem;color:#64748b}
.container{max-width:1200px;margin:0 auto;padding:24px}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px}
.card{background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.08);transition:transform .15s,box-shadow .15s;cursor:pointer}
.card:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(0,0,0,.12)}
.card img{width:100%;height:160px;object-fit:cover;display:block}
.card .info{padding:10px 12px}
.card .name{font-size:.75rem;font-weight:500;color:#334155;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.card .meta{font-size:.7rem;color:#94a3b8;margin-top:2px}
.empty{text-align:center;padding:60px 20px;color:#94a3b8}
.empty .icon{font-size:3rem;margin-bottom:12px}
.lightbox{display:none;position:fixed;inset:0;background:rgba(0,0,0,.9);z-index:100;align-items:center;justify-content:center}
.lightbox.active{display:flex}
.lightbox img{max-width:90vw;max-height:85vh;object-fit:contain;border-radius:8px}
.lightbox .close{position:absolute;top:16px;right:20px;color:#fff;font-size:2rem;cursor:pointer;z-index:101}
.lightbox .nav{position:absolute;top:50%;transform:translateY(-50%);color:#fff;font-size:2.5rem;cursor:pointer;z-index:101;user-select:none;padding:16px}
.lightbox .nav.prev{left:8px}.lightbox .nav.next{right:8px}
.lightbox .caption{position:absolute;bottom:20px;left:50%;transform:translateX(-50%);color:#fff;font-size:.85rem;background:rgba(0,0,0,.6);padding:6px 14px;border-radius:20px}
@media(max-width:640px){.grid{grid-template-columns:repeat(2,1fr);gap:10px}.card img{height:120px}}
</style>
</head>
<body>
<div class="header">
  <h1>📸 Bin Pics</h1>
  <span class="count"><?= count($files) ?> photos</span>
</div>
<div class="container">
<?php if (empty($files)): ?>
  <div class="empty">
    <div class="icon">📭</div>
    <p>No photos yet. Upload from the field to see them here.</p>
  </div>
<?php else: ?>
  <div class="grid">
  <?php foreach ($files as $i => $f): ?>
    <div class="card" data-index="<?= $i ?>" data-src="/bin-pics/<?= htmlspecialchars($f['name']) ?>">
      <img src="/bin-pics/<?= htmlspecialchars($f['name']) ?>" alt="<?= htmlspecialchars($f['name']) ?>" loading="lazy">
      <div class="info">
        <div class="name"><?= htmlspecialchars($f['name']) ?></div>
        <div class="meta"><?= formatSize($f['size']) ?> · <?= date('M j, g:i A', $f['modified']) ?></div>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
</div>

<div class="lightbox" id="lightbox">
  <span class="close">&times;</span>
  <span class="nav prev" id="prev">&lsaquo;</span>
  <span class="nav next" id="next">&rsaquo;</span>
  <img id="lightbox-img" src="" alt="">
  <span class="caption" id="lightbox-caption"></span>
</div>

<script>
(function(){
  const cards = document.querySelectorAll('.card');
  const lb = document.getElementById('lightbox');
  const lbImg = document.getElementById('lightbox-img');
  const lbCap = document.getElementById('lightbox-caption');
  let currentIndex = -1;

  function open(index) {
    currentIndex = index;
    const card = cards[index];
    lbImg.src = card.dataset.src;
    lbCap.textContent = card.querySelector('.name').textContent;
    lb.classList.add('active');
    document.body.style.overflow = 'hidden';
  }

  function close() {
    lb.classList.remove('active');
    document.body.style.overflow = '';
  }

  cards.forEach((card, i) => card.addEventListener('click', () => open(i)));
  document.querySelector('.close').addEventListener('click', close);
  lb.addEventListener('click', e => { if (e.target === lb) close(); });

  document.getElementById('prev').addEventListener('click', e => {
    e.stopPropagation();
    if (currentIndex > 0) open(currentIndex - 1);
  });
  document.getElementById('next').addEventListener('click', e => {
    e.stopPropagation();
    if (currentIndex < cards.length - 1) open(currentIndex + 1);
  });

  document.addEventListener('keydown', e => {
    if (!lb.classList.contains('active')) return;
    if (e.key === 'Escape') close();
    if (e.key === 'ArrowLeft' && currentIndex > 0) open(currentIndex - 1);
    if (e.key === 'ArrowRight' && currentIndex < cards.length - 1) open(currentIndex + 1);
  });
})();
</script>
</body>
</html>
