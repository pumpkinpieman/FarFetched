<?php
declare(strict_types=1);

/**
 * browse_more.php — pagination endpoint for the "Load more" button.
 *
 * GET params: cat (slug or numeric category id), cursor (opaque, from prior page)
 * Returns JSON: { ok, models:[{id,slug,name,creator,thumb}], cursor:next|null, error? }
 *
 * Cursor-based paging: each response carries the cursor for the *next* page;
 * pass it back to walk forward. null cursor = no more pages.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/PrintablesService.php';

header('Content-Type: application/json');

$cat    = (string) ($_GET['cat'] ?? 'all');
$cursor = trim((string) ($_GET['cursor'] ?? ''));
$cursor = $cursor === '' ? null : $cursor;

$svc = new PrintablesService();
if (!$svc->isAuthed()) {
    echo json_encode(['ok' => false, 'error' => 'No Printables token — set one in Settings.']);
    exit;
}

$models = $svc->searchModels($cat, 36, $cursor);
if ($svc->lastError !== '') {
    echo json_encode(['ok' => false, 'error' => $svc->lastError]);
    exit;
}

echo json_encode([
    'ok'     => true,
    'models' => $models,
    'cursor' => $svc->lastCursor,   // null when no further pages
]);
