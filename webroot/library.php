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
<style>
  :root{--bg:#FAF9F5;--panel:#F0EEE6;--card:#FFFFFF;--ink:#2B2A28;--muted:#6B6862;--line:#E5E2D8;--clay:#D97757;--clay-deep:#C2613F;--ok:#3F7D5B;--err:#B23B3B;}
  *{box-sizing:border-box;margin:0;padding:0;}
  body{font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;background:var(--bg);color:var(--ink);display:flex;min-height:100vh;}
  aside{width:240px;background:var(--panel);border-right:1px solid var(--line);padding:24px 16px;flex-shrink:0;}
  .brand{font-family:ui-serif,Georgia,serif;font-size:20px;font-weight:600;color:var(--clay-deep);padding:0 8px 16px;}
  nav a{display:block;padding:9px 12px;margin-bottom:2px;border-radius:8px;color:var(--muted);text-decoration:none;font-size:14px;}
  nav a:hover{background:#E8E5DA;color:var(--ink);} nav a.active{background:var(--clay);color:#fff;font-weight:500;}
  main{flex:1;padding:28px 32px;}
  h1{font-family:ui-serif,Georgia,serif;font-size:24px;font-weight:600;letter-spacing:-0.4px;}
  .sub{color:var(--muted);font-size:13px;margin:4px 0 20px;}
  .notice{padding:11px 14px;border-radius:9px;font-size:14px;margin-bottom:16px;} .notice.ok{background:#E8F1EC;color:var(--ok);} .notice.err{background:#F6E7E7;color:var(--err);}
  .bar{background:#FBF1D9;color:#8A6D1F;border:1px solid #ECD9A6;padding:11px 14px;border-radius:9px;font-size:14px;margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;}
  button{font:inherit;cursor:pointer;border:none;border-radius:9px;padding:9px 16px;font-size:13px;font-weight:500;background:var(--clay);color:#fff;} button:hover{background:var(--clay-deep);}
  .cardgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:18px;}
  .card{background:var(--card);border:1px solid var(--line);border-radius:14px;overflow:hidden;}
  .card.zip{opacity:.7;}
  .thumb{aspect-ratio:1;display:flex;align-items:center;justify-content:center;padding:16px;}
  .tiletext{color:#fff;font-family:ui-serif,Georgia,serif;font-weight:600;font-size:17px;line-height:1.25;text-align:center;text-shadow:0 1px 3px rgba(0,0,0,.28);overflow:hidden;display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical;}
  .meta{padding:12px 14px;}
  .mname{font-size:14px;font-weight:600;line-height:1.3;margin-bottom:3px;}
  .msub{font-size:12px;color:var(--muted);}
  .badges{margin-top:8px;display:flex;gap:5px;flex-wrap:wrap;}
  .badge{background:var(--panel);border-radius:5px;padding:1px 7px;font-size:10px;font-weight:600;color:var(--muted);}
  .muted{color:var(--muted);} .empty{color:var(--muted);font-size:14px;padding:20px;}
  code{background:var(--panel);padding:1px 5px;border-radius:4px;font-size:12px;}
</style>
</head>
<body>
  <aside>
    <div class="brand">◆ FarFetched</div>
    <nav>
      <a href="home.php">← Sources</a>
      <a href="library.php?src=<?= e($src) ?>" class="active"><?= e($label) ?></a>
      <a href="settings.php">Settings</a>
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
</body>
</html>
