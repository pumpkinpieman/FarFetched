<?php
declare(strict_types=1);
/**
 * author_backfill.php — one-time, resumable backfill of the per-source author
 * key (`creator_uid`) into meta.json for models downloaded before the key was
 * captured. "More by author" needs this key, not the display name:
 *
 *   MakerWorld  creator_uid = numeric creator uid   (design-service detail)
 *   Printables  creator_uid = user handle slug       (print(id:) { user { handle } })
 *   Creality    creator_uid = numeric userId         (3mfList → userInfo.userId)
 *
 * Design notes:
 *   - Auth + CSRF gated; the run itself is POST.
 *   - Idempotent & resumable: rows that already carry a non-empty creator_uid
 *     are skipped, so re-running only touches stragglers.
 *   - Streaming: processes one folder at a time (constant memory), with a short
 *     inter-request delay so we never hammer a source API.
 *   - Best-effort & defensive: any resolve/JSON/IO failure is logged and skipped;
 *     a single bad model never aborts the run. meta.json is written atomically.
 *   - Rewriting meta.json bumps the folder mtime, invalidating that folder in the
 *     library card-index cache so the new key is picked up on next load.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/MakerWorldService.php';
require_once __DIR__ . '/PrintablesService.php';
require_once __DIR__ . '/CrealityCloudService.php';

if (!auth_check()) {
    header('Location: login.php');
    exit;
}

/** Sources that support author-key resolution, with a small pacing delay (µs). */
const BACKFILL_SOURCES = ['makerworld', 'printables', 'creality'];
const BACKFILL_DELAY_US = 250_000; // 0.25s between resolves — polite to each API

/**
 * Resolve the author key for one model. Pure dispatch; never throws.
 * @return string '' when unresolved.
 */
function backfill_resolve(string $source, string $modelId, array $svc): string
{
    try {
        switch ($source) {
            case 'makerworld':
                return $svc['makerworld']->creatorUidForModel($modelId);
            case 'printables':
                return $svc['printables']->authorHandleForModel($modelId);
            case 'creality':
                return $svc['creality']->authorIdForModel($modelId);
        }
    } catch (\Throwable $e) {
        ff_log('warn', "author_backfill: resolve failed [$source/$modelId]: " . $e->getMessage());
    }
    return '';
}

/** Atomically write JSON to a path (temp file + rename). Returns success. */
function backfill_write_json(string $path, array $data): bool
{
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }
    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

$isRun = ($_SERVER['REQUEST_METHOD'] === 'POST');
$csrfOk = $isRun ? csrf_ok() : true;

header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Author Key Backfill · FarFetched</title>
<style>
  body{font:15px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#14171c;color:#e6e6e6;margin:0;padding:32px}
  .wrap{max-width:820px;margin:0 auto}
  h1{font-size:20px;margin:0 0 6px}
  p{color:#a9b0ba}
  .btn{display:inline-block;background:#c8752e;color:#fff;border:0;border-radius:8px;padding:10px 16px;font-size:15px;cursor:pointer;text-decoration:none}
  code{background:#0e1116;border:1px solid #262b33;border-radius:5px;padding:1px 5px;font-family:ui-monospace,Menlo,Consolas,monospace}
  .log{margin-top:18px;background:#0e1116;border:1px solid #262b33;border-radius:8px;padding:14px;font:13px/1.55 ui-monospace,Menlo,Consolas,monospace;white-space:pre-wrap;max-height:62vh;overflow:auto}
  .ok{color:#7fd18a}.skip{color:#8a93a0}.warn{color:#e0b64a}.done{color:#7fd18a;font-weight:600}
  a.back{color:#c8752e}
</style>
</head><body><div class="wrap">
<h1>Author Key Backfill</h1>
<?php if (!$isRun): ?>
  <p>Resolves the per-source author key for models downloaded before key capture existed, so <strong>“More by author”</strong> works from your library. Covers <code>makerworld</code>, <code>printables</code>, and <code>creality</code>. Only touches models missing a key, and is safe to re-run.</p>
  <p><a class="back" href="library.php">← Back to library</a></p>
  <form method="post" style="margin-top:14px">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
    <button class="btn" type="submit">Run backfill</button>
  </form>
<?php else: ?>
  <?php if (!$csrfOk): ?>
    <p class="warn">Session expired — <a class="back" href="author_backfill.php">reload and try again</a>.</p>
  <?php else: ?>
  <p><a class="back" href="library.php">← Back to library</a></p>
  <div class="log"><?php
    @set_time_limit(0);
    while (ob_get_level() > 0) { ob_end_flush(); }
    ob_implicit_flush(true);

    $svc = [
        'makerworld' => new MakerWorldService(),
        'printables' => new PrintablesService(),
        'creality'   => new CrealityCloudService(),
    ];

    $totScanned = $totFilled = $totSkipped = $totFailed = 0;

    foreach (BACKFILL_SOURCES as $source) {
        $root = source_path($source);
        if ($root === null || !is_dir($root)) {
            echo '<span class="skip">— ' . htmlspecialchars($source) . ": no downloads —</span>\n";
            continue;
        }
        echo '<strong>' . htmlspecialchars($source) . "</strong>\n";

        foreach (list_model_dirs($root) as $folder) {
            $metaPath = $root . '/' . $folder . '/.farfetched/meta.json';
            if (!is_file($metaPath)) {
                continue;
            }
            $totScanned++;

            $meta = json_decode((string) @file_get_contents($metaPath), true);
            if (!is_array($meta)) {
                $totFailed++;
                echo '<span class="warn">  ! ' . htmlspecialchars($folder) . " — unreadable meta.json</span>\n";
                continue;
            }
            if (trim((string) ($meta['creator_uid'] ?? '')) !== '') {
                $totSkipped++;
                continue; // already has a key
            }
            $modelId = trim((string) ($meta['model_id'] ?? ''));
            if ($modelId === '') {
                $totSkipped++;
                continue;
            }

            $key = backfill_resolve($source, $modelId, $svc);
            usleep(BACKFILL_DELAY_US);

            if ($key === '') {
                $totFailed++;
                echo '<span class="skip">  · ' . htmlspecialchars($folder) . " — unresolved</span>\n";
                continue;
            }

            $meta['creator_uid'] = $key;
            if (backfill_write_json($metaPath, $meta)) {
                $totFilled++;
                echo '<span class="ok">  ✓ ' . htmlspecialchars($folder) . ' → ' . htmlspecialchars($key) . "</span>\n";
            } else {
                $totFailed++;
                echo '<span class="warn">  ! ' . htmlspecialchars($folder) . " — write failed (permissions?)</span>\n";
            }
        }
    }

    echo "\n" . '<span class="done">Done. Scanned ' . $totScanned
       . ', filled ' . $totFilled
       . ', already-had/blank ' . $totSkipped
       . ', unresolved ' . $totFailed . '.</span>';
    ff_log('info', "author_backfill: scanned=$totScanned filled=$totFilled skipped=$totSkipped failed=$totFailed");
  ?></div>
  <?php endif; ?>
<?php endif; ?>
</div></body></html>
