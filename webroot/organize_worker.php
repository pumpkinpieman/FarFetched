<?php
/**
 * organize_worker.php — optional background driver for Organize.
 *
 * Runs the same chunk engine as the AJAX driver, but detached, so a large
 * organize survives the browser tab closing. Launch detached:
 *
 *   php organize_worker.php <custom_folder_id>
 *
 * It loops organize_chunk() until the folder is done or the state is paused
 * (the UI's Pause button sets status=paused; this worker exits cleanly then).
 * A PID lockfile prevents two workers organizing the same folder at once.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$id = preg_replace('/[^A-Za-z0-9]/', '', (string) ($argv[1] ?? ''));
if ($id === '') { fwrite(STDERR, "usage: organize_worker.php <folder_id>\n"); exit(1); }

$folder = null;
foreach (custom_folders() as $f) { if ($f['id'] === $id) { $folder = $f; break; } }
if ($folder === null) { fwrite(STDERR, "unknown folder id\n"); exit(1); }

$root = $folder['path'];
if (!is_dir($root)) { fwrite(STDERR, "folder not reachable: $root\n"); exit(1); }

// Per-folder lock so we never run two workers on the same folder.
$lock = rtrim($root, '/') . '/_processing/worker.lock';
@mkdir(dirname($lock), 0777, true);
$fp = @fopen($lock, 'c');
if ($fp === false || !flock($fp, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "another organize worker is already running for this folder\n");
    exit(0);
}
@ftruncate($fp, 0);
@fwrite($fp, (string) getmypid());
@fflush($fp);

// Seed state if not already started (idempotent — resumes if it exists).
if (organize_read_state($root) === null) {
    organize_start($root);
}

echo "Organizing: {$folder['label']} ($root)\n";
$loops = 0;
while (true) {
    $r = organize_chunk($root, 8);
    if (!($r['ok'] ?? false)) {
        fwrite(STDERR, "error: " . ($r['error'] ?? 'unknown') . "\n");
        break;
    }
    $st = $r['state'] ?? [];
    $status = $st['status'] ?? 'running';
    echo sprintf("  %d/%d (%s)%s\n",
        (int) ($st['done'] ?? 0),
        (int) ($st['total'] ?? 0),
        $status,
        isset($st['current']) && $st['current'] !== null ? ' — ' . $st['current'] : ''
    );

    if ($status === 'paused') { echo "Paused — exiting; resume to continue.\n"; break; }
    if ($status === 'done' || ($r['done'] ?? false)) { echo "Done.\n"; break; }

    // Safety valve so a logic bug can't spin forever.
    if (++$loops > 100000) { fwrite(STDERR, "loop cap hit\n"); break; }
    usleep(50000); // 50ms breather between chunks
}

flock($fp, LOCK_UN);
@fclose($fp);
