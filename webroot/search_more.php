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
require_once __DIR__ . '/CrealityCloudService.php';
require_once __DIR__ . '/NikkoService.php';
require_once __DIR__ . '/Hex3DForumService.php';

// --- Resilience: this endpoint must ALWAYS return JSON ----------------------
// A PHP fatal/Throwable would otherwise emit an HTML error page under a JSON
// content-type, surfacing on the Browse page as the opaque
// "JSON.parse: unexpected character". Buffer output and convert any uncaught
// Throwable or fatal into a clean JSON error (also logged for the Activity
// view), discarding any partial output first.
ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();

$smEmitError = static function (string $msg): void {
    if (ob_get_level() > 0) { ob_clean(); }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    echo json_encode(['ok' => false, 'error' => $msg]);
};

set_exception_handler(static function (\Throwable $e) use ($smEmitError): void {
    if (function_exists('ff_log')) {
        ff_log('error', 'search_more: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    }
    $smEmitError('Search failed: ' . $e->getMessage());
});

register_shutdown_function(static function () use ($smEmitError): void {
    $e = error_get_last();
    if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR], true)) {
        if (function_exists('ff_log')) {
            ff_log('error', 'search_more fatal: ' . $e['message'] . ' @ ' . basename($e['file']) . ':' . $e['line']);
        }
        $smEmitError('Search failed: ' . $e['message']);
    }
});

header('Content-Type: application/json');

$q      = trim((string) ($_GET['q'] ?? ''));
$author = trim((string) ($_GET['author'] ?? ''));
$offset = max(0, (int) ($_GET['offset'] ?? 0));
$page   = max(1, (int) ($offset / 20) + 1);
$paid   = (string) ($_GET['paid'] ?? 'all');
if (!in_array($paid, ['all', 'free', 'paid'], true)) { $paid = 'all'; }
$source = strtolower((string) ($_GET['src'] ?? 'printables'));
if (!in_array($source, ['printables', 'makerworld', 'thingiverse', 'cults3d', 'stlflix', 'creality', 'nikko', 'hex3dforum'], true)) {
    $source = 'printables';
}
$showNsfw = ($_GET['nsfw'] ?? '') === '1';
$mwCat    = preg_replace('/[^0-9]/', '', (string) ($_GET['mwcat'] ?? '')) ?? '';
$mwBrowse = ($_GET['browse'] ?? '') === '1';

$allowEmptyQ = ($source === 'makerworld' && ($mwBrowse || $mwCat !== ''))
    || ($author !== '')
    || $source === 'thingiverse'
    || $source === 'cults3d'
    || $source === 'stlflix'
    || $source === 'creality'
    || $source === 'nikko'
    || $source === 'hex3dforum';

if ($q === '' && !$allowEmptyQ) {
    echo json_encode(['ok' => false, 'error' => 'Empty search.']);
    exit;
}

// ---- MakerWorld -------------------------------------------------------------
if ($source === 'makerworld') {
    $mw     = new MakerWorldService();
    $limit  = 20;
    // Real author search when the card handed us a numeric MakerWorld creator id
    // (designCreator.uid). A bare name (e.g. from a library link) can't resolve to
    // a uid via MakerWorld's API, so fall back to a keyword search on the name.
    if ($author !== '' && ctype_digit($author)) {
        $models = $mw->searchByAuthor($author, $limit, $offset, $showNsfw);
    } else {
        $models = $mw->searchByKeyword(($author !== '' ? $author : $q), $limit, $offset, $showNsfw, $mwCat);
    }
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
    if ($author !== '') {
        $models = $tv->searchByAuthor($author, $limit, $page, $showNsfw);
    } else {
        $models = $tv->search($q, $limit, $page, $showNsfw, $tvCat);
    }
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
    $limit   = 24;
    $models  = $stlflix->search($q, $limit, $offset, $stlCat);
    if ($stlflix->lastError !== '') { echo json_encode(['ok' => false, 'error' => $stlflix->lastError]); exit; }
    $nextOffset = (($offset + $limit) < $stlflix->lastTotal) ? $offset + $limit : null;
    echo json_encode(['ok' => true, 'models' => $models, 'nextOffset' => $nextOffset, 'total' => $stlflix->lastTotal, 'source' => 'stlflix']);
    exit;
}

// ---- Creality Cloud ---------------------------------------------------------
if ($source === 'creality') {
    $creality = new CrealityCloudService();
    $limit    = 24;
    $crealityCat = trim((string) ($_GET['crealitycat'] ?? ''));
    // Author search (numeric userId) takes priority, then keyword, then browse.
    if ($author !== '' && ctype_digit($author)) {
        $models = $creality->searchByAuthor($author, $limit, $page);
    } elseif ($q !== '') {
        $models = $creality->search($q, $limit, $page);
    } else {
        $models = $creality->browseCategory($crealityCat, $limit, $page);
    }
    if ($creality->lastError !== '') { echo json_encode(['ok' => false, 'error' => $creality->lastError]); exit; }
    $nextOffset = (count($models) >= $limit) ? $offset + $limit : null;
    echo json_encode(['ok' => true, 'models' => $models, 'nextOffset' => $nextOffset, 'total' => $creality->lastTotal, 'source' => 'creality']);
    exit;
}

// ---- Nikko Industries (membership library) ----------------------------------
if ($source === 'nikko') {
    $nikko    = new NikkoService();
    $nikkoCat = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['nikkocat'] ?? '')));
    $limit    = 20;
    $models   = $nikko->search($q, $limit, $offset, $nikkoCat);
    if ($nikko->lastError !== '') { echo json_encode(['ok' => false, 'error' => $nikko->lastError]); exit; }
    $nextOffset = (($offset + $limit) < $nikko->lastTotal) ? $offset + $limit : null;
    echo json_encode(['ok' => true, 'models' => $models, 'nextOffset' => $nextOffset, 'total' => $nikko->lastTotal, 'source' => 'nikko']);
    exit;
}

// ---- Hex3D Forum (served from the local crawler index) ----------------------
if ($source === 'hex3dforum') {
    // The cached catalog is only browsable while the session is connected — a
    // disconnected source returns nothing (matches index.php's render gate), so
    // pagination/search can't expose the index once the user disconnects.
    if (!hex3dforum_configured()) {
        echo json_encode(['ok' => true, 'models' => [], 'nextOffset' => null, 'total' => 0, 'source' => 'hex3dforum']);
        exit;
    }
    // Browse/search now read the pre-built hex3d_topics index (populated by
    // hex3d_crawl.php) rather than hitting the slow, session-gated forum live.
    // forum id is optional: present = filter to that forum; absent = all forums.
    // BUT a search query always searches the WHOLE index — the per-forum filter
    // is a browse convenience, not a search constraint (otherwise selecting a
    // container forum with 0 topics would hide all matches).
    $forumId = preg_replace('/[^0-9]/', '', (string) ($_GET['hex3dforum_id'] ?? ''));
    $limit   = 20;

    $where  = ['1=1'];
    $args   = [];
    if ($forumId !== '' && $q === '') {
        $where[] = 'forum_id = :fid';
        $args[':fid'] = $forumId;
    }
    if ($q !== '') {
        $where[] = 'title LIKE :q';
        $args[':q'] = '%' . $q . '%';
    }
    $whereSql = implode(' AND ', $where);

    $total = (int) (function () use ($whereSql, $args) {
        $st = db()->prepare("SELECT COUNT(*) FROM hex3d_topics WHERE $whereSql");
        $st->execute($args);
        return $st->fetchColumn();
    })();

    $st = db()->prepare(
        "SELECT forum_id, topic_id, forum_name, title, thumb
           FROM hex3d_topics
          WHERE $whereSql
          ORDER BY forum_name, title
          LIMIT :lim OFFSET :off"
    );
    foreach ($args as $k => $v) { $st->bindValue($k, $v); }
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->bindValue(':off', $offset, PDO::PARAM_INT);
    $st->execute();

    $models = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $thumb = (string) $r['thumb'];
        $models[] = [
            'id'      => (string) $r['topic_id'],
            'slug'    => $r['forum_id'] . '-' . $r['topic_id'],
            'name'    => (string) $r['title'],
            'creator' => (string) ($r['forum_name'] ?: 'Hex3D'),
            'thumb'   => $thumb,
            'images'  => $thumb !== '' ? [$thumb] : [],
            'size'    => 0,
            'source'  => 'hex3dforum',
        ];
    }

    $nextOffset = (($offset + $limit) < $total) ? $offset + $limit : null;
    echo json_encode(['ok' => true, 'models' => $models, 'nextOffset' => $nextOffset, 'total' => $total, 'source' => 'hex3dforum']);
    exit;
}

// ---- Printables -------------------------------------------------------------
$svc = new PrintablesService();
if (!$svc->isAuthed()) {
    echo json_encode(['ok' => false, 'error' => 'No Printables token — set one in Settings.']);
    exit;
}
$limit      = 36;
$models     = ($author !== '')
    ? $svc->searchByAuthor($author, $limit, $offset)
    : $svc->searchByKeyword($q, $limit, $offset, $paid);
if ($svc->lastError !== '') { echo json_encode(['ok' => false, 'error' => $svc->lastError]); exit; }
$total      = $svc->lastTotalCount;
$nextOffset = (count($models) === $limit && ($offset + $limit) < $total) ? $offset + $limit : null;
echo json_encode(['ok' => true, 'models' => $models, 'nextOffset' => $nextOffset, 'total' => $total]);
