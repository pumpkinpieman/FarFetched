<?php
declare(strict_types=1);

/**
 * csv_import.php — bulk-import models into the queue from a CSV.
 *
 *   GET  ?template=1     download a CSV template with headers + example row
 *   POST (multipart)     import an uploaded CSV (field: file) + csrf
 *
 * CSV columns (header row required, case-insensitive):
 *   Model URL          (required) full model page URL on a supported source
 *   Source Thumbnail   (y/n)      grab the source's cover image
 *   Collection         (optional) name of an existing collection to match
 *   Favorites          (y/n)      add to Favorites
 *
 * Each processed row is logged to the worker/activity log so the import is
 * visible there. Errors are reported per-row with the missing/invalid field.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth.php';
if (!auth_check()) { http_response_code(401); header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>'auth required']); exit; }

/* ---------- GET: template download ---------- */
if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="farfetched_import_template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Model URL', 'Source Thumbnail', 'Collection', 'Favorites']);
    fputcsv($out, ['https://www.printables.com/model/123456-example-model', 'yes', 'My Collection', 'no']);
    fputcsv($out, ['https://makerworld.com/en/models/654321-another-model', 'no', '', 'yes']);
    fclose($out);
    exit;
}

header('Content-Type: application/json');
function ci_out(array $p): void { echo json_encode($p); exit; }
function ci_fail(string $m, int $c = 400): void { http_response_code($c); echo json_encode(['ok' => false, 'error' => $m]); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') ci_fail('POST required.', 405);

if (empty($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
    ci_fail('Bad CSRF token.', 403);
}
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    ci_fail('No CSV file uploaded.');
}

$tmp = $_FILES['file']['tmp_name'];
$fh  = @fopen($tmp, 'r');
if (!$fh) ci_fail('Could not read uploaded file.');

// Header row → column index map (case-insensitive, trimmed).
$header = fgetcsv($fh);
if (!$header) { fclose($fh); ci_fail('CSV is empty.'); }
$cols = [];
foreach ($header as $i => $h) { $cols[strtolower(trim((string) $h))] = $i; }
$need = 'model url';
if (!isset($cols[$need])) { fclose($fh); ci_fail('CSV missing required column: Model URL'); }

$col = function (array $row, string $name) use ($cols): string {
    $i = $cols[strtolower($name)] ?? null;
    return $i === null ? '' : trim((string) ($row[$i] ?? ''));
};
$truthy = function (string $v): bool {
    $v = strtolower(trim($v));
    return in_array($v, ['y', 'yes', '1', 'true', 'on'], true);
};

// Pre-load collections (name → id) for matching.
$collMap = [];
foreach (db()->query('SELECT id, name FROM collections') as $c) {
    $collMap[strtolower(trim($c['name']))] = (int) $c['id'];
}

$jobStmt = db()->prepare(
    'INSERT OR IGNORE INTO download_jobs (source, model_id, slug, name, creator, file_type, cover_url, collection_name, status)
     VALUES (:source, :model_id, :slug, :name, :creator, :file_type, :cover_url, :collection_name, "queued")'
);
$favStmt = db()->prepare(
    'INSERT OR IGNORE INTO favorites (source, model_id, slug, name, creator, thumb, price)
     VALUES (:source, :model_id, :slug, :name, :creator, "", 0)'
);

$queued = 0; $skipped = 0; $favs = 0; $errors = [];
$rowNum = 1; // header was row 1

ff_log('info', '[import] CSV import started.');

// Wrap the whole insert loop in a single transaction: turns N per-row implicit
// commits (an fsync each) into one, cutting bulk-import time dramatically, and
// makes the import atomic (all-or-nothing on a fatal error). Per-row validation
// errors are still collected and skipped without aborting the batch.
$importDb = db();
$importDb->beginTransaction();

while (($row = fgetcsv($fh)) !== false) {
    $rowNum++;
    // Skip fully blank lines.
    if (count(array_filter($row, fn($v) => trim((string) $v) !== '')) === 0) continue;

    $url = $col($row, 'Model URL');
    if ($url === '') {
        $errors[] = "Row $rowNum: missing field — Model URL";
        continue;
    }
    $parsed = parse_model_url($url);
    if ($parsed === null) {
        $errors[] = "Row $rowNum: unrecognized or unsupported Model URL";
        ff_log('warn', "[import] Row $rowNum: unsupported URL: $url");
        continue;
    }

    $source = $parsed['source'];
    $modelId = $parsed['model_id'];
    $urlSlug = $parsed['slug'];
    $wantThumb = $truthy($col($row, 'Source Thumbnail'));
    $wantFav   = $truthy($col($row, 'Favorites'));
    $collName  = $col($row, 'Collection');

    // Validate collection if given.
    $collId = 0;
    if ($collName !== '') {
        $collId = $collMap[strtolower($collName)] ?? 0;
        if ($collId === 0) {
            $errors[] = "Row $rowNum: collection not found — \"$collName\" (queued anyway)";
            ff_log('warn', "[import] Row $rowNum: collection not found: $collName");
        }
    }

    // Queue the job (default file_type STL; per-source worker resolves packs).
    try {
        $lock = db_write_lock();
        try {
            $jobStmt->execute([
                ':source' => $source, ':model_id' => $modelId, ':slug' => $urlSlug,
                ':name' => '', ':creator' => '', ':file_type' => 'STL', ':cover_url' => '',
                ':collection_name' => ($collId > 0 ? $collName : ''),
            ]);
            $added = $jobStmt->rowCount() > 0;
        } finally { db_write_unlock($lock); }
        if ($added) { $queued++; ff_log('info', "[import] Row $rowNum: queued $source/$modelId" . ($wantThumb ? ' (+thumb)' : '')); }
        else { $skipped++; }
    } catch (\Throwable $e) {
        $errors[] = "Row $rowNum: queue error — " . $e->getMessage();
        ff_log('error', "[import] Row $rowNum: queue error: " . $e->getMessage());
        continue;
    }

    // Favorites (model_id known now).
    if ($wantFav) {
        try {
            $lock = db_write_lock();
            try {
                $favStmt->execute([':source' => $source, ':model_id' => $modelId, ':slug' => $urlSlug, ':name' => '', ':creator' => '']);
                if ($favStmt->rowCount() > 0) $favs++;
            } finally { db_write_unlock($lock); }
        } catch (\Throwable $e) { /* non-fatal */ }
    }

    // Per-source thumbnail preference: honor the row's choice for this source.
    if ($wantThumb) {
        $st = cfg('source_thumbs');
        if (is_array($st) && empty($st[$source])) { $st[$source] = true; cfg_save(['source_thumbs' => $st]); }
    }
}
$importDb->commit();
fclose($fh);

ff_log('info', "[import] CSV import finished — queued $queued, skipped $skipped, favorites $favs, errors " . count($errors) . '.');

// Kick the worker if anything queued.
if ($queued > 0) {
    $worker = __DIR__ . '/worker.php';
    $log    = dirname(__DIR__) . '/private/worker.log';
    if (PHP_OS_FAMILY !== 'Windows' && is_file($worker)) {
        @exec('setsid nohup php ' . escapeshellarg($worker) . ' < /dev/null >> ' . escapeshellarg($log) . ' 2>&1 &');
    }
}

ci_out([
    'ok' => true,
    'queued' => $queued,
    'skipped' => $skipped,
    'favorites' => $favs,
    'errors' => $errors,
]);
