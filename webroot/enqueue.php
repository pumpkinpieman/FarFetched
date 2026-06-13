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

// Source selector. MakerWorld is always a whole-model ZIP, which the worker
// treats as PACK-equivalent — normalize its file_type so the unique key and
// the queue UI stay consistent.
$source = strtolower(trim((string) ($in['source'] ?? 'printables')));
if (!in_array($source, ['printables', 'makerworld'], true)) {
    $source = 'printables';
}
if ($source === 'makerworld') {
    $fileType = 'PACK';
}

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
    'INSERT OR IGNORE INTO download_jobs (source, model_id, slug, name, creator, file_type, status)
     VALUES (:source, :model_id, :slug, :name, :creator, :file_type, "queued")'
);

$queued = 0;
$skipped = 0;

$pdo->beginTransaction();
try {
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
        ]);
        if ($stmt->rowCount() > 0) {
            $queued++;
        } else {
            $skipped++; // already queued/done previously
        }
    }
    $pdo->commit();
} catch (Throwable $ex) {
    $pdo->rollBack();
    error_log('[enqueue] ' . $ex->getMessage());
    fail('Database error while queueing.', 500);
}

echo json_encode(['ok' => true, 'queued' => $queued, 'skipped' => $skipped]);
