<?php
/**
 * organize_run.php — AJAX driver for the chunked, pausable Organize action.
 *
 * Actions (POST):
 *   start  → seed state for a registered folder, return progress
 *   next   → process one chunk, return progress
 *   pause  → set status=paused (the running chunk finishes its current file)
 *   resume → set status=running
 *   status → just read current progress
 *
 * Always identifies the folder by its custom-folder id (never a raw path), so
 * the path is resolved from config — the same trust boundary as everywhere else.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth.php';
if (!auth_check()) { http_response_code(401); exit; }

header('Content-Type: application/json');

// CSRF on every mutating call.
if (!csrf_ok()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'bad_csrf']);
    exit;
}

$action = (string) ($_POST['action'] ?? 'status');
$id     = preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['custom_id'] ?? ''));

$folder = null;
foreach (custom_folders() as $f) { if ($f['id'] === $id) { $folder = $f; break; } }
if ($folder === null) {
    echo json_encode(['ok' => false, 'error' => 'unknown_folder']);
    exit;
}
$root = $folder['path'];
if (!is_dir($root)) {
    echo json_encode(['ok' => false, 'error' => 'not_reachable', 'path' => $root]);
    exit;
}

switch ($action) {
    case 'start':
        $r = organize_start($root);
        echo json_encode($r['ok'] ? ['ok' => true, 'state' => $r['state']] : ['ok' => false, 'error' => $r['error']]);
        break;

    case 'next':
        $chunk = (int) ($_POST['chunk'] ?? 8);
        if ($chunk < 1)  $chunk = 1;
        if ($chunk > 50) $chunk = 50;
        echo json_encode(organize_chunk($root, $chunk));
        break;

    case 'pause':
        echo json_encode(organize_set_status($root, 'paused'));
        break;

    case 'resume':
        echo json_encode(organize_set_status($root, 'running'));
        break;

    case 'status':
    default:
        $st = organize_read_state($root);
        echo json_encode(['ok' => true, 'state' => $st]);
        break;
}
