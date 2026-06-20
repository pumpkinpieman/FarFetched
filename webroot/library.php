<?php
declare(strict_types=1);

/**
 * library.php — unified "My Library" of downloaded models.
 *
 * Dual mode:
 *   library.php            -> all sources, with filter pills
 *   library.php?src=<slug> -> just that source (legacy entry from home.php)
 *
 * Each model folder becomes a tile. Folders with no printable 3D files are
 * skipped (nothing to view). Clicking a tile opens a detail modal with local
 * info + a "View in 3D" deep link into viewer.php. LOCAL FILESYSTEM ONLY.
 */

require_once __DIR__ . '/bootstrap.php';

// Printable extensions that make a folder worth showing / viewable.
const LIB_PRINT_EXT = ['stl', '3mf', 'obj', 'step', 'stp'];
const LIB_VIEW_EXT  = ['stl', '3mf']; // what viewer.php can actually render

/** Does this folder contain at least one NON-EMPTY printable 3D file? */
function lib_has_printable(string $dir): bool
{
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f->isFile()
                && $f->getSize() > 0
                && in_array(strtolower($f->getExtension()), LIB_PRINT_EXT, true)) {
                return true;
            }
        }
    } catch (\Throwable $e) {
        return false;
    }
    return false;
}

/** True if the folder has a file viewer.php can render (.stl/.3mf). */
function lib_is_viewable(string $dir): bool
{
    return lib_first_viewable($dir) !== null;
}

// Files above this are skipped by AUTO/batch generation (huge meshes crash the
// tab / blow the timeout). They can still be rendered manually in the modal.
const LIB_MAX_AUTO_BYTES = 18 * 1024 * 1024; // 18 MB

/**
 * Smallest renderable file (rel path) in a model folder, smallest-first so the
 * lightest mesh is used for the thumbnail. Returns the absolute-smallest even
 * if it's above the auto ceiling (so the modal can still render it); the batch
 * decides separately whether it's small enough to auto-generate.
 * @return array{rel:string,size:int}|null
 */
function lib_first_viewable_info(string $dir): ?array
{
    try {
        $cands = [];
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        ) as $f) {
            if (!$f->isFile()) continue;
            $sz = $f->getSize();
            if ($sz <= 0) continue;
            if (!in_array(strtolower($f->getExtension()), LIB_VIEW_EXT, true)) continue;
            $rel = str_replace('\\', '/', ltrim(substr($f->getPathname(), strlen($dir)), '/\\'));
            $cands[] = ['rel' => $rel, 'size' => $sz];
        }
        if ($cands === []) return null;
        usort($cands, static fn($a, $b) => $a['size'] <=> $b['size']);
        return $cands[0];
    } catch (\Throwable $e) {
        return null;
    }
}

/** Relative path of the smallest renderable file, or null. */
function lib_first_viewable(string $dir): ?string
{
    $info = lib_first_viewable_info($dir);
    return $info['rel'] ?? null;
}

/** Newest mtime among the folder's files — used for "downloaded" + sorting. */
function lib_folder_mtime(string $dir): int
{
    $newest = @filemtime($dir) ?: 0;
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f->isFile()) {
                $m = $f->getMTime();
                if ($m > $newest) $newest = $m;
            }
        }
    } catch (\Throwable $e) {
        // keep folder mtime
    }
    return $newest;
}

/** Cached thumbnail path for a model, if one has been generated. */
function lib_thumb_rel(string $slug, string $folder): ?string
{
    $base = MODELS_ROOT . '/' . $slug . '/' . $folder . '/.farfetched/thumb.png';
    return is_file($base) ? 'model_file.php?src=' . rawurlencode($slug)
        . '&model=' . rawurlencode($folder) . '&thumb=1' : null;
}

// ---- Gather models (one or all sources) ------------------------------------
$onlySrc = (string) ($_GET['src'] ?? '');
if ($onlySrc !== '' && source_path($onlySrc) === null) {
    http_response_code(404);
    echo 'Unknown source. <a href="library.php">Back to library</a>';
    exit;
}

$sources = $onlySrc !== ''
    ? [['slug' => $onlySrc, 'path' => (string) source_path($onlySrc)]]
    : array_map(static fn($s) => ['slug' => $s['slug'], 'path' => $s['path']], list_sources());

$models = [];          // tiles
$srcCounts = [];       // slug -> count (for filter pills)
$totalFiles = 0;
$totalBytes = 0;

foreach ($sources as $s) {
    $slug = $s['slug'];
    foreach (list_models($s['path']) as $m) {
        if ($m['kind'] !== 'folder') continue;            // skip loose zips
        $abs = $s['path'] . '/' . $m['name'];
        if (!lib_has_printable($abs)) continue;           // skip empty / non-printable

        $firstInfo = lib_first_viewable_info($abs);
        $firstView = $firstInfo['rel'] ?? null;
        $firstSize = $firstInfo['size'] ?? 0;
        $thumbRel  = lib_thumb_rel($slug, $m['name']);
        $models[] = [
            'slug'      => $slug,
            'folder'    => $m['name'],
            'title'     => clean_model_name($m['name']),
            'files'     => (int) $m['files'],
            'size'      => (int) $m['size'],
            'types'     => model_file_types($abs),
            'mtime'     => lib_folder_mtime($abs),
            'viewable'  => $firstView !== null,
            'firstfile' => $firstView,
            'firstsize' => $firstSize,
            'thumb'     => $thumbRel,
        ];
        $srcCounts[$slug] = ($srcCounts[$slug] ?? 0) + 1;
        $totalFiles += (int) $m['files'];
        $totalBytes += (int) $m['size'];
    }
}

// Newest first by default.
usort($models, static fn($a, $b) => $b['mtime'] <=> $a['mtime']);

$csrf  = csrf_token();
$title = $onlySrc !== '' ? ucfirst($onlySrc) . ' Library' : 'My Library';
// Favorites are a nice-to-have here; never let a DB hiccup blank the library.
try {
    $favSet = favorites_key_set();
} catch (\Throwable $e) {
    $favSet = [];
}

/** Extract the leading model id from a folder name like "1753580 - Hase Rabbit". */
function lib_model_id(string $folder): string
{
    if (preg_match('/^([A-Za-z0-9]+)\s*-\s*/', $folder, $mm)) {
        return $mm[1];
    }
    // Hex-only or id-only folder name (e.g. "6a1d5641eb21769e68f89c85").
    if (preg_match('/^[A-Za-z0-9]+$/', $folder)) {
        return $folder;
    }
    return $folder;
}

/** Stable placeholder gradient from the model name (shown when no thumb). */
function lib_tile_style(string $name): string
{
    $h = crc32($name);
    $hue = $h % 360;
    $hue2 = ($hue + 26) % 360;
    return "background:linear-gradient(150deg,hsl({$hue},38%,42%),hsl({$hue2},40%,28%));";
}

/** Short uppercase badge per source. */
function lib_badge(string $slug): string
{
    static $map = [
        'printables' => 'PT', 'makerworld' => 'MW', 'thingiverse' => 'TV',
        'cults3d' => 'C3D', 'stlflix' => 'SF', 'creality' => 'CR',
    ];
    return $map[strtolower($slug)] ?? strtoupper(substr($slug, 0, 2));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · FarFetched</title>
<link rel="stylesheet" href="css/styles.css">
<link rel="stylesheet" href="css/styles_library.css">
<script type="application/json" id="lib-csrf"><?= json_encode($csrf) ?></script>
<script type="importmap">
{
  "imports": {
    "three": "https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.module.js",
    "three/addons/": "https://cdn.jsdelivr.net/npm/three@0.160.0/examples/jsm/"
  }
}
</script>
</head>
<body>
  <aside>
    <div class="brand"><img src="logo.svg" alt="FarFetched" style="height:1.15em;width:auto;vertical-align:-.2em;margin-right:7px"> FarFetched</div>
    <div class="navlabel">Tool</div>
    <nav>
      <a href="index.php">Browse Models</a>
      <a href="jobs.php">Queue</a>
      <a href="viewer.php">3D Viewer</a>
      <a href="library.php" class="active">My Library</a>
      <a href="favorites.php">Favorites</a>
      <a href="settings.php">Settings</a>
      <button id="theme-toggle" aria-label="Toggle theme" class="btn-ghost">
        <span id="theme-toggle-icon">🌙</span> Change Appearance
      </button>
    </nav>
  </aside>

  <main>
    <h1><?= e($title) ?></h1>
    <div class="sub">
      <?= count($models) ?> model<?= count($models) === 1 ? '' : 's' ?> ·
      <?= number_format($totalFiles) ?> files ·
      <?= e(human_size($totalBytes)) ?> on disk
    </div>

    <?php if ($models !== []): ?>
    <div class="lib-toolbar">
      <button id="genAllBtn" class="lib-btn lib-btn-accent lib-btn-sm" type="button">
        📸 Generate all missing thumbnails
      </button>
    </div>
    <?php endif; ?>

    <?php if ($onlySrc === '' && count($srcCounts) > 1): ?>
    <div class="lib-filters" id="filters">
      <button class="pill active" data-src="">All</button>
      <?php foreach ($srcCounts as $slug => $n): ?>
        <button class="pill" data-src="<?= e($slug) ?>"><?= e(ucfirst($slug)) ?> <span class="pill-n"><?= $n ?></span></button>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($models === []): ?>
      <div class="lib-empty">No downloaded models yet. Head to <a href="index.php">Browse Models</a> to fetch some.</div>
    <?php else: ?>
      <div class="lib-grid" id="grid">
        <?php foreach ($models as $m): ?>
          <div class="lib-card" role="button" tabindex="0"
                  data-src="<?= e($m['slug']) ?>"
                  data-folder="<?= e($m['folder']) ?>"
                  data-title="<?= e($m['title']) ?>"
                  data-files="<?= (int) $m['files'] ?>"
                  data-size="<?= e(human_size($m['size'])) ?>"
                  data-types="<?= e(implode(', ', $m['types'])) ?>"
                  data-date="<?= e($m['mtime'] ? date('M j, Y', $m['mtime']) : '—') ?>"
                  data-viewable="<?= $m['viewable'] ? '1' : '0' ?>"
                  data-firstfile="<?= e((string) $m['firstfile']) ?>"
                  data-firstsize="<?= (int) $m['firstsize'] ?>"
                  data-autoable="<?= ($m['firstsize'] > 0 && $m['firstsize'] <= LIB_MAX_AUTO_BYTES) ? '1' : '0' ?>"
                  data-hasthumb="<?= $m['thumb'] ? '1' : '0' ?>"
                  data-badge="<?= e(lib_badge($m['slug'])) ?>">
            <div class="lib-thumb" style="<?= $m['thumb'] ? '' : e(lib_tile_style($m['title'])) ?>">
              <?php
                $mid   = lib_model_id($m['folder']);
                $isFav = isset($favSet[$m['slug'] . ':' . $mid]);
              ?>
              <button type="button" class="fav-star lib-star <?= $isFav ? 'on' : '' ?>"
                      data-fav-source="<?= e($m['slug']) ?>" data-fav-id="<?= e($mid) ?>"
                      data-fav-name="<?= e($m['title']) ?>"
                      aria-label="Favorite" title="Save to Favorites"><?= $isFav ? '★' : '☆' ?></button>
              <?php if ($m['thumb']): ?>
                <img src="<?= e($m['thumb']) ?>" alt="" loading="lazy">
              <?php else: ?>
                <span class="lib-thumb-ph"><?= e($m['title']) ?></span>
              <?php endif; ?>
            </div>
            <div class="lib-meta">
              <div class="lib-name"><?= e($m['title']) ?></div>
              <div class="lib-row">
                <span class="lib-badge"><?= e(lib_badge($m['slug'])) ?></span>
                <span class="lib-files"><?= (int) $m['files'] ?> file<?= (int) $m['files'] === 1 ? '' : 's' ?></span>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </main>

  <!-- Detail modal -->
  <div id="libModal" class="lib-modal-backdrop" hidden>
    <div class="lib-modal">
      <div class="lib-modal-hero" id="mHero">
        <!-- gradient placeholder shown until preview is loaded -->
        <span class="lib-modal-heroicon" id="mHeroIcon">📦</span>
        <button class="lib-preview-load" id="mLoadPreview" type="button">▶ Load preview</button>
        <div class="lib-preview-canvas" id="mCanvas" hidden></div>
        <div class="lib-preview-hint" id="mPreviewHint" hidden>Drag to orbit · scroll to zoom, then capture</div>
        <button class="lib-modal-close" id="mClose" aria-label="Close">&times;</button>
      </div>
      <div class="lib-modal-body">
        <div class="lib-modal-titlerow">
          <div class="lib-modal-title" id="mTitle">—</div>
          <span class="lib-modal-badge" id="mBadge">—</span>
        </div>
        <div class="lib-modal-grid">
          <div class="lib-stat"><div class="lib-stat-k">Downloaded</div><div class="lib-stat-v" id="mDate">—</div></div>
          <div class="lib-stat"><div class="lib-stat-k">Size</div><div class="lib-stat-v" id="mSize">—</div></div>
          <div class="lib-stat"><div class="lib-stat-k">Files</div><div class="lib-stat-v" id="mFiles">—</div></div>
          <div class="lib-stat"><div class="lib-stat-k">Types</div><div class="lib-stat-v" id="mTypes">—</div></div>
        </div>
        <div class="lib-stat lib-stat-wide">
          <div class="lib-stat-k">Folder</div>
          <div class="lib-stat-v lib-mono" id="mFolder">—</div>
        </div>
        <div class="lib-modal-actions">
          <a class="lib-btn lib-btn-primary" id="mView" href="#">📐 View in 3D</a>
          <button class="lib-btn lib-btn-accent" id="mGenThumb" type="button" hidden>📸 Generate thumbnail</button>
          <button class="lib-btn lib-btn-ghost" id="mReveal" type="button" title="Copy folder path">⧉ Copy path</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Batch (C) progress toast -->
  <div id="batchToast" class="lib-batch-toast" hidden>
    <div class="lib-batch-row">
      <span id="batchLabel">Generating thumbnails…</span>
      <button id="batchCancel" class="lib-batch-cancel" type="button">Cancel</button>
    </div>
    <div class="lib-batch-bar"><div class="lib-batch-fill" id="batchFill"></div></div>
  </div>

  <!-- Offscreen canvas host for batch rendering (C) -->
  <div id="batchCanvasHost" style="position:absolute;width:1px;height:1px;overflow:hidden;left:-9999px;top:-9999px;"></div>

<script>
  // ---- Filter pills --------------------------------------------------------
  const grid = document.getElementById('grid');
  const filters = document.getElementById('filters');
  if (filters) {
    filters.addEventListener('click', (e) => {
      const btn = e.target.closest('.pill');
      if (!btn) return;
      filters.querySelectorAll('.pill').forEach(p => p.classList.remove('active'));
      btn.classList.add('active');
      const want = btn.dataset.src;
      grid.querySelectorAll('.lib-card').forEach(card => {
        card.style.display = (!want || card.dataset.src === want) ? '' : 'none';
      });
    });
  }

  // ---- Detail modal --------------------------------------------------------
  const modal   = document.getElementById('libModal');
  const mTitle  = document.getElementById('mTitle');
  const mBadge  = document.getElementById('mBadge');
  const mDate   = document.getElementById('mDate');
  const mSize   = document.getElementById('mSize');
  const mFiles  = document.getElementById('mFiles');
  const mTypes  = document.getElementById('mTypes');
  const mFolder = document.getElementById('mFolder');
  const mView   = document.getElementById('mView');
  const mReveal = document.getElementById('mReveal');
  const mHero   = document.getElementById('mHero');

  let currentFolderPath = '';

  function openModal(card) {
    const d = card.dataset;
    mTitle.textContent  = d.title;
    mBadge.textContent  = d.badge;
    mDate.textContent   = d.date;
    mSize.textContent   = d.size;
    mFiles.textContent  = d.files;
    mTypes.textContent  = d.types || '—';
    mFolder.textContent = d.src + '/' + d.folder;
    currentFolderPath   = d.src + '/' + d.folder;

    // Stable hero gradient matching the tile.
    const thumb = card.querySelector('.lib-thumb');
    mHero.style.cssText = thumb.getAttribute('style') || '';

    // View in 3D — deep link, only if renderable.
    if (d.viewable === '1') {
      mView.style.display = '';
      mView.href = 'viewer.php?src=' + encodeURIComponent(d.src) +
                   '&model=' + encodeURIComponent(d.folder);
    } else {
      mView.style.display = 'none';
    }
    modal.hidden = false;
    // Notify the thumbnail-engine module so it can offer preview/generate.
    window.__libModalOpened && window.__libModalOpened(d);
  }
  function closeModal() {
    modal.hidden = true;
    window.__libModalClosed && window.__libModalClosed();
  }

  grid && grid.addEventListener('click', async (e) => {
    // Star click toggles favorite and must NOT open the modal.
    const star = e.target.closest('.fav-star');
    if (star) {
      e.stopPropagation();
      star.disabled = true;
      try {
        const res = await fetch('favorite.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action:   'toggle',
            source:   star.dataset.favSource,
            model_id: star.dataset.favId,
            name:     star.dataset.favName || '',
          }),
        });
        const j = await res.json();
        if (j.ok) {
          star.classList.toggle('on', j.favorited);
          star.textContent = j.favorited ? '★' : '☆';
        }
      } catch (_) { /* leave as-is on error */ }
      star.disabled = false;
      return;
    }
    const card = e.target.closest('.lib-card');
    if (card) openModal(card);
  });
  document.getElementById('mClose').addEventListener('click', closeModal);
  modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModal(); });

  mReveal.addEventListener('click', () => {
    navigator.clipboard?.writeText(currentFolderPath).then(() => {
      mReveal.textContent = '✓ Copied';
      setTimeout(() => { mReveal.textContent = '⧉ Copy path'; }, 1500);
    });
  });

  // ---- Theme toggle (matches other pages) ----------------------------------
  const toggleBtn = document.getElementById('theme-toggle');
  const toggleIcon = document.getElementById('theme-toggle-icon');
  const currentTheme = localStorage.getItem('theme') || 'dark';
  if (currentTheme === 'light') {
    document.documentElement.setAttribute('data-theme', 'light');
    if (toggleIcon) toggleIcon.textContent = '☀️';
  }
  if (toggleBtn) toggleBtn.addEventListener('click', () => {
    let theme = 'dark';
    if (document.documentElement.getAttribute('data-theme') !== 'light') {
      document.documentElement.setAttribute('data-theme', 'light');
      toggleIcon.textContent = '☀️';
      theme = 'light';
    } else {
      document.documentElement.removeAttribute('data-theme');
      toggleIcon.textContent = '🌙';
    }
    localStorage.setItem('theme', theme);
  });
</script>

<script type="module">
  import { createViewer } from './js/viewer-core.js';

  const CSRF = JSON.parse(document.getElementById('lib-csrf').textContent || '""');
  const fileUrl = (src, model, rel) =>
    'model_file.php?src=' + encodeURIComponent(src) +
    '&model=' + encodeURIComponent(model) +
    '&file=' + encodeURIComponent(rel);

  async function saveThumb(src, model, pngDataUrl) {
    const res = await fetch('save_thumb.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ csrf: CSRF, src, model, png: pngDataUrl }),
    });
    const j = await res.json().catch(() => ({}));
    if (!res.ok || !j.ok) throw new Error(j.error || 'Save failed');
    return j.url;
  }

  // Race a load against a timeout so a corrupt/unreadable model can never hang
  // the modal or the batch forever.
  function loadWithTimeout(viewer, url, ext, ms = 20000) {
    return Promise.race([
      viewer.loadFile(url, ext),
      new Promise((_, rej) => setTimeout(() => rej(new Error('Timed out')), ms)),
    ]);
  }

  async function deleteModel(src, model) {
    const res = await fetch('model_delete.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ csrf: CSRF, src, models: [model] }),
    });
    const j = await res.json().catch(() => ({}));
    return res.ok && j.ok;
  }

  function removeCard(src, folder) {
    const card = document.querySelector(
      `.lib-card[data-src="${CSS.escape(src)}"][data-folder="${CSS.escape(folder)}"]`);
    if (card) card.remove();
  }

  // Swap a card's gradient placeholder for the freshly-saved thumbnail.
  function applyThumbToCard(src, folder, url) {
    const card = document.querySelector(
      `.lib-card[data-src="${CSS.escape(src)}"][data-folder="${CSS.escape(folder)}"]`);
    if (!card) return;
    const thumb = card.querySelector('.lib-thumb');
    thumb.style.cssText = '';
    thumb.innerHTML = '<img alt="" loading="lazy">';
    thumb.querySelector('img').src = url;
    card.dataset.hasthumb = '1';
  }

  // ===== B: interactive preview + capture in the modal ======================
  const mHero        = document.getElementById('mHero');
  const mHeroIcon    = document.getElementById('mHeroIcon');
  const mLoadPreview = document.getElementById('mLoadPreview');
  const mCanvas      = document.getElementById('mCanvas');
  const mPreviewHint = document.getElementById('mPreviewHint');
  const mGenThumb    = document.getElementById('mGenThumb');

  let modalViewer = null;
  let modalCtx = null; // {src, folder, firstfile}

  function teardownModalViewer() {
    if (modalViewer) { modalViewer.dispose(); modalViewer = null; }
    mCanvas.hidden = true;
    mCanvas.innerHTML = '';
    mPreviewHint.hidden = true;
    mHeroIcon.style.display = '';
    mLoadPreview.style.display = '';
    mLoadPreview.textContent = '▶ Load preview';
    mLoadPreview.disabled = false;
    mGenThumb.hidden = true;
  }

  // Called by the existing classic-script openModal via this hook.
  window.__libModalOpened = function (d) {
    teardownModalViewer();
    modalCtx = { src: d.src, folder: d.folder, firstfile: d.firstfile };
    // Only offer preview/generate when there's a renderable file.
    mLoadPreview.style.display = (d.viewable === '1' && d.firstfile) ? '' : 'none';
  };
  window.__libModalClosed = teardownModalViewer;

  mLoadPreview.addEventListener('click', async () => {
    if (!modalCtx || !modalCtx.firstfile) return;
    mLoadPreview.disabled = true;
    mLoadPreview.textContent = 'Loading…';
    mHeroIcon.style.display = 'none';
    mCanvas.hidden = false;

    modalViewer = createViewer(mCanvas, { background: 0x16140f });
    const ext = modalCtx.firstfile.split('.').pop().toLowerCase();
    try {
      await loadWithTimeout(modalViewer, fileUrl(modalCtx.src, modalCtx.folder, modalCtx.firstfile), ext);
      mLoadPreview.style.display = 'none';
      mPreviewHint.hidden = false;
      mGenThumb.hidden = false;
    } catch (e) {
      teardownModalViewer();
      mHeroIcon.textContent = '⚠';
      mHeroIcon.style.display = '';
      // Offer to remove a model that won't render (empty / corrupt).
      if (confirm('This model could not be rendered — it may be empty or corrupt.\n\nDelete this folder?')) {
        const ok = await deleteModel(modalCtx.src, modalCtx.folder);
        if (ok) { removeCard(modalCtx.src, modalCtx.folder); closeModal(); }
        else alert('Could not delete the folder.');
      }
    }
  });

  mGenThumb.addEventListener('click', async () => {
    if (!modalViewer || !modalViewer.hasModel()) return;
    mGenThumb.disabled = true;
    const orig = mGenThumb.textContent;
    mGenThumb.textContent = 'Saving…';
    try {
      const png = modalViewer.capturePNG(512);
      const url = await saveThumb(modalCtx.src, modalCtx.folder, png);
      applyThumbToCard(modalCtx.src, modalCtx.folder, url);
      mGenThumb.textContent = '✓ Saved';
      setTimeout(() => { mGenThumb.textContent = orig; mGenThumb.disabled = false; }, 1400);
    } catch (e) {
      mGenThumb.textContent = '✗ ' + (e.message || 'Failed');
      setTimeout(() => { mGenThumb.textContent = orig; mGenThumb.disabled = false; }, 2200);
    }
  });

  // ===== C: batch-generate all missing thumbnails ===========================
  const genAllBtn   = document.getElementById('genAllBtn');
  const batchToast  = document.getElementById('batchToast');
  const batchLabel  = document.getElementById('batchLabel');
  const batchFill   = document.getElementById('batchFill');
  const batchCancel = document.getElementById('batchCancel');
  const batchHost   = document.getElementById('batchCanvasHost');

  let batchCancelled = false;
  batchCancel?.addEventListener('click', () => { batchCancelled = true; });

  genAllBtn?.addEventListener('click', async () => {
    // Respect the active source filter: only generate for visible cards.
    // (Filtered-out cards have display:none.)
    const activeSrc = filters
      ? (filters.querySelector('.pill.active')?.dataset.src || '')
      : '';
    const allTargets = [...document.querySelectorAll('.lib-card')].filter(c => {
      if (c.dataset.viewable !== '1' || c.dataset.hasthumb === '1' || !c.dataset.firstfile) return false;
      if (c.dataset.autoable !== '1') return false;   // skip huge meshes (manual only)
      if (activeSrc && c.dataset.src !== activeSrc) return false;  // scope to filter
      return true;
    });

    // Cap each click at 30 models. Bulk-rendering thousands in one go exhausts
    // browser memory and crashes the tab no matter how carefully we dispose —
    // so we slice into small chunks with hard memory recovery between them.
    const targets = allTargets;

    if (targets.length === 0) {
      const msg = activeSrc ? '✓ All ' + activeSrc + ' thumbnails present' : '✓ All thumbnails present';
      genAllBtn.textContent = msg;
      setTimeout(() => { genAllBtn.textContent = '📸 Generate all missing thumbnails'; }, 1800);
      return;
    }

    batchCancelled = false;
    genAllBtn.disabled = true;
    batchToast.hidden = false;

    let done = 0, failed = 0;
    const failures = []; // {src, folder} that couldn't render
    const total = targets.length;

    // Tear the WebGL context fully down between every CHUNK_SIZE models so GPU/JS
    // memory is reclaimed (a single long-lived context lets it creep until the
    // tab dies). After every COOL_AFTER models, pause COOL_MS so the browser can
    // actually run GC before continuing — this is what lets large backlogs run
    // unattended without crashing.
    const CHUNK_SIZE = 15;
    const COOL_AFTER = 30;
    const COOL_MS    = 10000;

    async function renderOne(v, card) {
      const { src, folder, firstfile } = card.dataset;
      const ext = firstfile.split('.').pop().toLowerCase();
      try {
        await loadWithTimeout(v, fileUrl(src, folder, firstfile), ext, 20000);
        await new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r)));
        const png = v.capturePNG(512);
        const url = await saveThumb(src, folder, png);
        applyThumbToCard(src, folder, url);
      } catch (e) {
        failed++;
        failures.push({ src, folder });
      }
      done++;
    }

    let sinceCool = 0;
    for (let i = 0; i < total && !batchCancelled; i += CHUNK_SIZE) {
      const chunk = targets.slice(i, i + CHUNK_SIZE);

      const host = document.createElement('div');
      host.style.cssText = 'width:512px;height:512px;';
      batchHost.appendChild(host);
      const v = createViewer(host, { background: 0x16140f, showGrid: false });

      try {
        for (const card of chunk) {
          if (batchCancelled) break;
          batchLabel.textContent = `Generating ${done + 1} of ${total}…`;
          batchFill.style.width = Math.round((done / total) * 100) + '%';
          await renderOne(v, card);
          await new Promise(r => setTimeout(r, 0)); // yield between models
        }
      } finally {
        v.dispose();
        host.remove();
      }

      sinceCool += chunk.length;
      const moreLeft = (i + CHUNK_SIZE < total) && !batchCancelled;
      if (moreLeft && sinceCool >= COOL_AFTER) {
        // Longer cooldown so memory is fully reclaimed before the next run.
        sinceCool = 0;
        for (let s = COOL_MS / 1000; s > 0 && !batchCancelled; s--) {
          batchLabel.textContent = `Cooling down (${s}s)…  ${done} of ${total} done`;
          await new Promise(r => setTimeout(r, 1000));
        }
      } else if (moreLeft) {
        await new Promise(r => setTimeout(r, 400)); // short breather between chunks
      }
    }

    batchFill.style.width = '100%';
    batchLabel.textContent = batchCancelled
      ? `Stopped — ${done - failed} done, ${failed} failed`
      : `Done — ${done - failed} generated${failed ? ', ' + failed + ' failed' : ''}`;

    setTimeout(() => {
      batchToast.hidden = true;
      genAllBtn.disabled = false;
      batchFill.style.width = '0%';
      genAllBtn.textContent = '📸 Generate all missing thumbnails';
    }, 2400);

    // Offer to clean up models that couldn't render at all.
    if (failures.length > 0) {
      const n = failures.length;
      if (confirm(`${n} model${n === 1 ? '' : 's'} could not be rendered (empty or corrupt).\n\nDelete ${n === 1 ? 'it' : 'them'}?`)) {
        for (const f of failures) {
          if (await deleteModel(f.src, f.folder)) removeCard(f.src, f.folder);
        }
      }
    }
  });
</script>
</body>
</html>
