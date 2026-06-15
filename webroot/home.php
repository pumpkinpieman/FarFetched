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
    'printables'  => ['label' => 'Printables',  'desc' => 'Browse & download free models from printables.com',            'href' => 'index.php'],
    'makerworld'  => ['label' => 'MakerWorld',  'desc' => 'Browse & download whole-model ZIPs from makerworld.com',       'href' => 'index.php?src=makerworld&browse=all'],
    'thingiverse' => ['label' => 'Thingiverse', 'desc' => 'Browse & download from the world\'s largest 3D model archive', 'href' => 'index.php?src=thingiverse&browse=all'],
    'cults3d'     => ['label' => 'Cults3D',     'desc' => 'Artistic & unique designs from the cults3d.com community',    'href' => 'index.php?src=cults3d&browse=all'],
    'stlflix'     => ['label' => 'STLFlix',     'desc' => 'Browse STLFlix platform categories and models',                'href' => 'index.php?src=stlflix&browse=all'],
];

// Library labels for known non-fetch folders.
$meta = [];

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
  :root{--bg:#0c0a08;--panel:#141009;--card:#1a140c;--ink:#f0e6d3;--muted:#7a6a52;--line:#2e2218;--clay:#ff6b1a;--clay-deep:#c44d0d;--ok:#c8a020;--err:#e05c5c;--warn:#f5c842;}
  body{background-image:radial-gradient(ellipse at 0% 100%, rgba(255,107,26,0.06) 0%, transparent 60%);}
  .brand{color:#ff6b1a !important;font-weight:800 !important;letter-spacing:-.5px;}
  nav a{color:#8a8070;}
  nav a:hover{background:#1a140c;color:#f0e6d3;}
  nav a.active{background:rgba(255,107,26,0.1);color:#ff6b1a;border:1px solid rgba(255,107,26,0.2);font-weight:600;}
  .msize{color:#f5c842 !important;}
  .btn-primary{background:#ff6b1a;color:#fff;} .btn-primary:hover{background:#c44d0d;}
  .btn-primary:disabled{background:#2e1a0a;color:#5a4a32;cursor:not-allowed;}
  .btn-ghost{color:#8a8070;border-color:#2e2218;} .btn-ghost:hover{border-color:#ff6b1a;color:#ff6b1a;}
  .srcBtn.active{background:rgba(245,200,66,0.08);color:#f5c842;}
  select{background:#1a140c;color:#f0e6d3;border-color:#2e2218;}
  .searchbar input,.pastebar-row input,textarea,input[type=text]{background:#1a140c;color:#f0e6d3;border-color:#2e2218;}
  .searchbar input:focus,textarea:focus,input:focus{border-color:#ff6b1a;box-shadow:0 0 0 2px rgba(255,107,26,0.15);}
  .card.sel{border-color:#ff6b1a;box-shadow:0 0 0 2px rgba(255,107,26,0.2);}
  .pick{accent-color:#ff6b1a;}
  .banner{background:#1a1000;color:#f5c842;border-color:#3d2800;}
  .notice.ok{background:#1a1200;color:#f5c842;}
  .notice.err{background:#1f0d0d;color:#e05c5c;}
  .badge.paid{background:#3d2000;color:#f5c842;}
  .tab-btn.active{color:#ff6b1a;border-bottom-color:#ff6b1a;}
  .stat,.panel,.src-card,.overall,table{background:#1a140c;border-color:#2e2218;}
  th{background:#141009;}
  .pill.fetch{background:#1a1200;color:#f5c842;}
  .pill{background:#1a140c;}
  a.tile:hover{border-color:#ff6b1a;box-shadow:0 0 0 2px rgba(255,107,26,0.15);}
  .track{background:#2e2218;} .fill{background:#ff6b1a;}
  .rowfill{background:#ff6b1a;} .rowfill.green{background:#c8a020;}
  .overall .live .dot{background:#ff6b1a;}
  .act button:hover{border-color:#ff6b1a;color:#ff6b1a;}
  .filebtn{background:#1a140c;border-color:#2e2218;color:#f0e6d3;}
  .filebtn:hover{border-color:#ff6b1a;}
  .filebtn.active{border-color:#f5c842;box-shadow:0 0 0 2px rgba(245,200,66,0.15);}
  .folder-hdr{border-color:#2e2218;color:#7a6a52;}
  .bar{background:#1a1000;color:#f5c842;border-color:#3d2800;}
  .file-counter{background:#2e2218;color:#f0e6d3;}
  .srcBtn{color:#7a6a52;}
  .navlabel{color:#5a4a32;}
  code{background:#1a140c;}
  .notice{background:#1a140c;}
  .step a{color:#ff6b1a;}
  .act button{background:#1a140c;border-color:#2e2218;color:#7a6a52;}
  .tag{background:#ff6b1a;}

  body{background-image:radial-gradient(circle,rgba(57,168,92,.06) 1px,transparent 1px);background-size:24px 24px;}
  .brand{color:#ff6b1a !important;font-family:ui-monospace,monospace !important;letter-spacing:-.5px;}
  nav a:hover{background:#1a140c;color:#e8ede9;}
  nav a.active{background:rgba(255,107,26,.1);color:#ff6b1a;border:1px solid rgba(57,168,92,.2);font-weight:500;}
  nav a:not(.active){color:#c8d4c9;}
  .msize{color:#f5a623 !important;}
  .btn-primary{background:#39a85c;color:#0a1a0e;} .btn-primary:hover{background:#2a7d44;}
  .btn-primary:disabled{background:#1c3023;color:#6b8070;cursor:not-allowed;}
  .btn-ghost{color:#c8d4c9;border-color:#2a3028;} .btn-ghost:hover{border-color:#ff6b1a;color:#ff6b1a;}
  .srcBtn.active{background:rgba(255,107,26,.1);color:#ff6b1a;}
  select{background:#1a140c;color:#e8ede9;border-color:#2a3028;}
  .searchbar input,.pastebar-row input,textarea,input[type=text]{background:#1a140c;color:#e8ede9;border-color:#2a3028;}
  .searchbar input:focus,textarea:focus,input:focus{border-color:#ff6b1a;box-shadow:0 0 0 2px rgba(255,107,26,.15);}
  .card.sel{border-color:#d4820a;box-shadow:0 0 0 2px rgba(255,107,26,.2);}
  .pick{accent-color:#d4820a;}
  .banner{background:#1a1500;color:#f5a623;border-color:#3d3000;}
  .notice.ok,.notice{background:#0d1f12;color:#ff6b1a;}
  .notice.err{background:#1f0d0d;color:#e05c5c;}
  .notice.warn{background:#1a1200;color:#d4820a;}
  .badge.paid{background:#3d2600;color:#f5a623;}
  .tab-btn.active{color:#ff6b1a;border-bottom-color:#ff6b1a;}
  .stat,.panel,.src-card,.overall,table{background:#1a140c;border-color:#2a3028;}
  th{background:#161a17;}
  .pill.fetch{background:#0d1f12;color:#ff6b1a;}
  .pill{background:#1a140c;}
  a.tile:hover{border-color:#ff6b1a;box-shadow:0 0 0 2px rgba(255,107,26,.15);}
  .track{background:#2a3028;} .fill{background:#39a85c;}
  .rowfill.green{background:#39a85c;}
  .overall .live .dot{background:#39a85c;}
  .act button:hover{border-color:#ff6b1a;color:#ff6b1a;}
  .filebtn{background:#1a140c;border-color:#2a3028;color:#e8ede9;}
  .filebtn:hover{border-color:#ff6b1a;}
  .filebtn.active{border-color:#d4820a;box-shadow:0 0 0 2px rgba(212,130,10,.2);}
  .folder-hdr{border-color:#2a3028;color:#6b8070;}
  .bar{background:#1a1500;color:#f5a623;border-color:#3d3000;}

  *{box-sizing:border-box;margin:0;padding:0;}
  body{font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;background:var(--bg);color:var(--ink);min-height:100vh;}
  header{padding:40px 32px 24px;max-width:1000px;margin:0 auto;}
  .brand{font-family:ui-serif,Georgia,serif;font-size:26px;font-weight:600;color:var(--clay-deep);letter-spacing:-0.4px;}
  .sub{color:var(--muted);font-size:15px;margin-top:6px;}
  main{max-width:1000px;margin:0 auto;padding:0 32px 48px;}
  .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:18px;margin-top:8px;}
  a.tile{display:block;text-decoration:none;color:inherit;background:var(--card);border:1px solid var(--line);border-radius:16px;padding:22px;transition:border-color .15s,box-shadow .15s;}
  a.tile:hover{border-color:var(--clay);box-shadow:0 0 0 2px rgba(255,107,26,.15);}
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
