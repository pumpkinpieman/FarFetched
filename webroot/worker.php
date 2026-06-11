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

// Retry cap now comes from the UI-editable config (clamped in cfg_save).
$maxAttempts = (int) cfg('max_attempts');
const LOCK_FILE      = PRIVATE_DIR . '/worker.lock';
const MAX_RUN_SECONDS = 6 * 3600; // safety ceiling per invocation

function logln(string $m): void
{
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $m . "\n");
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
if (!$svc->isAuthed()) {
    logln('No token configured — set one in Settings. Exiting.');
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
$startedAt = time();
$processed = 0;

logln('Worker start. dir=' . $baseDir . ' delay=' . DOWNLOAD_DELAY_SECONDS . 's');

while (true) {
    if (time() - $startedAt > MAX_RUN_SECONDS) {
        logln('Run-time ceiling hit — exiting (cron will resume).');
        break;
    }

    // Claim the next job atomically: select oldest queued, flip to working.
    $job = $pdo->query(
        "SELECT * FROM download_jobs
         WHERE status = 'queued'
         ORDER BY id ASC LIMIT 1"
    )->fetch();

    if (!$job) {
        logln('Queue empty. Processed ' . $processed . ' job(s) this run.');
        break;
    }

    $jobId   = (int) $job['id'];
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
                logln('  Token rejected — halting run. Re-auth in Settings.');
                break;
            }
            if ($link === '') {
                $msg = $svc->lastError !== '' ? $svc->lastError : 'No pack available.';
                $upd->execute([':st' => 'skipped', ':inc' => 1, ':err' => $msg, ':path' => '', ':id' => $jobId]);
                logln('  Skipped: ' . $msg);
                pace();
                continue;
            }

            $destDir = rtrim($baseDir, '/') . '/' . safe_segment($slug);
            if (!is_dir($destDir)) {
                @mkdir($destDir, 0775, true);
            }
            $dest = $destDir . '/' . safe_segment($slug) . '_model_files.zip';

            if (is_file($dest)) {
                logln('  Exists, skip: ' . basename($dest));
                $upd->execute([':st' => 'done', ':inc' => 1, ':err' => '', ':path' => $dest, ':id' => $jobId]);
                continue; // no network, no pace
            }

            if ($svc->downloadToFile($link, $dest)) {
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
        pace();
        continue;
    }

    try {
        $files = $svc->getModelFiles($modelId, $fileTy);

        // Dead token: stop the whole run so the user re-auths; requeue this job.
        if ($svc->lastError !== '' && str_contains($svc->lastError, 'rejected')) {
            $upd->execute([':st' => 'queued', ':inc' => 0, ':err' => $svc->lastError, ':path' => '', ':id' => $jobId]);
            logln('  Token rejected — halting run. Re-auth in Settings.');
            break;
        }

        if ($files === []) {
            $msg = $svc->lastError !== '' ? $svc->lastError : 'No matching files on model.';
            $upd->execute([':st' => 'skipped', ':inc' => 1, ':err' => $msg, ':path' => '', ':id' => $jobId]);
            logln('  Skipped: ' . $msg);
            continue;
        }

        $destDir   = rtrim($baseDir, '/') . '/' . safe_segment($slug);
        $savedAny  = false;
        $lastPath  = '';

        foreach ($files as $i => $f) {
            $link = $svc->getDownloadLink($f['id'], $modelId, $fileTy);
            if ($link === '') {
                logln('  Link refused for file ' . $f['id'] . ': ' . $svc->lastError);
                pace();
                continue;
            }

            $fname = safe_segment($f['name']);
            if (!preg_match('/\.(stl|3mf)$/i', $fname)) {
                $fname .= '.' . strtolower($fileTy);
            }
            $dest = $destDir . '/' . $fname;

            if (is_file($dest)) {
                logln('  Exists, skip: ' . $fname);
                $savedAny = true;
                $lastPath = $dest;
                continue; // no network, no pace
            }

            if ($svc->downloadToFile($link, $dest)) {
                logln('  Saved: ' . $dest);
                $savedAny = true;
                $lastPath = $dest;
            } else {
                logln('  Download failed: ' . $svc->lastError);
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
    pace(); // pace between jobs too
}

flock($lock, LOCK_UN);
fclose($lock);
logln('Worker exit.');

// ---- helpers --------------------------------------------------------------
function pace(): void
{
    sleep(DOWNLOAD_DELAY_SECONDS);
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
