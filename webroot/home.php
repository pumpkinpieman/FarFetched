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
<style>
  :root{--bg:#0c0a08;--panel:#141009;--card:#1a140c;--ink:#f0e6d3;--muted:#7a6a52;--line:#2e2218;--clay:#ff6b1a;--clay-deep:#c44d0d;--gold:#f5c842;}
  *{box-sizing:border-box;margin:0;padding:0;}
  body{font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;background:var(--bg);color:var(--ink);min-height:100vh;background-image:radial-gradient(ellipse at 0% 100%,rgba(255,107,26,.06) 0%,transparent 60%);}
  a{color:inherit;text-decoration:none;}

  /* ── Top nav ── */
  .topnav{display:flex;align-items:center;justify-content:space-between;padding:16px 32px;border-bottom:1px solid var(--line);position:sticky;top:0;z-index:20;background:rgba(12,10,8,.9);backdrop-filter:blur(8px);}
  .brand{font-size:18px;font-weight:800;color:var(--clay);letter-spacing:-.5px;display:flex;align-items:center;gap:8px;}
  .brand img{height:1.2em;width:auto;vertical-align:-.15em;}
  .nav-links{display:flex;gap:20px;font-size:13px;color:var(--muted);}
  .nav-links a:hover{color:var(--ink);}

  /* ── Hero ── */
  .hero{max-width:900px;margin:0 auto;padding:56px 32px 36px;}
  .hero-eyebrow{font-size:11px;font-weight:700;letter-spacing:.15em;text-transform:uppercase;color:var(--clay);margin-bottom:14px;}
  .hero h1{font-size:clamp(32px,5vw,52px);font-weight:800;line-height:1.05;letter-spacing:-.02em;margin-bottom:18px;}
  .hero h1 em{font-style:italic;color:var(--clay);}
  .hero-lede{font-size:17px;color:var(--muted);line-height:1.65;max-width:58ch;margin-bottom:28px;}
  .hero-stats{display:flex;gap:32px;flex-wrap:wrap;}
  .hero-stat{font-size:13px;color:var(--muted);}
  .hero-stat strong{display:block;font-size:22px;font-weight:800;color:var(--clay);line-height:1;}

  /* ── Sources grid ── */
  .section{max-width:900px;margin:0 auto;padding:0 32px 48px;}
  .section-label{font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);margin-bottom:16px;}
  .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px;margin-bottom:48px;}
  a.tile{display:block;background:var(--card);border:1px solid var(--line);border-radius:14px;padding:20px 22px;transition:border-color .15s,box-shadow .15s;}
  a.tile:hover{border-color:var(--clay);box-shadow:0 0 0 2px rgba(255,107,26,.15);}
  .tname{font-size:17px;font-weight:700;margin-bottom:5px;}
  .tdesc{font-size:12px;color:var(--muted);line-height:1.5;min-height:34px;}
  .tmeta{margin-top:14px;display:flex;justify-content:space-between;align-items:center;font-size:12px;color:var(--muted);}
  .pill{border-radius:20px;padding:3px 10px;font-weight:700;font-size:10px;text-transform:uppercase;letter-spacing:.04em;background:var(--panel);}
  .pill.fetch{background:rgba(255,107,26,.12);color:var(--clay);}

  /* ── Feature cards ── */
  .feat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:48px;}
  .feat{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:18px 20px;}
  .feat-icon{font-size:20px;margin-bottom:10px;}
  .feat-title{font-size:13px;font-weight:700;color:var(--ink);margin-bottom:4px;}
  .feat-desc{font-size:12px;color:var(--muted);line-height:1.5;}

  /* ── Token guide ── */
  .token-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px;margin-bottom:48px;}
  .token-card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:18px 20px;}
  .token-source{font-size:13px;font-weight:700;color:var(--clay);margin-bottom:10px;display:flex;align-items:center;gap:7px;}
  .token-source span{width:6px;height:6px;background:var(--clay);border-radius:50%;display:inline-block;}
  .token-step{font-size:12px;color:var(--muted);line-height:1.6;margin-bottom:6px;padding-left:12px;position:relative;}
  .token-step::before{content:"→";position:absolute;left:0;color:var(--clay);font-size:10px;}
  .token-note{font-size:11px;color:var(--muted);margin-top:8px;padding:6px 10px;background:var(--panel);border-radius:6px;line-height:1.5;}
  .browser-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px;}
  .btab{font-size:10px;font-weight:700;padding:2px 8px;border-radius:4px;background:var(--panel);color:var(--muted);}

  /* ── Footer links ── */
  .foot{max-width:900px;margin:0 auto;padding:0 32px 40px;display:flex;gap:20px;font-size:13px;color:var(--muted);flex-wrap:wrap;}
  .foot a:hover{color:var(--clay);}
</style>
</head>
<body>

<nav class="topnav">
  <div class="brand"><img src="logo.svg" alt=""> FarFetched</div>
  <div class="nav-links">
    <a href="index.php">Browse</a>
    <a href="jobs.php">Queue</a>
    <a href="viewer.php">3D Viewer</a>
    <a href="settings.php">Settings</a>
  </div>
</nav>

<div class="hero">
  <div class="hero-eyebrow">Self-hosted 3D model downloader</div>
  <h1>Fetch your library.<br><em>Patiently.</em></h1>
  <p class="hero-lede">FarFetched browses, searches, and downloads 3D models from Printables, MakerWorld, Thingiverse, Cults3D, and STLFlix — all to your own server. One queue. One pace. Your files, forever.</p>
  <div class="hero-stats">
    <div class="hero-stat"><strong><?= array_sum(array_column($tiles, 'count')) ?></strong>models saved</div>
    <div class="hero-stat"><strong><?= count(array_filter($tiles, fn($t) => $t['count'] > 0)) ?></strong>active sources</div>
    <div class="hero-stat"><strong>5</strong>platforms supported</div>
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
    <div class="feat"><div class="feat-icon">🔍</div><div class="feat-title">Browse & search</div><div class="feat-desc">Full catalog search across all five platforms without leaving the app.</div></div>
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
  <a href="settings.php">Settings</a>
</div>

</body>
</html>
