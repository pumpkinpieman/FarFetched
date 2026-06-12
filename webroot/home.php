<?php
declare(strict_types=1);

/**
 * home.php — multi-source landing page.
 * Lists each source folder under MODELS_ROOT as a tile. Printables links to the
 * live fetcher; every other source links to the local library browser.
 */

require_once __DIR__ . '/bootstrap.php';

$sources = list_sources();

// Friendly labels + mode per known source; unknown folders default to library.
$meta = [
    'printables' => ['label' => 'Printables',  'mode' => 'fetch',   'desc' => 'Browse & download free models from printables.com'],
    'stlflix'    => ['label' => 'STLFlix',      'mode' => 'library', 'desc' => 'Your downloaded STLFlix files, organized locally'],
];
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
    <div class="brand">◆ Fetcher</div>
    <div class="sub">Pick a source. Each folder under your models directory shows up here automatically.</div>
  </header>
  <main>
    <?php if ($sources === []): ?>
      <div class="empty">
        No sources yet. Create a folder under <code><?= e(MODELS_ROOT) ?></code>
        (e.g. <code>printables/</code> or <code>stlflix/</code>) and it'll appear here.
      </div>
    <?php else: ?>
      <div class="grid">
        <?php foreach ($sources as $s):
          $m = $meta[$s['slug']] ?? ['label' => ucfirst($s['slug']), 'mode' => 'library', 'desc' => 'Local model library'];
          $href = $m['mode'] === 'fetch' ? 'index.php' : ('library.php?src=' . urlencode($s['slug']));
        ?>
          <a class="tile" href="<?= e($href) ?>">
            <div class="tname"><?= e($m['label']) ?></div>
            <div class="tdesc"><?= e($m['desc']) ?></div>
            <div class="tmeta">
              <span><?= (int) $s['count'] ?> model<?= $s['count'] === 1 ? '' : 's' ?></span>
              <span class="pill <?= $m['mode'] === 'fetch' ? 'fetch' : '' ?>"><?= e($m['mode']) ?></span>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="links">
      <a href="index.php">Printables fetcher</a>
      <a href="jobs.php">Queue</a>
      <a href="viewer.php">3D Viewer</a>
      <a href="settings.php">Settings</a>
    </div>
  </main>
</body>
</html>
