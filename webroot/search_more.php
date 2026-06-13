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
require_once __DIR__ . '/MakerWorldService.php';

header('Content-Type: application/json');

$q      = trim((string) ($_GET['q'] ?? ''));
$offset = max(0, (int) ($_GET['offset'] ?? 0));
$paid   = (string) ($_GET['paid'] ?? 'all');
if (!in_array($paid, ['all', 'free', 'paid'], true)) {
    $paid = 'all';
}
$source = strtolower((string) ($_GET['src'] ?? 'printables'));
if (!in_array($source, ['printables', 'makerworld'], true)) {
    $source = 'printables';
}
$showNsfw = ($_GET['nsfw'] ?? '') === '1';
$mwCat    = preg_replace('/[^0-9]/', '', (string) ($_GET['mwcat'] ?? '')) ?? '';
$mwBrowse = ($_GET['browse'] ?? '') === '1';

// A keyword is required, EXCEPT for MakerWorld browse (All Models / category),
// where an empty keyword returns MakerWorld's popular list.
if ($q === '' && !($source === 'makerworld' && ($mwBrowse || $mwCat !== ''))) {
    echo json_encode(['ok' => false, 'error' => 'Empty search.']);
    exit;
}

// ---- MakerWorld branch (search needs no auth; limit/offset paging) ---------
if ($source === 'makerworld') {
    $mw     = new MakerWorldService();
    $limit  = 20;
    $models = $mw->searchByKeyword($q, $limit, $offset, $showNsfw, $mwCat);
    if ($mw->lastError !== '') {
        echo json_encode(['ok' => false, 'error' => $mw->lastError]);
        exit;
    }
    $total      = $mw->lastTotalCount;
    // "Has more" is driven by page fullness: a full page of raw hits means another
    // page likely exists. This works even when `total` is 0/absent (All Models /
    // popular feed). If `total` IS known, also stop once we've passed it.
    $gotFullPage = ($mw->lastPageHitCount >= $limit);
    $withinTotal = ($total <= 0) || (($offset + $limit) < $total);
    $nextOffset  = ($gotFullPage && $withinTotal) ? $offset + $limit : null;
    echo json_encode([
        'ok'         => true,
        'models'     => $models,
        'nextOffset' => $nextOffset,
        'total'      => $total,
        'source'     => 'makerworld',
    ]);
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
