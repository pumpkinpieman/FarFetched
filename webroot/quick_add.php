<?php
declare(strict_types=1);

/**
 * quick_add.php — queue a single model directly from a pasted URL (or bare id
 * for the current source). POST JSON: {csrf, url}. Returns {ok, source, model_id,
 * queued}. Mirrors enqueue's insert; logs to the activity log.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth.php';
if (!auth_check()) { http_response_code(401); header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>'auth required']); exit; }

header('Content-Type: application/json');
function qa_fail(string $m, int $c = 400): void { http_response_code($c); echo json_encode(['ok' => false, 'error' => $m]); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') qa_fail('POST required.', 405);

$raw = file_get_contents('php://input') ?: '';
$in  = json_decode($raw, true);
if (!is_array($in)) qa_fail('Invalid request.');

if (empty($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], (string) ($in['csrf'] ?? ''))) {
    qa_fail('Bad CSRF token.', 403);
}

$url = trim((string) ($in['url'] ?? ''));
if ($url === '') qa_fail('No URL provided.');

$parsed = parse_model_url($url);
if ($parsed === null) {
    qa_fail('Unrecognized or unsupported model URL.');
}

$source  = $parsed['source'];
$modelId = $parsed['model_id'];
$urlSlug = $parsed['slug'];

try {
    $lock = db_write_lock();
    try {
        $stmt = db()->prepare(
            'INSERT OR IGNORE INTO download_jobs (source, model_id, slug, name, creator, file_type, cover_url, status)
             VALUES (:source, :model_id, :slug, "", "", "STL", "", "queued")'
        );
        $stmt->execute([':source' => $source, ':model_id' => $modelId, ':slug' => $urlSlug]);
        $queued = $stmt->rowCount() > 0;
    } finally { db_write_unlock($lock); }
} catch (\Throwable $e) {
    qa_fail('Queue error: ' . $e->getMessage(), 500);
}

if ($queued) {
    ff_log('info', "[quick-add] queued $source/$modelId");
    // Kick the worker (detached).
    $worker = __DIR__ . '/worker.php';
    $log    = dirname(__DIR__) . '/private/worker.log';
    if (PHP_OS_FAMILY !== 'Windows' && is_file($worker)) {
        @exec('setsid nohup php ' . escapeshellarg($worker) . ' < /dev/null >> ' . escapeshellarg($log) . ' 2>&1 &');
    }
}

echo json_encode(['ok' => true, 'source' => $source, 'model_id' => $modelId, 'queued' => $queued]);
