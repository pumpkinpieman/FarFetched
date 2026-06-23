<?php
declare(strict_types=1);

/**
 * collections_view.php — browse your collections and their models.
 * ?id=<collectionId> shows that collection; otherwise the first one.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth.php';
require_auth();

/** Stable gradient from a name (self-contained; mirrors the library tile look). */
function cv_tile_style(string $name): string
{
    $h = crc32($name);
    $h1 = $h % 360; $h2 = ($h1 + 40) % 360;
    return 'background:linear-gradient(135deg,hsl(' . $h1 . ',45%,42%),hsl(' . $h2 . ',45%,30%));';
}

$db = db();
$cols = $db->query(
    'SELECT c.id, c.name, COUNT(ci.id) AS cnt
     FROM collections c LEFT JOIN collection_items ci ON ci.collection_id = c.id
     GROUP BY c.id ORDER BY c.name'
)->fetchAll(PDO::FETCH_ASSOC);

$activeId = (int) ($_GET['id'] ?? 0);
if ($activeId === 0 && $cols !== []) $activeId = (int) $cols[0]['id'];

// Resolve the active collection's models to real folders on disk.
$items = [];
if ($activeId > 0) {
    $st = $db->prepare('SELECT source, folder FROM collection_items WHERE collection_id = :c ORDER BY folder');
    $st->execute([':c' => $activeId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $src = $row['source']; $folder = $row['folder'];
        $base = source_path($src);
        if ($base === null) continue;
        $dir = $base . '/' . $folder;
        if (!is_dir($dir)) continue; // model was deleted from disk

        $files = 0; $size = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile() && strpos($f->getPathname(), '.farfetched') === false) { $files++; $size += $f->getSize(); }
        }
        $types = array_map('strtoupper', model_file_types($dir));
        $thumb = is_file($dir . '/.farfetched/thumb.png')
            ? 'model_file.php?thumb=1&src=' . rawurlencode($src) . '&model=' . rawurlencode($folder)
            : '';
        $items[] = [
            'src' => $src, 'folder' => $folder, 'title' => clean_model_name($folder),
            'thumb' => $thumb, 'files' => $files, 'size' => human_size($size),
            'types' => implode(', ', array_unique($types)), 'mtime' => @filemtime($dir) ?: 0,
        ];
    }
}

$activeName = '';
foreach ($cols as $c) { if ((int) $c['id'] === $activeId) { $activeName = $c['name']; break; } }
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Collections · FarFetched</title>
<link rel="stylesheet" href="css/styles.css">
<link rel="stylesheet" href="css/styles_library.css">
<script type="application/json" id="cv-csrf"><?= json_encode($csrf) ?></script>
</head>
<body>
  <aside>
    <div class="brand"><img src="logo.svg" alt="FarFetched" style="height:1.15em;width:auto;vertical-align:-.2em;margin-right:7px"> FarFetched</div>
    <div class="navlabel">Tool</div>
    <nav>
      <a href="index.php">Browse Models</a>
      <a href="jobs.php">Queue</a>
      <a href="viewer.php">3D Viewer</a>
      <a href="library.php">My Library</a>
      <a href="customize.php">Customize</a>
      <a href="insights.php">Insights</a>
      <a href="printers.php">My Printers</a>
      <a href="collections_view.php" class="active">Collections</a>
      <a href="favorites.php">Favorites</a>
      <a href="settings.php">Settings</a>
      <button id="theme-toggle" aria-label="Toggle theme" class="btn-ghost">
        <span id="theme-toggle-icon">🌙</span> Change Appearance
      </button>
    </nav>
  </aside>

  <main>
    <div class="cv-masthead">
      <div class="cv-masthead-text">
        <div class="cv-eyebrow">Curated</div>
        <h1 class="cv-h1">Collections</h1>
        <div class="cv-sub">Models you've grouped together.</div>
      </div>
      <div class="cv-toolbar">
        <input id="cvNewName" class="cv-input" placeholder="New collection name…">
        <button class="cv-btn cv-btn-primary" id="cvCreate" type="button">+ Create</button>
      </div>
    </div>

    <?php if ($cols === []): ?>
      <div class="cv-empty-luxe">
        <div class="cv-empty-mark">◇</div>
        <p class="cv-empty-title">No collections yet</p>
        <p class="cv-empty-sub">Create one above, or open a model in <a href="library.php">My Library</a> and click <strong>Collection</strong>.</p>
      </div>
    <?php else: ?>
      <div class="cv-pills-row">
        <div class="cv-pills">
          <?php foreach ($cols as $c): $isActive = (int) $c['id'] === $activeId; ?>
            <span class="cv-pill <?= $isActive ? 'active' : '' ?>">
              <a class="cv-pill-link" href="collections_view.php?id=<?= (int) $c['id'] ?>">
                <?= e($c['name']) ?> <span class="cv-pill-cnt"><?= (int) $c['cnt'] ?></span>
              </a>
              <?php if ($isActive): ?>
                <span class="cv-pill-actions">
                  <button class="cv-pill-ico" id="cvRename" data-id="<?= $activeId ?>" data-name="<?= e($activeName) ?>" title="Rename collection">✏️</button>
                  <button class="cv-pill-ico" id="cvDelete" data-id="<?= $activeId ?>" title="Delete collection">🗑</button>
                </span>
              <?php endif; ?>
            </span>
          <?php endforeach; ?>
        </div>
      </div>

      <?php if ($items === []): ?>
        <div class="cv-empty-luxe">
          <div class="cv-empty-mark">◇</div>
          <p class="cv-empty-title">This collection is empty</p>
          <p class="cv-empty-sub">Add models from My Library to see them here.</p>
        </div>
      <?php else: ?>
        <div class="cv-grid" id="cvGrid">
          <?php foreach ($items as $m): ?>
            <div class="cv-card" role="button" tabindex="0"
                 data-src="<?= e($m['src']) ?>" data-folder="<?= e($m['folder']) ?>"
                 data-title="<?= e($m['title']) ?>" data-thumb="<?= e($m['thumb']) ?>"
                 data-files="<?= (int) $m['files'] ?>" data-size="<?= e($m['size']) ?>"
                 data-types="<?= e($m['types']) ?>"
                 data-date="<?= e($m['mtime'] ? date('M j, Y', $m['mtime']) : '—') ?>"
                 data-badge="<?= e(strtoupper(substr($m['src'], 0, 2))) ?>">
              <div class="cv-card-thumb" style="<?= $m['thumb'] ? '' : e(cv_tile_style($m["title"])) ?>">
                <?php if ($m['thumb']): ?>
                  <img src="<?= e($m['thumb']) ?>" alt="" loading="lazy">
                <?php endif; ?>
                <span class="cv-card-badge"><?= e(strtoupper(substr($m['src'], 0, 2))) ?></span>
              </div>
              <div class="cv-card-meta"><div class="cv-card-name"><?= e($m['title']) ?></div></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </main>

  <!-- Compact detail modal -->
  <div id="cvModal" class="lib-modal-backdrop" hidden>
    <div class="lib-modal">
      <div class="lib-modal-hero" id="cvHero">
        <span class="lib-modal-heroicon">📦</span>
        <button class="lib-modal-close" id="cvModalClose" aria-label="Close">&times;</button>
      </div>
      <div class="lib-modal-body">
        <div class="lib-modal-titlerow">
          <div class="lib-modal-title" id="cvMTitle">—</div>
          <span class="lib-modal-badge" id="cvMBadge">—</span>
        </div>
        <div class="lib-printinfo" id="cvMPrintInfo" hidden></div>
        <div class="lib-modal-grid">
          <div class="lib-stat"><div class="lib-stat-k">Downloaded</div><div class="lib-stat-v" id="cvMDate">—</div></div>
          <div class="lib-stat"><div class="lib-stat-k">Size</div><div class="lib-stat-v" id="cvMSize">—</div></div>
          <div class="lib-stat"><div class="lib-stat-k">Files</div><div class="lib-stat-v" id="cvMFiles">—</div></div>
          <div class="lib-stat"><div class="lib-stat-k">Types</div><div class="lib-stat-v" id="cvMTypes">—</div></div>
        </div>
        <div class="lib-stat lib-stat-wide">
          <div class="lib-stat-k">Folder</div>
          <div class="lib-stat-v lib-mono" id="cvMFolder">—</div>
        </div>
        <div class="lib-modal-actions">
          <a class="lib-btn lib-btn-primary" id="cvMView" href="#">📐 View in 3D</a>
          <a class="lib-btn lib-btn-ghost" id="cvMExport" href="#">⬇ Export</a>
          <button class="lib-btn lib-btn-danger" id="cvMRemove" type="button">✕ Remove from collection</button>
        </div>
      </div>
    </div>
  </div>


<script>
  const CSRF = JSON.parse(document.getElementById('cv-csrf').textContent || '""');
  const ACTIVE_ID = <?= (int) $activeId ?>;

  async function coPost(payload) {
    const r = await fetch('collections.php', { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(Object.assign({ csrf: CSRF }, payload)) });
    return r.json().catch(() => ({ ok: false }));
  }

  // Create
  document.getElementById('cvCreate')?.addEventListener('click', async () => {
    const name = document.getElementById('cvNewName').value.trim();
    if (!name) return;
    const d = await coPost({ action: 'create', name });
    if (d.ok) location.href = 'collections_view.php?id=' + d.id;
  });
  document.getElementById('cvNewName')?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') document.getElementById('cvCreate').click();
  });

  // Rename
  document.getElementById('cvRename')?.addEventListener('click', async (e) => {
    const cur = e.target.dataset.name;
    const name = prompt('Rename collection:', cur);
    if (name === null || !name.trim()) return;
    const d = await coPost({ action: 'rename', collection_id: +e.target.dataset.id, name: name.trim() });
    if (d.ok) location.reload();
  });

  // Delete
  document.getElementById('cvDelete')?.addEventListener('click', async (e) => {
    if (!confirm('Delete this collection? (Your models stay on disk.)')) return;
    const d = await coPost({ action: 'delete', collection_id: +e.target.dataset.id });
    if (d.ok) location.href = 'collections_view.php';
  });

  // ===== Card → modal =====
  const modal = document.getElementById('cvModal');
  let modalModel = { src: '', folder: '' };
  document.getElementById('cvGrid')?.addEventListener('click', (e) => {
    const card = e.target.closest('.cv-card');
    if (card) openModal(card);
  });

  function openModal(card) {
    const d = card.dataset;
    modalModel = { src: d.src, folder: d.folder };
    document.getElementById('cvMTitle').textContent = d.title;
    document.getElementById('cvMBadge').textContent = d.badge;
    document.getElementById('cvMDate').textContent  = d.date;
    document.getElementById('cvMSize').textContent  = d.size;
    document.getElementById('cvMFiles').textContent = d.files;
    document.getElementById('cvMTypes').textContent = d.types || '—';
    document.getElementById('cvMFolder').textContent = d.src + '/' + d.folder;

    const hero = document.getElementById('cvHero');
    const icon = hero.querySelector('.lib-modal-heroicon');
    if (d.thumb) { hero.style.cssText = 'background-image:url(' + d.thumb + ');background-size:cover;background-position:center'; if (icon) icon.style.display = 'none'; }
    else { hero.style.cssText = card.querySelector('.cv-card-thumb').getAttribute('style') || ''; if (icon) icon.style.display = ''; }

    document.getElementById('cvMView').href = 'viewer.php?src=' + encodeURIComponent(d.src) + '&model=' + encodeURIComponent(d.folder);
    document.getElementById('cvMExport').href = 'export_bundle.php?src=' + encodeURIComponent(d.src) + '&model=' + encodeURIComponent(d.folder);

    // Print stats badge.
    const pInfo = document.getElementById('cvMPrintInfo');
    pInfo.hidden = true; pInfo.innerHTML = '';
    fetch('model_meta.php?src=' + encodeURIComponent(d.src) + '&model=' + encodeURIComponent(d.folder))
      .then(r => r.json()).then(meta => {
        if (!meta.ok) return;
        const chips = [];
        if (meta.printSeconds > 0) { const h = Math.floor(meta.printSeconds/3600), m = Math.round((meta.printSeconds%3600)/60);
          chips.push('<span class="lib-chip"><span class="lib-chip-ico">⏱</span>' + (h>0?h+'h '+m+'m':m+'m') + '</span>'); }
        if (meta.filamentGrams > 0) chips.push('<span class="lib-chip"><span class="lib-chip-ico">🧵</span>' + meta.filamentGrams + ' g</span>');
        if (meta.filamentMeters > 0) chips.push('<span class="lib-chip"><span class="lib-chip-ico">📏</span>' + meta.filamentMeters + ' m</span>');
        if (meta.colors > 1) chips.push('<span class="lib-chip"><span class="lib-chip-ico">🎨</span>' + meta.colors + ' colors</span>');
        if (meta.plates > 0) chips.push('<span class="lib-chip"><span class="lib-chip-ico">🍽</span>' + meta.plates + (meta.plates===1?' plate':' plates') + '</span>');
        if (meta.printer) chips.push('<span class="lib-chip"><span class="lib-chip-ico">🖨</span>' + meta.printer + '</span>');
        if (chips.length) { pInfo.innerHTML = chips.join(''); pInfo.hidden = false; }
      }).catch(() => {});

    modal.hidden = false;
  }

  document.getElementById('cvModalClose')?.addEventListener('click', () => modal.hidden = true);
  modal?.addEventListener('click', (e) => { if (e.target === modal) modal.hidden = true; });

  // Remove from this collection
  document.getElementById('cvMRemove')?.addEventListener('click', async () => {
    const d = await coPost({ action: 'remove', collection_id: ACTIVE_ID, src: modalModel.src, folder: modalModel.folder });
    if (d.ok) location.reload();
  });

  // Theme
  const root = document.documentElement;
  if (localStorage.getItem('theme') === 'light') root.setAttribute('data-theme', 'light');
  const tb = document.getElementById('theme-toggle'), ti = document.getElementById('theme-toggle-icon');
  function sync(){ if (ti) ti.textContent = root.getAttribute('data-theme') === 'light' ? '☀️' : '🌙'; }
  sync();
  if (tb) tb.addEventListener('click', () => {
    if (root.getAttribute('data-theme') === 'light') { root.removeAttribute('data-theme'); localStorage.setItem('theme','dark'); }
    else { root.setAttribute('data-theme','light'); localStorage.setItem('theme','light'); }
    sync();
  });
</script>
  <script src="js/theme.js"></script>
</body>
</html>
