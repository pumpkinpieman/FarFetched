<?php
declare(strict_types=1);

/**
 * search_more.php — keyword-search results + pagination for the Browse page.
 *
 * GET params: q (search text), offset (int, 0-based), paid (all|free|paid)
 * Returns JSON: { ok, models:[{id,slug,name,creator,thumb,size,price,club}],
 *                 nextOffset:int|null, total:int, error? }
 *
 * Offset paging: each response carries the offset for the *next* page (or null
 * when the result set is exhausted).
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/PrintablesService.php';

header('Content-Type: application/json');

$q      = trim((string) ($_GET['q'] ?? ''));
$offset = max(0, (int) ($_GET['offset'] ?? 0));
$paid   = (string) ($_GET['paid'] ?? 'all');
if (!in_array($paid, ['all', 'free', 'paid'], true)) {
    $paid = 'all';
}

if ($q === '') {
    echo json_encode(['ok' => false, 'error' => 'Empty search.']);
    exit;
}

$svc = new PrintablesService();
if (!$svc->isAuthed()) {
    echo json_encode(['ok' => false, 'error' => 'No Printables token — set one in Settings.']);
    exit;
}

$limit  = 36;
$models = $svc->searchByKeyword($q, $limit, $offset, $paid);
if ($svc->lastError !== '') {
    echo json_encode(['ok' => false, 'error' => $svc->lastError]);
    exit;
}

// Next page exists only if this page was full AND we haven't passed the total.
$total      = $svc->lastTotalCount;
$nextOffset = (count($models) === $limit && ($offset + $limit) < $total) ? $offset + $limit : null;

echo json_encode([
    'ok'         => true,
    'models'     => $models,
    'nextOffset' => $nextOffset,
    'total'      => $total,
]);
