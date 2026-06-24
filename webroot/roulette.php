<?php
declare(strict_types=1);

/**
 * roulette.php — "🎲 Surprise me" — return 5 random models from one source.
 *
 * GET ?src=<slug>
 *   -> JSON { ok, source, models: [ {id, slug, name, creator, thumb, price, club}, ... ], error? }
 *
 * Strategy (no source offers a true "random" endpoint): pick a random category
 * for the source, pull a page of models from it, shuffle, take up to 5. If a
 * source isn't authenticated, returns ok:false with a friendly reason so the
 * UI can say "set this source up in Settings."
 *
 * Reuses the same service methods and model shape as the Browse page.
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

header('Content-Type: application/json');
header('Cache-Control: no-store');

function r_out(array $payload): void { echo json_encode($payload); exit; }
function r_fail(string $msg): void { r_out(['ok' => false, 'error' => $msg, 'models' => []]); }

$source = strtolower(trim((string) ($_GET['src'] ?? '')));
$valid  = ['printables', 'makerworld', 'thingiverse', 'cults3d', 'stlflix', 'creality', 'nikko', 'hex3dforum'];
if (!in_array($source, $valid, true)) {
    r_fail('Unknown source.');
}

// Category pools mirror the Browse page (hardcoded where the source has no API
// category list; API-driven for the rest). Keys are what each browse method
// expects.
$PRINTABLES = ['art', 'gadgets', 'household', 'hobby', 'toys', 'tabletop', 'seasonal', 'sports', 'fashion', 'learning'];
$MW         = ['100', '200', '300', '400', '600', '700', '800', '900', '1000'];
$TV         = ['art', 'gadgets', 'household', 'hobby', 'tools', 'toys-and-games', '3d-printing', 'fashion'];
$CULTS      = ['art', 'gadget', 'home', 'hobby', 'miniature-figure', 'toy-game', 'tool', 'jewelry', 'fashion-accessories'];

mt_srand();

/** Take up to $n random items from a list. */
function r_pick(array $list, int $n = 5): array
{
    if (count($list) <= $n) { shuffle($list); return $list; }
    shuffle($list);
    return array_slice($list, 0, $n);
}

/** Normalise a service model row to the Browse tile shape. */
function r_norm(array $m): array
{
    return [
        'id'      => (string) ($m['id'] ?? ''),
        'slug'    => (string) ($m['slug'] ?? ''),
        'name'    => (string) ($m['name'] ?? 'Untitled'),
        'creator' => (string) ($m['creator'] ?? ''),
        'thumb'   => (string) ($m['thumb'] ?? ''),
        'price'   => (int) ($m['price'] ?? 0),
        'club'    => !empty($m['club']),
    ];
}

try {
    $models = [];

    if ($source === 'printables') {
        $svc = new PrintablesService();
        if (!$svc->isAuthed()) r_fail('Add your Printables token in Settings first.');
        $cat = $PRINTABLES[array_rand($PRINTABLES)];
        $models = $svc->searchModels($cat, 36);

    } elseif ($source === 'makerworld') {
        if ((string) cfg('makerworld_token') === '') r_fail('Add your MakerWorld token in Settings first.');
        $svc = new MakerWorldService();
        $cat = $MW[array_rand($MW)];
        $models = $svc->searchByKeyword('', 20, 0, false, $cat);

    } elseif ($source === 'thingiverse') {
        if ((string) cfg('thingiverse_token') === '') r_fail('Add your Thingiverse token in Settings first.');
        $svc = new ThingiverseService();
        $cat = $TV[array_rand($TV)];
        // popular() gives a browsable page we can shuffle.
        $models = $svc->popular(20, max(1, mt_rand(1, 3)), $cat);

    } elseif ($source === 'cults3d') {
        if ((string) cfg('cults3d_username') === '' || (string) cfg('cults3d_token') === '') {
            r_fail('Add your Cults3D username and API key in Settings first.');
        }
        $svc = new Cults3DService();
        $cat = $CULTS[array_rand($CULTS)];
        $models = $svc->search('', 24, max(1, mt_rand(1, 3)), false, $cat);

    } elseif ($source === 'stlflix') {
        if ((string) cfg('stlflix_token') === '') r_fail('Add your STLFlix token in Settings first.');
        $svc  = new STLFlixService();
        $cats = array_keys($svc->categories());
        $cats = array_values(array_filter($cats, static fn($c) => $c !== ''));
        $cat  = $cats !== [] ? $cats[array_rand($cats)] : '';
        $models = $svc->search('', 24, 0, (string) $cat);

    } elseif ($source === 'creality') {
        if (!creality_ready()) r_fail('Add your Creality token and user ID in Settings first.');
        $cc   = new CrealityCloudService();
        $tree = $cc->categoryTree();
        // Flatten the tree to leaf category ids.
        $ids = [];
        array_walk_recursive($tree, static function ($v, $k) use (&$ids) {
            if ($k === 'id' || $k === 'categoryId') $ids[] = (string) $v;
        });
        $ids = array_values(array_unique(array_filter($ids)));
        $cat = $ids !== [] ? $ids[array_rand($ids)] : '';
        $models = $cc->browseCategory($cat, 24, max(1, mt_rand(1, 2)));

    } elseif ($source === 'nikko') {
        if ((string) cfg('nikko_phpsessid') === '') r_fail('Add your Nikko Industries session cookie in Settings first.');
        $svc  = new NikkoService();
        // Random category from the real list, plus a random early page for
        // variety (offset = page-1 * 20). Falls back to the full library.
        $cats = array_keys($svc->categories());
        $cats = array_values(array_filter($cats, static fn($c) => $c !== ''));
        $cat  = $cats !== [] ? (string) $cats[array_rand($cats)] : '';
        $offset = 20 * mt_rand(0, 4);
        $models = $svc->search('', 20, $offset, $cat);

    } elseif ($source === 'hex3dforum') {
        if ((string) cfg('hex3dforum_cookie') === '') r_fail('Add your Hex3D Forum session cookie in Settings first.');
        $ids = hex3dforum_ids();
        if ($ids === []) r_fail('Add at least one Hex3D Forum ID in Settings first.');
        $svc = new Hex3DForumService();
        $fid = (string) $ids[array_rand($ids)];
        $models = $svc->browse($fid, 20, 0);
    }

    if (!is_array($models) || $models === []) {
        r_fail('No models came back for that spin — try again.');
    }

    $picked = array_map('r_norm', r_pick(array_values($models), 5));
    r_out(['ok' => true, 'source' => $source, 'models' => $picked]);

} catch (\Throwable $e) {
    r_fail('Roulette hit a snag — try again.');
}
