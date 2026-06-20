<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

$sources = list_sources();
$bySlug  = [];
foreach ($sources as $s) { $bySlug[$s['slug']] = $s; }

$fetchSources = [
    'printables'  => ['label' => 'Printables',  'desc' => 'Browse & download free models from printables.com',            'href' => 'index.php'],
    'makerworld'  => ['label' => 'MakerWorld',  'desc' => 'Browse & download whole-model ZIPs from makerworld.com',       'href' => 'index.php?src=makerworld&browse=all'],
    'thingiverse' => ['label' => 'Thingiverse', 'desc' => 'Browse & download from the world\'s largest 3D model archive', 'href' => 'index.php?src=thingiverse&browse=all'],
    'cults3d'     => ['label' => 'Cults3D',     'desc' => 'Artistic & unique designs from the cults3d.com community',    'href' => 'index.php?src=cults3d&browse=all'],
    'stlflix'     => ['label' => 'STLFlix',     'desc' => 'Browse STLFlix platform categories and models',                'href' => 'index.php?src=stlflix&browse=all'],
    'creality'    => ['label' => 'Creality',    'desc' => 'Search & browse free models from crealitycloud.com',          'href' => 'index.php?src=creality'],
];

$tiles = [];
foreach ($fetchSources as $slug => $fs) {
    $tiles[] = ['slug' => $slug, 'label' => $fs['label'], 'desc' => $fs['desc'], 'mode' => 'fetch', 'href' => $fs['href'], 'count' => isset($bySlug[$slug]) ? (int) $bySlug[$slug]['count'] : 0];
}
foreach ($sources as $s) {
    if (isset($fetchSources[$s['slug']])) continue;
    $tiles[] = ['slug' => $s['slug'], 'label' => ucfirst($s['slug']), 'desc' => 'Local model library', 'mode' => 'library', 'href' => 'library.php?src=' . urlencode($s['slug']), 'count' => (int) $s['count']];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>FarFetched — Sources</title>
<link rel="stylesheet" href="css/styles.css">
<link rel="stylesheet" href="css/styles_home.css">

</head>
<body>

<nav class="topnav">
  <div class="brand"><img src="logo.svg" alt=""> FarFetched</div>
  <div class="nav-links">
    <a href="index.php">Browse</a>
    <a href="jobs.php">Queue</a>
    <a href="viewer.php">3D Viewer</a>
    <a href="library.php">My Library</a>
    <a href="insights.php">Insights</a>
    <a href="favorites.php">Favorites</a>
  <a href="settings.php">Settings</a>
		<button id="theme-toggle" aria-label="Toggle theme" class="btn-ghost">
		<span id="theme-toggle-icon">🌙</span> Change Appearance
		</button>
  </div>
</nav>

<div class="hero">
  <div class="hero-eyebrow">Self-hosted 3D model downloader</div>
  <h1>Fetch your library.<br><em>Patiently.</em></h1>
  <p class="hero-lede">FarFetched browses, searches, and downloads 3D models from Printables, MakerWorld, Thingiverse, Cults3D, STLFlix, and Creality Cloud — all to your own server. One queue. One pace. Your files, forever.</p>
  <div class="hero-stats">
    <div class="hero-stat"><strong><?= array_sum(array_column($tiles, 'count')) ?></strong>models saved</div>
    <div class="hero-stat"><strong><?= count(array_filter($tiles, fn($t) => $t['count'] > 0)) ?></strong>active sources</div>
    <div class="hero-stat"><strong>6</strong>platforms supported</div>
  </div>
</div>

<div class="section">
  <div class="section-label">Sources</div>
  <div class="grid">
    <?php foreach ($tiles as $t): ?>
      <a class="tile" href="<?= e($t['href']) ?>">
        <div class="tname"><?= e($t['label']) ?></div>
        <div class="tdesc"><?= e($t['desc']) ?></div>
        <div class="tmeta">
          <span><?= (int) $t['count'] ?> model<?= $t['count'] === 1 ? '' : 's' ?></span>
          <span class="pill <?= $t['mode'] === 'fetch' ? 'fetch' : '' ?>"><?= e($t['mode']) ?></span>
        </div>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="section-label">What FarFetched does</div>
  <div class="feat-grid">
    <div class="feat"><div class="feat-icon">⬇</div><div class="feat-title">Paced downloads</div><div class="feat-desc">Downloads one file at a time with configurable delays. Polite to APIs, safe for long queues.</div></div>
    <div class="feat"><div class="feat-icon">🗂</div><div class="feat-title">Local library</div><div class="feat-desc">Every model lands in your own folder structure — organized by source and model name.</div></div>
    <div class="feat"><div class="feat-icon">🔍</div><div class="feat-title">Browse & search</div><div class="feat-desc">Full catalog search across all six platforms without leaving the app.</div></div>
    <div class="feat"><div class="feat-icon">📐</div><div class="feat-title">3D Viewer</div><div class="feat-desc">Preview STL and 3MF files in-browser — no slicer required.</div></div>
    <div class="feat"><div class="feat-icon">🔄</div><div class="feat-title">Auto-retry</div><div class="feat-desc">Failed downloads are requeued automatically. Signed URL expired? Re-minted on the next run.</div></div>
    <div class="feat"><div class="feat-icon">🛡</div><div class="feat-title">Self-hosted</div><div class="feat-desc">Runs entirely on your hardware via Docker. No accounts, no cloud, no tracking.</div></div>
  </div>

  <div class="section-label">How to find your auth tokens</div>
  <div class="token-grid">

    <div class="token-card">
      <div class="token-source"><span></span>Printables</div>
      <div class="browser-tabs"><span class="btab">Chrome</span><span class="btab">Firefox</span><span class="btab">Edge</span><span class="btab">Safari</span></div>
      <div class="token-step">Log in to printables.com</div>
      <div class="token-step">Open DevTools: <strong>F12</strong> (Win/Linux) or <strong>⌘⌥I</strong> (Mac)</div>
      <div class="token-step">Go to <strong>Network</strong> tab → filter by <code>graphql</code></div>
      <div class="token-step">Click any model or browse a category</div>
      <div class="token-step">Click the <code>graphql</code> request → <strong>Headers</strong></div>
      <div class="token-step">Copy the <code>Authorization: Bearer ey...</code> value</div>
      <div class="token-note">Token expires every ~1 hour. Paste into Settings → Printables.</div>
    </div>

    <div class="token-card">
      <div class="token-source"><span></span>MakerWorld</div>
      <div class="browser-tabs"><span class="btab">Chrome</span><span class="btab">Firefox</span><span class="btab">Edge</span><span class="btab">Safari</span></div>
      <div class="token-step">Log in to makerworld.com</div>
      <div class="token-step">Open DevTools → <strong>Network</strong> → filter <code>api</code></div>
      <div class="token-step">Browse any model page</div>
      <div class="token-step">Find a request to <code>api.makerworld.com</code> → <strong>Headers</strong></div>
      <div class="token-step">Copy <code>Authorization: Bearer ey...</code></div>
      <div class="token-note">Paste into Settings → MakerWorld.</div>
    </div>

    <div class="token-card">
      <div class="token-source"><span></span>Thingiverse</div>
      <div class="browser-tabs"><span class="btab">Chrome</span><span class="btab">Firefox</span><span class="btab">Edge</span></div>
      <div class="token-step">Log in to thingiverse.com</div>
      <div class="token-step">Open DevTools → <strong>Network</strong> → filter <code>api.thingiverse</code></div>
      <div class="token-step">Browse any Thing page</div>
      <div class="token-step">Click any API request → <strong>Request Headers</strong></div>
      <div class="token-step">Copy the <code>Authorization: Bearer ey...</code> value</div>
      <div class="token-note">Paste into Settings → Thingiverse.</div>
    </div>

    <div class="token-card">
      <div class="token-source"><span></span>Cults3D</div>
      <div class="browser-tabs"><span class="btab">Chrome</span><span class="btab">Firefox</span><span class="btab">Edge</span><span class="btab">Safari</span></div>
      <div class="token-step">Log in to cults3d.com</div>
      <div class="token-step">Open DevTools → <strong>Application</strong> tab (Chrome/Edge) or <strong>Storage</strong> tab (Firefox)</div>
      <div class="token-step">Go to <strong>Cookies</strong> → <code>cults3d.com</code></div>
      <div class="token-step">Copy the value of <code>user_email</code> and <code>user_token</code></div>
      <div class="token-note">Paste username + token separately into Settings → Cults3D.</div>
    </div>

    <div class="token-card">
      <div class="token-source"><span></span>STLFlix</div>
      <div class="browser-tabs"><span class="btab">Chrome</span><span class="btab">Firefox</span><span class="btab">Edge</span><span class="btab">Safari</span></div>
      <div class="token-step">Log in to platform.stlflix.com</div>
      <div class="token-step">Open DevTools → <strong>Network</strong> → filter <code>graphql</code></div>
      <div class="token-step">Click any graphql request → <strong>Request Headers</strong></div>
      <div class="token-step">Copy the <code>authorization: Bearer ey...</code> value</div>
      <div class="token-note">Long-lived JWT (~30 day expiry). Paste into Settings → STLFlix.</div>
    </div>

    <div class="token-card">
      <div class="token-source"><span></span>Creality Cloud</div>
      <div class="browser-tabs"><span class="btab">Chrome</span><span class="btab">Firefox</span><span class="btab">Edge</span><span class="btab">Safari</span></div>
      <div class="token-step">Log in to crealitycloud.com</div>
      <div class="token-step">Open DevTools → <strong>Application/Storage</strong> → <strong>Cookies</strong></div>
      <div class="token-step">Copy <code>model_token</code>, <code>model_user_id</code>, and <code>cf_clearance</code></div>
      <div class="token-note">Paste the three values into Settings → Creality. Re-paste if downloads start failing.</div>
    </div>

    <div class="token-card">
      <div class="token-source"><span></span>Safari users</div>
      <div class="browser-tabs"><span class="btab">Safari</span></div>
      <div class="token-step">Enable DevTools: <strong>Safari → Settings → Advanced → Show features for web developers</strong></div>
      <div class="token-step">Open DevTools: <strong>⌘⌥I</strong> or right-click → Inspect</div>
      <div class="token-step">Go to <strong>Network</strong> tab → browse the relevant site</div>
      <div class="token-step">Click a request → <strong>Headers</strong> section on the right</div>
      <div class="token-note">Safari hides DevTools by default. Enable it once in Settings and it stays enabled.</div>
    </div>

  </div>
</div>

<div class="foot">
  <a href="index.php">Browse Models</a>
  <a href="jobs.php">Queue</a>
  <a href="viewer.php">3D Viewer</a>
  <a href="library.php">My Library</a>
    <a href="insights.php">Insights</a>
  <a href="favorites.php">Favorites</a>
  <a href="settings.php">Settings</a>
</div>

<script>
  const toggleBtn = document.getElementById('theme-toggle');
  const toggleIcon = document.getElementById('theme-toggle-icon');

  // Check for saved user preference, otherwise default to dark
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

</body>
</html>
