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
require_once __DIR__ . '/ThingiverseService.php';
require_once __DIR__ . '/Cults3DService.php';
require_once __DIR__ . '/STLFlixService.php';

header('Content-Type: application/json');

$q      = trim((string) ($_GET['q'] ?? ''));
$offset = max(0, (int) ($_GET['offset'] ?? 0));
$page   = max(1, (int) ($offset / 20) + 1);
$paid   = (string) ($_GET['paid'] ?? 'all');
if (!in_array($paid, ['all', 'free', 'paid'], true)) { $paid = 'all'; }
$source = strtolower((string) ($_GET['src'] ?? 'printables'));
if (!in_array($source, ['printables', 'makerworld', 'thingiverse', 'cults3d', 'stlflix'], true)) {
    $source = 'printables';
}
$showNsfw = ($_GET['nsfw'] ?? '') === '1';
$mwCat    = preg_replace('/[^0-9]/', '', (string) ($_GET['mwcat'] ?? '')) ?? '';
$mwBrowse = ($_GET['browse'] ?? '') === '1';

$allowEmptyQ = ($source === 'makerworld' && ($mwBrowse || $mwCat !== ''))
    || $source === 'thingiverse'
    || $source === 'cults3d'
    || $source === 'stlflix';

if ($q === '' && !$allowEmptyQ) {
    echo json_encode(['ok' => false, 'error' => 'Empty search.']);
    exit;
}

// ---- MakerWorld -------------------------------------------------------------
if ($source === 'makerworld') {
    $mw     = new MakerWorldService();
    $limit  = 20;
    $models = $mw->searchByKeyword($q, $limit, $offset, $showNsfw, $mwCat);
    if ($mw->lastError !== '') { echo json_encode(['ok' => false, 'error' => $mw->lastError]); exit; }
    $total       = $mw->lastTotalCount;
    $gotFullPage = ($mw->lastPageHitCount >= $limit);
    $withinTotal = ($total <= 0) || (($offset + $limit) < $total);
    $nextOffset  = ($gotFullPage && $withinTotal) ? $offset + $limit : null;
    echo json_encode(['ok' => true, 'models' => $models, 'nextOffset' => $nextOffset, 'total' => $total, 'source' => 'makerworld']);
    exit;
}

// ---- Thingiverse ------------------------------------------------------------
if ($source === 'thingiverse') {
    $tv     = new ThingiverseService();
    $tvCat  = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['tvcat'] ?? ''))) ?: null;
    $limit  = 20;
    $models = $tv->search($q, $limit, $page, $showNsfw, $tvCat);
    if ($tv->lastError !== '') { echo json_encode(['ok' => false, 'error' => $tv->lastError]); exit; }
    $total      = $tv->lastTotal;
    $nextOffset = (count($models) >= $limit) ? $offset + $limit : null;
    echo json_encode(['ok' => true, 'models' => $models, 'nextOffset' => $nextOffset, 'total' => $total, 'source' => 'thingiverse']);
    exit;
}



// ---- Cults3D ----------------------------------------------------------------
if ($source === 'cults3d') {
    $cults    = new Cults3DService();
    $cultsCat = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['cultscat'] ?? '')));
    $freeOnly = ($paid === 'free');
    $limit    = 20;
    $models   = $cults->search($q, $limit, $page, $freeOnly, $cultsCat);
    if ($cults->lastError !== '') { echo json_encode(['ok' => false, 'error' => $cults->lastError]); exit; }
    $nextOffset = (count($models) >= $limit) ? $offset + $limit : null;
    echo json_encode(['ok' => true, 'models' => $models, 'nextOffset' => $nextOffset, 'total' => $cults->lastTotal, 'source' => 'cults3d']);
    exit;
}

// ---- STLFlix ----------------------------------------------------------------
if ($source === 'stlflix') {
    $stlflix = new STLFlixService();
    $stlCat  = preg_replace('/[^0-9]/', '', (string) ($_GET['stlcat'] ?? ''));
    $limit   = 20;
    $models  = $stlflix->search($q, $limit, $offset, $stlCat);
    if ($stlflix->lastError !== '') { echo json_encode(['ok' => false, 'error' => $stlflix->lastError]); exit; }
    $nextOffset = (($offset + $limit) < $stlflix->lastTotal) ? $offset + $limit : null;
    echo json_encode(['ok' => true, 'models' => $models, 'nextOffset' => $nextOffset, 'total' => $stlflix->lastTotal, 'source' => 'stlflix']);
    exit;
}

// ---- Printables -------------------------------------------------------------
$svc = new PrintablesService();
if (!$svc->isAuthed()) {
    echo json_encode(['ok' => false, 'error' => 'No Printables token — set one in Settings.']);
    exit;
}
$limit      = 36;
$models     = $svc->searchByKeyword($q, $limit, $offset, $paid);
if ($svc->lastError !== '') { echo json_encode(['ok' => false, 'error' => $svc->lastError]); exit; }
$total      = $svc->lastTotalCount;
$nextOffset = (count($models) === $limit && ($offset + $limit) < $total) ? $offset + $limit : null;
echo json_encode(['ok' => true, 'models' => $models, 'nextOffset' => $nextOffset, 'total' => $total]);
