<?php
declare(strict_types=1);

/**
 * library.php — unified "My Library" of downloaded models.
 *
 * Dual mode:
 *   library.php            -> all sources, with filter pills
 *   library.php?src=<slug> -> just that source (legacy entry from home.php)
 *
 * Each model folder becomes a tile. Folders with no printable 3D files are
 * skipped (nothing to view). Clicking a tile opens a detail modal with local
 * info + a "View in 3D" deep link into viewer.php. LOCAL FILESYSTEM ONLY.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth.php';
require_auth();

// Printable extensions that make a folder worth showing / viewable.
const LIB_PRINT_EXT = ['stl', '3mf', 'obj', 'step', 'stp'];
const LIB_VIEW_EXT  = ['stl', '3mf']; // what viewer.php can actually render
const LIB_SOURCE_EXT = ['scad', 'py']; // parametric source — worth keeping even with no mesh

/** Does this folder contain at least one NON-EMPTY printable 3D file? */
function lib_has_printable(string $dir): bool
{
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f->isFile()
                && $f->getSize() > 0
                && in_array(strtolower($f->getExtension()), LIB_PRINT_EXT, true)) {
                return true;
            }
        }
    } catch (\Throwable $e) {
        return false;
    }
    return false;
}

/**
 * A folder is worth showing in the library if it has a printable mesh OR a
 * parametric source file (.scad/.py). MakerWorld parametric models often ship
 * only the recovered .scad — those should still appear (and be openable in the
 * Customize workshop), not be treated as empty.
 */
function lib_has_content(string $dir): bool
{
    if (lib_has_printable($dir)) {
        return true;
    }
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f->isFile()
                && $f->getSize() > 0
                && in_array(strtolower($f->getExtension()), LIB_SOURCE_EXT, true)) {
                return true;
            }
        }
    } catch (\Throwable $e) {
        return false;
    }
    return false;
}

/** True if the folder has a file viewer.php can render (.stl/.3mf). */
function lib_is_viewable(string $dir): bool
{
    return lib_first_viewable($dir) !== null;
}

// Files above this are skipped by AUTO/batch generation (huge meshes crash the
// tab / blow the timeout). They can still be rendered manually in the modal.
const LIB_MAX_AUTO_BYTES = 18 * 1024 * 1024; // 18 MB

/**
 * Smallest renderable file (rel path) in a model folder, smallest-first so the
 * lightest mesh is used for the thumbnail. Returns the absolute-smallest even
 * if it's above the auto ceiling (so the modal can still render it); the batch
 * decides separately whether it's small enough to auto-generate.
 * @return array{rel:string,size:int}|null
 */
function lib_first_viewable_info(string $dir): ?array
{
    try {
        $cands = [];
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        ) as $f) {
            if (!$f->isFile()) continue;
            $sz = $f->getSize();
            if ($sz <= 0) continue;
            if (!in_array(strtolower($f->getExtension()), LIB_VIEW_EXT, true)) continue;
            $rel = str_replace('\\', '/', ltrim(substr($f->getPathname(), strlen($dir)), '/\\'));
            $cands[] = ['rel' => $rel, 'size' => $sz];
        }
        if ($cands === []) return null;
        usort($cands, static fn($a, $b) => $a['size'] <=> $b['size']);
        return $cands[0];
    } catch (\Throwable $e) {
        return null;
    }
}

/** Relative path of the smallest renderable file, or null. */
function lib_first_viewable(string $dir): ?string
{
    $info = lib_first_viewable_info($dir);
    return $info['rel'] ?? null;
}

/** Newest mtime among the folder's files — used for "downloaded" + sorting. */
function lib_folder_mtime(string $dir): int
{
    $newest = @filemtime($dir) ?: 0;
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f->isFile()) {
                $m = $f->getMTime();
                if ($m > $newest) $newest = $m;
            }
        }
    } catch (\Throwable $e) {
        // keep folder mtime
    }
    return $newest;
}

/** Cached thumbnail path for a model, if one has been generated. */
function lib_thumb_rel(string $slug, string $folder): ?string
{
    // Custom (registered) folders: use an existing preview image, served by
    // model_file.php?thumb=1 (which finds the image). MODELS_ROOT path
    // construction doesn't apply since custom folders live elsewhere.
    if (str_starts_with($slug, 'custom:')) {
        $base = source_path($slug);
        if ($base === null) return null;
        if (lib_find_preview_image($base . '/' . $folder) === null) return null;
        return 'model_file.php?src=' . rawurlencode($slug)
            . '&model=' . rawurlencode($folder) . '&thumb=1';
    }
    // Online sources: if the per-source "use source thumbnails" toggle is on and
    // a saved source cover exists, prefer it; otherwise fall back to the
    // generated STL render. model_file.php serves whichever via prefer=source.
    $genThumb = MODELS_ROOT . '/' . $slug . '/' . $folder . '/.farfetched/thumb.png';
    $srcThumb = MODELS_ROOT . '/' . $slug . '/' . $folder . '/.farfetched/source.png';
    $preferSource = source_thumbs_on($slug) && is_file($srcThumb);
    if ($preferSource) {
        return 'model_file.php?src=' . rawurlencode($slug)
            . '&model=' . rawurlencode($folder) . '&thumb=1&prefer=source';
    }
    return is_file($genThumb) ? 'model_file.php?src=' . rawurlencode($slug)
        . '&model=' . rawurlencode($folder) . '&thumb=1' : null;
}

// ---- Library card-index cache ----------------------------------------------
// Per-folder card data is expensive to compute (several recursive directory
// walks over a FUSE/shfs mount) and never changes once a model is downloaded.
// We persist it in the `library_index` SQLite table keyed by (slug, folder),
// validated by a cheap `sig` (top-level folder mtime + .farfetched mtime). On a
// cache hit we do ZERO walks; only new/changed folders are scanned, so steady-
// state library loads scale with folder COUNT, not total file count.

/**
 * Cheap validity signature for a model folder: the folder's own mtime plus its
 * .farfetched dir mtime (which bumps when thumbnails/meta are (re)generated).
 * Two stat() calls — no directory walk.
 */
function lib_card_sig(string $abs): string
{
    $a  = @filemtime($abs) ?: 0;
    $ff = @filemtime($abs . '/.farfetched') ?: 0;
    return $a . ':' . $ff;
}

/**
 * Expensive scan of a single model folder. Runs ONLY on a cache miss. Returns
 * the cacheable payload, or ['_skip' => true] when the folder has no printable
 * files (so empties are remembered and never re-walked either).
 *
 * @return array<string,mixed>
 */
function lib_scan_folder(string $abs): array
{
    if (!lib_has_content($abs)) {
        return ['_skip' => true];
    }
    [$files, $size] = dir_stats($abs);

    $firstInfo = lib_first_viewable_info($abs);
    $firstView = $firstInfo['rel'] ?? null;
    $firstSize = $firstInfo['size'] ?? 0;
    $typeList  = model_file_types($abs);

    // Customizable hint: has a .scad/.py script, or ships multiple meshes.
    $hasScript = in_array('SCAD', $typeList, true) || in_array('PY', $typeList, true);
    $meshCount = 0;
    if (!$hasScript) {
        foreach (['stl', '3mf', 'obj'] as $ext) {
            $meshCount += count(glob($abs . '/*.' . $ext) ?: []);
            $meshCount += count(glob($abs . '/*.' . strtoupper($ext)) ?: []);
        }
    }
    $isCustomizable = $hasScript || $meshCount >= 2;

    $metaCreator = ''; $metaModelId = ''; $metaCreatorUid = '';
    $metaFile = $abs . '/.farfetched/meta.json';
    if (is_file($metaFile)) {
        $md = json_decode((string) @file_get_contents($metaFile), true);
        if (is_array($md)) {
            $metaCreator = (string) ($md['creator'] ?? '');
            $metaModelId = (string) ($md['model_id'] ?? '');
            $metaCreatorUid = (string) ($md['creator_uid'] ?? '');
        }
    }

    return [
        'files'        => (int) $files,
        'size'         => (int) $size,
        'types'        => $typeList,
        'mtime'        => lib_folder_mtime($abs),
        'viewable'     => $firstView !== null,
        'firstfile'    => $firstView,
        'firstsize'    => (int) $firstSize,
        'customizable' => $isCustomizable,
        'creator'      => $metaCreator,
        'creator_uid'  => $metaCreatorUid,
        'model_id'     => $metaModelId,
    ];
}

/**
 * Load the whole cache for a source in one query.
 * @return array<string,array{sig:string,payload:string}>
 */
function lib_index_load(string $slug): array
{
    try {
        $st = db()->prepare('SELECT folder, sig, payload FROM library_index WHERE slug = ?');
        $st->execute([$slug]);
        $map = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[(string) $r['folder']] = ['sig' => (string) $r['sig'], 'payload' => (string) $r['payload']];
        }
        return $map;
    } catch (\Throwable $e) {
        return []; // cache unavailable → fall back to full scans this load
    }
}

/**
 * Upsert freshly-scanned rows in one transaction.
 * @param array<int,array{0:string,1:string,2:string,3:string}> $rows  [slug, folder, sig, payloadJson]
 */
function lib_index_save(array $rows): void
{
    if ($rows === []) {
        return;
    }
    $pdo = db();
    $lk  = db_write_lock();
    try {
        $pdo->beginTransaction();
        $ins = $pdo->prepare(
            'INSERT OR REPLACE INTO library_index (slug, folder, sig, payload) VALUES (?, ?, ?, ?)'
        );
        foreach ($rows as $r) {
            $ins->execute($r);
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            try { $pdo->rollBack(); } catch (\Throwable $e2) { /* ignore */ }
        }
    } finally {
        db_write_unlock($lk);
    }
}

/**
 * Drop cache rows for a source whose folders no longer exist on disk.
 * @param array<int,string> $keepFolders
 */
function lib_index_prune(string $slug, array $keepFolders): void
{
    $keep = array_fill_keys($keepFolders, true);
    $stale = [];
    foreach (lib_index_load($slug) as $folder => $_row) {
        if (!isset($keep[$folder])) {
            $stale[] = $folder;
        }
    }
    if ($stale === []) {
        return;
    }
    $pdo = db();
    $lk  = db_write_lock();
    try {
        $del = $pdo->prepare('DELETE FROM library_index WHERE slug = ? AND folder = ?');
        foreach ($stale as $folder) {
            $del->execute([$slug, $folder]);
        }
    } catch (\Throwable $e) {
        /* non-fatal: stale rows are harmless, just untidy */
    } finally {
        db_write_unlock($lk);
    }
}

// ---- Gather models (one or all sources) ------------------------------------
$onlySrc = (string) ($_GET['src'] ?? '');
if ($onlySrc !== '' && source_path($onlySrc) === null) {
    http_response_code(404);
    echo 'Unknown source. <a href="library.php">Back to library</a>';
    exit;
}

$sources = $onlySrc !== ''
    ? [['slug' => $onlySrc, 'path' => (string) source_path($onlySrc)]]
    : array_map(static fn($s) => ['slug' => $s['slug'], 'path' => $s['path']], list_sources());

$models = [];          // tiles
$srcCounts = [];       // slug -> count (for filter pills)
$totalFiles = 0;
$totalBytes = 0;

foreach ($sources as $s) {
    $slug = $s['slug'];
    $cache    = lib_index_load($slug);      // one query per source
    $dirs     = list_model_dirs($s['path']); // cheap: names only, no deep stat
    $toSave   = [];                          // [slug, folder, sig, payloadJson] for misses
    $seen     = [];

    foreach ($dirs as $name) {
        // Skip housekeeping folders (e.g. _processed from Organize) and dotdirs.
        if ($name === '_processed' || $name === '_processing' || ($name !== '' && $name[0] === '.')) {
            continue;
        }
        $seen[] = $name;
        $abs    = $s['path'] . '/' . $name;
        $sig    = lib_card_sig($abs);        // two stat()s, no walk

        if (isset($cache[$name]) && $cache[$name]['sig'] === $sig) {
            // Cache hit — zero directory walks.
            $payload = json_decode($cache[$name]['payload'], true);
            if (!is_array($payload)) {
                $payload = lib_scan_folder($abs);
                $toSave[] = [$slug, $name, $sig, json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE)];
            }
        } else {
            // Miss or changed → scan once and remember it.
            $payload  = lib_scan_folder($abs);
            $toSave[] = [$slug, $name, $sig, json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE)];
        }

        if (!empty($payload['_skip'])) {
            continue; // no printable files — remembered, never re-walked
        }

        // Live (cheap) fields recomputed every load so they stay responsive:
        //   thumb depends on the per-source "use source thumbnails" toggle and on
        //   is_file() checks; title is pure string work.
        $models[] = [
            'slug'      => $slug,
            'folder'    => $name,
            'title'     => clean_model_name($name),
            'files'     => (int) ($payload['files'] ?? 0),
            'size'      => (int) ($payload['size'] ?? 0),
            'types'     => (array) ($payload['types'] ?? []),
            'mtime'     => (int) ($payload['mtime'] ?? 0),
            'viewable'  => (bool) ($payload['viewable'] ?? false),
            'firstfile' => $payload['firstfile'] ?? null,
            'firstsize' => (int) ($payload['firstsize'] ?? 0),
            'thumb'     => lib_thumb_rel($slug, $name),
            'customizable' => (bool) ($payload['customizable'] ?? false),
            'creator'   => (string) ($payload['creator'] ?? ''),
            'creator_uid' => (string) ($payload['creator_uid'] ?? ''),
            'model_id'  => (string) ($payload['model_id'] ?? ''),
        ];
        $srcCounts[$slug] = ($srcCounts[$slug] ?? 0) + 1;
        $totalFiles += (int) ($payload['files'] ?? 0);
        $totalBytes += (int) ($payload['size'] ?? 0);
    }

    // Persist newly-scanned rows and drop rows for folders that vanished.
    lib_index_save($toSave);
    lib_index_prune($slug, $seen);
}

// Newest first by default.
usort($models, static fn($a, $b) => $b['mtime'] <=> $a['mtime']);

// Print counts for the printed-badge on tiles.
$printCounts = [];
foreach (db()->query('SELECT source, folder, print_count FROM prints WHERE print_count > 0')->fetchAll(PDO::FETCH_ASSOC) as $pr) {
    $printCounts[$pr['source'] . '/' . $pr['folder']] = (int) $pr['print_count'];
}

$csrf  = csrf_token();
$title = $onlySrc !== '' ? ucfirst($onlySrc) . ' Library' : 'My Library';
// Favorites are a nice-to-have here; never let a DB hiccup blank the library.
try {
    $favSet = favorites_key_set();
} catch (\Throwable $e) {
    $favSet = [];
}

/** Extract the leading model id from a folder name like "1753580 - Hase Rabbit". */
function lib_model_id(string $folder): string
{
    if (preg_match('/^([A-Za-z0-9]+)\s*-\s*/', $folder, $mm)) {
        return $mm[1];
    }
    // Hex-only or id-only folder name (e.g. "6a1d5641eb21769e68f89c85").
    if (preg_match('/^[A-Za-z0-9]+$/', $folder)) {
        return $folder;
    }
    return $folder;
}

/** Stable placeholder gradient from the model name (shown when no thumb). */
function lib_tile_style(string $name): string
{
    $h = crc32($name);
    $hue = $h % 360;
    $hue2 = ($hue + 26) % 360;
    return "background:linear-gradient(150deg,hsl({$hue},38%,42%),hsl({$hue2},40%,28%));";
}

/** Short uppercase badge per source. */
function lib_badge(string $slug): string
{
    static $map = [
        'printables' => 'PT', 'makerworld' => 'MW', 'thingiverse' => 'TV',
        'cults3d' => 'C3D', 'stlflix' => 'SF', 'creality' => 'CR',
    ];
    return $map[strtolower($slug)] ?? strtoupper(substr($slug, 0, 2));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · FarFetched</title>
<link rel="stylesheet" href="css/styles.css">
<link rel="stylesheet" href="css/styles_library.css">
<script type="application/json" id="lib-csrf"><?= json_encode($csrf) ?></script>
<script type="importmap">
{
  "imports": {
    "three": "https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.module.js",
    "three/addons/": "https://cdn.jsdelivr.net/npm/three@0.160.0/examples/jsm/"
  }
}
</script>
</head>
<body>
  <aside>
    <div class="brand"><img src="logo.svg" alt="FarFetched" style="height:1.15em;width:auto;vertical-align:-.2em;margin-right:7px"> FarFetched</div>
    <div class="navlabel">Tool</div>
    <nav>
      <a href="index.php">Browse Models</a>
      <a href="jobs.php">Queue</a>
      <a href="viewer.php">3D Viewer</a>
      <a href="library.php" class="active">My Library</a>
      <a href="customize.php">Customize</a>
      <a href="insights.php">Insights</a>
      <a href="printers.php">My Printers</a>
      <a href="collections_view.php">Collections</a>
      <a href="favorites.php">Favorites</a>
      <a href="settings.php">Settings</a>
      <button id="theme-toggle" aria-label="Toggle theme" class="btn-ghost">
        <span id="theme-toggle-icon">🌙</span> Change Appearance
      </button>
    </nav>
  </aside>

  <main>
    <h1><?= e($title) ?></h1>
    <div class="sub">
      <?= count($models) ?> model<?= count($models) === 1 ? '' : 's' ?> ·
      <?= number_format($totalFiles) ?> files ·
      <?= e(human_size($totalBytes)) ?> on disk
    </div>

    <?php if ($models !== []): ?>
    <div class="lib-toolbar">
      <button id="genAllBtn" class="lib-btn lib-btn-accent lib-btn-sm" type="button">
        📸 Generate all missing thumbnails
      </button>
      <button id="grabSrcBtn" class="lib-btn lib-btn-sm" type="button">
        🖼️ Grab thumbnails from source
      </button>
      <span id="grabSrcStatus" class="lib-muted" style="margin-left:8px;font-size:13px;"></span>
      <input type="search" id="libSearch" class="lib-search" placeholder="🔍 Search your library…" autocomplete="off">
    </div>
    <?php endif; ?>

    <?php
      // Recently-added shelf: the newest few models (already sorted newest-first).
      $recent = array_slice($models, 0, 8);
      if (count($models) > 8 && $recent !== []):
    ?>
    <div class="lib-shelf" id="recentShelf">
      <div class="lib-shelf-label">Recently added</div>
      <div class="lib-shelf-row">
        <?php foreach ($recent as $m):
          $rThumb = $m['thumb'];
        ?>
          <button type="button" class="lib-shelf-card"
                  data-src="<?= e($m['slug']) ?>" data-folder="<?= e($m['folder']) ?>">
            <div class="lib-shelf-thumb" style="<?= $rThumb ? '' : e(lib_tile_style($m['title'])) ?>">
              <?php if ($rThumb): ?><img src="<?= e($rThumb) ?>" alt="" loading="lazy"><?php else: ?><span><?= e(mb_substr($m['title'], 0, 18)) ?></span><?php endif; ?>
            </div>
            <div class="lib-shelf-name"><?= e($m['title']) ?></div>
          </button>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($onlySrc === '' && count($srcCounts) > 1): ?>
    <div class="lib-filters" id="filters">
      <button class="pill active" data-src="">All</button>
      <?php foreach ($srcCounts as $slug => $n): ?>
        <button class="pill" data-src="<?= e($slug) ?>"><?= e(ucfirst($slug)) ?> <span class="pill-n"><?= $n ?></span></button>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($models === []): ?>
      <div class="lib-empty">No downloaded models yet. Head to <a href="index.php">Browse Models</a> to fetch some.</div>
    <?php else: ?>
      <div class="lib-grid" id="grid">
        <?php foreach ($models as $m): ?>
          <div class="lib-card" role="button" tabindex="0"
                  data-src="<?= e($m['slug']) ?>"
                  data-folder="<?= e($m['folder']) ?>"
                  data-title="<?= e($m['title']) ?>"
                  data-files="<?= (int) $m['files'] ?>"
                  data-size="<?= e(human_size($m['size'])) ?>"
                  data-types="<?= e(implode(', ', $m['types'])) ?>"
                  data-date="<?= e($m['mtime'] ? date('M j, Y', $m['mtime']) : '—') ?>"
                  data-viewable="<?= $m['viewable'] ? '1' : '0' ?>"
                  data-firstfile="<?= e((string) $m['firstfile']) ?>"
                  data-firstsize="<?= (int) $m['firstsize'] ?>"
                  data-autoable="<?= ($m['firstsize'] > 0 && $m['firstsize'] <= LIB_MAX_AUTO_BYTES) ? '1' : '0' ?>"
                  data-hasthumb="<?= $m['thumb'] ? '1' : '0' ?>"
                  data-badge="<?= e(lib_badge($m['slug'])) ?>">
            <div class="lib-thumb" style="<?= $m['thumb'] ? '' : e(lib_tile_style($m['title'])) ?>">
              <?php $pc = $printCounts[$m['slug'] . '/' . $m['folder']] ?? 0; ?>
              <?php if ($pc > 0): ?><div class="lib-printed-badge">✓ <?= $pc ?></div><?php endif; ?>
              <?php
                $mid   = lib_model_id($m['folder']);
                $isFav = isset($favSet[$m['slug'] . ':' . $mid]);
              ?>
              <button type="button" class="fav-star lib-star <?= $isFav ? 'on' : '' ?>"
                      data-fav-source="<?= e($m['slug']) ?>" data-fav-id="<?= e($mid) ?>"
                      data-fav-name="<?= e($m['title']) ?>"
                      aria-label="Favorite" title="Save to Favorites"><?= $isFav ? '★' : '☆' ?></button>
              <?php if ($m['thumb']): ?>
                <img src="<?= e($m['thumb']) ?>" alt="" loading="lazy">
              <?php else: ?>
                <span class="lib-thumb-ph"><?= e($m['title']) ?></span>
              <?php endif; ?>
            </div>
            <div class="lib-meta">
              <div class="lib-name"><?= e($m['title']) ?></div>
              <?php if (($m['creator'] ?? '') !== ''): ?>
                <?php
                  // Author search needs a per-source key, not the display name:
                  // MakerWorld uses a numeric uid, Printables the handle slug
                  // (e.g. "zx82net_107245"). Both are stored in creator_uid at
                  // download. Link by it when valid; otherwise fall back to the
                  // display name (older items lack the key — search may miss).
                  $authKey = (string) ($m['creator_uid'] ?? '');
                  $useKey  = (($m['slug'] === 'makerworld' || $m['slug'] === 'creality') && $authKey !== '' && ctype_digit($authKey))
                          || ($m['slug'] === 'printables' && $authKey !== '');
                  if ($useKey) {
                      $authorHref = 'index.php?src=' . e($m['slug'])
                                  . '&author=' . rawurlencode($authKey)
                                  . '&authorname=' . rawurlencode($m['creator']);
                  } else {
                      $authorHref = 'index.php?src=' . e($m['slug'])
                                  . '&author=' . rawurlencode($m['creator']);
                  }
                ?>
                <div class="lib-creator">by <a href="<?= $authorHref ?>" title="Search this creator on <?= e(lib_badge($m['slug'])) ?>"><?= e($m['creator']) ?></a></div>
              <?php endif; ?>
              <div class="lib-row">
                <span class="lib-badge"><?= e(lib_badge($m['slug'])) ?></span>
                <span class="lib-files-wrap">
                  <span class="lib-files"><?= (int) $m['files'] ?> file<?= (int) $m['files'] === 1 ? '' : 's' ?></span>
                  <?php if (!empty($m['customizable'])): ?><span class="lib-custom-badge" title="Customizable / poseable">⚙</span><?php endif; ?>
                </span>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </main>

  <!-- Detail modal -->
  <div id="libModal" class="lib-modal-backdrop" hidden>
    <div class="lib-modal">
      <div class="lib-modal-hero" id="mHero">
        <!-- gradient placeholder shown until preview is loaded -->
        <span class="lib-modal-heroicon" id="mHeroIcon">📦</span>
        <button class="lib-preview-load" id="mLoadPreview" type="button">▶ Load preview</button>
        <div class="lib-preview-canvas" id="mCanvas" hidden></div>
        <div class="lib-preview-hint" id="mPreviewHint" hidden>Drag to orbit · scroll to zoom, then capture</div>
        <button class="lib-modal-close" id="mClose" aria-label="Close">&times;</button>
      </div>
      <div class="lib-modal-body">
        <div class="lib-modal-titlerow">
          <div class="lib-modal-title" id="mTitle">—</div>
          <span class="lib-modal-badge" id="mBadge">—</span>
        </div>
        <div class="lib-printinfo" id="mPrintInfo" hidden></div>

        <div class="lib-tracker" id="mTracker">
          <div class="lib-tracker-row">
            <span class="lib-tracker-label">Printed</span>
            <button class="lib-cnt-btn" id="mPrintDec">−</button>
            <span class="lib-cnt" id="mPrintCount">0</span>
            <button class="lib-cnt-btn" id="mPrintInc">+</button>
            <span class="lib-cnt-times">times</span>
            <span class="lib-tracker-last" id="mPrintLast"></span>
          </div>
          <textarea class="lib-tracker-notes" id="mPrintNotes" placeholder="Print notes (filament, settings, results…)" rows="2"></textarea>
          <button class="lib-btn lib-btn-ghost lib-btn-sm" id="mPrintSave" type="button">Save notes</button>
        </div>
        <div class="lib-modal-grid">
          <div class="lib-stat"><div class="lib-stat-k">Downloaded</div><div class="lib-stat-v" id="mDate">—</div></div>
          <div class="lib-stat"><div class="lib-stat-k">Size</div><div class="lib-stat-v" id="mSize">—</div></div>
          <div class="lib-stat"><div class="lib-stat-k">Files</div><div class="lib-stat-v" id="mFiles">—</div></div>
          <div class="lib-stat"><div class="lib-stat-k">Types</div><div class="lib-stat-v" id="mTypes">—</div></div>
        </div>
        <div class="lib-stat lib-stat-wide">
          <div class="lib-stat-k">Folder</div>
          <div class="lib-stat-v lib-mono" id="mFolder">—</div>
        </div>
        <div class="lib-modal-actions">
          <a class="lib-btn lib-btn-primary" id="mView" href="#">📐 View in 3D</a>
          <button class="lib-btn lib-btn-accent" id="mGenThumb" type="button" hidden>📸 Generate thumbnail</button>
          <button class="lib-btn lib-btn-ghost" id="mReveal" type="button" title="Copy folder path">⧉ Copy path</button>
          <button class="lib-btn lib-btn-ghost" id="mCollection" type="button" title="Add to collection">📁 Collection</button>
          <a class="lib-btn lib-btn-ghost" id="mExport" href="#" title="Export bundle">⬇ Export</a>
          <button class="lib-btn lib-btn-danger" id="mDelete" type="button" title="Delete this model">🗑 Delete</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Collection picker sub-modal -->
  <div id="colModal" class="lib-modal-backdrop" hidden>
    <div class="lib-modal lib-modal-sm">
      <div class="lib-modal-head">
        <span class="lib-modal-title">Add to collection</span>
        <button class="lib-modal-close" id="colClose">&times;</button>
      </div>
      <div class="lib-modal-body">
        <div id="colList" class="col-list"></div>
        <div class="col-create">
          <input id="colNewName" class="lib-search" placeholder="New collection name…">
          <button class="lib-btn lib-btn-accent lib-btn-sm" id="colCreate" type="button" style="flex:0 0 auto">Create</button>
        </div>
      </div>
    </div>
  </div>

  <div id="batchToast" class="lib-batch-toast" hidden>
    <div class="lib-batch-row">
      <span id="batchLabel">Generating thumbnails…</span>
      <button id="batchCancel" class="lib-batch-cancel" type="button">Cancel</button>
    </div>
    <div class="lib-batch-bar"><div class="lib-batch-fill" id="batchFill"></div></div>
  </div>

  <!-- Offscreen canvas host for batch rendering (C) -->
  <div id="batchCanvasHost" style="position:absolute;width:1px;height:1px;overflow:hidden;left:-9999px;top:-9999px;"></div>

<script>
  // ---- Shared refs ---------------------------------------------------------
  const grid = document.getElementById('grid');
  let colCurrent = { src: '', folder: '' };
  const filters = document.getElementById('filters');

  // ---- Live search filter --------------------------------------------------
  const libSearch = document.getElementById('libSearch');
  function applyFilters() {
    const q = (libSearch && libSearch.value || '').trim().toLowerCase();
    const activePill = filters ? filters.querySelector('.pill.active') : null;
    const wantSrc = activePill ? activePill.dataset.src : '';
    grid.querySelectorAll('.lib-card').forEach(card => {
      const matchSrc = !wantSrc || card.dataset.src === wantSrc;
      const matchQ = !q || (card.dataset.title || '').toLowerCase().includes(q)
                        || (card.dataset.folder || '').toLowerCase().includes(q);
      card.style.display = (matchSrc && matchQ) ? '' : 'none';
    });
    const shelf = document.getElementById('recentShelf');
    if (shelf) shelf.style.display = q ? 'none' : '';
  }
  if (libSearch) libSearch.addEventListener('input', applyFilters);

  // ---- Recently-added shelf: open modal for the clicked card ---------------
  const recentShelf = document.getElementById('recentShelf');
  if (recentShelf) {
    recentShelf.addEventListener('click', (e) => {
      const sc = e.target.closest('.lib-shelf-card');
      if (!sc) return;
      const card = grid.querySelector(
        `.lib-card[data-src="${CSS.escape(sc.dataset.src)}"][data-folder="${CSS.escape(sc.dataset.folder)}"]`);
      if (card) openModal(card);
    });
  }

  // ---- Filter pills --------------------------------------------------------
  if (filters) {
    filters.addEventListener('click', (e) => {
      const btn = e.target.closest('.pill');
      if (!btn) return;
      filters.querySelectorAll('.pill').forEach(p => p.classList.remove('active'));
      btn.classList.add('active');
      applyFilters(); // respect any active search query too
    });
  }

  // ---- Detail modal --------------------------------------------------------
  const modal   = document.getElementById('libModal');
  const mTitle  = document.getElementById('mTitle');
  const mBadge  = document.getElementById('mBadge');
  const mDate   = document.getElementById('mDate');
  const mSize   = document.getElementById('mSize');
  const mFiles  = document.getElementById('mFiles');
  const mTypes  = document.getElementById('mTypes');
  const mFolder = document.getElementById('mFolder');
  const mView   = document.getElementById('mView');
  const mReveal = document.getElementById('mReveal');
  const mHero   = document.getElementById('mHero');

  let currentFolderPath = '';

  function openModal(card) {
    const d = card.dataset;
    mTitle.textContent  = d.title;
    mBadge.textContent  = d.badge;
    mDate.textContent   = d.date;
    mSize.textContent   = d.size;
    mFiles.textContent  = d.files;
    mTypes.textContent  = d.types || '—';
    mFolder.textContent = d.src + '/' + d.folder;
    currentFolderPath   = d.src + '/' + d.folder;
    colCurrent = { src: d.src, folder: d.folder };

    // Export bundle deep link.
    const mExport = document.getElementById('mExport');
    if (mExport) mExport.href = 'export_bundle.php?src=' + encodeURIComponent(d.src) + '&model=' + encodeURIComponent(d.folder);

    // Load print-tracker state (defined in the module script).
    if (window.__ffOpenModelMeta) window.__ffOpenModelMeta(d.src, d.folder);

    // Stable hero gradient matching the tile.
    const thumb = card.querySelector('.lib-thumb');
    mHero.style.cssText = thumb.getAttribute('style') || '';

    // View in 3D — deep link, only if renderable.
    if (d.viewable === '1') {
      mView.style.display = '';
      mView.href = 'viewer.php?src=' + encodeURIComponent(d.src) +
                   '&model=' + encodeURIComponent(d.folder);
    } else {
      mView.style.display = 'none';
    }
    modal.hidden = false;
    // Notify the thumbnail-engine module so it can offer preview/generate.
    window.__libModalOpened && window.__libModalOpened(d);

    // Print-time / filament badge — fetched from the model's 3MF metadata.
    const pInfo = document.getElementById('mPrintInfo');
    if (pInfo) {
      pInfo.hidden = true;
      pInfo.innerHTML = '';
      fetch('model_meta.php?src=' + encodeURIComponent(d.src) + '&model=' + encodeURIComponent(d.folder))
        .then(r => r.json())
        .then(meta => {
          if (!meta.ok) return;
          const chips = [];
          if (meta.printSeconds > 0) {
            const h = Math.floor(meta.printSeconds / 3600);
            const m = Math.round((meta.printSeconds % 3600) / 60);
            const t = h > 0 ? (h + 'h ' + m + 'm') : (m + 'm');
            chips.push('<span class="lib-chip"><span class="lib-chip-ico">⏱</span>' + t + '</span>');
          }
          if (meta.filamentGrams > 0) {
            chips.push('<span class="lib-chip"><span class="lib-chip-ico">🧵</span>' + meta.filamentGrams + ' g</span>');
          }
          if (meta.filamentMeters > 0) {
            chips.push('<span class="lib-chip"><span class="lib-chip-ico">📏</span>' + meta.filamentMeters + ' m</span>');
          }
          if (meta.colors > 1) {
            chips.push('<span class="lib-chip"><span class="lib-chip-ico">🎨</span>' + meta.colors + ' colors</span>');
          }
          if (meta.plates > 0) {
            chips.push('<span class="lib-chip"><span class="lib-chip-ico">🍽</span>' + meta.plates + (meta.plates === 1 ? ' plate' : ' plates') + '</span>');
          }
          if (meta.printer) {
            chips.push('<span class="lib-chip"><span class="lib-chip-ico">🖨</span>' + meta.printer + '</span>');
          }
          if (chips.length) { pInfo.innerHTML = chips.join(''); pInfo.hidden = false; }
        })
        .catch(() => {});
    }
  }
  function closeModal() {
    modal.hidden = true;
    window.__libModalClosed && window.__libModalClosed();
  }

  grid && grid.addEventListener('click', async (e) => {
    // Star click toggles favorite and must NOT open the modal.
    const star = e.target.closest('.fav-star');
    if (star) {
      e.stopPropagation();
      star.disabled = true;
      try {
        const res = await fetch('favorite.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action:   'toggle',
            source:   star.dataset.favSource,
            model_id: star.dataset.favId,
            name:     star.dataset.favName || '',
          }),
        });
        const j = await res.json();
        if (j.ok) {
          star.classList.toggle('on', j.favorited);
          star.textContent = j.favorited ? '★' : '☆';
        }
      } catch (_) { /* leave as-is on error */ }
      star.disabled = false;
      return;
    }
    // Author link navigates to the source search — let it through without
    // opening the modal (which would flash before the navigation lands).
    if (e.target.closest('.lib-creator a')) {
      return;
    }
    const card = e.target.closest('.lib-card');
    if (card) openModal(card);
  });

  document.getElementById('mClose').addEventListener('click', closeModal);
  modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModal(); });

  mReveal.addEventListener('click', () => {
    navigator.clipboard?.writeText(currentFolderPath).then(() => {
      mReveal.textContent = '✓ Copied';
      setTimeout(() => { mReveal.textContent = '⧉ Copy path'; }, 1500);
    });
  });

  // ---- Theme toggle (matches other pages) ----------------------------------
  const toggleBtn = document.getElementById('theme-toggle');
  const toggleIcon = document.getElementById('theme-toggle-icon');
  const currentTheme = localStorage.getItem('theme') || 'dark';
  if (currentTheme === 'light') {
    document.documentElement.setAttribute('data-theme', 'light');
    if (toggleIcon) toggleIcon.textContent = '☀️';
  }
  if (toggleBtn) toggleBtn.addEventListener('click', () => {
    let theme = 'dark';
    if (document.documentElement.getAttribute('data-theme') !== 'light') {
      document.documentElement.setAttribute('data-theme', 'light');
      toggleIcon.textContent = '☀️';
      theme = 'light';
    } else {
      document.documentElement.removeAttribute('data-theme');
      toggleIcon.textContent = '🌙';
    }
    localStorage.setItem('theme', theme);
  });
</script>

<script type="module">
  import { createViewer } from './js/viewer-core.js';

  const CSRF = JSON.parse(document.getElementById('lib-csrf').textContent || '""');

  // "Grab thumbnails from source" — backfill source covers for all enabled
  // sources, overwriting existing source.png (force). Reloads on completion so
  // the new images show.
  (function () {
    const btn = document.getElementById('grabSrcBtn');
    const st  = document.getElementById('grabSrcStatus');
    if (!btn) return;
    btn.addEventListener('click', async () => {
      btn.disabled = true;
      if (st) st.textContent = 'Fetching source images… (older models may take a minute)';
      try {
        const body = new URLSearchParams({ csrf: CSRF, force: '1' });
        const res = await fetch('source_thumbs_backfill.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: body.toString()
        });
        const d = await res.json();
        if (d && d.ok) {
          if (st) st.textContent = 'Done — saved ' + (d.saved || 0) +
            (d.resolved ? (' (' + d.resolved + ' looked up from source)') : '') +
            ', skipped ' + (d.skipped || 0) +
            ((d.failed) ? (', failed ' + d.failed) : '') + '. Reloading…';
          setTimeout(() => location.reload(), 1200);
        } else {
          if (st) st.textContent = 'Failed: ' + ((d && d.error) || 'unknown error');
          btn.disabled = false;
        }
      } catch (e) {
        if (st) st.textContent = 'Failed: ' + e;
        btn.disabled = false;
      }
    });
  })();

  // ===== Print tracker =====
  let trackCurrent = { src: '', folder: '' };
  window.__ffOpenModelMeta = (src, folder) => {
    trackCurrent = { src, folder };
    const mExport = document.getElementById('mExport');
    if (mExport) mExport.href = 'export_bundle.php?src=' + encodeURIComponent(src) + '&model=' + encodeURIComponent(folder);
    loadTracker(src, folder);
  };
  const mPrintCount = document.getElementById('mPrintCount');
  const mPrintNotes = document.getElementById('mPrintNotes');
  const mPrintLast  = document.getElementById('mPrintLast');

  async function loadTracker(src, folder) {    try {
      const r = await fetch('print_track.php?src=' + encodeURIComponent(src) + '&folder=' + encodeURIComponent(folder));
      const d = await r.json();
      if (d.ok) renderTracker(d);
    } catch (_) {}
  }
  function renderTracker(d) {
    if (mPrintCount) mPrintCount.textContent = d.count;
    if (mPrintNotes) mPrintNotes.value = d.notes || '';
    if (mPrintLast) mPrintLast.textContent = d.last_printed ? ('last: ' + d.last_printed.split(' ')[0]) : '';
  }
  async function trackAction(action, extra) {
    const body = Object.assign({ csrf: CSRF, action, src: trackCurrent.src, folder: trackCurrent.folder }, extra || {});
    const r = await fetch('print_track.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    const d = await r.json();
    if (d.ok) { renderTracker(d); markTilePrinted(trackCurrent.src, trackCurrent.folder, d.count); }
  }
  document.getElementById('mPrintInc')?.addEventListener('click', () => trackAction('inc'));
  document.getElementById('mPrintDec')?.addEventListener('click', () => trackAction('dec'));
  document.getElementById('mPrintSave')?.addEventListener('click', () =>
    trackAction('set', { count: +mPrintCount.textContent, notes: mPrintNotes.value }));

  function markTilePrinted(src, folder, count) {
    const card = document.querySelector('.lib-card[data-src="' + CSS.escape(src) + '"][data-folder="' + CSS.escape(folder) + '"]');
    if (!card) return;
    let b = card.querySelector('.lib-printed-badge');
    if (count > 0) {
      if (!b) { b = document.createElement('div'); b.className = 'lib-printed-badge'; card.appendChild(b); }
      b.textContent = '✓ ' + count;
    } else if (b) { b.remove(); }
  }

  // ===== Collections =====
  const colModal = document.getElementById('colModal');
  const colList = document.getElementById('colList');
  document.getElementById('mCollection')?.addEventListener('click', openColModal);
  document.getElementById('colClose')?.addEventListener('click', () => colModal.hidden = true);
  colModal?.addEventListener('click', (e) => { if (e.target === colModal) colModal.hidden = true; });

  async function openColModal() {
    colModal.hidden = false;
    colList.innerHTML = '<div class="col-loading">Loading…</div>';
    const [listR, forR] = await Promise.all([
      fetch('collections.php?list=1').then(r => r.json()),
      fetch('collections.php?for=1&src=' + encodeURIComponent(trackCurrent.src) + '&folder=' + encodeURIComponent(trackCurrent.folder)).then(r => r.json()),
    ]);
    const inSet = new Set((forR.ids || []));
    if (!listR.collections || !listR.collections.length) {
      colList.innerHTML = '<div class="col-empty">No collections yet — create one below.</div>';
      return;
    }
    colList.innerHTML = '';
    for (const c of listR.collections) {
      const row = document.createElement('label');
      row.className = 'col-row';
      const checked = inSet.has(c.id) ? 'checked' : '';
      row.innerHTML = '<input type="checkbox" ' + checked + ' data-id="' + c.id + '"> <span>' + c.name + '</span><span class="col-cnt">' + c.count + '</span>';
      row.querySelector('input').addEventListener('change', async (e) => {
        const adding = e.target.checked;
        await fetch('collections.php', { method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ csrf: CSRF, action: adding ? 'add' : 'remove', collection_id: c.id, src: trackCurrent.src, folder: trackCurrent.folder }) });
        ffToast(adding ? ('Added to "' + c.name + '"') : ('Removed from "' + c.name + '"'));
      });
      colList.appendChild(row);
    }
  }
  document.getElementById('colCreate')?.addEventListener('click', async () => {
    const name = document.getElementById('colNewName').value.trim();
    if (!name) return;
    const r = await fetch('collections.php', { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ csrf: CSRF, action: 'create', name }) });
    const d = await r.json();
    if (d.ok) {
      await fetch('collections.php', { method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ csrf: CSRF, action: 'add', collection_id: d.id, src: trackCurrent.src, folder: trackCurrent.folder }) });
      document.getElementById('colNewName').value = '';
      ffToast('Created & added to "' + name + '"');
      openColModal();
    }
  });

  // Lightweight toast (mirrors the Browse page pattern; CSS lives in styles.css).
  let _tT = null, _tE = null;
  function ffToast(message) {
    let t = document.getElementById('ff-toast');
    if (!t) { t = document.createElement('div'); t.id = 'ff-toast'; document.body.appendChild(t); }
    t.innerHTML = '<span class="ff-toast-msg"></span>';
    t.querySelector('.ff-toast-msg').textContent = message;
    if (_tT) clearTimeout(_tT); if (_tE) clearTimeout(_tE);
    t.classList.remove('hide', 'show');
    requestAnimationFrame(() => requestAnimationFrame(() => t.classList.add('show')));
    _tT = setTimeout(() => { t.classList.remove('show'); t.classList.add('hide'); _tE = setTimeout(() => t.classList.remove('hide'), 500); }, 2600);
  }
  const fileUrl = (src, model, rel) =>
    'model_file.php?src=' + encodeURIComponent(src) +
    '&model=' + encodeURIComponent(model) +
    '&file=' + encodeURIComponent(rel);

  async function saveThumb(src, model, pngDataUrl) {
    const res = await fetch('save_thumb.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ csrf: CSRF, src, model, png: pngDataUrl }),
    });
    const j = await res.json().catch(() => ({}));
    // Soft failures (e.g. model folder not writable because it was added via the
    // network share) are not real errors — thumbnails are a nice-to-have. Return
    // null so callers skip the thumbnail without surfacing an error.
    if (j && j.soft) return null;
    if (!res.ok || !j.ok) throw new Error(j.error || 'Save failed');
    return j.url;
  }

  // Race a load against a timeout so a corrupt/unreadable model can never hang
  // the modal or the batch forever.
  function loadWithTimeout(viewer, url, ext, ms = 20000) {
    return Promise.race([
      viewer.loadFile(url, ext),
      new Promise((_, rej) => setTimeout(() => rej(new Error('Timed out')), ms)),
    ]);
  }

  async function deleteModel(src, model) {
    const res = await fetch('model_delete.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ csrf: CSRF, src, models: [model] }),
    });
    const j = await res.json().catch(() => ({}));
    return res.ok && j.ok;
  }

  function removeCard(src, folder) {
    const card = document.querySelector(
      `.lib-card[data-src="${CSS.escape(src)}"][data-folder="${CSS.escape(folder)}"]`);
    if (card) card.remove();
  }

  // Swap a card's gradient placeholder for the freshly-saved thumbnail.
  function applyThumbToCard(src, folder, url) {
    if (!url) return; // soft fail (folder not writable) — leave the placeholder
    const card = document.querySelector(
      `.lib-card[data-src="${CSS.escape(src)}"][data-folder="${CSS.escape(folder)}"]`);
    if (!card) return;
    const thumb = card.querySelector('.lib-thumb');
    thumb.style.cssText = '';
    thumb.innerHTML = '<img alt="" loading="lazy">';
    thumb.querySelector('img').src = url;
    card.dataset.hasthumb = '1';
  }

  // ===== B: interactive preview + capture in the modal ======================
  const mHero        = document.getElementById('mHero');
  const mHeroIcon    = document.getElementById('mHeroIcon');
  const mLoadPreview = document.getElementById('mLoadPreview');
  const mCanvas      = document.getElementById('mCanvas');
  const mPreviewHint = document.getElementById('mPreviewHint');
  const mGenThumb    = document.getElementById('mGenThumb');

  let modalViewer = null;
  let modalCtx = null; // {src, folder, firstfile}

  function teardownModalViewer() {
    if (modalViewer) { modalViewer.dispose(); modalViewer = null; }
    mCanvas.hidden = true;
    mCanvas.innerHTML = '';
    mPreviewHint.hidden = true;
    mHeroIcon.style.display = '';
    mLoadPreview.style.display = '';
    mLoadPreview.textContent = '▶ Load preview';
    mLoadPreview.disabled = false;
    mGenThumb.hidden = true;
  }

  // Called by the existing classic-script openModal via this hook.
  window.__libModalOpened = function (d) {
    teardownModalViewer();
    modalCtx = { src: d.src, folder: d.folder, firstfile: d.firstfile };
    // Only offer preview/generate when there's a renderable file.
    mLoadPreview.style.display = (d.viewable === '1' && d.firstfile) ? '' : 'none';
  };
  window.__libModalClosed = teardownModalViewer;

  // Delete the currently-open model from disk.
  const mDelete = document.getElementById('mDelete');
  mDelete && mDelete.addEventListener('click', async () => {
    if (!modalCtx) return;
    if (!confirm('Delete "' + modalCtx.folder + '"?\n\nThis permanently removes the folder and all its files from disk.')) return;
    mDelete.disabled = true;
    const orig = mDelete.textContent;
    mDelete.textContent = 'Deleting…';
    const ok = await deleteModel(modalCtx.src, modalCtx.folder);
    if (ok) {
      removeCard(modalCtx.src, modalCtx.folder);
      closeModal();
    } else {
      mDelete.textContent = '✗ Failed';
      setTimeout(() => { mDelete.textContent = orig; mDelete.disabled = false; }, 2000);
    }
  });

  mLoadPreview.addEventListener('click', async () => {
    if (!modalCtx || !modalCtx.firstfile) return;
    mLoadPreview.disabled = true;
    mLoadPreview.textContent = 'Loading…';
    mHeroIcon.style.display = 'none';
    mCanvas.hidden = false;

    modalViewer = createViewer(mCanvas, { background: 0x16140f });
    const ext = modalCtx.firstfile.split('.').pop().toLowerCase();
    try {
      await loadWithTimeout(modalViewer, fileUrl(modalCtx.src, modalCtx.folder, modalCtx.firstfile), ext);
      mLoadPreview.style.display = 'none';
      mPreviewHint.hidden = false;
      mGenThumb.hidden = false;
    } catch (e) {
      teardownModalViewer();
      mHeroIcon.textContent = '⚠';
      mHeroIcon.style.display = '';
      // Offer to remove a model that won't render (empty / corrupt).
      if (confirm('This model could not be rendered — it may be empty or corrupt.\n\nDelete this folder?')) {
        const ok = await deleteModel(modalCtx.src, modalCtx.folder);
        if (ok) { removeCard(modalCtx.src, modalCtx.folder); closeModal(); }
        else alert('Could not delete the folder.');
      }
    }
  });

  mGenThumb.addEventListener('click', async () => {
    if (!modalViewer || !modalViewer.hasModel()) return;
    mGenThumb.disabled = true;
    const orig = mGenThumb.textContent;
    mGenThumb.textContent = 'Saving…';
    try {
      const png = modalViewer.capturePNG(512);
      const url = await saveThumb(modalCtx.src, modalCtx.folder, png);
      if (!url) {
        // Soft fail — folder not writable (model added via network share).
        mGenThumb.textContent = '✗ Folder read-only';
        setTimeout(() => { mGenThumb.textContent = orig; mGenThumb.disabled = false; }, 2600);
        return;
      }
      applyThumbToCard(modalCtx.src, modalCtx.folder, url);
      mGenThumb.textContent = '✓ Saved';
      setTimeout(() => { mGenThumb.textContent = orig; mGenThumb.disabled = false; }, 1400);
    } catch (e) {
      mGenThumb.textContent = '✗ ' + (e.message || 'Failed');
      setTimeout(() => { mGenThumb.textContent = orig; mGenThumb.disabled = false; }, 2200);
    }
  });

  // ===== C: batch-generate all missing thumbnails ===========================
  const genAllBtn   = document.getElementById('genAllBtn');
  const batchToast  = document.getElementById('batchToast');
  const batchLabel  = document.getElementById('batchLabel');
  const batchFill   = document.getElementById('batchFill');
  const batchCancel = document.getElementById('batchCancel');
  const batchHost   = document.getElementById('batchCanvasHost');

  let batchCancelled = false;
  batchCancel?.addEventListener('click', () => { batchCancelled = true; });

  genAllBtn?.addEventListener('click', async () => {
    // Respect the active source filter: only generate for visible cards.
    // (Filtered-out cards have display:none.)
    const activeSrc = filters
      ? (filters.querySelector('.pill.active')?.dataset.src || '')
      : '';
    const allTargets = [...document.querySelectorAll('.lib-card')].filter(c => {
      if (c.dataset.viewable !== '1' || c.dataset.hasthumb === '1' || !c.dataset.firstfile) return false;
      if (c.dataset.autoable !== '1') return false;   // skip huge meshes (manual only)
      if (activeSrc && c.dataset.src !== activeSrc) return false;  // scope to filter
      return true;
    });

    // Cap each click at 30 models. Bulk-rendering thousands in one go exhausts
    // browser memory and crashes the tab no matter how carefully we dispose —
    // so we slice into small chunks with hard memory recovery between them.
    const targets = allTargets;

    if (targets.length === 0) {
      const msg = activeSrc ? '✓ All ' + activeSrc + ' thumbnails present' : '✓ All thumbnails present';
      genAllBtn.textContent = msg;
      setTimeout(() => { genAllBtn.textContent = '📸 Generate all missing thumbnails'; }, 1800);
      return;
    }

    batchCancelled = false;
    genAllBtn.disabled = true;
    batchToast.hidden = false;

    let done = 0, failed = 0;
    const failures = []; // {src, folder} that couldn't render
    const total = targets.length;

    // Tear the WebGL context fully down between every CHUNK_SIZE models so GPU/JS
    // memory is reclaimed (a single long-lived context lets it creep until the
    // tab dies). After every COOL_AFTER models, pause COOL_MS so the browser can
    // actually run GC before continuing — this is what lets large backlogs run
    // unattended without crashing.
    const CHUNK_SIZE = 15;
    const COOL_AFTER = 30;
    const COOL_MS    = 10000;

    async function renderOne(v, card) {
      const { src, folder, firstfile } = card.dataset;
      const ext = firstfile.split('.').pop().toLowerCase();
      try {
        await loadWithTimeout(v, fileUrl(src, folder, firstfile), ext, 20000);
        await new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r)));
        const png = v.capturePNG(512);
        const url = await saveThumb(src, folder, png);
        applyThumbToCard(src, folder, url);
      } catch (e) {
        failed++;
        failures.push({ src, folder });
      }
      done++;
    }

    let sinceCool = 0;
    for (let i = 0; i < total && !batchCancelled; i += CHUNK_SIZE) {
      const chunk = targets.slice(i, i + CHUNK_SIZE);

      const host = document.createElement('div');
      host.style.cssText = 'width:512px;height:512px;';
      batchHost.appendChild(host);
      const v = createViewer(host, { background: 0x16140f, showGrid: false });

      try {
        for (const card of chunk) {
          if (batchCancelled) break;
          batchLabel.textContent = `Generating ${done + 1} of ${total}…`;
          batchFill.style.width = Math.round((done / total) * 100) + '%';
          await renderOne(v, card);
          await new Promise(r => setTimeout(r, 0)); // yield between models
        }
      } finally {
        v.dispose();
        host.remove();
      }

      sinceCool += chunk.length;
      const moreLeft = (i + CHUNK_SIZE < total) && !batchCancelled;
      if (moreLeft && sinceCool >= COOL_AFTER) {
        // Longer cooldown so memory is fully reclaimed before the next run.
        sinceCool = 0;
        for (let s = COOL_MS / 1000; s > 0 && !batchCancelled; s--) {
          batchLabel.textContent = `Cooling down (${s}s)…  ${done} of ${total} done`;
          await new Promise(r => setTimeout(r, 1000));
        }
      } else if (moreLeft) {
        await new Promise(r => setTimeout(r, 400)); // short breather between chunks
      }
    }

    batchFill.style.width = '100%';
    batchLabel.textContent = batchCancelled
      ? `Stopped — ${done - failed} done, ${failed} failed`
      : `Done — ${done - failed} generated${failed ? ', ' + failed + ' failed' : ''}`;

    setTimeout(() => {
      batchToast.hidden = true;
      genAllBtn.disabled = false;
      batchFill.style.width = '0%';
      genAllBtn.textContent = '📸 Generate all missing thumbnails';
    }, 2400);

    // Offer to clean up models that couldn't render at all.
    if (failures.length > 0) {
      const n = failures.length;
      if (confirm(`${n} model${n === 1 ? '' : 's'} could not be rendered (empty or corrupt).\n\nDelete ${n === 1 ? 'it' : 'them'}?`)) {
        for (const f of failures) {
          if (await deleteModel(f.src, f.folder)) removeCard(f.src, f.folder);
        }
      }
    }
  });
</script>
  <script src="js/theme.js"></script>
</body>
</html>
