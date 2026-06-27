<?php
declare(strict_types=1);

/**
 * printer_action.php — manage the user's printers.
 *
 * POST JSON:
 *   {action:'toggle', id}                          enable/disable a printer
 *   {action:'add_from_catalog', name}              add a catalog printer
 *   {action:'add_custom', name, x, y, z, brand?}   add a custom printer
 *   {action:'remove', id}                          delete a printer
 *
 * All actions are CSRF-guarded.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth.php';
if (!auth_check()) { http_response_code(401); header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>'auth required']); exit; }
require_once __DIR__ . '/printer_catalog.php';

header('Content-Type: application/json');

function pa_out(array $p): void { echo json_encode($p); exit; }
function pa_fail(string $m, int $c = 400): void { http_response_code($c); echo json_encode(['ok' => false, 'error' => $m]); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pa_fail('POST required.', 405);

$raw = file_get_contents('php://input') ?: '';
$in  = json_decode($raw, true);
if (!is_array($in)) $in = $_POST;

if (!csrf_ok()) {
    // Allow csrf passed in JSON body too.
    if (empty($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], (string) ($in['csrf'] ?? ''))) {
        pa_fail('Bad CSRF token.', 403);
    }
}

$action = strtolower(trim((string) ($in['action'] ?? '')));
$db = db();

try {
    if ($action === 'toggle') {
        $id = (int) ($in['id'] ?? 0);
        $row = $db->prepare('SELECT enabled FROM printers WHERE id = :id');
        $row->execute([':id' => $id]);
        $cur = $row->fetchColumn();
        if ($cur === false) pa_fail('Printer not found.');
        $new = $cur ? 0 : 1;
        db_exec_retry('UPDATE printers SET enabled = :e WHERE id = :id', [':e' => $new, ':id' => $id], 8);
        pa_out(['ok' => true, 'enabled' => (bool) $new]);

    } elseif ($action === 'add_from_catalog') {
        $name = trim((string) ($in['name'] ?? ''));
        $nickname = trim((string) ($in['nickname'] ?? ''));
        $cat = null;
        foreach (printer_catalog() as $p) {
            if (strcasecmp($p['name'], $name) === 0) { $cat = $p; break; }
        }
        if ($cat === null) pa_fail('Not in catalog.');
        db_exec_retry(
            'INSERT INTO printers (name, nickname, brand, bed_x, bed_y, bed_z, enabled, is_custom)
             VALUES (:n, :nk, :b, :x, :y, :z, 1, 0)',
            [':n' => $cat['name'], ':nk' => $nickname, ':b' => $cat['brand'],
             ':x' => $cat['x'], ':y' => $cat['y'], ':z' => $cat['z']],
            8
        );
        pa_out(['ok' => true, 'id' => (int) $db->lastInsertId()]);

    } elseif ($action === 'add_custom') {
        $name = trim((string) ($in['name'] ?? ''));
        $nickname = trim((string) ($in['nickname'] ?? ''));
        $x = (int) ($in['x'] ?? 0); $y = (int) ($in['y'] ?? 0); $z = (int) ($in['z'] ?? 0);
        $brand = trim((string) ($in['brand'] ?? 'Custom'));
        if ($name === '' || $x <= 0 || $y <= 0 || $z <= 0) pa_fail('Name and positive X/Y/Z required.');
        db_exec_retry(
            'INSERT INTO printers (name, nickname, brand, bed_x, bed_y, bed_z, enabled, is_custom)
             VALUES (:n, :nk, :b, :x, :y, :z, 1, 1)',
            [':n' => $name, ':nk' => $nickname, ':b' => $brand, ':x' => $x, ':y' => $y, ':z' => $z],
            8
        );
        pa_out(['ok' => true, 'id' => (int) $db->lastInsertId()]);

    } elseif ($action === 'rename') {
        $id = (int) ($in['id'] ?? 0);
        $nickname = trim((string) ($in['nickname'] ?? ''));
        db_exec_retry('UPDATE printers SET nickname = :nk WHERE id = :id', [':nk' => $nickname, ':id' => $id], 8);
        pa_out(['ok' => true]);

    } elseif ($action === 'remove') {
        $id = (int) ($in['id'] ?? 0);
        db_exec_retry('DELETE FROM printers WHERE id = :id', [':id' => $id], 8);
        pa_out(['ok' => true]);

    } else {
        pa_fail('Unknown action.');
    }
} catch (\Throwable $e) {
    pa_fail('Database error.', 500);
}
