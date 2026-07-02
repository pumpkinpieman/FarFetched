<?php
declare(strict_types=1);
/**
 * mw_backfill.php — one-time backfill of MakerWorld creator uids into the
 * meta.json of models that were downloaded before the uid was captured.
 *
 * MakerWorld author search is keyed by the creator's numeric uid (the name is
 * not a valid search key). New downloads now store `creator_uid` in meta.json;
 * this script fills it in for older ones by resolving each model_id -> uid via
 * the MakerWorld design-service detail endpoint.
 *
 * Behaviour:
 *   - Auth required; the run itself is POST + CSRF.
 *   - Idempotent and resumable: models that already have a creator_uid are
 *     skipped, so re-running only touches the stragglers.
 *   - Rewriting meta.json bumps the folder's .farfetched mtime, which invalidates
 *     that folder in the library card-index cache so the new uid is picked up.
 *   - Best-effort: a model whose uid can't be resolved is left untouched.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/MakerWorldService.php';

if (!auth_check()) {
    header('Location: login.php');
    exit;
}

$mwPath = source_path('makerworld');
if ($mwPath === null || !is_dir($mwPath)) {
    $mwPath = defined('MAKERWORLD_DOWNLOAD_DIR') ? MAKERWORLD_DOWNLOAD_DIR : '';
}

$isRun = ($_SERVER['REQUEST_METHOD'] === 'POST');

header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MakerWorld UID Backfill · FarFetched</title>
<style>
  body{font:15px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#14171c;color:#e6e6e6;margin:0;padding:32px}
  .wrap{max-width:760px;margin:0 auto}
  h1{font-size:20px;margin:0 0 6px}
  p{color:#a9b0ba}
  .btn{display:inline-block;background:#c8752e;color:#fff;border:0;border-radius:8px;padding:10px 16px;font-size:15px;cursor:pointer;text-decoration:none}
  .btn:disabled{opacity:.6;cursor:default}
  .log{margin-top:18px;background:#0e1116;border:1px solid #262b33;border-radius:8px;padding:14px;font:13px/1.55 ui-monospace,Menlo,Consolas,monospace;white-space:pre-wrap;max-height:60vh;overflow:auto}
  .ok{color:#7fd18a}.skip{color:#8a93a0}.warn{color:#e0b64a}.done{color:#7fd18a;font-weight:600}
  a.back{color:#c8752e}
</style>
</head><body><div class="wrap">
<h1>MakerWorld UID Backfill</h1>
<?php if (!$isRun): ?>
  <p>This resolves the MakerWorld creator uid for models downloaded before uid capture existed, so “More by author” works from your library. It only touches MakerWorld models that don’t already have a uid, and can be safely re-run.</p>
  <p><a class="back" href="library.php">← Back to library</a></p>
  <form method="post" style="margin-top:14px">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
    <button class="btn" type="submit">Run backfill</button>
  </form>
<?php
    echo '</div></body></html>';
    exit;
endif;

// ---- Run -------------------------------------------------------------------
if (!csrf_ok()) {
    echo '<p class="warn">Invalid CSRF token. <a class="back" href="mw_backfill.php">Try again</a>.</p></div></body></html>';
    exit;
}

@set_time_limit(0);
@ini_set('output_buffering', '0');
while (ob_get_level() > 0) { @ob_end_flush(); }

echo '<div class="log" id="log">';
$flush = static function (string $html): void {
    echo $html . "\n";
    @flush();
};

if ($mwPath === '' || !is_dir($mwPath)) {
    $flush('<span class="warn">No MakerWorld library folder found. Nothing to do.</span>');
    echo '</div></div></body></html>';
    exit;
}

$mw = new MakerWorldService();

$dirs      = list_model_dirs($mwPath);
$total     = count($dirs);
$scanned   = 0;
$filled    = 0;
$already   = 0;
$noId      = 0;
$unresolved = 0;

$flush(sprintf('<span class="skip">Scanning %d MakerWorld folder%s in %s</span>',
    $total, $total === 1 ? '' : 's', htmlspecialchars($mwPath, ENT_QUOTES)));

foreach ($dirs as $name) {
    if ($name === '' || $name[0] === '.' || $name === '_processed' || $name === '_processing') {
        continue;
    }
    $scanned++;
    $metaFile = $mwPath . '/' . $name . '/.farfetched/meta.json';
    if (!is_file($metaFile)) {
        continue;
    }
    $meta = json_decode((string) @file_get_contents($metaFile), true);
    if (!is_array($meta)) {
        continue;
    }
    if (trim((string) ($meta['creator_uid'] ?? '')) !== '') {
        $already++;
        continue;
    }
    $modelId = trim((string) ($meta['model_id'] ?? ''));
    if ($modelId === '') {
        $noId++;
        $flush('<span class="skip">– ' . htmlspecialchars($name, ENT_QUOTES) . ': no model_id, skipped</span>');
        continue;
    }

    $uid = $mw->creatorUidForModel($modelId);
    if ($uid === '' || !ctype_digit($uid)) {
        $unresolved++;
        $flush('<span class="warn">? ' . htmlspecialchars($name, ENT_QUOTES) . ': uid not resolved (model ' . htmlspecialchars($modelId, ENT_QUOTES) . ')</span>');
        usleep(250000);
        continue;
    }

    $meta['creator_uid'] = $uid;
    $ok = @file_put_contents(
        $metaFile,
        json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
    );
    if ($ok !== false) {
        $filled++;
        $flush('<span class="ok">✓ ' . htmlspecialchars($name, ENT_QUOTES) . ' → uid ' . htmlspecialchars($uid, ENT_QUOTES) . '</span>');
    } else {
        $unresolved++;
        $flush('<span class="warn">! ' . htmlspecialchars($name, ENT_QUOTES) . ': could not write meta.json (permissions?)</span>');
    }
    usleep(250000); // be gentle on the MakerWorld API
}

$flush(sprintf(
    '<span class="done">Done. Filled %d, already had %d, no id %d, unresolved %d (of %d folders).</span>',
    $filled, $already, $noId, $unresolved, $scanned
));
$flush('<span class="skip">Reload your library — the card cache re-indexes touched folders automatically.</span>');

echo '</div>';
echo '<p style="margin-top:14px"><a class="back" href="library.php">← Back to library</a></p>';
echo '</div></body></html>';
