<?php
/**
 * reset_crawl.php — Hex3D crawler reset + status helper (CLI only).
 *
 * Quote-free replacement for the fragile inline `php -r '...'` reset command,
 * which breaks under PowerShell's quote handling. Run from ANY shell:
 *
 *   docker exec FarFetched php /var/www/html/webroot/reset_crawl.php
 *
 * What it does:
 *   1. Clears the crawl lockfile (so a fresh crawl can start).
 *   2. Resets crawl state to 'idle' and clears the last error.
 *   3. Reports whether a session is configured and whether forums are actually
 *      discoverable right now — so you know if the SID is alive BEFORE launching
 *      a crawl (phpBB SIDs rotate and a "connected" Settings save can already be
 *      stale by crawl time).
 *
 * Flags:
 *   --kill      also pkill any running hex3d_crawl.php process first
 *   --status    only report status; do NOT reset anything
 *   --no-check  skip the live forum-discovery probe (faster, offline)
 */

declare(strict_types=1);

// CLI guard — never expose this over HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/bootstrap.php';

cli_fix_ownership_after_root();

$argvFlags = array_slice($argv, 1);
$has = static fn(string $f): bool => in_array($f, $argvFlags, true);

$doKill   = $has('--kill');
$statusOnly = $has('--status');
$noCheck  = $has('--no-check');

function line(string $s = ''): void { echo $s . "\n"; }

line('FarFetched — Hex3D crawl reset/status');
line('======================================');

// --- 1. Optionally kill a running crawler --------------------------------
if ($doKill && !$statusOnly) {
    // We're inside the container already, so target the process directly.
    @exec('pkill -f hex3d_crawl.php 2>/dev/null', $o, $rc);
    line('• kill: sent pkill to hex3d_crawl.php (' . ($rc === 0 ? 'a process was running' : 'none running') . ')');
}

// --- 2. Lockfile ----------------------------------------------------------
$lock = sys_get_temp_dir() . '/hex3d_crawl.lock';
if (!$statusOnly) {
    if (is_file($lock)) {
        @unlink($lock);
        line('• lock: removed ' . $lock);
    } else {
        line('• lock: none present (' . $lock . ')');
    }
} else {
    line('• lock: ' . (is_file($lock) ? 'PRESENT (' . $lock . ')' : 'none'));
}

// --- 3. Current state (before reset) -------------------------------------
try {
    $row = db()->query('SELECT * FROM hex3d_crawl_state WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $row = null;
}
if ($row) {
    line('• state: status=' . ($row['status'] ?? '?')
        . '  topics_seen=' . ($row['topics_seen'] ?? 0)
        . '  details_done=' . ($row['details_done'] ?? 0));
    if (!empty($row['last_error'])) {
        line('         last_error: ' . $row['last_error']);
    }
    if (!empty($row['started_at']))  line('         started_at:  ' . $row['started_at']);
    if (!empty($row['finished_at'])) line('         finished_at: ' . $row['finished_at']);
} else {
    line('• state: (no row yet — will be created on first crawl)');
}

// --- 4. Reset to idle -----------------------------------------------------
if (!$statusOnly) {
    try {
        db()->exec("UPDATE hex3d_crawl_state SET status = 'idle', last_error = '' WHERE id = 1");
        line('• reset: status set to idle, last_error cleared');
    } catch (\Throwable $e) {
        line('• reset: FAILED — ' . $e->getMessage());
    }
}

// --- 5. Session / SID status ---------------------------------------------
$configured = function_exists('hex3dforum_configured') ? hex3dforum_configured() : false;
$sid = (string) cfg('hex3dforum_sid');
$u   = (string) cfg('hex3dforum_u');
line('• session: configured=' . ($configured ? 'yes' : 'NO')
    . '  sid=' . ($sid !== '' ? substr($sid, 0, 6) . '… (' . strlen($sid) . ' chars)' : '(empty)')
    . '  u=' . ($u !== '' ? $u : '(empty)'));

// --- 6. Live discovery probe (is the SID actually alive right now?) -------
if (!$noCheck && $configured) {
    $svcFile = __DIR__ . '/Hex3DForumService.php';
    if (is_file($svcFile)) {
        require_once $svcFile;
        try {
            $svc = new Hex3DForumService();
            $forums = $svc->discoverForums();
            if ($forums !== []) {
                line('• live:  SESSION ALIVE ✓ — ' . count($forums) . ' forum(s) discoverable. Safe to crawl.');
            } else {
                $err = $svc->lastError ?? '';
                line('• live:  SESSION DEAD ✗ — no forums discoverable.' . ($err !== '' ? ' (' . $err . ')' : ''));
                line('         → Re-paste a fresh SID in Settings → Hex3D Forum, then relaunch.');
            }
        } catch (\Throwable $e) {
            line('• live:  probe error — ' . $e->getMessage());
        }
    } else {
        line('• live:  (Hex3DForumService.php not found — skipped probe)');
    }
} elseif (!$configured) {
    line('• live:  skipped — no session configured. Paste your cookie in Settings first.');
} else {
    line('• live:  skipped (--no-check)');
}

line('');
line('Next: launch a crawl with —');
line('  docker exec -d FarFetched sh -c "php /var/www/html/webroot/hex3d_crawl.php > /tmp/hex3d_crawl.log 2>&1"');
line('Then watch: docker exec FarFetched tail -f /tmp/hex3d_crawl.log');
