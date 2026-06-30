<?php
declare(strict_types=1);

/**
 * worker.php — paced, resumable download worker. CLI ONLY.
 *
 * Run from cron on Local every 5 minutes (see deploy/crontab for the exact
 * schedule line; it runs worker.php and appends output to private/worker.log).
 *
 * Behavior:
 *   - Single instance enforced via a lock file (cron overlap = safe no-op).
 *   - Pulls 'queued' jobs one at a time, oldest first.
 *   - For each: resolve files -> get signed link -> download to <dir>/<slug>/.
 *   - Sleeps DOWNLOAD_DELAY_SECONDS between files (your pacing rule).
 *   - Marks done/failed; failures are retried on later runs (attempts capped).
 *   - Resumable: kill it anytime; queued/failed rows survive in SQLite.
 *
 * Resilience:
 *   - 401/403 (dead token) halts the run cleanly so you re-auth, rather than
 *     burning every job to 'failed'.
 *   - Per-file try/catch; one bad model never aborts the queue.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("worker.php is CLI-only.\n");
}

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/PrintablesService.php';
require_once __DIR__ . '/MakerWorldService.php';
require_once __DIR__ . '/ThingiverseService.php';
require_once __DIR__ . '/Cults3DService.php';
require_once __DIR__ . '/STLFlixService.php';
require_once __DIR__ . '/CrealityCloudService.php';
require_once __DIR__ . '/NikkoService.php';
require_once __DIR__ . '/Hex3DForumService.php';

// Auto-correct root-created file ownership on exit (no-op unless run as root).
// The worker is the main file creator; when launched via `docker exec -d` it
// runs as root, so this keeps downloaded models owned by www-data.
cli_fix_ownership_after_root();

// Retry cap now comes from the UI-editable config (clamped in cfg_save).
$maxAttempts = (int) cfg('max_attempts');
const LOCK_FILE      = PRIVATE_DIR . '/worker.lock';
const MAX_RUN_SECONDS = 6 * 3600; // safety ceiling per invocation

function logln(string $m): void
{
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $m . "\n");
}

/** Log a line to stdout AND the curated event log the UI surfaces. */
function logerr(string $level, string $m): void
{
    logln($m);
    ff_log($level, $m);
}

/**
 * Classify a failed/empty download into a specific status so the queue isn't a
 * vague "skipped" pile. Returns one of:
 *   'paywalled' — model is behind payment / requires purchase / free-order
 *   'no_files'  — no downloadable STL/3MF/pack exists for the model
 *   'error'     — anything else (corruption, transient, unexpected)
 * The message is matched case-insensitively against known source phrasings.
 */
function classify_skip(string $msg): string
{
    $m = strtolower($msg);
    // Paywalled / purchase-required.
    foreach (['paid', 'purchase', 'free-order', 'behind a pay', 'payment', 'buy ', 'premium', 'subscriber', 'patreon'] as $p) {
        if (strpos($m, $p) !== false) return 'paywalled';
    }
    // No files available (but not an error — just nothing to fetch).
    foreach (['no files', 'no download', 'no stl', 'no 3mf', 'no printable', 'no downloadable', 'not available', 'no packs'] as $p) {
        if (strpos($m, $p) !== false) return 'no_files';
    }
    return 'error';
}

/** Atomically write the worker's live status (read by jobs_status.php). */
function write_worker_status(array $s): void
{
    $s['updated'] = microtime(true);
    $tmp = WORKER_STATUS . '.tmp';
    if (@file_put_contents($tmp, json_encode($s)) !== false) {
        @rename($tmp, WORKER_STATUS);
    }
}

/**
 * Build a throttled progress callback for downloadToFile(). Writes at most a
 * few times/sec so the status file isn't churned on every curl tick.
 */
function progress_writer(int $jobId, string $file): callable
{
    $last = 0.0;
    return static function (int $total, int $now, int $fileNum = 0, int $fileTotal = 0) use ($jobId, $file, &$last): void {
        $t = microtime(true);
        if ($now > 0 && $now < $total && ($t - $last) < 0.30) {
            return;
        }
        $last = $t;
        $s = [
            'phase'  => 'downloading',
            'job_id' => $jobId,
            'file'   => $file,
            'bytes'  => $now,
            'total'  => $total,
        ];
        if ($fileNum > 0)    $s['file_num']   = $fileNum;
        if ($fileTotal > 0)  $s['file_total'] = $fileTotal;
        write_worker_status($s);
    };
}

// ---- Single-instance lock -------------------------------------------------
$lock = fopen(LOCK_FILE, 'c+');
if ($lock === false) {
    logln('FATAL: cannot open lock file.');
    exit(1);
}
// Primary guard: flock advisory lock (works on local filesystems).
$gotFlock = flock($lock, LOCK_EX | LOCK_NB);

// FUSE-resilient secondary guard: flock() is unreliable on FUSE/network mounts
// (Unraid shfs), so a second worker can slip past flock and run concurrently —
// causing "database is locked" contention. Back flock with a PID-liveness check:
// the lockfile stores the running worker's PID; if that PID is still alive, this
// instance stands down regardless of what flock reported.
$existingPid = 0;
$raw = stream_get_contents($lock);
if (is_string($raw) && trim($raw) !== '') {
    $existingPid = (int) trim($raw);
}
$pidAlive = static function (int $pid): bool {
    if ($pid <= 0) return false;
    if (is_dir('/proc/' . $pid)) return true;                 // Linux: /proc check
    if (function_exists('posix_kill')) return @posix_kill($pid, 0); // signal-0 probe
    return false;
};

if (!$gotFlock || ($existingPid > 0 && $existingPid !== getmypid() && $pidAlive($existingPid))) {
    logln('[info] Worker already running (pid ' . ($existingPid ?: '?')
        . ') — this instance is standing down (queue is being handled).');
    exit(0);
}

// We own the lock — record our PID so other instances can detect us even if
// flock is unreliable on this filesystem.
ftruncate($lock, 0);
rewind($lock);
fwrite($lock, (string) getmypid());
fflush($lock);


$svc    = new PrintablesService();
$mw     = new MakerWorldService();
$tv     = new ThingiverseService();
$cults  = new Cults3DService();
$stlfix = new STLFlixService();
$creality = new CrealityCloudService();
$nikko  = new NikkoService();
$hex3dforum = new Hex3DForumService();

// Per-source readiness. A source that isn't configured simply has its jobs wait
// in the queue (they're filtered out of the claim query below) rather than
// failing — so a MakerWorld-only or Printables-only setup both work.
$printablesReady = false;
if ($svc->isAuthed() && $svc->ensureFreshToken()) {
    $printablesReady = true;
    $ts = token_status();
    logln('Printables token OK — access valid ' . human_duration((int) ($ts['seconds'] ?? 0)) . '.');
} else {
    logln('Printables not ready (' . ($svc->lastError !== '' ? $svc->lastError : 'no token') . ') — Printables jobs will wait.');
}

$makerworldReady = $mw->isAuthed();
logln($makerworldReady
    ? 'MakerWorld token present.'
    : 'MakerWorld token absent — MakerWorld jobs will wait.');

$thingiverseReady = $tv->isAuthed();
logln($thingiverseReady
    ? 'Thingiverse token present.'
    : 'Thingiverse token absent — Thingiverse jobs will wait.');

$cultsReady = $cults->isAuthed();
logln($cultsReady
    ? 'Cults3D credentials present.'
    : 'Cults3D credentials absent — Cults3D jobs will wait.');

$stlflixReady = $stlfix->isAuthed();
$crealityReady = $creality->isAuthed();
$nikkoReady = $nikko->isAuthed();
$hex3dforumReady = $hex3dforum->isAuthed();
logln($stlflixReady
    ? 'STLFlix token present.'
    : 'STLFlix token absent — STLFlix jobs will wait.');
logln($nikkoReady
    ? 'Nikko Industries session cookie present.'
    : 'Nikko Industries session cookie absent — Nikko jobs will wait.');
logln($hex3dforumReady
    ? 'Hex3D Forum session cookie present.'
    : 'Hex3D Forum session cookie absent — Hex3D Forum jobs will wait.');

$readySources = [];
if ($printablesReady)  { $readySources[] = 'printables'; }
if ($makerworldReady)  { $readySources[] = 'makerworld'; }
if ($thingiverseReady) { $readySources[] = 'thingiverse'; }
if ($cultsReady)       { $readySources[] = 'cults3d'; }
if ($stlflixReady)     { $readySources[] = 'stlflix'; }
if ($crealityReady)    { $readySources[] = 'creality'; }
if ($nikkoReady)       { $readySources[] = 'nikko'; }
if ($hex3dforumReady)  { $readySources[] = 'hex3dforum'; }

if ($readySources === []) {
    logerr('error', 'No source configured — paste at least one token in Settings. Exiting.');
    exit(0);
}

if (cfg('paused') === true) {
    logln('Downloads paused via Settings — exiting without processing.');
    exit(0);
}

$baseDir = get_download_dir();
if (!is_dir($baseDir) && !@mkdir($baseDir, 0777, true) && !is_dir($baseDir)) {
    logln('FATAL: download dir not creatable: ' . $baseDir);
    exit(1);
}
if (!is_writable($baseDir)) {
    logln('FATAL: download dir not writable: ' . $baseDir . ' (fix directory permissions on the local array / shared folder so the container user can write).');
    exit(1);
}

// Pre-create all source subdirectories at startup.
// If any fail, log a clear warning — jobs for that source will error out.
$sourceDirs = [
    'printables'  => $baseDir,
    'makerworld'  => get_makerworld_dir(),
    'thingiverse' => get_thingiverse_dir(),
    'cults3d'     => get_cults3d_dir(),
    'stlflix'     => get_stlflix_dir(),
    'creality'    => get_creality_dir(),
    'nikko'       => get_nikko_dir(),
    'hex3dforum'  => get_hex3dforum_dir(),
];
foreach ($sourceDirs as $srcName => $srcDir) {
    if ($srcDir === '') continue;
    if (!is_dir($srcDir)) {
        if (!@mkdir($srcDir, 0777, true) && !is_dir($srcDir)) {
            logln('WARN: Cannot create ' . $srcName . ' dir: ' . $srcDir . ' — check directory permissions on the local array / shared folder so the container user can write to ' . dirname($srcDir) . '.');
        } else {
            logln('Created ' . $srcName . ' dir: ' . $srcDir);
        }
    }
    if (is_dir($srcDir) && !is_writable($srcDir)) {
        logln('WARN: ' . $srcName . ' dir not writable: ' . $srcDir . ' — jobs for this source will fail.');
    }
}

$pdo = db();

// Auto-unstick: any 'working' row at startup is orphaned (a prior worker died
// mid-job). The single-instance lock guarantees no live worker owns it, so
// requeue it for a clean retry.
$unstuck = $pdo->exec("UPDATE download_jobs SET status='queued' WHERE status='working'");
if ($unstuck) { logln("Requeued $unstuck orphaned 'working' job(s)."); }

$startedAt = time();
$processed = 0;

logln('Worker start. dir=' . $baseDir . ' delay=' . DOWNLOAD_DELAY_SECONDS . 's');

while (true) {
    if (time() - $startedAt > MAX_RUN_SECONDS) {
        logln('Run-time ceiling hit — exiting (cron will resume).');
        break;
    }

    // Claim the next job atomically: select oldest queued, flip to working.
    // Only jobs whose source is configured/ready are eligible — others wait.
    $inHolders = implode(',', array_fill(0, count($readySources), '?'));
    $claimStmt = db()->prepare(
        "SELECT * FROM download_jobs
         WHERE status = 'queued' AND source IN ($inHolders)
         ORDER BY id ASC LIMIT 1"
    );
    $claimStmt->execute($readySources);
    $job = $claimStmt->fetch();

    if (!$job) {
        logln('Queue empty. Processed ' . $processed . ' job(s) this run.');
        break;
    }

    $jobId   = (int) $job['id'];
    $GLOBALS['ACTIVE_JOB_ID'] = $jobId;
    $modelId = (string) $job['model_id'];
    $fileTy  = (string) $job['file_type'];
    $slug    = $job['slug'] !== '' ? $job['slug'] : $modelId;
    $name    = (string) ($job['name'] ?? '');

    // Both job-status writers now delegate to db_exec_retry(), which serializes
    // every write through the cross-platform tmpfs mutex (db_write_lock) and
    // applies the busy_timeout/retry backstop. This is what prevents the
    // "database is locked" crashes on FUSE/network mounts where SQLite's own
    // locking is unreliable — all writers (this worker, a cron-launched worker,
    // the crawler, Apache) queue on one flock instead of colliding.
    $updJob = static function (string $st, int $inc, string $err, string $path, int $id): void {
        db_exec_retry(
            "UPDATE download_jobs
             SET status = :st, attempts = attempts + :inc, last_error = :err,
                 saved_path = :path, updated_at = datetime('now')
             WHERE id = :id",
            [':st' => $st, ':inc' => $inc, ':err' => $err, ':path' => $path, ':id' => $id],
            12
        );
    };
    $jobCoverUrl = (string) ($job['cover_url'] ?? '');
    $jobSlug     = (string) ($job['source'] ?? '');
    $jobCreator  = (string) ($job['creator'] ?? '');
    $jobName     = (string) ($job['name'] ?? '');
    $jobModelId  = (string) ($job['model_id'] ?? '');
    $jobCollection = (string) ($job['collection_name'] ?? '');
    $upd = new class($jobCoverUrl, $jobSlug, $jobCreator, $jobName, $jobModelId, $jobCollection) {
        private string $coverUrl;
        private string $slug;
        private string $creator;
        private string $mname;
        private string $modelId;
        private string $collection;
        public function __construct(string $coverUrl, string $slug, string $creator, string $mname, string $modelId, string $collection) {
            $this->coverUrl = $coverUrl;
            $this->slug = $slug;
            $this->creator = $creator;
            $this->mname = $mname;
            $this->modelId = $modelId;
            $this->collection = $collection;
        }
        public function execute(array $p): void {
            db_exec_retry(
                "UPDATE download_jobs
                 SET status = :st, attempts = attempts + :inc, last_error = :err,
                     saved_path = :path, updated_at = datetime('now')
                 WHERE id = :id",
                [
                    ':st'   => (string) ($p[':st']   ?? ''),
                    ':inc'  => (int)    ($p[':inc']  ?? 0),
                    ':err'  => (string) ($p[':err']  ?? ''),
                    ':path' => (string) ($p[':path'] ?? ''),
                    ':id'   => (int)    ($p[':id']   ?? 0),
                ],
                12
            );
            // On successful completion, save the source cover as source.png so
            // My Library shows the real image instead of a generated render —
            // when the source has source-thumbnails enabled and we captured a
            // cover URL at enqueue. Best-effort: never blocks or fails the job.
            if (($p[':st'] ?? '') === 'done') {
                $path = (string) ($p[':path'] ?? '');
                $dir = is_dir($path) ? $path : dirname($path);
                if ($dir !== '' && is_dir($dir)) {
                    if ($this->coverUrl !== '' && $this->slug !== ''
                        && function_exists('source_thumbs_on') && source_thumbs_on($this->slug)) {
                        @save_source_thumb($dir, $this->coverUrl);
                    }
                    // Persist model metadata (creator, source, id) so My Library
                    // can show a clickable author. Best-effort.
                    $meta = $dir . '/.farfetched';
                    if (!is_dir($meta)) @mkdir($meta, 0777, true);
                    if (is_dir($meta)) {
                        @file_put_contents($meta . '/meta.json', json_encode([
                            'source'   => $this->slug,
                            'model_id' => $this->modelId,
                            'creator'  => $this->creator,
                            'name'     => $this->mname,
                            'saved_at' => date('c'),
                        ], JSON_UNESCAPED_SLASHES));
                    }
                    // Add to a collection if the import requested one (matched by
                    // name to an existing collection id). Folder is known now.
                    if ($this->collection !== '') {
                        try {
                            $cid = db()->prepare('SELECT id FROM collections WHERE name = :n LIMIT 1');
                            $cid->execute([':n' => $this->collection]);
                            $collectionId = (int) $cid->fetchColumn();
                            if ($collectionId > 0) {
                                $folder = basename($dir);
                                db_exec_retry(
                                    'INSERT OR IGNORE INTO collection_items (collection_id, source, folder) VALUES (:c, :s, :f)',
                                    [':c' => $collectionId, ':s' => $this->slug, ':f' => $folder],
                                    8
                                );
                            }
                        } catch (\Throwable $e) { /* non-fatal */ }
                    }
                }
            }
        }
    };

    // Mark working.
    db_exec_retry("UPDATE download_jobs SET status='working', updated_at=datetime('now') WHERE id = :id", [':id' => $jobId]);

    // Release the DB connection for the duration of the download. Downloads can
    // take many seconds; holding the SQLite handle open the whole time would
    // block the UI's writes. Status writes below go through db_exec_retry(),
    // which reconnects on demand — so the DB is only held for the brief writes,
    // never across the network download itself.
    db_disconnect();

    logln("Job #$jobId  model=$modelId  type=$fileTy  ($slug)");

    // ---- MakerWorld: whole-model ZIP (STL or 3MF per job file_type) ----------
    if (($job['source'] ?? 'printables') === 'makerworld') {
        try {
            $link = $mw->getModelZipLink($modelId, $fileTy);
            $privateNote = '';
            // Parametric models whose SOURCE file is private return no combined
            // pack. MakerWorld still serves the printable STL/3MF instances — fall
            // back to those and warn, rather than skipping the whole model.
            if ($link === '' && $mw->looksPrivateSource()) {
                $link2 = $mw->getInstanceFileLink($modelId);
                if ($link2 !== '') {
                    $link = $link2;
                    $privateNote = $mw->sourcePrivateNote;
                    logerr('warn', '  MakerWorld: ' . $privateNote);
                }
            }
            if ($link === '') {
                $msg = $mw->lastError !== '' ? $mw->lastError : 'No MakerWorld download available.';
                // Auth-ish failures halt the run (so the user re-pastes the token);
                // requeue this job so nothing is lost.
                if (stripos($msg, 'token') !== false || stripos($msg, 'log in') !== false
                    || preg_match('/\b(401|403)\b/', $msg)) {
                    $upd->execute([':st' => 'queued', ':inc' => 0, ':err' => $msg, ':path' => '', ':id' => $jobId]);
                    logerr('error', '  MakerWorld auth issue — halting. Re-paste token in Settings.');
                    break;
                }
                $upd->execute([':st' => classify_skip($msg), ':inc' => 1, ':err' => $msg, ':path' => '', ':id' => $jobId]);
                logerr('warn', '  Skipped: ' . $msg);
                $GLOBALS['ACTIVE_JOB_ID'] = null;
                pace(MAKERWORLD_DELAY_SECONDS);
                continue;
            }

            $mwBase  = get_makerworld_dir();
            $destDir = rtrim($mwBase, '/') . '/' . model_folder($modelId, $name, $slug);
            if (!is_dir($destDir) && !@mkdir($destDir, 0777, true) && !is_dir($destDir)) {
                $upd->execute([':st' => 'error', ':inc' => 1, ':err' => 'Cannot create MakerWorld dir: ' . $destDir, ':path' => '', ':id' => $jobId]);
                logerr('error', '  Cannot create MakerWorld dir: ' . $destDir);
                $GLOBALS['ACTIVE_JOB_ID'] = null;
                pace(MAKERWORLD_DELAY_SECONDS);
                continue;
            }
            // Detect if MakerWorld returned a bare .3mf file instead of a .zip.
            // Single-instance models return instance/{uuid}.3mf directly.
            $isBare3mf = (bool) preg_match('/\.3mf(\?|$)/i', strtok($link, '?') ?: $link);
            $dest = $isBare3mf
                ? $destDir . '/' . model_folder($modelId, $name, $slug) . '.3mf'
                : $destDir . '/' . model_folder($modelId, $name, $slug) . '_makerworld.zip';

            if (!cfg('overwrite') && is_file($dest)) {
                logln('  Exists, skip: ' . basename($dest));
                $upd->execute([':st' => 'done', ':inc' => 1, ':err' => '', ':path' => $dest, ':id' => $jobId]);
                continue; // no network, no pace
            }

            $okDl = $mw->downloadToFile($link, $dest, progress_writer($jobId, basename($dest)));

            // Signed MakerWorld URLs have a ~5 min TTL; a 401/403 means it lapsed
            // between mint and fetch. Re-mint once and retry — cheap recovery.
            if (!$okDl && preg_match('/\b(401|403)\b/', $mw->lastError)) {
                logln('  Signed URL stale (' . trim($mw->lastError) . ') — re-minting and retrying.');
                $link2 = $mw->getModelZipLink($modelId, $fileTy);
                if ($link2 !== '') {
                    $isBare3mf = (bool) preg_match('/\.3mf(\?|$)/i', strtok($link2, '?') ?: $link2);
                    $dest2 = $isBare3mf
                        ? $destDir . '/' . model_folder($modelId, $name, $slug) . '.3mf'
                        : $destDir . '/' . model_folder($modelId, $name, $slug) . '_makerworld.zip';
                    if ($dest2 !== $dest) { @unlink($dest); $dest = $dest2; }
                    $okDl = $mw->downloadToFile($link2, $dest, progress_writer($jobId, basename($dest)));
                }
            }

            if ($okDl) {
                if ($isBare3mf) {
                    // Bare .3mf file — save as-is, no extraction needed.
                    logln('  Saved MakerWorld 3MF: ' . $dest);
                    $finalPath = $dest;
                } else {
                    logln('  Saved MakerWorld zip: ' . $dest);
                    if (extract_zip_safe($dest, $destDir)) {
                        logln('  Extracted into: ' . $destDir);
                        if (cfg('keep_zip') !== true) {
                            @unlink($dest);
                            logln('  Removed zip (keep_zip off).');
                        }
                        $finalPath = $destDir;
                    } else {
                        logln('  Extract failed; zip kept at ' . $dest);
                        $finalPath = $dest;
                    }
                }
                $upd->execute([':st' => 'done', ':inc' => 1, ':err' => '', ':path' => $finalPath, ':id' => $jobId]);
            } else {
                $upd->execute([':st' => 'error', ':inc' => 1, ':err' => $mw->lastError, ':path' => '', ':id' => $jobId]);
                logln('  MakerWorld download failed: ' . $mw->lastError);
            }
        } catch (Throwable $e) {
            $upd->execute([':st' => 'error', ':inc' => 1, ':err' => $e->getMessage(), ':path' => '', ':id' => $jobId]);
            logln('  MakerWorld error: ' . $e->getMessage());
        }
        $GLOBALS['ACTIVE_JOB_ID'] = null;
        pace(MAKERWORLD_DELAY_SECONDS);
        continue;
    }

    // ---- Thingiverse: fetch all files, then zip locally --------------------
    // Thingiverse removed its server-side /things/{id}/zip endpoint (now returns
    // HTTP 400). Its website downloads each file from the CDN individually and
    // zips them client-side. We mirror that: fetch every file into the model
    // folder with a light inter-file delay (these are one model — the full
    // per-source pacing is for BETWEEN models, applied once at the end), then
    // optionally bundle them into a single .zip for delivery parity.
    if (($job['source'] ?? '') === 'thingiverse') {
        try {
            $files = $tv->getFiles($modelId);
            if (empty($files)) {
                $msg = $tv->lastError !== '' ? $tv->lastError : 'No files found for this Thingiverse thing.';
                $upd->execute([':st' => classify_skip($msg), ':inc' => 1, ':err' => $msg, ':path' => '', ':id' => $jobId]);
                logerr('warn', '  Skipped: ' . $msg);
                $GLOBALS['ACTIVE_JOB_ID'] = null;
                pace(THINGIVERSE_DELAY_SECONDS);
                continue;
            }
            $destDir = rtrim(get_thingiverse_dir(), '/') . '/' . model_folder($modelId, $name, $slug);
            if (!is_dir($destDir)) @mkdir($destDir, 0777, true);

            $total  = count($files);
            $okCount = 0;
            logln('  Thingiverse: downloading ' . $total . ' file(s) for this model.');
            $baseDelay = (int) (cfg('thingiverse_file_delay') ?: 6); // seconds between files
            $rateLimited = false;
            foreach ($files as $i => $f) {
                $fname = safe_segment($f['name']);
                $dest  = $destDir . '/' . $fname;
                if (!cfg('overwrite') && is_file($dest)) { logln('  Exists, skip: ' . $fname); $okCount++; continue; }

                // 429-aware download. Prefer the server's Retry-After header for the
                // wait; otherwise escalate 30/60/120s. Retry the SAME file up to 4x.
                $ok = false;
                $maxRetries = 4;
                $fallbacks = [30, 60, 120, 180];
                $attempt = 0;
                while (true) {
                    $ok = $tv->downloadToFile($f['url'], $dest, progress_writer($jobId, $fname));
                    if ($ok) break;
                    $is429 = ($tv->lastStatus === 429) || (strpos($tv->lastError, '429') !== false);
                    if ($is429 && $attempt < $maxRetries) {
                        // Honor Retry-After if the server sent one; clamp to a sane range.
                        $wait = $tv->lastRetryAfter > 0
                            ? min(600, max(5, $tv->lastRetryAfter + 2))
                            : $fallbacks[min($attempt, count($fallbacks) - 1)];
                        $attempt++;
                        logln("  Rate limited (429) — waiting {$wait}s (Retry-After" .
                              ($tv->lastRetryAfter > 0 ? "={$tv->lastRetryAfter}" : "=none") .
                              "), retry {$attempt}/{$maxRetries}: $fname");
                        write_worker_status(['state' => 'rate-limited', 'detail' => "429 wait {$wait}s", 'job' => $jobId]);
                        @db_disconnect();
                        sleep($wait);
                        continue;
                    }
                    break; // non-429, or retries exhausted
                }

                if ($ok) { logln('  Saved (' . ($i + 1) . '/' . $total . '): ' . $fname); $okCount++; }
                else {
                    logln('  Failed: ' . $fname . ' — ' . $tv->lastError);
                    if (($tv->lastStatus === 429) || (strpos($tv->lastError, '429') !== false)) { $rateLimited = true; }
                }
                // Delay between files of the SAME model (full pacing is between models).
                if ($i < $total - 1) { sleep($baseDelay); }
            }
            if ($rateLimited) {
                logerr('warn', '  Thingiverse rate-limited this model; some files may be missing. Re-queue later — already-downloaded files are skipped, so it resumes.');
            }

            if ($okCount === 0) {
                $upd->execute([':st' => 'error', ':inc' => 1, ':err' => $tv->lastError ?: 'No files downloaded.', ':path' => '', ':id' => $jobId]);
            } else {
                // Bundle into a single .zip for delivery parity with other sources,
                // unless the user prefers loose files (keep_zip=false leaves the
                // extracted folder, which is our normal end-state). We keep the
                // folder as the canonical result; create a zip only if keep_zip.
                if (cfg('keep_zip') === true) {
                    $zipPath = $destDir . '/' . model_folder($modelId, $name, $slug) . '_thingiverse.zip';
                    @zip_dir_contents($destDir, $zipPath);
                    if (is_file($zipPath)) { logln('  Bundled into: ' . basename($zipPath)); }
                }
                logln('  Thingiverse model complete: ' . $okCount . '/' . $total . ' file(s).');
                $upd->execute([':st' => 'done', ':inc' => 1, ':err' => '', ':path' => $destDir, ':id' => $jobId]);
            }
        } catch (Throwable $e) {
            $upd->execute([':st' => 'error', ':inc' => 1, ':err' => $e->getMessage(), ':path' => '', ':id' => $jobId]);
            logln('  Thingiverse error: ' . $e->getMessage());
        }
        $GLOBALS['ACTIVE_JOB_ID'] = null;
        pace(THINGIVERSE_DELAY_SECONDS);
        continue;
    }

    // ---- Cults3D: whole-model ZIP via authenticated web session -------------
    if (($job['source'] ?? '') === 'cults3d') {
        try {
            $destDir = rtrim(get_cults3d_dir(), '/') . '/' . model_folder($modelId, $name, $slug);

            // Primary path: the public API never exposes file URLs, so free
            // models download through the authenticated web session flow
            // (model page -> free order -> order page -> signed CDN zip).
            if ($cults->hasSession()) {
                if (!is_dir($destDir)) @mkdir($destDir, 0777, true);

                $savedPath = $cults->downloadAllViaSession($slug ?: $modelId, $destDir, progress_writer($jobId, $name !== '' ? $name : $slug));
                if ($savedPath !== '') {
                    logln('  Saved Cults3D file: ' . $savedPath);
                    // If it's a ZIP, extract and honor keep_zip; raw files stay as-is.
                    if (strtolower(substr($savedPath, -4)) === '.zip') {
                        if (extract_zip_safe($savedPath, $destDir)) {
                            logln('  Extracted into: ' . $destDir);
                            if (cfg('keep_zip') !== true) { @unlink($savedPath); logln('  Removed zip (keep_zip off).'); }
                            $upd->execute([':st' => 'done', ':inc' => 1, ':err' => '', ':path' => $destDir, ':id' => $jobId]);
                        } else {
                            logerr('warn', '  Extraction failed — keeping ZIP at: ' . $savedPath);
                            $upd->execute([':st' => 'error', ':inc' => 1, ':err' => 'Downloaded but could not extract (kept the .zip).', ':path' => $savedPath, ':id' => $jobId]);
                        }
                    }
                } else {
                    // Resolve/download failed. Distinguish paid (skip) from error.
                    $msg = $cults->lastError !== '' ? $cults->lastError : 'Cults3D download failed.';
                    $isPaid = stripos($msg, 'paid') !== false || stripos($msg, 'free-order') !== false;
                    $upd->execute([':st' => classify_skip($msg), ':inc' => 1, ':err' => $msg, ':path' => '', ':id' => $jobId]);
                    logerr('warn', '  Cults3D ' . (classify_skip($msg)) . ': ' . $msg);
                }
                $GLOBALS['ACTIVE_JOB_ID'] = null;
                pace(CULTS3D_DELAY_SECONDS);
                continue;
            }

            // No session configured: fall back to the API blueprints path.
            // fileUrl is always null there, so this effectively reports that a
            // session cookie is required (or flags paid models).
            $files = $cults->getFiles($slug ?: $modelId);
            if (empty($files)) {
                $msg = $cults->lastError !== '' ? $cults->lastError : 'No files found for this Cults3D creation.';
                if (stripos($msg, 'no downloadable') !== false || stripos($msg, 'withheld') !== false) {
                    $msg .= ' Add a Cults3D download session (_session_id) in Settings to enable free downloads.';
                }
                $upd->execute([':st' => classify_skip($msg), ':inc' => 1, ':err' => $msg, ':path' => '', ':id' => $jobId]);
                logerr('warn', '  Skipped: ' . $msg);
                $GLOBALS['ACTIVE_JOB_ID'] = null;
                pace(CULTS3D_DELAY_SECONDS);
                continue;
            }
            if (!is_dir($destDir)) @mkdir($destDir, 0777, true);
            $anyOk = false;
            foreach ($files as $f) {
                $fname = safe_segment($f['name']);
                $dest  = $destDir . '/' . $fname;
                if (!cfg('overwrite') && is_file($dest)) { logln('  Exists, skip: ' . $fname); $anyOk = true; continue; }
                $ok = $cults->downloadToFile($f['url'], $dest, progress_writer($jobId, $fname));
                if ($ok) { logln('  Saved: ' . $fname); $anyOk = true; }
                else { logln('  Failed: ' . $fname . ' — ' . $cults->lastError); }
                pace(CULTS3D_DELAY_SECONDS);
            }
            $upd->execute([':st' => $anyOk?'done':'error', ':inc' => 1, ':err' => $anyOk?'':$cults->lastError, ':path' => $anyOk?$destDir:'', ':id' => $jobId]);
        } catch (Throwable $e) {
            $upd->execute([':st' => 'error', ':inc' => 1, ':err' => $e->getMessage(), ':path' => '', ':id' => $jobId]);
            logln('  Cults3D error: ' . $e->getMessage());
        }
        $GLOBALS['ACTIVE_JOB_ID'] = null;
        pace(CULTS3D_DELAY_SECONDS);
        continue;
    }

    // ---- Creality Cloud: whole-model download (all files in the set) --------
    if (($job['source'] ?? '') === 'creality') {
        try {
            $destDir = rtrim(get_creality_dir(), '/') . '/' . model_folder($modelId, $name, $slug);

            // memberDownload returns signed URLs for every file in the model;
            // downloadModel fetches them all into the model's folder.
            $saved = $creality->downloadModel($modelId, $destDir, progress_writer($jobId, $name !== '' ? $name : $modelId));

            if ($saved > 0) {
                logln('  Saved ' . $saved . ' Creality file(s) into: ' . $destDir);
                $upd->execute([':st' => 'done', ':inc' => 1, ':err' => '', ':path' => $destDir, ':id' => $jobId]);
            } else {
                $msg = $creality->lastError !== '' ? $creality->lastError : 'Creality download failed.';
                // A 403 (Cloudflare) or auth failure should halt so the user can
                // re-paste a fresh token / cf_clearance rather than burn the queue.
                if (stripos($msg, '403') !== false || stripos($msg, 'auth') !== false || stripos($msg, 're-paste') !== false) {
                    $upd->execute([':st' => 'queued', ':inc' => 0, ':err' => $msg, ':path' => '', ':id' => $jobId]);
                    logerr('error', '  Creality auth/cloudflare issue — halting run: ' . $msg);
                    break;
                }
                $isPaid = stripos($msg, 'paid') !== false || stripos($msg, 'region') !== false;
                $upd->execute([':st' => classify_skip($msg), ':inc' => 1, ':err' => $msg, ':path' => '', ':id' => $jobId]);
                logerr('warn', '  Creality ' . (classify_skip($msg)) . ': ' . $msg);
            }
        } catch (\Throwable $e) {
            $upd->execute([':st' => 'error', ':inc' => 1, ':err' => $e->getMessage(), ':path' => '', ':id' => $jobId]);
            logln('  Creality error: ' . $e->getMessage());
        }
        $GLOBALS['ACTIVE_JOB_ID'] = null;
        pace(CREALITY_DELAY_SECONDS);
        continue;
    }

    // ---- STLFlix: whole-model ZIP download ----------------------------------
    if (($job['source'] ?? '') === 'stlflix') {
        try {
            $links = $stlfix->getDownloadUrls($modelId, $slug);

            // Dead/expired token — halt the run.
            if (empty($links) && str_contains(strtolower($stlfix->lastError), 'auth')) {
                $upd->execute([':st' => 'queued', ':inc' => 0, ':err' => $stlfix->lastError, ':path' => '', ':id' => $jobId]);
                logerr('error', '  STLFlix auth failed — halting run. Re-paste jwt in Settings.');
                break;
            }
            if (empty($links)) {
                $msg = $stlfix->lastError !== '' ? $stlfix->lastError : 'Could not resolve STLFlix download URLs.';
                // A product with no attached files is not a retryable error — skip it.
                $isNoFiles = str_contains(strtolower($msg), 'no downloadable files');
                $upd->execute([':st' => classify_skip($msg), ':inc' => 1, ':err' => $msg, ':path' => '', ':id' => $jobId]);
                logerr('warn', '  STLFlix ' . (classify_skip($msg)) . ': ' . $msg);
                $GLOBALS['ACTIVE_JOB_ID'] = null;
                pace(STLFLIX_DELAY_SECONDS);
                continue;
            }

            $destDir = rtrim(get_stlflix_dir(), '/') . '/' . model_folder($modelId, $name, $slug);
            if (!is_dir($destDir)) @mkdir($destDir, 0777, true);

            $anyOk    = false;
            $fileTotal = count($links);
            foreach ($links as $i => $link) {
                $fileNum = $i + 1;
                // Derive a filename from the URL, fallback to indexed name.
                $urlBasename = basename(parse_url($link, PHP_URL_PATH) ?? '');
                $zipName     = $urlBasename !== '' ? $urlBasename
                    : model_folder($modelId, $name, $slug) . '_' . ($i + 1) . '.zip';
                $dest = $destDir . '/' . $zipName;

                if (!cfg('overwrite') && is_file($dest)) {
                    logln('  Exists, skip: ' . $zipName);
                    $anyOk = true;
                    continue;
                }

                logln('  Downloading: ' . $zipName . ' (' . $fileNum . '/' . $fileTotal . ') from [CDN]');
                $pwFn = progress_writer($jobId, $zipName);
                $progressCb = static function (int $bytes) use ($pwFn, $fileNum, $fileTotal): void {
                    $pwFn(0, $bytes, $fileNum, $fileTotal);
                };

                if ($stlfix->downloadToFile($link, $dest, $progressCb)) {
                    logln('  Saved: ' . $dest);
                    if (extract_zip_safe($dest, $destDir)) {
                        logln('  Extracted into: ' . $destDir);
                        if (cfg('keep_zip') !== true) {
                            @unlink($dest);
                            logln('  Removed zip (keep_zip off).');
                        }
                    } else {
                        logln('  Extract failed; zip kept at ' . $dest);
                    }
                    $anyOk = true;
                } else {
                    logln('  Failed: ' . $zipName . ' — ' . $stlfix->lastError);
                }
                if ($i < count($links) - 1) pace(STLFLIX_DELAY_SECONDS);
            }

            $upd->execute([
                ':st'   => $anyOk ? 'done' : 'error',
                ':inc'  => 1,
                ':err'  => $anyOk ? '' : (string) $stlfix->lastError,
                ':path' => $anyOk ? (string) $destDir : '',
                ':id'   => (int) $jobId,
            ]);
        } catch (Throwable $e) {
            $upd->execute([':st' => 'error', ':inc' => 1, ':err' => $e->getMessage(), ':path' => '', ':id' => $jobId]);
            logln('  STLFlix error: ' . $e->getMessage());
        }
        $GLOBALS['ACTIVE_JOB_ID'] = null;
        pace(STLFLIX_DELAY_SECONDS);
        continue;
    }

    // ---- Nikko Industries: whole-model ZIP download via Shopify Digital
    // Downloads landing page, scraped off the product page ----------------
    if (($job['source'] ?? '') === 'nikko') {
        try {
            $links = $nikko->getDownloadUrls($modelId, $slug);

            // Dead/expired session — halt the run rather than burning the queue.
            if (empty($links) && str_contains(strtolower($nikko->lastError), 'session')) {
                $upd->execute([':st' => 'queued', ':inc' => 0, ':err' => $nikko->lastError, ':path' => '', ':id' => $jobId]);
                logerr('error', '  Nikko session expired — halting run. Re-paste session cookie in Settings.');
                break;
            }
            if (empty($links)) {
                $msg = $nikko->lastError !== '' ? $nikko->lastError : 'Could not resolve Nikko download URL.';
                // A product page with no attached download is not retryable.
                $isNoFiles = str_contains(strtolower($msg), 'no download link found');
                $upd->execute([':st' => classify_skip($msg), ':inc' => 1, ':err' => $msg, ':path' => '', ':id' => $jobId]);
                logerr('warn', '  Nikko ' . (classify_skip($msg)) . ': ' . $msg);
                $GLOBALS['ACTIVE_JOB_ID'] = null;
                pace(NIKKO_DELAY_SECONDS);
                continue;
            }

            $destDir = rtrim(get_nikko_dir(), '/') . '/' . model_folder($modelId, $name, $slug);
            if (!is_dir($destDir)) @mkdir($destDir, 0777, true);

            $anyOk     = false;
            $fileTotal = count($links);
            foreach ($links as $i => $link) {
                $fileNum = $i + 1;
                $urlBasename = basename(parse_url($link, PHP_URL_PATH) ?? '');
                $zipName     = $urlBasename !== '' ? $urlBasename
                    : model_folder($modelId, $name, $slug) . '_' . ($i + 1) . '.zip';
                $dest = $destDir . '/' . $zipName;

                if (!cfg('overwrite') && is_file($dest)) {
                    logln('  Exists, skip: ' . $zipName);
                    $anyOk = true;
                    continue;
                }

                logln('  Downloading: ' . $zipName . ' (' . $fileNum . '/' . $fileTotal . ') from [signed storage URL]');
                $pwFn = progress_writer($jobId, $zipName);
                $progressCb = static function (int $bytes) use ($pwFn, $fileNum, $fileTotal): void {
                    $pwFn(0, $bytes, $fileNum, $fileTotal);
                };

                if ($nikko->downloadToFile($link, $dest, $progressCb)) {
                    logln('  Saved: ' . $dest);
                    if (extract_zip_safe($dest, $destDir)) {
                        logln('  Extracted into: ' . $destDir);
                        if (cfg('keep_zip') !== true) {
                            @unlink($dest);
                            logln('  Removed zip (keep_zip off).');
                        }
                    } else {
                        logln('  Extract failed; zip kept at ' . $dest);
                    }
                    $anyOk = true;
                } else {
                    logln('  Failed: ' . $zipName . ' — ' . $nikko->lastError);
                }
                if ($i < count($links) - 1) pace(NIKKO_DELAY_SECONDS);
            }

            $upd->execute([
                ':st'   => $anyOk ? 'done' : 'error',
                ':inc'  => 1,
                ':err'  => $anyOk ? '' : (string) $nikko->lastError,
                ':path' => $anyOk ? (string) $destDir : '',
                ':id'   => (int) $jobId,
            ]);
        } catch (Throwable $e) {
            $upd->execute([':st' => 'error', ':inc' => 1, ':err' => $e->getMessage(), ':path' => '', ':id' => $jobId]);
            logln('  Nikko error: ' . $e->getMessage());
        }
        $GLOBALS['ACTIVE_JOB_ID'] = null;
        pace(NIKKO_DELAY_SECONDS);
        continue;
    }

    // ---- Hex3D Forum: topic attachment download(s) ---------------------
    if (($job['source'] ?? '') === 'hex3dforum') {
        try {
            $links = $hex3dforum->getDownloadUrls($modelId, $slug);

            if (empty($links) && str_contains(strtolower($hex3dforum->lastError), 'session')) {
                $upd->execute([':st' => 'queued', ':inc' => 0, ':err' => $hex3dforum->lastError, ':path' => '', ':id' => $jobId]);
                logerr('error', '  Hex3D Forum session expired — halting run. Re-paste session cookie in Settings.');
                break;
            }
            if (empty($links)) {
                $msg = $hex3dforum->lastError !== '' ? $hex3dforum->lastError : 'Could not resolve Hex3D Forum attachment URLs.';
                $isNoFiles = str_contains(strtolower($msg), 'no attachments found');
                $upd->execute([':st' => classify_skip($msg), ':inc' => 1, ':err' => $msg, ':path' => '', ':id' => $jobId]);
                logerr('warn', '  Hex3D Forum ' . (classify_skip($msg)) . ': ' . $msg);
                $GLOBALS['ACTIVE_JOB_ID'] = null;
                pace(HEX3DFORUM_DELAY_SECONDS);
                continue;
            }

            $destDir = rtrim(get_hex3dforum_dir(), '/') . '/' . model_folder($modelId, $name, $slug);
            if (!is_dir($destDir)) @mkdir($destDir, 0777, true);

            $anyOk     = false;
            $fileTotal = count($links);
            foreach ($links as $i => $link) {
                $fileNum = $i + 1;
                $urlBasename = basename(parse_url($link, PHP_URL_PATH) ?? '');
                // download/file.php?id=N has no filename in the path — fall
                // back to an indexed name; the actual filename arrives via
                // Content-Disposition, which downloadToFile() doesn't parse,
                // so attachments are saved generically and identified by
                // their extracted contents after unzip.
                $zipName = ($urlBasename !== '' && $urlBasename !== 'file.php')
                    ? $urlBasename
                    : model_folder($modelId, $name, $slug) . '_' . $fileNum . '.zip';
                $dest = $destDir . '/' . $zipName;

                if (!cfg('overwrite') && is_file($dest)) {
                    logln('  Exists, skip: ' . $zipName);
                    $anyOk = true;
                    continue;
                }

                logln('  Downloading: ' . $zipName . ' (' . $fileNum . '/' . $fileTotal . ') from [forum attachment]');
                $pwFn = progress_writer($jobId, $zipName);
                $progressCb = static function (int $bytes) use ($pwFn, $fileNum, $fileTotal): void {
                    $pwFn(0, $bytes, $fileNum, $fileTotal);
                };

                if ($hex3dforum->downloadToFile($link, $dest, $progressCb)) {
                    logln('  Saved: ' . $dest);
                    if (extract_zip_safe($dest, $destDir)) {
                        logln('  Extracted into: ' . $destDir);
                        // NOTE: keep_zip is intentionally FORCED ON for Hex3D,
                        // regardless of the global setting. Hex3D attachments are
                        // saved with generic names (download/file.php?id=N has no
                        // filename, so we use "<folder>_N.zip"). The "exists,
                        // skip" guard above matches on that zip name — so if we
                        // delete the zip after extracting, the next run can't tell
                        // the model was already fetched and re-downloads it
                        // forever (a download loop). Keeping the zip is what lets
                        // the skip-guard recognize completed Hex3D models.
                        // (Other sources keep stable per-file names, so they can
                        // honor keep_zip safely.)
                    } else {
                        logln('  Extract failed; zip kept at ' . $dest);
                    }
                    $anyOk = true;
                } else {
                    logln('  Failed: ' . $zipName . ' — ' . $hex3dforum->lastError);
                }
                if ($i < count($links) - 1) pace(HEX3DFORUM_DELAY_SECONDS);
            }

            $upd->execute([
                ':st'   => $anyOk ? 'done' : 'error',
                ':inc'  => 1,
                ':err'  => $anyOk ? '' : (string) $hex3dforum->lastError,
                ':path' => $anyOk ? (string) $destDir : '',
                ':id'   => (int) $jobId,
            ]);
        } catch (Throwable $e) {
            $upd->execute([':st' => 'error', ':inc' => 1, ':err' => $e->getMessage(), ':path' => '', ':id' => $jobId]);
            logln('  Hex3D Forum error: ' . $e->getMessage());
        }
        $GLOBALS['ACTIVE_JOB_ID'] = null;
        pace(HEX3DFORUM_DELAY_SECONDS);
        continue;
    }

    // ---- PACK mode: one whole-model ZIP instead of the per-file loop --------
    if (strtoupper($fileTy) === 'PACK') {
        try {
            // Paste-ID jobs arrive with no name/slug — resolve from API.
            if ($name === '' && ($slug === '' || $slug === $modelId)) {
                $info = $svc->getModelInfo($modelId);
                if ($info['name'] !== '') $name = $info['name'];
                if ($info['slug'] !== '') $slug = $info['slug'];
            } elseif ($name === '') {
                $info = $svc->getModelInfo($modelId);
                if ($info['name'] !== '') $name = $info['name'];
            }

            $link = $svc->getPackLink($modelId, 'MODEL_FILES');

            // Dead token halts the run (pack resolution needs auth); requeue.
            if ($svc->lastError !== '' && str_contains($svc->lastError, 'rejected')) {
                $upd->execute([':st' => 'queued', ':inc' => 0, ':err' => $svc->lastError, ':path' => '', ':id' => $jobId]);
                logerr('error', '  Token rejected — halting run. Re-auth in Settings.');
                break;
            }
            if ($link === '') {
                $msg = $svc->lastError !== '' ? $svc->lastError : 'No pack available.';
                $upd->execute([':st' => classify_skip($msg), ':inc' => 1, ':err' => $msg, ':path' => '', ':id' => $jobId]);
                logerr('warn', '  Skipped: ' . $msg);
                $GLOBALS['ACTIVE_JOB_ID'] = null;
                pace();
                continue;
            }

            $destDir = rtrim($baseDir, '/') . '/' . model_folder($modelId, $name, $slug);
            if (!is_dir($destDir)) {
                @mkdir($destDir, 0777, true);
            }
            $dest = $destDir . '/' . model_folder($modelId, $name, $slug) . '_model_files.zip';

            if (!cfg('overwrite') && is_file($dest)) {
                logln('  Exists, skip: ' . basename($dest));
                $upd->execute([':st' => 'done', ':inc' => 1, ':err' => '', ':path' => $dest, ':id' => $jobId]);
                continue; // no network, no pace
            }

            if ($svc->downloadToFile($link, $dest, progress_writer($jobId, basename($dest)))) {
                logln('  Saved pack: ' . $dest);

                // Extract the zip into the model folder (zip-slip guarded).
                if (extract_zip_safe($dest, $destDir)) {
                    logln('  Extracted into: ' . $destDir);
                    // Honor "Keep .zip files?" — delete the archive if unchecked.
                    if (cfg('keep_zip') !== true) {
                        @unlink($dest);
                        logln('  Removed zip (keep_zip off).');
                    }
                    $finalPath = $destDir;
                } else {
                    // Extraction failed — keep the zip regardless so nothing is lost.
                    logln('  Extract failed; zip kept at ' . $dest);
                    $finalPath = $dest;
                }

                $upd->execute([':st' => 'done', ':inc' => 1, ':err' => '', ':path' => $finalPath, ':id' => $jobId]);
            } else {
                $upd->execute([':st' => 'error', ':inc' => 1, ':err' => $svc->lastError, ':path' => '', ':id' => $jobId]);
                logln('  Pack download failed: ' . $svc->lastError);
            }
        } catch (Throwable $e) {
            $upd->execute([':st' => 'error', ':inc' => 1, ':err' => $e->getMessage(), ':path' => '', ':id' => $jobId]);
            logln('  Pack error: ' . $e->getMessage());
        }
        $GLOBALS['ACTIVE_JOB_ID'] = null;
        pace();
        continue;
    }

    try {
        // Global "always pack" preference: when enabled, pull the whole-model
        // ZIP and extract instead of fetching each requested file individually.
        // Skipped when the user explicitly queued a PACK job (already pack mode).
        if (cfg('prefer_pack') === true && strtoupper($fileTy) !== 'PACK') {
            $packLink = $svc->getPackLink($modelId, 'MODEL_FILES');
            if ($packLink !== '') {
                logln('  Prefer-pack on — downloading whole-model ZIP.');
                $destDir = rtrim($baseDir, '/') . '/' . model_folder($modelId, $name, $slug);
                if (!is_dir($destDir)) @mkdir($destDir, 0777, true);
                $dest = $destDir . '/' . model_folder($modelId, $name, $slug) . '_model_files.zip';
                if (!cfg('overwrite') && is_file($dest)) {
                    logln('  Exists, skip: ' . basename($dest));
                    $upd->execute([':st' => 'done', ':inc' => 1, ':err' => '', ':path' => $dest, ':id' => $jobId]);
                } elseif ($svc->downloadToFile($packLink, $dest, progress_writer($jobId, basename($dest)))) {
                    if (extract_zip_safe($dest, $destDir)) {
                        logln('  Extracted pack into: ' . $destDir);
                        if (cfg('keep_zip') !== true) { @unlink($dest); }
                        $upd->execute([':st' => 'done', ':inc' => 1, ':err' => '', ':path' => $destDir, ':id' => $jobId]);
                    } else {
                        // Download succeeded but the ZIP couldn't be extracted
                        // (corrupt, empty, or all entries unsafe). Keep the ZIP so
                        // the user still has the file, and report it.
                        logerr('warn', '  Pack downloaded but extraction failed — keeping ZIP at: ' . $dest);
                        $upd->execute([':st' => 'error', ':inc' => 1, ':err' => 'Pack downloaded but could not be extracted (kept the .zip).', ':path' => $dest, ':id' => $jobId]);
                    }
                } else {
                    logerr('warn', '  Pack download failed: ' . ($svc->lastError ?: 'unknown error'));
                    $upd->execute([':st' => 'error', ':inc' => 1, ':err' => $svc->lastError, ':path' => '', ':id' => $jobId]);
                }
                $GLOBALS['ACTIVE_JOB_ID'] = null;
                pace();
                continue;
            }
            // No pack available — fall through to the normal per-file path.
            logln('  Prefer-pack on, but no whole-model ZIP available — using per-file download.');
        }

        $files = $svc->getModelFiles($modelId, $fileTy);

        // Dead token: stop the whole run so the user re-auths; requeue this job.
        if ($svc->lastError !== '' && str_contains($svc->lastError, 'rejected')) {
            $upd->execute([':st' => 'queued', ':inc' => 0, ':err' => $svc->lastError, ':path' => '', ':id' => $jobId]);
            logerr('error', '  Token rejected — halting run. Re-auth in Settings.');
            break;
        }

        if ($files === []) {
            // Fallback chain: STL → 3MF → PACK
            if (strtoupper($fileTy) === 'STL') {
                logln('  No STL files — trying 3MF fallback.');
                $files = $svc->getModelFiles($modelId, '3MF');
                if ($files !== []) {
                    $fileTy = '3MF';
                    logln('  Falling back to 3MF (' . count($files) . ' file(s)).');
                }
            }
            if ($files === []) {
                $packLink = $svc->getPackLink($modelId, 'MODEL_FILES');
                if ($packLink !== '') {
                    logln('  No per-file match — falling back to PACK download.');
                    $destDir = rtrim($baseDir, '/') . '/' . model_folder($modelId, $name, $slug);
                    if (!is_dir($destDir)) @mkdir($destDir, 0777, true);
                    $dest = $destDir . '/' . model_folder($modelId, $name, $slug) . '_model_files.zip';
                    if (!cfg('overwrite') && is_file($dest)) {
                        logln('  Exists, skip: ' . basename($dest));
                        $upd->execute([':st' => 'done', ':inc' => 1, ':err' => '', ':path' => $dest, ':id' => $jobId]);
                    } elseif ($svc->downloadToFile($packLink, $dest, progress_writer($jobId, basename($dest)))) {
                        if (extract_zip_safe($dest, $destDir)) {
                            logln('  Extracted pack into: ' . $destDir);
                            if (cfg('keep_zip') !== true) { @unlink($dest); }
                            $upd->execute([':st' => 'done', ':inc' => 1, ':err' => '', ':path' => $destDir, ':id' => $jobId]);
                        } else {
                            logerr('warn', '  Pack downloaded but extraction failed — keeping ZIP at: ' . $dest);
                            $upd->execute([':st' => 'error', ':inc' => 1, ':err' => 'Pack downloaded but could not be extracted (kept the .zip).', ':path' => $dest, ':id' => $jobId]);
                        }
                    } else {
                        $upd->execute([':st' => 'error', ':inc' => 1, ':err' => $svc->lastError, ':path' => '', ':id' => $jobId]);
                    }
                    $GLOBALS['ACTIVE_JOB_ID'] = null;
                    pace();
                    continue;
                }
                $msg = $svc->lastError !== '' ? $svc->lastError : 'No matching files on model.';
                $upd->execute([':st' => classify_skip($msg), ':inc' => 1, ':err' => $msg, ':path' => '', ':id' => $jobId]);
                logerr('warn', '  Skipped: ' . $msg);
                continue;
            }
        }

        $destDir   = rtrim($baseDir, '/') . '/' . model_folder($modelId, $name, $slug);
        $savedAny  = false;
        $lastPath  = '';

        foreach ($files as $i => $f) {
            $link = $svc->getDownloadLink($f['id'], $modelId, $fileTy, $f['dl'] ?? null);
            if ($link === '') {
                logerr('error', '  Link refused for file ' . $f['id'] . ': ' . $svc->lastError);
                pace();
                continue;
            }

            $fname = safe_segment($f['name']);
            if (!preg_match('/\.(stl|3mf)$/i', $fname)) {
                $fname .= '.' . strtolower($fileTy);
            }
            $dest = $destDir . '/' . $fname;

            if (!cfg('overwrite') && is_file($dest)) {
                logln('  Exists, skip: ' . $fname);
                $savedAny = true;
                $lastPath = $dest;
                continue; // no network, no pace
            }

            if ($svc->downloadToFile($link, $dest, progress_writer($jobId, $fname))) {
                logln('  Saved: ' . $dest);
                $savedAny = true;
                $lastPath = $dest;
            } elseif (preg_match('/\b(401|403)\b/', $svc->lastError)) {
                // A 401/403 here means the signed download URL expired (it has
                // its own short TTL) or the access token lapsed mid-run. Re-mint
                // the link once — getDownloadLink() routes through gql(), which
                // refreshes the access token too — then retry. Cheap recovery.
                logln('  Signed URL stale (' . trim($svc->lastError) . ') — re-minting and retrying.');
                $link2 = $svc->getDownloadLink($f['id'], $modelId, $fileTy, $f['dl'] ?? null);
                if ($link2 !== '' && $svc->downloadToFile($link2, $dest, progress_writer($jobId, $fname))) {
                    logln('  Saved (after re-mint): ' . $dest);
                    $savedAny = true;
                    $lastPath = $dest;
                } else {
                    logerr('error', '  Download failed after re-mint: ' . $svc->lastError);
                }
            } else {
                logerr('error', '  Download failed: ' . $svc->lastError);
            }

            // Pace between every network fetch (skip after the final file).
            if ($i < count($files) - 1) {
                pace();
            }
        }

        if ($savedAny) {
            $upd->execute([':st' => 'done', ':inc' => 1, ':err' => '', ':path' => $lastPath, ':id' => $jobId]);
            logln('  Done.');
        } else {
            $newStatus = ((int) $job['attempts'] + 1) >= $maxAttempts ? 'failed' : 'queued';
            $upd->execute([':st' => $newStatus, ':inc' => 1, ':err' => $svc->lastError ?: 'no files saved', ':path' => '', ':id' => $jobId]);
            logln('  No files saved -> ' . $newStatus);
        }
    } catch (Throwable $ex) {
        $newStatus = ((int) $job['attempts'] + 1) >= $maxAttempts ? 'failed' : 'queued';
        $upd->execute([':st' => $newStatus, ':inc' => 1, ':err' => $ex->getMessage(), ':path' => '', ':id' => $jobId]);
        logln('  Exception: ' . $ex->getMessage() . ' -> ' . $newStatus);
    }

    $processed++;
    // The job is finalized; clear the active marker so the between-jobs pace
    // countdown isn't attributed to a now-finished row.
    $GLOBALS['ACTIVE_JOB_ID'] = null;
    pace(); // pace between jobs too
}

write_worker_status(['phase' => 'idle']);
fclose($lock);
logln('Worker exit.');

// ---- helpers --------------------------------------------------------------
function pace(?int $delay = null): void
{
    // Release the DB connection for the entire wait. The worker does no DB work
    // while pacing (status heartbeats go to a JSON file, not the DB), so holding
    // the SQLite handle open here would needlessly block the UI's writes
    // (enqueue, printer saves) for the full delay. db() transparently reconnects
    // on the next status write. This is the core fix for "database error while
    // queueing" / hung "queueing…" while a download is in progress.
    db_disconnect();

    $delay = $delay ?? DOWNLOAD_DELAY_SECONDS;
    // Randomize the wait around the configured delay (±10s) so the cadence
    // isn't a detectable fixed interval. e.g. a 55s setting waits 45–65s.
    // Clamped to a sane floor so we never hammer a source.
    $delay = jitter_delay($delay);
    // Surface the deliberate delay as a countdown the queue UI can show, so the
    // paced wait reads as "intentional", not "frozen". The worker status has a
    // staleness guard (read_worker_status), so we must refresh the heartbeat
    // throughout the wait — a single write + one long sleep would go "stale"
    // partway through and make the countdown bar disappear before it hits 0:00.
    $nextAt = microtime(true) + $delay;
    $beat   = 5; // seconds per heartbeat; comfortably under the 12s stale cutoff
    do {
        write_worker_status([
            'phase'   => 'waiting',
            'job_id'  => $GLOBALS['ACTIVE_JOB_ID'] ?? null,
            'next_at' => $nextAt,
            'delay'   => $delay,
        ]);
        $left = $nextAt - microtime(true);
        if ($left <= 0) {
            break;
        }
        sleep((int) min($beat, ceil($left)));
    } while (microtime(true) < $nextAt);
}

/**
 * Apply ±10s of random jitter around a base delay so the download cadence
 * isn't a fixed, fingerprintable interval. The result is clamped to a safe
 * 50–120s window so we never hammer a source (and never stall too long),
 * regardless of the configured delay.
 */
function jitter_delay(int $base): int
{
    if ($base <= 0) {
        return 0; // pacing explicitly disabled — respect that, no jitter
    }
    $jittered = $base + random_int(-10, 10);
    return max(50, min(120, $jittered));
}

/** Make a filesystem-safe path segment (no traversal, no separators). */
function safe_segment(string $s): string
{
    // 1. Force UTF-8 cleanliness and remove control characters first
    $s = preg_replace('/[\x00-\x1F\x7F]/', '', $s) ?? $s;

    // 2. Strip quotes and dangerous OS characters
    $s = preg_replace("/['\"`]/", '', $s) ?? $s;
    $s = preg_replace('/[\/\\\:*?<>|]+/', '_', $s) ?? $s;
    // 2b. Strip shell/filesystem-problematic chars: $ breaks shell ops,
    //     CSS selector matching, and some path handling; drop ; { } too,
    //     and turn & into 'and' so it still reads naturally.
    $s = str_replace(['$', ';', '{', '}'], '', $s);
    $s = str_replace('&', 'and', $s);

    // 3. Compress multiple spaces and underscores
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    $s = preg_replace('/_+/', '_', $s) ?? $s;

    // 4. Clean edges *before* checking for reserved names
    $s = trim($s, " .-_");

    // 5. Hard lockdown against traversal and OS reserved words (e.g. CON, NUL)
    $lowercase = strtolower($s);
    $reserved = ['file', '.', '..', 'con', 'prn', 'aux', 'nul', 'com1', 'lpt1'];
    
    if ($s === '' || in_array($lowercase, $reserved, true)) {
        $s = 'file';
    }

    return substr($s, 0, 150);
}

/** Build a human-readable folder name: "{modelId} - {model name}" */
function model_folder(string $modelId, string $name, string $slug = ''): string
{
    // Clean, readable fallback chain using standard if/else
    if ($name !== '') {
        $label = $name;
    } elseif ($slug !== '') {
        $label = $slug;
    } else {
        $label = '';
    }

    $folder = $label !== '' ? $modelId . ' - ' . $label : $modelId;
    return safe_segment($folder);
}
