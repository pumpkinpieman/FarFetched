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
    return static function (int $total, int $now) use ($jobId, $file, &$last): void {
        $t = microtime(true);
        if ($now > 0 && $now < $total && ($t - $last) < 0.30) {
            return; // throttle mid-transfer; always allow first/last ticks
        }
        $last = $t;
        write_worker_status([
            'phase'  => 'downloading',
            'job_id' => $jobId,
            'file'   => $file,
            'bytes'  => $now,
            'total'  => $total,
        ]);
    };
}

// ---- Single-instance lock -------------------------------------------------
$lock = fopen(LOCK_FILE, 'c');
if ($lock === false) {
    logln('FATAL: cannot open lock file.');
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    logln('Another worker holds the lock — exiting.');
    exit(0);
}

$svc = new PrintablesService();
$mw  = new MakerWorldService();

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

$readySources = [];
if ($printablesReady) { $readySources[] = 'printables'; }
if ($makerworldReady) { $readySources[] = 'makerworld'; }

if ($readySources === []) {
    logerr('error', 'No source configured — paste a Printables and/or MakerWorld token in Settings. Exiting.');
    exit(0);
}

if (cfg('paused') === true) {
    logln('Downloads paused via Settings — exiting without processing.');
    exit(0);
}

$baseDir = get_download_dir();
if (!is_dir($baseDir) && !@mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
    logln('FATAL: download dir not creatable: ' . $baseDir);
    exit(1);
}
if (!is_writable($baseDir)) {
    logln('FATAL: download dir not writable: ' . $baseDir . ' (fix share permissions).');
    exit(1);
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
    $claimStmt = $pdo->prepare(
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

    $upd = $pdo->prepare(
        "UPDATE download_jobs
         SET status = :st, attempts = attempts + :inc, last_error = :err,
             saved_path = :path, updated_at = datetime('now')
         WHERE id = :id"
    );

    // Mark working.
    $pdo->prepare("UPDATE download_jobs SET status='working', updated_at=datetime('now') WHERE id = :id")
        ->execute([':id' => $jobId]);

    logln("Job #$jobId  model=$modelId  type=$fileTy  ($slug)");

    // ---- MakerWorld: always a whole-model ZIP ------------------------------
    if (($job['source'] ?? 'printables') === 'makerworld') {
        try {
            $link = $mw->getModelZipLink($modelId);
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
                $upd->execute([':st' => 'skipped', ':inc' => 1, ':err' => $msg, ':path' => '', ':id' => $jobId]);
                logerr('warn', '  Skipped: ' . $msg);
                $GLOBALS['ACTIVE_JOB_ID'] = null;
                pace(MAKERWORLD_DELAY_SECONDS);
                continue;
            }

            $mwBase  = get_makerworld_dir();
            $destDir = rtrim($mwBase, '/') . '/' . safe_segment($slug);
            if (!is_dir($destDir) && !@mkdir($destDir, 0775, true) && !is_dir($destDir)) {
                $upd->execute([':st' => 'error', ':inc' => 1, ':err' => 'Cannot create MakerWorld dir: ' . $destDir, ':path' => '', ':id' => $jobId]);
                logerr('error', '  Cannot create MakerWorld dir: ' . $destDir);
                $GLOBALS['ACTIVE_JOB_ID'] = null;
                pace(MAKERWORLD_DELAY_SECONDS);
                continue;
            }
            $dest = $destDir . '/' . safe_segment($slug) . '_makerworld.zip';

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
                $link2 = $mw->getModelZipLink($modelId);
                if ($link2 !== '') {
                    $okDl = $mw->downloadToFile($link2, $dest, progress_writer($jobId, basename($dest)));
                }
            }

            if ($okDl) {
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

    // ---- PACK mode: one whole-model ZIP instead of the per-file loop --------
    if (strtoupper($fileTy) === 'PACK') {
        try {
            // Paste-ID jobs arrive with no slug — resolve a sensible folder name.
            if ($slug === '' || $slug === $modelId) {
                $info = $svc->getModelInfo($modelId);
                if ($info['slug'] !== '') {
                    $slug = $info['slug'];
                } elseif ($info['name'] !== '') {
                    $slug = $info['name'];
                }
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
                $upd->execute([':st' => 'skipped', ':inc' => 1, ':err' => $msg, ':path' => '', ':id' => $jobId]);
                logerr('warn', '  Skipped: ' . $msg);
                $GLOBALS['ACTIVE_JOB_ID'] = null;
                pace();
                continue;
            }

            $destDir = rtrim($baseDir, '/') . '/' . safe_segment($slug);
            if (!is_dir($destDir)) {
                @mkdir($destDir, 0775, true);
            }
            $dest = $destDir . '/' . safe_segment($slug) . '_model_files.zip';

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
        $files = $svc->getModelFiles($modelId, $fileTy);

        // Dead token: stop the whole run so the user re-auths; requeue this job.
        if ($svc->lastError !== '' && str_contains($svc->lastError, 'rejected')) {
            $upd->execute([':st' => 'queued', ':inc' => 0, ':err' => $svc->lastError, ':path' => '', ':id' => $jobId]);
            logerr('error', '  Token rejected — halting run. Re-auth in Settings.');
            break;
        }

        if ($files === []) {
            $msg = $svc->lastError !== '' ? $svc->lastError : 'No matching files on model.';
            $upd->execute([':st' => 'skipped', ':inc' => 1, ':err' => $msg, ':path' => '', ':id' => $jobId]);
            logerr('warn', '  Skipped: ' . $msg);
            continue;
        }

        $destDir   = rtrim($baseDir, '/') . '/' . safe_segment($slug);
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
    $delay = $delay ?? DOWNLOAD_DELAY_SECONDS;
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

/** Make a filesystem-safe path segment (no traversal, no separators). */
function safe_segment(string $s): string
{
    $s = preg_replace('/[\/\\\\\x00-\x1F]+/', '_', $s) ?? $s;
    $s = trim($s, " .");
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    if ($s === '' || $s === '.' || $s === '..') {
        $s = 'file';
    }
    return substr($s, 0, 150);
}
