<?php
declare(strict_types=1);

/**
 * insights.php — "Library Insights": stats about everything downloaded.
 * Pure filesystem aggregation (fast): counts, disk usage, breakdown by source
 * and file type, biggest models, recent activity. No heavy 3MF parsing.
 */

require_once __DIR__ . '/bootstrap.php';

$bySource = [];   // slug => ['models'=>, 'files'=>, 'bytes'=>]
$byType   = [];   // ext => count
$biggest  = [];   // [name, slug, bytes]
$totalModels = 0; $totalFiles = 0; $totalBytes = 0;
$newest = 0; $oldest = PHP_INT_MAX;

foreach (list_sources() as $s) {
    $slug = $s['slug'];
    $bySource[$slug] = ['models' => 0, 'files' => 0, 'bytes' => 0];
    foreach (list_models($s['path']) as $m) {
        if ($m['kind'] !== 'folder') continue;
        $abs = $s['path'] . '/' . $m['name'];
        $bySource[$slug]['models']++;
        $bySource[$slug]['files'] += (int) $m['files'];
        $bySource[$slug]['bytes'] += (int) $m['size'];
        $totalModels++;
        $totalFiles += (int) $m['files'];
        $totalBytes += (int) $m['size'];

        foreach (model_file_types($abs) as $ext) {
            $byType[strtoupper($ext)] = ($byType[strtoupper($ext)] ?? 0) + 1;
        }
        $biggest[] = ['name' => clean_model_name($m['name']), 'slug' => $slug, 'bytes' => (int) $m['size']];

        $mt = @filemtime($abs) ?: 0;
        if ($mt > $newest) $newest = $mt;
        if ($mt > 0 && $mt < $oldest) $oldest = $mt;
    }
}

// Top 8 biggest models.
usort($biggest, static fn($a, $b) => $b['bytes'] <=> $a['bytes']);
$biggest = array_slice($biggest, 0, 8);

// Sort source + type breakdowns by size/count desc.
uasort($bySource, static fn($a, $b) => $b['bytes'] <=> $a['bytes']);
arsort($byType);

$maxSrcBytes = 1;
foreach ($bySource as $v) { if ($v['bytes'] > $maxSrcBytes) $maxSrcBytes = $v['bytes']; }
$maxType = $byType ? max($byType) : 1;

$srcLabels = [
    'printables' => 'Printables', 'makerworld' => 'MakerWorld', 'thingiverse' => 'Thingiverse',
    'cults3d' => 'Cults3D', 'stlflix' => 'STLFlix', 'creality' => 'Creality',
];
$srcColors = [
    'printables' => '#e8754a', 'makerworld' => '#2d7d9a', 'thingiverse' => '#5b8a3a',
    'cults3d' => '#9a5ba8', 'stlflix' => '#c1622e', 'creality' => '#4a8a6b',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Insights · FarFetched</title>
<link rel="stylesheet" href="css/styles.css">
<link rel="stylesheet" href="css/styles_library.css">
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
      <a href="insights.php" class="active">Insights</a>
      <a href="favorites.php">Favorites</a>
      <a href="settings.php">Settings</a>
      <button id="theme-toggle" aria-label="Toggle theme" class="btn-ghost">
        <span id="theme-toggle-icon">🌙</span> Change Appearance
      </button>
    </nav>
  </aside>

  <main>
    <h1>Library Insights</h1>
    <div class="sub">A look at everything you've collected.</div>

    <div class="ins-cards">
      <div class="ins-card"><div class="ins-num"><?= number_format($totalModels) ?></div><div class="ins-lbl">Models</div></div>
      <div class="ins-card"><div class="ins-num"><?= number_format($totalFiles) ?></div><div class="ins-lbl">Files</div></div>
      <div class="ins-card"><div class="ins-num"><?= e(human_size($totalBytes)) ?></div><div class="ins-lbl">On disk</div></div>
      <div class="ins-card"><div class="ins-num"><?= $newest ? e(date('M j', $newest)) : '—' ?></div><div class="ins-lbl">Last added</div></div>
    </div>

    <?php if ($totalModels > 0): ?>
    <div class="ins-grid">
      <div class="ins-panel">
        <div class="ins-panel-h">By source</div>
        <?php foreach ($bySource as $slug => $v): if ($v['models'] === 0) continue;
          $pct = (int) round($v['bytes'] / $maxSrcBytes * 100);
          $col = $srcColors[$slug] ?? '#888';
        ?>
          <div class="ins-bar-row">
            <div class="ins-bar-label"><?= e($srcLabels[$slug] ?? ucfirst($slug)) ?></div>
            <div class="ins-bar-track"><div class="ins-bar-fill" style="width:<?= $pct ?>%;background:<?= e($col) ?>;"></div></div>
            <div class="ins-bar-val"><?= e(human_size($v['bytes'])) ?> · <?= $v['models'] ?></div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="ins-panel">
        <div class="ins-panel-h">File types</div>
        <?php foreach (array_slice($byType, 0, 100, true) as $ext => $cnt):
          $pct = (int) round($cnt / $maxType * 100);
        ?>
          <div class="ins-bar-row">
            <div class="ins-bar-label"><?= e($ext) ?></div>
            <div class="ins-bar-track"><div class="ins-bar-fill" style="width:<?= $pct ?>%;background:var(--clay);"></div></div>
            <div class="ins-bar-val"><?= number_format($cnt) ?></div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="ins-panel ins-panel-wide">
        <div class="ins-panel-h">Biggest models</div>
        <?php foreach ($biggest as $b): ?>
          <div class="ins-big-row">
            <span class="ins-big-name"><?= e($b['name']) ?></span>
            <span class="ins-big-src"><?= e($srcLabels[$b['slug']] ?? ucfirst($b['slug'])) ?></span>
            <span class="ins-big-size"><?= e(human_size($b['bytes'])) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php else: ?>
      <i><div class="lib-empty">Nothing downloaded yet — <a href="index.php">browse some models</a> to see your stats.</div></i>
    <?php endif; ?>
  </main>

<script>
  const root = document.documentElement;
  if (localStorage.getItem('theme') === 'light') root.setAttribute('data-theme', 'light');
  const btn = document.getElementById('theme-toggle');
  const icon = document.getElementById('theme-toggle-icon');
  function sync() { if (icon) icon.textContent = root.getAttribute('data-theme') === 'light' ? '☀️' : '🌙'; }
  sync();
  if (btn) btn.addEventListener('click', () => {
    if (root.getAttribute('data-theme') === 'light') { root.removeAttribute('data-theme'); localStorage.setItem('theme','dark'); }
    else { root.setAttribute('data-theme','light'); localStorage.setItem('theme','light'); }
    sync();
  });
</script>
</body>
</html>
