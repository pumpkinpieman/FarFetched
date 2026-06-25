<?php
/**
 * crawl_status.php — check whether the Hex3D crawler is running, and optionally
 * (re)start it. CLI only. Quote-free, so it works from any shell.
 *
 *   Check only:
 *     docker exec FarFetched php /var/www/html/webroot/crawl_status.php
 *
 *   Start if not already running (won't launch a duplicate):
 *     docker exec FarFetched php /var/www/html/webroot/crawl_status.php --start
 *
 *   Force kill any running crawler, reset state, then start fresh:
 *     docker exec FarFetched php /var/www/html/webroot/crawl_status.php --restart
 *
 * Both --start and --restart verify the session is alive before launching, so
 * you never kick off a crawl that will immediately die with "session expired".
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/bootstrap.php';

$flags    = array_slice($argv, 1);
$doStart  = in_array('--start', $flags, true);
$doRestart= in_array('--restart', $flags, true);

$CRAWL  = __DIR__ . '/hex3d_crawl.php';
$LOG    = sys_get_temp_dir() . '/hex3d_crawl.log';
$LOCK   = sys_get_temp_dir() . '/hex3d_crawl.lock';

function line(string $s = ''): void { echo $s . "\n"; }

/** Is a hex3d_crawl.php process currently running? Returns the PID list. */
function crawler_pids(): array
{
    $out = [];
    @exec('pgrep -f hex3d_crawl.php 2>/dev/null', $out);
    // pgrep may match this very script if it shares the name — it doesn't here
    // (this is crawl_status.php), so the list is clean.
    return array_values(array_filter(array_map('trim', $out), static fn($p) => $p !== ''));
}

line('FarFetched — Hex3D crawler status');
line('==================================');

// --- Running? -------------------------------------------------------------
$pids = crawler_pids();
$running = $pids !== [];
line('• process: ' . ($running ? ('RUNNING (pid ' . implode(', ', $pids) . ')') : 'not running'));

// --- Lockfile -------------------------------------------------------------
line('• lock:    ' . (is_file($LOCK) ? ('present (' . $LOCK . ')') : 'none'));

// --- DB state -------------------------------------------------------------
try {
    $st = db()->query('SELECT * FROM hex3d_crawl_state WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
} catch (\Throwable $e) { $st = null; }
if ($st) {
    line('• state:   status=' . ($st['status'] ?? '?')
        . '  topics_seen=' . ($st['topics_seen'] ?? 0)
        . '  details_done=' . ($st['details_done'] ?? 0));
    if (!empty($st['last_error'])) line('           last_error: ' . $st['last_error']);
    if (!empty($st['updated_at'])) line('           updated_at: ' . $st['updated_at']);
} else {
    line('• state:   (no row yet)');
}

// --- Recent log tail ------------------------------------------------------
if (is_file($LOG)) {
    $lines = @file($LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $tail = array_slice($lines, -3);
    if ($tail) {
        line('• log tail:');
        foreach ($tail as $l) line('    ' . $l);
    }
}

// --- Nothing more to do unless asked to start/restart ---------------------
if (!$doStart && !$doRestart) {
    line('');
    line($running
        ? 'Crawler is running. Use --restart to kill and relaunch.'
        : 'Crawler is idle. Use --start to launch (or --restart to reset + launch).');
    exit(0);
}

line('');
line('-- ' . ($doRestart ? 'restart' : 'start') . ' requested --');

// --- Restart: kill + clear lock + reset state -----------------------------
if ($doRestart) {
    if ($running) {
        @exec('pkill -f hex3d_crawl.php 2>/dev/null');
        line('• killed running crawler');
        usleep(300000); // give it a moment to release the lock
    }
    if (is_file($LOCK)) { @unlink($LOCK); line('• cleared lockfile'); }
    try {
        db()->exec("UPDATE hex3d_crawl_state SET status = 'idle', last_error = '' WHERE id = 1");
        line('• reset state to idle');
    } catch (\Throwable $e) {
        line('• reset state FAILED: ' . $e->getMessage());
    }
    $running = false;
}

// --- Start: refuse if already running (no duplicates) ---------------------
if ($doStart && $running) {
    line('• already running — not launching a duplicate. Use --restart to relaunch.');
    exit(0);
}

// --- Verify the session is alive before launching -------------------------
if (!hex3dforum_configured()) {
    line('• session: NOT configured — paste your cookie in Settings → Hex3D Forum first.');
    line('  Aborting; nothing launched.');
    exit(0); // valid status, not a script error
}
$alive = false; $err = '';
$svcFile = __DIR__ . '/Hex3DForumService.php';
if (is_file($svcFile)) {
    require_once $svcFile;
    try {
        $probe = new Hex3DForumService();
        $alive = $probe->discoverForums() !== [];
        if (!$alive) $err = (string) ($probe->lastError ?? '');
    } catch (\Throwable $e) { $err = $e->getMessage(); }
}
if (!$alive) {
    line('• session: DEAD — no forums discoverable' . ($err !== '' ? ' (' . $err . ')' : '') . '.');
    line('  Re-paste a fresh SID in Settings → Hex3D Forum, then run this again.');
    line('  Aborting; nothing launched.');
    exit(0); // valid status (session needs refresh), not a script error
}
line('• session: alive ✓');

// --- Launch detached ------------------------------------------------------
$cmd = 'nohup php ' . escapeshellarg($CRAWL) . ' > ' . escapeshellarg($LOG) . ' 2>&1 &';
@exec($cmd);
usleep(400000); // let it spin up so we can confirm
$nowPids = crawler_pids();
if ($nowPids !== []) {
    line('• launched ✓ (pid ' . implode(', ', $nowPids) . ')');
    line('  Watch: docker exec FarFetched tail -f ' . $LOG);
} else {
    line('• launch attempted, but no process detected yet — check the log:');
    line('  docker exec FarFetched tail -f ' . $LOG);
}
