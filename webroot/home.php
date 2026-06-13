<?php
declare(strict_types=1);

/**
 * home.php — multi-source landing page.
 * Lists each source folder under MODELS_ROOT as a tile. Printables links to the
 * live fetcher; every other source links to the local library browser.
 */

require_once __DIR__ . '/bootstrap.php';

$sources = list_sources();
$bySlug  = [];
foreach ($sources as $s) {
    $bySlug[$s['slug']] = $s;
}

// Fetch sources are always shown as entry points (even before any download),
// each linking to its own Browse view. Other discovered folders show as library.
$fetchSources = [
    'printables' => ['label' => 'Printables', 'desc' => 'Browse & download free models from printables.com',     'href' => 'index.php'],
    'makerworld' => ['label' => 'MakerWorld', 'desc' => 'Browse & download whole-model ZIPs from makerworld.com', 'href' => 'index.php?src=makerworld'],
];

// Library labels for known non-fetch folders.
$meta = [
    'stlflix' => ['label' => 'STLFlix', 'desc' => 'Your downloaded STLFlix files, organized locally'],
];

$tiles = [];
foreach ($fetchSources as $slug => $fs) {
    $tiles[] = [
        'slug'  => $slug,
        'label' => $fs['label'],
        'desc'  => $fs['desc'],
        'mode'  => 'fetch',
        'href'  => $fs['href'],
        'count' => isset($bySlug[$slug]) ? (int) $bySlug[$slug]['count'] : 0,
    ];
}
foreach ($sources as $s) {
    if (isset($fetchSources[$s['slug']])) {
        continue; // already represented above
    }
    $lib = $meta[$s['slug']] ?? ['label' => ucfirst($s['slug']), 'desc' => 'Local model library'];
    $tiles[] = [
        'slug'  => $s['slug'],
        'label' => $lib['label'],
        'desc'  => $lib['desc'],
        'mode'  => 'library',
        'href'  => 'library.php?src=' . urlencode($s['slug']),
        'count' => (int) $s['count'],
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Fetcher · Sources</title>
<style>
  :root{--bg:#FAF9F5;--panel:#F0EEE6;--card:#FFFFFF;--ink:#2B2A28;--muted:#6B6862;--line:#E5E2D8;--clay:#D97757;--clay-deep:#C2613F;}
  *{box-sizing:border-box;margin:0;padding:0;}
  body{font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;background:var(--bg);color:var(--ink);min-height:100vh;}
  header{padding:40px 32px 24px;max-width:1000px;margin:0 auto;}
  .brand{font-family:ui-serif,Georgia,serif;font-size:26px;font-weight:600;color:var(--clay-deep);letter-spacing:-0.4px;}
  .sub{color:var(--muted);font-size:15px;margin-top:6px;}
  main{max-width:1000px;margin:0 auto;padding:0 32px 48px;}
  .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:18px;margin-top:8px;}
  a.tile{display:block;text-decoration:none;color:inherit;background:var(--card);border:1px solid var(--line);border-radius:16px;padding:22px;transition:border-color .15s,box-shadow .15s;}
  a.tile:hover{border-color:var(--clay);box-shadow:0 0 0 2px rgba(217,119,87,.18);}
  .tname{font-family:ui-serif,Georgia,serif;font-size:20px;font-weight:600;margin-bottom:4px;}
  .tdesc{font-size:13px;color:var(--muted);line-height:1.5;min-height:38px;}
  .tmeta{margin-top:14px;font-size:12px;color:var(--muted);display:flex;justify-content:space-between;align-items:center;}
  .pill{background:var(--panel);border-radius:20px;padding:3px 11px;font-weight:600;text-transform:capitalize;}
  .pill.fetch{background:#E8F1EC;color:#3F7D5B;}
  .empty{background:var(--card);border:1px dashed var(--line);border-radius:16px;padding:28px;text-align:center;color:var(--muted);font-size:14px;}
  code{background:var(--panel);padding:1px 6px;border-radius:5px;font-size:12px;}
  .links{margin-top:28px;font-size:13px;}
  .links a{color:var(--clay-deep);text-decoration:none;margin-right:16px;}
</style>
</head>
<body>
  <header>
    <div class="brand"><img src="logo.svg" alt="FarFetched" style="height:1.15em;width:auto;vertical-align:-.2em;margin-right:7px"> FarFetched</div>
    <div class="sub">Pick a source. Each folder under your models directory shows up here automatically.</div>
  </header>
  <main>
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

    <div class="links">
      <a href="index.php">Browse Models</a>
      <a href="jobs.php">Queue</a>
      <a href="viewer.php">3D Viewer</a>
      <a href="settings.php">Settings</a>
    </div>
  </main>
</body>
</html>
