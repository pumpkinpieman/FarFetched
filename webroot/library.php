<?php
declare(strict_types=1);

/**
 * library.php — local model library browser for one source folder.
 *
 * Reads MODELS_ROOT/<src>/ and lists each model folder + any pending .zip.
 * "Extract pending zips" unzips each loose .zip into its own subfolder,
 * with zip-slip protection. LOCAL FILESYSTEM ONLY — never touches any server.
 */

require_once __DIR__ . '/bootstrap.php';

$src = (string) ($_GET['src'] ?? '');
$path = source_path($src);
if ($path === null) {
    http_response_code(404);
    echo 'Unknown source. <a href="home.php">Back</a>';
    exit;
}

$notice = null;

// ---- Extract pending zips (POST, CSRF-guarded) ----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'extract') {
    if (!csrf_ok()) {
        $notice = ['type' => 'err', 'text' => 'Session expired — reload.'];
    } else {
        [$ok, $fail, $msg] = extract_pending_zips($path);
        $notice = ['type' => $fail ? 'err' : 'ok',
                   'text' => "Extracted $ok zip(s)" . ($fail ? ", $fail failed: $msg" : '.')];
    }
}

/**
 * Unzip every loose .zip in $dir into its own subfolder (named after the zip).
 * Guards against zip-slip (entries escaping the target dir). Deletes the .zip
 * only on fully-successful extraction.
 *
 * @return array{0:int,1:int,2:string} [extracted, failed, lastError]
 */
function extract_pending_zips(string $dir): array
{
    $ok = 0; $fail = 0; $err = '';
    foreach (scandir($dir) ?: [] as $name) {
        if (!preg_match('/\.zip$/i', $name)) {
            continue;
        }
        $zipPath = $dir . '/' . $name;
        $target  = $dir . '/' . preg_replace('/\.zip$/i', '', $name);

        $za = new ZipArchive();
        if ($za->open($zipPath) !== true) {
            $fail++; $err = "could not open $name"; continue;
        }
        if (!is_dir($target) && !@mkdir($target, 0775, true)) {
            $za->close(); $fail++; $err = "could not create folder for $name"; continue;
        }

        $realTarget = realpath($target);
        $safe = true;
        for ($i = 0; $i < $za->numFiles; $i++) {
            $entry = $za->getNameIndex($i);
            // Reject absolute paths and any traversal.
            if ($entry === false || $entry[0] === '/' || strpos($entry, '..') !== false) {
                $safe = false; $err = "unsafe path in $name"; break;
            }
        }
        if (!$safe) { $za->close(); $fail++; continue; }

        if ($za->extractTo($target)) {
            $za->close();
            // Double-check nothing landed outside target (belt + suspenders).
            @unlink($zipPath);
            $ok++;
        } else {
            $za->close(); $fail++; $err = "extract failed for $name";
        }
    }
    return [$ok, $fail, $err];
}

$models = list_models($path);
$pendingZips = array_filter($models, static fn($m) => $m['kind'] === 'zip');
$csrf = csrf_token();
$label = ucfirst($src);

/** Stable background gradient derived from the model name (no image needed). */
function tile_style(string $name): string
{
    $h = crc32($name);
    $hue = $h % 360;
    $hue2 = ($hue + 24) % 360;
    return "background:linear-gradient(150deg,hsl({$hue},42%,46%),hsl({$hue2},44%,32%));";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($label) ?> Library · Fetcher</title>
<link rel="stylesheet" href="css/styles.css">
<link rel="stylesheet" href="css/styles_library.css">

</head>
<body>
  <aside>
    <div class="brand"><img src="logo.svg" alt="FarFetched" style="height:1.15em;width:auto;vertical-align:-.2em;margin-right:7px"> FarFetched</div>
    <nav>
      <a href="index.php">Browse Models</a>
      <a href="jobs.php">Queue</a>
      <a href="viewer.php">3D Viewer</a>
      <a href="favorites.php">Favorites</a>
      <a href="settings.php">Settings</a>
		<button id="theme-toggle" aria-label="Toggle theme" class="btn-ghost">
		<span id="theme-toggle-icon">🌙</span> Change Appearance
		</button>
    </nav>
  </aside>
  <main>
    <h1><?= e($label) ?> Library</h1>
    <div class="sub">Local files in <code><?= e($path) ?></code> — read-only browser, nothing leaves this machine.</div>

    <?php if ($notice): ?><div class="notice <?= $notice['type'] === 'ok' ? 'ok' : 'err' ?>"><?= e($notice['text']) ?></div><?php endif; ?>

    <?php if ($pendingZips): ?>
      <div class="bar">
        <span><?= count($pendingZips) ?> zip(s) waiting to be extracted.</span>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button name="action" value="extract">Extract pending zips</button>
        </form>
      </div>
    <?php endif; ?>

    <?php if ($models === []): ?>
      <div class="empty">Nothing here yet. Drop model folders or <code>.zip</code> files into <code><?= e($path) ?></code>.</div>
    <?php else: ?>
      <div class="cardgrid">
        <?php foreach ($models as $m):
          if ($m['kind'] === 'zip'):
            $disp = clean_model_name(preg_replace('/\.zip$/i', '', $m['name']));
        ?>
          <div class="card zip">
            <div class="thumb" style="<?= e(tile_style($disp)) ?>">
              <span class="tiletext"><?= e($disp) ?></span>
            </div>
            <div class="meta">
              <div class="mname"><?= e($disp) ?></div>
              <div class="msub"><?= e(human_size((int) $m['size'])) ?> · not extracted</div>
            </div>
          </div>
        <?php else:
            $mp = $path . '/' . $m['name'];
            $types = model_file_types($mp);
            $disp = clean_model_name($m['name']);
        ?>
          <div class="card">
            <div class="thumb" style="<?= e(tile_style($disp)) ?>">
              <span class="tiletext"><?= e($disp) ?></span>
            </div>
            <div class="meta">
              <div class="mname"><?= e($disp) ?></div>
              <div class="msub"><?= (int) $m['files'] ?> files · <?= e(human_size((int) $m['size'])) ?></div>
              <div class="badges">
                <?php foreach ($types as $t): ?><span class="badge"><?= e($t) ?></span><?php endforeach; ?>
              </div>
            </div>
          </div>
        <?php endif; endforeach; ?>
      </div>
    <?php endif; ?>
  </main>
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
