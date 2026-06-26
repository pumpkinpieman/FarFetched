<?php
/**
 * source_thumbs_backfill.php — fetch & save each model's source cover image as
 * <model>/.farfetched/source.png, so My Library can show the site's own thumbnail
 * instead of (or before) a generated STL render.
 *
 * Runs in two modes:
 *
 *   CLI:
 *     docker exec FarFetched php /var/www/html/webroot/source_thumbs_backfill.php
 *     flags: --source=<slug>   limit to one source
 *            --force           re-save even if source.png already exists
 *            --limit=N         stop after N saves (default: all)
 *
 *   AJAX (from Settings button): POST with csrf, optional source, force.
 *     Returns JSON {ok, scanned, saved, skipped, failed, details:[]}.
 *
 * Cover URLs come from the download_jobs.cover_url captured at enqueue time. A
 * model with no stored cover URL is skipped (nothing to fetch) — those can still
 * use the generated render. Only sources with the per-source toggle ON are
 * processed unless --source explicitly names one.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * Resolve a model's cover image URL directly from its source, for models that
 * predate cover_url capture. Only sources with a clean single-model lookup are
 * supported; others return '' (those models keep using generated renders).
 * Services are lazily constructed once and reused across the run.
 */
function backfill_resolve_cover(string $src, string $modelId, array &$svcCache): string
{
    if ($modelId === '') return '';
    try {
        switch ($src) {
            case 'printables':
                if (!isset($svcCache['printables'])) {
                    require_once __DIR__ . '/PrintablesService.php';
                    $svcCache['printables'] = new PrintablesService();
                }
                return (string) $svcCache['printables']->coverForModel($modelId);
            case 'thingiverse':
                if (!isset($svcCache['thingiverse'])) {
                    require_once __DIR__ . '/ThingiverseService.php';
                    $svcCache['thingiverse'] = new ThingiverseService();
                }
                return (string) $svcCache['thingiverse']->coverForModel($modelId);
            case 'makerworld':
                if (!isset($svcCache['makerworld'])) {
                    require_once __DIR__ . '/MakerWorldService.php';
                    $svcCache['makerworld'] = new MakerWorldService();
                }
                return (string) $svcCache['makerworld']->coverForModel($modelId);
            // creality, stlflix, cults3d, nikko, hex3dforum: no clean per-model
            // cover lookup — skip (new downloads still capture cover_url).
            default:
                return '';
        }
    } catch (\Throwable $e) {
        return '';
    }
}

$IS_CLI = (PHP_SAPI === 'cli');

if (!$IS_CLI) {
    require_once __DIR__ . '/auth.php';
    if (!auth_check()) { http_response_code(401); header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>'auth required']); exit; }
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST required']); exit; }
    $csrf = (string) ($_POST['csrf'] ?? '');
    if (empty($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], $csrf)) {
        http_response_code(419); echo json_encode(['ok'=>false,'error'=>'CSRF check failed — reload the page.']); exit;
    }
}

// ---- Options -------------------------------------------------------------
$onlySource = '';
$force      = false;
$limit      = 0;

if ($IS_CLI) {
    foreach (array_slice($argv, 1) as $a) {
        if (str_starts_with($a, '--source=')) $onlySource = strtolower(trim(substr($a, 9)));
        elseif ($a === '--force')             $force = true;
        elseif (str_starts_with($a, '--limit=')) $limit = max(0, (int) substr($a, 8));
    }
} else {
    $onlySource = strtolower(trim((string) ($_POST['source'] ?? '')));
    $force      = !empty($_POST['force']);
    $limit      = max(0, (int) ($_POST['limit'] ?? 0));
}

// ---- Which sources to process -------------------------------------------
$allSources = ['printables','makerworld','thingiverse','cults3d','stlflix','creality','nikko','hex3dforum'];
if ($onlySource !== '') {
    if (!in_array($onlySource, $allSources, true)) {
        $msg = 'Unknown source: ' . $onlySource;
        if ($IS_CLI) { fwrite(STDERR, $msg . "\n"); exit(1); }
        echo json_encode(['ok'=>false,'error'=>$msg]); exit;
    }
    $sources = [$onlySource];
} else {
    // Only sources with the toggle ON.
    $sources = array_values(array_filter($allSources, 'source_thumbs_on'));
}

$scanned = 0; $saved = 0; $skipped = 0; $failed = 0; $resolved = 0;
$details = [];
$svcCache = [];

function out(string $s): void { if (PHP_SAPI === 'cli') echo $s . "\n"; }

if ($sources === []) {
    $msg = 'No sources have "use source thumbnails" enabled. Turn one on in Settings, or pass --source=<slug>.';
    out($msg);
    if (!$IS_CLI) echo json_encode(['ok'=>true,'scanned'=>0,'saved'=>0,'skipped'=>0,'failed'=>0,'note'=>$msg]);
    exit;
}

// Sources that can resolve a cover by model id when none was stored at download
// time, verified against the live APIs:
//   makerworld  - design-service endpoint returns coverUrl
//   thingiverse - /things/{id}/images returns the cover
// Printables is intentionally excluded: print(id:) returns null, introspection
// is blocked (HTTP 400), and search can't find a model by its own id. Creality,
// STLFlix, Cults3D have no per-model cover endpoint. Those sources still capture
// cover_url at DOWNLOAD time, so only OLD pre-capture models keep renders.
$resolvable = ['makerworld', 'thingiverse'];

$pdo = db();

foreach ($sources as $src) {
    $base = MODELS_ROOT . '/' . $src;
    if (!is_dir($base)) { out("[$src] no models dir, skipping"); continue; }

    // Map model_id -> cover_url from the jobs table (most recent non-empty wins).
    // We match against the on-disk folder by its leading model_id, since folders
    // are named "<model_id> - <name>" (or just "<model_id>"). This avoids needing
    // the worker's model_folder()/safe_segment() helpers here.
    $covers = [];
    try {
        $rows = $pdo->query("SELECT model_id, cover_url FROM download_jobs WHERE source = " . $pdo->quote($src) . " AND cover_url <> ''");
        foreach ($rows as $r) {
            $mid = (string) $r['model_id'];
            if ($mid !== '') $covers[$mid] = (string) $r['cover_url'];
        }
    } catch (\Throwable $e) { /* table may predate cover_url; nothing to map */ }

    $dirs = @scandir($base) ?: [];
    foreach ($dirs as $folder) {
        if ($folder === '.' || $folder === '..' || $folder[0] === '.') continue;
        $modelDir = $base . '/' . $folder;
        if (!is_dir($modelDir)) continue;
        $scanned++;

        $existing = $modelDir . '/.farfetched/source.png';
        if (!$force && is_file($existing)) { $skipped++; continue; }

        // Extract the leading model_id from the folder name ("<id> - <name>").
        $mid = $folder;
        $dashPos = strpos($folder, ' - ');
        if ($dashPos !== false) $mid = substr($folder, 0, $dashPos);

        $cover = $covers[$mid] ?? ($covers[$folder] ?? '');
        $viaResolver = false;
        if ($cover === '' && in_array($src, $resolvable, true)) {
            // No stored cover URL (pre-dates capture) — ask the source directly.
            $cover = backfill_resolve_cover($src, $mid, $svcCache);
            if ($cover !== '') { $viaResolver = true; $resolved++; }
        }
        if ($cover === '') { $skipped++; continue; } // nothing to fetch

        if (save_source_thumb($modelDir, $cover)) {
            $saved++;
            $details[] = "$src/$folder";
            out("  saved: $src/$folder" . ($viaResolver ? '  (resolved)' : ''));
        } else {
            $failed++;
            out("  FAILED: $src/$folder  ($cover)");
        }

        // Pace resolver lookups so we don't hammer a source's API. Stored-cover
        // saves (CDN image fetches) are fine at full speed; only the API lookups
        // get a short delay.
        if ($viaResolver) usleep(600000); // ~0.6s between source API calls

        if ($limit > 0 && $saved >= $limit) { out("limit $limit reached"); break 2; }
    }
}

$summary = "scanned=$scanned saved=$saved (resolved=$resolved) skipped=$skipped failed=$failed";
out("Done. $summary");

if (!$IS_CLI) {
    echo json_encode([
        'ok'       => true,
        'scanned'  => $scanned,
        'saved'    => $saved,
        'resolved' => $resolved,
        'skipped'  => $skipped,
        'failed'   => $failed,
        'details'  => array_slice($details, 0, 100),
    ]);
}
