<?php
/**
 * hex3d_crawl.php — background indexer for the Hex3D Forum source.
 *
 * WHY THIS EXISTS
 * The Hex3D board is slow, session-gated, and paginated 25-topics-per-page
 * across ~10+ access-tier forums (≈1500 topics total). Browsing it live is
 * painful, so this crawler walks every accessible forum and records each topic
 * (title, thumbnail, attachment IDs) into the local `hex3d_topics` table. The
 * Browse/Search UI then reads that index instantly instead of hitting the forum.
 *
 * DESIGN NOTES (hard-won this session)
 *  - The phpBB session is stable *within* an active window but dies between
 *    them; a full first crawl (~25h at the safe 60s pace) will outlive any one
 *    session. So this crawler is RESUMABLE and INCREMENTAL: every topic is
 *    committed the instant it's indexed, `detail_done` tracks which topics
 *    still need their per-topic page fetched, and a re-run simply continues
 *    where the last left off. A dead session is detected and the run exits
 *    cleanly (state = 'stalled') rather than corrupting the index.
 *  - Two phases per run:
 *      Phase 1 (cheap): walk each forum's listing pages, INSERT OR IGNORE every
 *        topic id + title. Fast, ~1 request per 25 topics.
 *      Phase 2 (costly): for every topic with detail_done=0, fetch its page
 *        once to fill thumbnail + attachment IDs, paced by the download delay.
 *    Phase 1 alone gives an immediately-browsable (thumbnail-less) index; phase
 *    2 enriches it over subsequent runs.
 *
 * USAGE
 *   docker exec FarFetched php /var/www/html/webroot/hex3d_crawl.php
 * or via cron (see Settings → Hex3D Forum for the suggested crontab line).
 * Honors a --phase1-only flag to do just the fast listing pass.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/Hex3DForumService.php';

// CLI only — never web-accessible (it's long-running and writes the index).
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("hex3d_crawl.php is a CLI script.\n");
}

$phase1Only = in_array('--phase1-only', $argv, true);

function crawl_log(string $msg): void
{
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n");
}

/** Update the single-row crawl state for the Settings UI. */
/**
 * Execute a write with retry on SQLite lock contention. The download worker
 * and this crawler share one DB; on FUSE/network mounts busy_timeout isn't
 * always honored, so a transient lock must not crash either process. Mirrors
 * the worker's db_exec_retry so neither side starves the other.
 */
function crawl_exec_retry(string $sql, array $args): void
{
    crawl_retry(static function () use ($sql, $args) {
        db()->prepare($sql)->execute($args);
    });
}

/** Retry any DB write callable on lock contention. */
function crawl_retry(callable $fn): void
{
    $maxAttempts = 12;
    for ($attempt = 1; ; $attempt++) {
        try {
            $fn();
            return;
        } catch (\PDOException $e) {
            $locked = stripos($e->getMessage(), 'locked') !== false
                   || stripos($e->getMessage(), 'busy') !== false;
            if (!$locked || $attempt >= $maxAttempts) {
                throw $e;
            }
            $backoff = (int) min(3000000, 250000 * $attempt);
            usleep($backoff + random_int(0, 250000));
        }
    }
}

/**
 * Yield the database to the download worker. In WAL mode two processes cannot
 * write concurrently — the second gets immediate SQLITE_BUSY no matter the
 * busy_timeout. Since the worker is the user-facing priority (active downloads)
 * and the crawler is a background batch job, the crawler waits while the worker
 * holds its single-instance lock. We test that lock non-destructively: if we
 * can grab it we release immediately (worker idle); if not, the worker is busy
 * and we pause. Caps the wait so a wedged worker can't stall the crawl forever.
 */
function wait_for_worker_idle(int $maxWaitSeconds = 180): void
{
    $lockPath = PRIVATE_DIR . '/worker.lock';
    $waited = 0;
    while ($waited < $maxWaitSeconds) {
        $fh = @fopen($lockPath, 'c');
        if ($fh === false) return; // can't test — proceed rather than block
        $got = @flock($fh, LOCK_EX | LOCK_NB);
        if ($got) {
            // Worker isn't holding it → safe to write. Release and go.
            @flock($fh, LOCK_UN);
            @fclose($fh);
            return;
        }
        @fclose($fh);
        // Worker is active — back off and re-check.
        sleep(3);
        $waited += 3;
    }
    // Timed out waiting; proceed anyway (retry logic still guards the write).
}

function crawl_state(array $fields): void
{
    $fields['updated_at'] = date('Y-m-d H:i:s');
    $sets = [];
    $args = [':id' => 1];
    foreach ($fields as $k => $v) {
        $sets[]    = "$k = :$k";
        $args[":$k"] = $v;
    }
    $sql = 'UPDATE hex3d_crawl_state SET ' . implode(', ', $sets) . ' WHERE id = :id';
    crawl_exec_retry($sql, $args);
}

function counts(): array
{
    $seen  = (int) db()->query('SELECT COUNT(*) FROM hex3d_topics')->fetchColumn();
    $done  = (int) db()->query('SELECT COUNT(*) FROM hex3d_topics WHERE detail_done = 1')->fetchColumn();
    return [$seen, $done];
}

// ---------------------------------------------------------------------------

if (!hex3dforum_configured()) {
    crawl_log('No Hex3D Forum session configured — nothing to crawl. Set it in Settings.');
    crawl_state(['status' => 'idle', 'last_error' => 'No session configured.']);
    exit(1);
}

$svc = new Hex3DForumService();

// Liveness check: a dead session renders the index as a guest with zero
// discoverable forums. Bail cleanly so a stale cookie doesn't wipe progress.
$forums = $svc->discoverForums();
if ($forums === []) {
    crawl_log('Session appears dead (no forums discoverable). Re-paste the cookie in Settings. Exiting without changes.');
    crawl_state(['status' => 'stalled', 'last_error' => 'Session expired — re-paste cookie. Crawl will resume next run.']);
    exit(2);
}

crawl_log('Session OK. ' . count($forums) . ' forums discoverable.');

// Concurrency guard: a second crawl running alongside the first would fight the
// SQLite write lock and double the load on the board. Use a simple PID lockfile;
// if a live process already holds it, exit. Stale locks (process gone) are
// reclaimed automatically.
$lockFile = sys_get_temp_dir() . '/hex3d_crawl.lock';
$lockOk = false;
if (is_file($lockFile)) {
    $oldPid = (int) @file_get_contents($lockFile);
    if ($oldPid > 0 && function_exists('posix_kill') && @posix_kill($oldPid, 0)) {
        crawl_log("Another crawl is already running (pid $oldPid). Exiting.");
        exit(3);
    }
    // Stale lock (or no posix to verify) — assume the previous run died and
    // reclaim it rather than blocking forever.
}
@file_put_contents($lockFile, (string) getmypid());
$lockOk = true;
// Release the lock on any exit path.
register_shutdown_function(static function () use ($lockFile) {
    @unlink($lockFile);
});

// Clean interruption: Ctrl-C (SIGINT) or a kill (SIGTERM) should leave the
// state as 'stalled' (resumable) rather than stuck on 'running'. Requires pcntl;
// if it's not built in, the worst case is the old stuck-'running' behavior,
// which the next run overwrites anyway.
if (function_exists('pcntl_signal') && function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $sigHandler = static function (int $signo): void {
        [$seen, $done] = counts();
        crawl_state(['status' => 'stalled', 'topics_seen' => $seen, 'details_done' => $done,
                     'last_error' => 'Interrupted manually — resumes on next run.']);
        crawl_log('Interrupted (signal ' . $signo . '). State saved; exiting.');
        exit(130);
    };
    pcntl_signal(SIGINT,  $sigHandler);
    pcntl_signal(SIGTERM, $sigHandler);
}

crawl_state([
    'status'      => 'running',
    'started_at'  => date('Y-m-d H:i:s'),
    'finished_at' => '',
    'last_error'  => '',
]);

$delay = max(1, (int) cfg('hex3dforum_delay'));   // seconds between paced requests
$perPage = 25;                                     // confirmed: 25 topics/page

$insTopic = db()->prepare(
    'INSERT OR IGNORE INTO hex3d_topics (forum_id, topic_id, forum_name, title)
     VALUES (:f, :t, :fn, :ti)'
);

// ---- PHASE 1: cheap listing walk — every topic id + title -----------------
crawl_log('Phase 1: walking forum listings…');
foreach ($forums as $fid => $fname) {
    $fid = (string) $fid;

    // First page tells us the total topic count for this forum.
    $first = $svc->browse($fid, $perPage, 0);
    if ($svc->lastError !== '' && $first === []) {
        // Could be a tier the account can't enter — skip, don't abort.
        crawl_log("  forum $fid ($fname): " . $svc->lastError . ' — skipping.');
        continue;
    }
    $total = max(count($first), $svc->lastTotal);

    // Container forums (e.g. "Level Access") hold sub-forums but no topics of
    // their own — discoverForums() already returns those sub-forums separately,
    // so an empty forum here is just a category wrapper. Skip it WITHOUT the
    // pacing delay (no request is saved by waiting on nothing).
    if ($total === 0 && $first === []) {
        crawl_log("  forum $fid ($fname): container/empty — skipping.");
        continue;
    }
    crawl_log("  forum $fid ($fname): $total topics");

    $page = $first;
    $offset = 0;
    while ($page !== []) {
        // Yield the DB to the download worker if it's actively running, so we
        // don't fight it for the WAL write lock.
        wait_for_worker_idle();
        foreach ($page as $t) {
            crawl_retry(static function () use ($insTopic, $fid, $t, $fname) {
                $insTopic->execute([
                    ':f'  => $fid,
                    ':t'  => (string) $t['id'],
                    ':fn' => $fname,
                    ':ti' => (string) $t['name'],
                ]);
            });
        }
        // Flush the running count every page so the Settings UI reflects
        // progress live (not just once per forum at the end).
        [$seen, $done] = counts();
        crawl_state(['topics_seen' => $seen, 'details_done' => $done]);

        $offset += $perPage;
        if ($offset >= $total) break;

        sleep($delay);
        $page = $svc->browse($fid, $perPage, $offset);
        if ($svc->lastError !== '') {
            // Session may have died mid-walk — stop cleanly, keep progress.
            crawl_log('  listing interrupted: ' . $svc->lastError);
            [$seen, $done] = counts();
            crawl_state(['status' => 'stalled', 'topics_seen' => $seen, 'details_done' => $done,
                         'last_error' => 'Interrupted during listing: ' . $svc->lastError]);
            exit(2);
        }
    }

    [$seen, $done] = counts();
    crawl_state(['topics_seen' => $seen, 'details_done' => $done]);
    sleep($delay);
}

[$seen, $done] = counts();
crawl_log("Phase 1 complete. $seen topics indexed (titles).");

if ($phase1Only) {
    crawl_state(['status' => 'idle', 'finished_at' => date('Y-m-d H:i:s'),
                 'topics_seen' => $seen, 'details_done' => $done,
                 'last_error' => '']);
    crawl_log('Phase-1-only run done.');
    exit(0);
}

// ---- PHASE 2: per-topic detail (thumbnail + attachment ids) ---------------
crawl_log('Phase 2: filling topic details (thumbnails + attachments)…');

$pending = db()->query(
    'SELECT forum_id, topic_id FROM hex3d_topics WHERE detail_done = 0 ORDER BY first_seen'
)->fetchAll(PDO::FETCH_ASSOC);

crawl_log(count($pending) . ' topics need detail.');

$updTopic = db()->prepare(
    'UPDATE hex3d_topics
        SET thumb = :th, attachment_ids = :ai, detail_done = 1, indexed_at = datetime(\'now\')
      WHERE forum_id = :f AND topic_id = :t'
);

$processed = 0;
foreach ($pending as $row) {
    $detail = $svc->fetchTopicDetail((string) $row['forum_id'], (string) $row['topic_id']);

    if ($detail === null) {
        // A null here on a previously-working session means it likely just
        // died. Stop cleanly and resume next run.
        crawl_log('  detail fetch failed (' . $svc->lastError . ') — pausing. Resumes next run.');
        [$seen, $done] = counts();
        crawl_state(['status' => 'stalled', 'topics_seen' => $seen, 'details_done' => $done,
                     'last_error' => 'Paused during detail pass: ' . $svc->lastError]);
        exit(2);
    }

    wait_for_worker_idle();
    crawl_retry(static function () use ($updTopic, $detail, $row) {
        $updTopic->execute([
            ':th' => $detail['thumb'],
            ':ai' => json_encode($detail['attachment_ids']),
            ':f'  => (string) $row['forum_id'],
            ':t'  => (string) $row['topic_id'],
        ]);
    });

    $processed++;
    if ($processed % 10 === 0) {
        [$seen, $done] = counts();
        crawl_state(['topics_seen' => $seen, 'details_done' => $done]);
        crawl_log("  …$processed/" . count($pending) . " details done");
    }

    sleep($delay);
}

[$seen, $done] = counts();
crawl_state(['status' => 'idle', 'finished_at' => date('Y-m-d H:i:s'),
             'topics_seen' => $seen, 'details_done' => $done, 'last_error' => '']);
crawl_log("Crawl complete. $seen topics, $done with full detail.");
exit(0);
