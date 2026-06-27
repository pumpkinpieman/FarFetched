<?php
declare(strict_types=1);

/**
 * enqueue.php — receives the selected models and queues them.
 *
 * Accepts JSON: { csrf, fileType, models: [ {id,slug,name,creator}, ... ] }
 * Inserts one queued row per model (UNIQUE(model_id,file_type) makes re-queueing
 * a no-op via INSERT OR IGNORE — safe to click twice).
 *
 * Returns JSON: { ok, queued, skipped, error? }
 *
 * Security:
 *   - CSRF required.
 *   - Prepared statements only (SQLi-safe).
 *   - Hard cap on batch size (defensive against a runaway payload).
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth.php';
if (!auth_check()) { http_response_code(401); header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>'auth required']); exit; }

header('Content-Type: application/json');

function fail(string $msg, int $code = 400): never
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('POST required.', 405);
}

// Body is JSON (sent by fetch from index.php).
$raw = file_get_contents('php://input') ?: '';
$in  = json_decode($raw, true);
if (!is_array($in)) {
    fail('Malformed JSON body.');
}

// CSRF (token echoed from the page, compared to session).
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$csrf = (string) ($in['csrf'] ?? '');
if (empty($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], $csrf)) {
    fail('CSRF check failed — reload the page.', 419);
}

$fileType = strtoupper(trim((string) ($in['fileType'] ?? 'STL')));
if (!in_array($fileType, ['STL', '3MF', 'PACK'], true)) {
    $fileType = 'STL';
}

$source = strtolower(trim((string) ($in['source'] ?? 'printables')));
if (!in_array($source, ['printables', 'makerworld', 'thingiverse', 'cults3d', 'stlflix', 'creality', 'nikko', 'hex3dforum'], true)) {
    $source = 'printables';
}
if ($source === 'makerworld') { $fileType = 'PACK'; }
if ($source === 'thingiverse' || $source === 'cults3d' || $source === 'stlflix' || $source === 'creality' || $source === 'nikko' || $source === 'hex3dforum') { $fileType = 'PACK'; }

$models = $in['models'] ?? null;
if (!is_array($models) || $models === []) {
    fail('No models selected.');
}
$batchCap = (int) cfg('batch_cap');
if (count($models) > $batchCap) {
    fail('Batch too large (max ' . $batchCap . ' per submit).');
}

$pdo = db();
$stmt = $pdo->prepare(
    'INSERT OR IGNORE INTO download_jobs (source, model_id, slug, name, creator, file_type, cover_url, status)
     VALUES (:source, :model_id, :slug, :name, :creator, :file_type, :cover_url, "queued")'
);

$queued = 0;
$skipped = 0;

// Serialize this transaction against the worker's writes via the same
// cross-platform mutex the worker uses. The enqueue runs inside a transaction
// (which holds a write lock for its duration); without the mutex it can collide
// with an active worker status-write and fail with "database is locked" — the
// exact "database error while queueing" seen when adding models while the queue
// is running. Acquiring the mutex makes the UI insert wait for the worker's
// write to finish instead of erroring. Retries cover the rare lock that slips
// through (e.g. the mutex was briefly unavailable).
$enqueueAttempts = 0;
$enqueueMax = 8;
enqueue_attempt:
$enqueueAttempts++;
$lock = db_write_lock();
try {
    $pdo->beginTransaction();
    $queued = 0;
    $skipped = 0;
    foreach ($models as $m) {
        $id = trim((string) ($m['id'] ?? ''));
        if ($id === '') {
            $skipped++;
            continue;
        }
        $stmt->execute([
            ':source'    => $source,
            ':model_id'  => $id,
            ':slug'      => substr((string) ($m['slug'] ?? ''), 0, 200),
            ':name'      => substr((string) ($m['name'] ?? ''), 0, 300),
            ':creator'   => substr((string) ($m['creator'] ?? ''), 0, 120),
            ':file_type' => $fileType,
            // Cover/thumbnail URL from the browse grid, saved so the worker can
            // store the source's own image when source-thumbnails are enabled.
            ':cover_url'  => substr((string) ($m['thumb'] ?? ($m['cover'] ?? '')), 0, 1000),
        ]);
        if ($stmt->rowCount() > 0) {
            $queued++;
        } else {
            $skipped++; // already queued/done previously
        }
    }
    $pdo->commit();
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    db_write_unlock($lock);
    $locked = stripos($ex->getMessage(), 'locked') !== false
           || stripos($ex->getMessage(), 'busy') !== false;
    if ($locked && $enqueueAttempts < $enqueueMax) {
        usleep(150000 * $enqueueAttempts); // 0.15s, 0.3s… brief backoff
        goto enqueue_attempt;
    }
    error_log('[enqueue] ' . $ex->getMessage());
    fail('Database error while queueing.', 500);
}
db_write_unlock($lock);

echo json_encode(['ok' => true, 'queued' => $queued, 'skipped' => $skipped]);

// Kick the worker immediately so the first job starts without waiting for cron.
// Runs detached (fire-and-forget); cron overlap is safe — worker uses a lock file.
if ($queued > 0) {
    $worker = __DIR__ . '/worker.php';
    $log    = dirname(__DIR__) . '/private/worker.log';
    if (PHP_OS_FAMILY !== 'Windows' && is_file($worker)) {
        // Fully detached via setsid + nohup so exec() returns instantly and the
        // HTTP response isn't held while the worker boots (loads services, checks
        // tokens). stdin/stdout/stderr all redirected so no fd keeps the parent
        // request alive.
        @exec('setsid nohup php ' . escapeshellarg($worker)
            . ' < /dev/null >> ' . escapeshellarg($log) . ' 2>&1 &');
    }
}
