<?php
declare(strict_types=1);

/**
 * filament_action.php — JSON endpoint for the filament inventory.
 *
 * Actions (POST, auth + CSRF gated):
 *   add_spool     { type:{...}, spool:{...} }  — upsert type + create spool
 *   update_type   { id, ...type fields }
 *   update_spool  { id, ...spool fields }
 *   adjust        { id, delta }                — atomic remaining_g change
 *   delete_type   { id }                       — soft-delete + archive spools
 *   delete_spool  { id }
 *
 * Security: parameterized PDO throughout (in FilamentService), CSRF + auth,
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/FilamentService.php';

header('Content-Type: application/json');

function fa_out(array $p): void { echo json_encode($p); exit; }
function fa_err(string $m, int $c = 400): void { http_response_code($c); fa_out(['ok' => false, 'error' => $m]); }

if (!auth_check())                         fa_err('Not authorized.', 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fa_err('POST only.', 405);

$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in)) { fa_err('Invalid JSON.'); }
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], (string) ($in['csrf'] ?? ''))) {
    fa_err('Session expired — reload the page.', 403);
}

$action = (string) ($in['action'] ?? '');

try {
    switch ($action) {

        case 'add_spool': {
            $typeId = FilamentService::upsertType((array) ($in['type'] ?? []));
            $spoolId = FilamentService::createSpool($typeId, (array) ($in['spool'] ?? []));
            fa_out(['ok' => true, 'type_id' => $typeId, 'spool_id' => $spoolId]);
        }

        case 'update_type': {
            $id = (int) ($in['id'] ?? 0);
            if (!FilamentService::getType($id)) { fa_err('Type not found.', 404); }
            FilamentService::updateType($id, $in);
            fa_out(['ok' => true]);
        }

        case 'update_spool': {
            FilamentService::updateSpool((int) ($in['id'] ?? 0), $in);
            fa_out(['ok' => true]);
        }

        case 'adjust': {
            $rem = FilamentService::adjustRemaining((int) ($in['id'] ?? 0), (float) ($in['delta'] ?? 0));
            if ($rem === null) { fa_err('Spool not found.', 404); }
            fa_out(['ok' => true, 'remaining_g' => $rem]);
        }

        case 'delete_type': {
            $id = (int) ($in['id'] ?? 0);
            FilamentService::deleteType($id);
            fa_out(['ok' => true]);
        }

        case 'delete_spool': {
            FilamentService::deleteSpool((int) ($in['id'] ?? 0));
            fa_out(['ok' => true]);
        }


        default:
            fa_err('Unknown action.');
    }
} catch (Throwable $e) {
    ff_log('warn', 'filament_action: ' . $e->getMessage());
    fa_err('Server error.', 500);
}
