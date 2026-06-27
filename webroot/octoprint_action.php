<?php
declare(strict_types=1);

/**
 * octoprint_action.php — per-printer OctoPrint integration endpoint.
 *
 * POST JSON (all CSRF-guarded):
 *   {action:'save',    id, url, api_key, enabled}   store connection settings
 *   {action:'test',    id}                           test connection (live)
 *   {action:'status',  id}                           printer state + temps + job
 *   {action:'upload',  id, file, autoprint?}         upload a downloaded file
 *   {action:'control', id, cmd}                      start|cancel|pause|resume
 *
 * `file` for upload is a path RELATIVE to the downloads root (no absolute paths
 * from the client — resolved + contained server-side to prevent traversal).
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth.php';
if (!auth_check()) { http_response_code(401); header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>'auth required']); exit; }
require_once __DIR__ . '/OctoPrintService.php';

header('Content-Type: application/json');

function op_out(array $p): void { echo json_encode($p); exit; }
function op_fail(string $m, int $c = 400): void { http_response_code($c); echo json_encode(['ok' => false, 'error' => $m]); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') op_fail('POST required.', 405);

$raw = file_get_contents('php://input') ?: '';
$in  = json_decode($raw, true);
if (!is_array($in)) $in = $_POST;

if (empty($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], (string) ($in['csrf'] ?? ''))) {
    op_fail('Bad CSRF token.', 403);
}

$action = strtolower(trim((string) ($in['action'] ?? '')));
$id     = (int) ($in['id'] ?? 0);
if ($id <= 0) op_fail('Missing printer id.');

/* Load the printer row (and its OctoPrint settings). */
$stmt = db()->prepare('SELECT id, name, nickname, octoprint_url, octoprint_api_key, octoprint_enabled FROM printers WHERE id = :id');
$stmt->execute([':id' => $id]);
$printer = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$printer) op_fail('Printer not found.', 404);

/* ----------------------------------------------------------------- *
 * save — store URL + API key + enabled flag (mutex-safe write)
 * ----------------------------------------------------------------- */
if ($action === 'save') {
    $url     = trim((string) ($in['url'] ?? ''));
    $apiKey  = trim((string) ($in['api_key'] ?? ''));
    $enabled = !empty($in['enabled']) ? 1 : 0;

    // Light validation: if enabling, require both fields.
    if ($enabled && ($url === '' || $apiKey === '')) {
        op_fail('URL and API key are required to enable OctoPrint.');
    }
    if ($url !== '' && !preg_match('#^https?://#i', $url) && !preg_match('#^[\w.\-]+(:\d+)?$#', $url)) {
        op_fail('Invalid OctoPrint URL.');
    }

    db_exec_retry(
        'UPDATE printers SET octoprint_url = :u, octoprint_api_key = :k, octoprint_enabled = :e WHERE id = :id',
        [':u' => $url, ':k' => $apiKey, ':e' => $enabled, ':id' => $id],
        8
    );
    op_out(['ok' => true, 'saved' => true, 'enabled' => (bool) $enabled]);
}

/* For all live actions, build a service from stored creds. */
$svc = new OctoPrintService(
    (string) $printer['octoprint_url'],
    (string) $printer['octoprint_api_key']
);

if (!$svc->isConfigured()) {
    op_fail('OctoPrint is not configured for this printer.');
}

try {
    switch ($action) {
        case 'test':
            op_out($svc->testConnection());

        case 'status':
            op_out($svc->status());

        case 'control':
            $cmd = strtolower(trim((string) ($in['cmd'] ?? '')));
            switch ($cmd) {
                case 'start':  op_out($svc->startPrint());
                case 'cancel': op_out($svc->cancelPrint());
                case 'pause':  op_out($svc->pausePrint());
                case 'resume': op_out($svc->resumePrint());
                default: op_fail('Unknown control command.');
            }
            break;

        case 'upload':
            $rel = (string) ($in['file'] ?? '');
            if ($rel === '') op_fail('No file specified.');

            // Resolve against the downloads root and CONTAIN it (no traversal).
            $root = realpath(MODELS_ROOT);
            $target = realpath(MODELS_ROOT . '/' . ltrim($rel, '/'));
            if ($root === false || $target === false || strpos($target, $root) !== 0) {
                op_fail('Invalid file path.');
            }
            if (!is_file($target)) op_fail('File not found.');

            $autoPrint = !empty($in['autoprint']);
            // Only allow auto-print for gcode (OctoPrint can't print STL/3MF directly).
            $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
            if ($autoPrint && !in_array($ext, ['gcode', 'gco', 'g'], true)) {
                $autoPrint = false; // silently downgrade; report below
            }
            $res = $svc->uploadFile($target, $autoPrint);
            $res['note'] = (!$autoPrint && in_array($ext, ['stl', '3mf', 'obj'], true))
                ? 'Uploaded as a model file. OctoPrint cannot print STL/3MF without slicing to gcode first.'
                : '';
            op_out($res);

        default:
            op_fail('Unknown action.');
    }
} catch (RuntimeException $e) {
    op_fail($e->getMessage(), 502);
} catch (Throwable $e) {
    op_fail('OctoPrint request failed.', 500);
}
