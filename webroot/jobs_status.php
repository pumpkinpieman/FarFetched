<?php
declare(strict_types=1);

/**
 * jobs_status.php — JSON snapshot for the live queue UI (polled by jobs.php).
 *
 * Returns queue counts, the job rows, and the worker's current activity
 * (active download byte-progress, or the pacing countdown). Reads the worker's
 * status from a small JSON file, never the SQLite DB, to avoid lock contention
 * with the cron worker's WAL writes.
 */

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$pdo = db();

// Counts by status (single cheap aggregate).
$by = [];
$total = 0;
foreach ($pdo->query("SELECT status, COUNT(*) c FROM download_jobs GROUP BY status") as $r) {
    $by[$r['status']] = (int) $r['c'];
    $total += (int) $r['c'];
}
$done = (int) ($by['done'] ?? 0);

// Job rows (same ordering as the page; capped).
$jobs = [];
$q = $pdo->query(
    "SELECT id, model_id, name, creator, file_type, status, attempts, last_error
     FROM download_jobs
     ORDER BY CASE status WHEN 'working' THEN 0 WHEN 'queued' THEN 1 WHEN 'failed' THEN 2
                          WHEN 'skipped' THEN 3 ELSE 4 END, updated_at DESC
     LIMIT 500"
);
foreach ($q as $r) {
    $jobs[] = [
        'id'         => (int) $r['id'],
        'model_id'   => (string) $r['model_id'],
        'name'       => (string) ($r['name'] ?? ''),
        'creator'    => (string) ($r['creator'] ?? ''),
        'file_type'  => (string) $r['file_type'],
        'status'     => (string) $r['status'],
        'attempts'   => (int) $r['attempts'],
        'last_error' => (string) ($r['last_error'] ?? ''),
    ];
}

// Live worker activity (null if the worker isn't currently running).
$active = null;
$ws = read_worker_status();
if ($ws !== null) {
    $phase = (string) ($ws['phase'] ?? '');
    $now = microtime(true);
    if ($phase === 'downloading') {
        $bytes = (int) ($ws['bytes'] ?? 0);
        $tot   = (int) ($ws['total'] ?? 0);
        $active = [
            'phase'   => 'downloading',
            'job_id'  => isset($ws['job_id']) ? (int) $ws['job_id'] : null,
            'file'    => (string) ($ws['file'] ?? ''),
            'bytes'   => $bytes,
            'total'   => $tot,
            'percent' => $tot > 0 ? min(100, (int) floor(($bytes / $tot) * 100)) : null,
        ];
    } elseif ($phase === 'waiting') {
        $active = [
            'phase'     => 'waiting',
            'job_id'    => isset($ws['job_id']) ? (int) $ws['job_id'] : null,
            'remaining' => max(0, (int) ceil(((float) ($ws['next_at'] ?? $now)) - $now)),
            'delay'     => (int) ($ws['delay'] ?? 0),
        ];
    } else {
        $active = ['phase' => 'idle'];
    }
}

// Estimated time to drain the queue at the configured pace. Each queued job
// incurs one pace gap for its source; transfer time is unpredictable so it's
// excluded (and labelled as such in the UI).
$pbDelay = defined('DOWNLOAD_DELAY_SECONDS')   ? (int) DOWNLOAD_DELAY_SECONDS   : 45;
$mwDelay = defined('MAKERWORLD_DELAY_SECONDS') ? (int) MAKERWORLD_DELAY_SECONDS : $pbDelay;
$eta = 0;
try {
    foreach ($pdo->query("SELECT source, COUNT(*) c FROM download_jobs WHERE status='queued' GROUP BY source") as $r) {
        $d = ((string) ($r['source'] ?? '') === 'makerworld') ? $mwDelay : $pbDelay;
        $eta += (int) $r['c'] * $d;
    }
} catch (\Throwable $e) {
    // pre-migration row without source column — fall back to a flat estimate
    $eta = (int) ($by['queued'] ?? 0) * $pbDelay;
}
if ($active && ($active['phase'] ?? '') === 'waiting') {
    $eta += (int) ($active['remaining'] ?? 0);
}

echo json_encode([
    'counts'      => ['total' => $total, 'done' => $done, 'by' => $by],
    'active'      => $active,
    'jobs'        => $jobs,
    'feed'        => worker_feed_tail(20),
    'eta_seconds' => $eta,
    'pace'        => ['printables' => $pbDelay, 'makerworld' => $mwDelay],
]);
