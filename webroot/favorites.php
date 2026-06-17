<?php
declare(strict_types=1);

/**
 * favorites.php — starred models, server-side.
 * Phase 1: tile grid with unstar + "View on source" link.
 * (Phase 2 will add an on-click detail modal: author, description, tags, files.)
 */

require_once __DIR__ . '/bootstrap.php';

$favs = favorites_all();
$csrf = csrf_token();

$srcLabels = [
    'printables' => 'Printables', 'makerworld' => 'MakerWorld',
    'thingiverse' => 'Thingiverse', 'cults3d' => 'Cults3D',
    'stlflix' => 'STLFlix', 'creality' => 'Creality',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Favorites · FarFetched</title>
<link rel="stylesheet" href="css/styles.css?v=20260617d">
<link rel="stylesheet" href="css/styles_favorites.css">
</head>
<body>
  <aside>
    <div class="brand"><img src="logo.svg" alt="FarFetched" style="height:1.15em;width:auto;vertical-align:-.2em;margin-right:7px"> FarFetched</div>
    <nav>
      <a href="index.php">Browse Models</a>
      <a href="jobs.php">Queue</a>
      <a href="viewer.php">3D Viewer</a>
      <a href="favorites.php" class="active">Favorites</a>
      <a href="settings.php">Settings</a>
      <button id="theme-toggle" aria-label="Toggle theme" class="btn-ghost">
        <span id="theme-toggle-icon">🌙</span> Change Appearance
      </button>
    </nav>
  </aside>

  <main>
    <div class="topbar">
      <h1>Favorites</h1>
      <span class="fav-count"><?= count($favs) ?> saved</span>
    </div>

    <?php if (count($favs) === 0): ?>
      <div class="fav-empty">
        <div class="fav-empty-star">☆</div>
        <p>No favorites yet.</p>
        <p class="fav-empty-sub">Star a model while browsing to save it here.</p>
        <a href="index.php" class="btn-primary">Browse Models</a>
      </div>
    <?php else: ?>
      <div class="grid" id="favGrid">
        <?php foreach ($favs as $f):
          $src   = (string) $f['source'];
          $label = $srcLabels[$src] ?? ucfirst($src);
          $url   = favorite_source_url($src, (string) $f['model_id'], (string) $f['slug']);
          $thumb = (string) $f['thumb'];
          // Proxy thumbnails that need it (same sources as the grid).
          $thumbUrl = $thumb;
          if ($thumb !== '' && in_array($src, ['cults3d', 'thingiverse'], true)) {
              $thumbUrl = 'proxy.php?url=' . urlencode($thumb);
          }
        ?>
          <div class="card fav-card" data-source="<?= e($src) ?>" data-id="<?= e($f['model_id']) ?>">
            <button type="button" class="fav-star on" data-fav-source="<?= e($src) ?>" data-fav-id="<?= e($f['model_id']) ?>"
                    aria-label="Remove from favorites" title="Remove from Favorites">★</button>
            <span class="src-badge <?= e($src) ?>"><?= e($label) ?></span>
            <?php if (!empty($f['price']) && (int) $f['price'] > 0): ?>
              <span class="badge paid">Payment Required</span>
            <?php endif; ?>
            <div class="thumb">
              <?php if ($thumbUrl !== ''): ?>
                <img class="thumb-img" src="<?= e($thumbUrl) ?>" alt="" loading="lazy">
              <?php else: ?>
                <div class="thumb-placeholder"><?= e(strtoupper(substr($label, 0, 2))) ?></div>
              <?php endif; ?>
            </div>
            <div class="meta">
              <div class="mname"><?= e($f['name'] !== '' ? $f['name'] : 'Untitled') ?></div>
              <?php if ((string) $f['creator'] !== ''): ?>
                <div class="mcreator">by <?= e($f['creator']) ?></div>
              <?php endif; ?>
            </div>
            <div class="fav-actions">
              <?php if ($url !== ''): ?>
                <a href="<?= e($url) ?>" target="_blank" rel="noopener" class="fav-link">View on <?= e($label) ?> ↗</a>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </main>

<script>
  // Theme toggle (shared key with other pages).
  (function () {
    const root = document.documentElement;
    const saved = localStorage.getItem('theme');
    if (saved === 'light') root.setAttribute('data-theme', 'light');
    const btn = document.getElementById('theme-toggle');
    const icon = document.getElementById('theme-toggle-icon');
    function sync() { if (icon) icon.textContent = root.getAttribute('data-theme') === 'light' ? '☀️' : '🌙'; }
    sync();
    if (btn) btn.addEventListener('click', () => {
      const light = root.getAttribute('data-theme') === 'light';
      if (light) { root.removeAttribute('data-theme'); localStorage.setItem('theme', 'dark'); }
      else { root.setAttribute('data-theme', 'light'); localStorage.setItem('theme', 'light'); }
      sync();
    });
  })();

  // Unstar: remove the card on success.
  document.addEventListener('click', async e => {
    const star = e.target.closest('.fav-star');
    if (!star) return;
    e.preventDefault();
    star.disabled = true;
    try {
      const res = await fetch('favorite.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'remove',
          source: star.dataset.favSource,
          model_id: star.dataset.favId,
        }),
      });
      const data = await res.json();
      if (data.ok) {
        const card = star.closest('.fav-card');
        if (card) {
          card.style.transition = 'opacity .2s, transform .2s';
          card.style.opacity = '0';
          card.style.transform = 'scale(.94)';
          setTimeout(() => {
            card.remove();
            const left = document.querySelectorAll('.fav-card').length;
            const countEl = document.querySelector('.fav-count');
            if (countEl) countEl.textContent = left + ' saved';
            if (left === 0) location.reload();
          }, 200);
        }
      }
    } catch (_) { star.disabled = false; }
  });
</script>
</body>
</html>
