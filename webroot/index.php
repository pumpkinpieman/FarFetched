<?php
declare(strict_types=1);

/**
 * index.php — browse + filter + select + queue.
 * Renders real models from Printables (falls back to a clear banner if the
 * token/API isn't ready yet). Selection posts to enqueue.php.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth.php';
require_auth();
require_once __DIR__ . '/PrintablesService.php';
require_once __DIR__ . '/STLFlixService.php';
require_once __DIR__ . '/CrealityCloudService.php';

const CATEGORIES = [
    'all'         => 'All Categories',
    '3d-printers' => '3D Printers',
    'art'         => 'Art & Design',
    'costumes'    => 'Costumes & Accessories',
    'fashion'     => 'Fashion',
    'gadgets'     => 'Gadgets',
    'healthcare'  => 'Healthcare',
    'hobby'       => 'Hobby & Makers',
    'household'   => 'Household',
    'learning'    => 'Learning',
    'seasonal'    => 'Seasonal designs',
    'sports'      => 'Sports & Outdoor',
    'tabletop'    => 'Tabletop Miniatures',
    'toys'        => 'Toys & Games',
    'world-scans' => 'World & Scans',
];

// MakerWorld categories (id => label). Ids drive the category browse filter.
const MW_CATEGORIES = [
    ''     => 'All Models',
    '900'  => '3D Printer',
    '100'  => 'Art',
    '500'  => 'Education',
    '200'  => 'Fashion',
    '300'  => 'Hobby & DIY',
    '400'  => 'Household',
    '600'  => 'Miniatures',
    '1000' => 'Props & Cosplays',
    '700'  => 'Tools',
    '800'  => 'Toys & Games',
    '2000' => 'Generative 3D Model',
];

// Thingiverse categories (slug => label).
const TV_CATEGORIES = [
    ''              => 'All Models',
    '3d-printing'   => '3D Printing',
    'art'           => 'Art',
    'fashion'       => 'Fashion',
    'gadgets'       => 'Gadgets',
    'hobby'         => 'Hobby',
    'household'     => 'Household',
    'learning'      => 'Learning',
    'models'        => 'Models',
    'tools'         => 'Tools',
    'toys-and-games' => 'Toys & Games',
];

// Cults3D categories (slug => label).
const CULTS3D_CATEGORIES = [
    ''                    => 'All Models',
    '3d-printing'         => '3D Printing',
    'art'                 => 'Art',
    'fashion-accessories' => 'Fashion',
    'gadget'              => 'Gadgets',
    'hobby'               => 'Hobby',
    'home'                => 'Household',
    'miniature-figure'    => 'Miniatures',
    'architecture'        => 'Architecture',
    'toy-game'            => 'Toys & Games',
    'tool'                => 'Tools',
    'jewelry'             => 'Jewelry',
];

$active = $_GET['cat'] ?? 'all';
$isRawId = false;
if (!array_key_exists($active, CATEGORIES)) {
    if (ctype_digit((string) $active)) {
        $isRawId = true;          // pasted category number
    } else {
        $active = 'all';
    }
}
$title = $isRawId ? ('Category ' . $active) : CATEGORIES[$active];
$fileType = strtoupper($_GET['type'] ?? 'STL');
if (!in_array($fileType, ['STL', '3MF', 'PACK'], true)) {
    $fileType = 'STL';
}

$source = strtolower($_GET['src'] ?? 'printables');
if (!in_array($source, ['printables', 'makerworld', 'thingiverse', 'cults3d', 'stlflix', 'creality'], true)) {
    $source = 'printables';
}

// MakerWorld category browse state.
$mwCat    = preg_replace('/[^0-9]/', '', (string) ($_GET['mwcat'] ?? '')) ?? '';
$mwBrowse = $source === 'makerworld' && (isset($_GET['mwcat']) || isset($_GET['browse']));
if (!array_key_exists($mwCat, MW_CATEGORIES)) { $mwCat = ''; }

// Thingiverse category browse state.
$tvCat    = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['tvcat'] ?? '')));
$tvBrowse = $source === 'thingiverse' && (isset($_GET['tvcat']) || isset($_GET['browse']));
if (!array_key_exists($tvCat, TV_CATEGORIES)) { $tvCat = ''; }

// Cults3D category browse state.
$cultsCat    = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['cultscat'] ?? '')));
$cultsBrowse = $source === 'cults3d' && (isset($_GET['cultscat']) || isset($_GET['browse']));
if (!array_key_exists($cultsCat, CULTS3D_CATEGORIES)) { $cultsCat = ''; }

$stlflixCategories = ['' => 'All Models'];
$stlCat = preg_replace('/[^0-9]/', '', (string) ($_GET['stlcat'] ?? ''));
$stlBrowse = $source === 'stlflix' && (isset($_GET['stlcat']) || isset($_GET['browse']));
$crealityBrowse = $source === 'creality';
$crealityCat = $source === 'creality' ? trim((string) ($_GET['crealitycat'] ?? '')) : '';
$favSet = favorites_key_set();
$crealityCategories = [];
$crealityCatTree    = [];
if ($source === 'creality' && creality_ready()) {
    $crealityCatTree = (new CrealityCloudService())->categoryTree();
}
if ($source === 'stlflix') {
    $stlflixCategories = (new STLFlixService())->categories();
    if (!array_key_exists($stlCat, $stlflixCategories)) { $stlCat = ''; }
}

if ($source === 'makerworld') {
    $svc           = null;
    $models        = [];
    $initialCursor = null;
    $mwReady       = (string) cfg('makerworld_token') !== '';
    if ($mwBrowse) {
        $banner = null;
    } else {
        $banner = $mwReady
            ? null
            : 'MakerWorld — search & browse work now; add your MakerWorld token in Settings to download.';
    }
} elseif ($source === 'thingiverse') {
    $svc           = null;
    $models        = [];
    $initialCursor = null;
    $tvReady       = (string) cfg('thingiverse_token') !== '';
    $banner        = $tvReady
        ? null
        : 'Thingiverse — add your token in Settings to browse and download.';
} elseif ($source === 'cults3d') {
    $svc           = null;
    $models        = [];
    $initialCursor = null;
    $cultsReady    = (string) cfg('cults3d_username') !== '' && (string) cfg('cults3d_token') !== '';
    $banner        = $cultsReady
        ? null
        : 'Cults3D — add your username and API key in Settings to browse and download.';
} elseif ($source === 'stlflix') {
    $svc           = null;
    $models        = [];
    $initialCursor = null;
    $stlReady      = (string) cfg('stlflix_token') !== '';
    $banner        = $stlReady
        ? null
        : 'STLFlix - add your jwt token in Settings to pull categories and browse models.';
} elseif ($source === 'creality') {
    $svc           = null;
    $initialCursor = null;
    $crealityReady = creality_ready();
    if ($crealityReady) {
        $cc     = new CrealityCloudService();
        $models = $cc->browseCategory($crealityCat, 24, 1);
        $banner = null;
    } else {
        $models = [];
        $banner = 'Creality Cloud — add your token and user ID in Settings to search and download.';
    }
} else {
    $svc    = new PrintablesService();
    $models = $svc->isAuthed() ? $svc->searchModels($active) : [];
    $initialCursor = $svc->lastCursor; // for "Load more"
    $banner = null;
    if (!$svc->isAuthed()) {
        $banner = 'No Printables token yet — add one in Settings to load real models.';
    } elseif ($svc->lastError !== '') {
        $banner = $svc->lastError;
    } elseif ($models === []) {
        $banner = 'No models returned. If you just wired the API, verify the search query against your Network tab.';
    }
}

$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Browse · FarFetched</title>
<link rel="stylesheet" href="css/styles.css?v=20260622a">
</head>



<body>
  <aside>
    <div class="brand"><img src="logo.svg" alt="FarFetched" style="height:1.15em;width:auto;vertical-align:-.2em;margin-right:7px"> FarFetched</div>



    <?php if ($source === 'makerworld'): ?>
    <div class="navlabel">MakerWorld Categories</div>
    <nav id="mwCatNav">
      <?php foreach (MW_CATEGORIES as $cid => $label): $cid = (string) $cid; ?>
        <a href="javascript:void(0)" data-mwcat="<?= e($cid) ?>" data-mwlabel="<?= e($label) ?>"
           class="<?= ($mwBrowse && $cid === $mwCat) ? 'active' : '' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <?php elseif ($source === 'thingiverse'): ?>
    <div class="navlabel">Thingiverse Categories</div>
    <nav id="tvCatNav">
      <?php foreach (TV_CATEGORIES as $cid => $label): $cid = (string) $cid; ?>
        <a href="javascript:void(0)" data-tvcat="<?= e($cid) ?>" data-tvlabel="<?= e($label) ?>"
           class="<?= ($tvBrowse && $cid === $tvCat) ? 'active' : '' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <?php elseif ($source === 'cults3d'): ?>
    <div class="navlabel">Cults3D Categories</div>
    <nav id="cultsCatNav">
      <?php foreach (CULTS3D_CATEGORIES as $cid => $label): $cid = (string) $cid; ?>
        <a href="javascript:void(0)" data-cultscat="<?= e($cid) ?>" data-cultslabel="<?= e($label) ?>"
           class="<?= ($cultsBrowse && $cid === $cultsCat) ? 'active' : '' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <?php elseif ($source === 'stlflix'): ?>
    <div class="navlabel">STLFlix Categories</div>
    <nav id="stlCatNav">
      <?php foreach ($stlflixCategories as $cid => $label): $cid = (string) $cid; ?>
        <a href="javascript:void(0)" data-stlcat="<?= e($cid) ?>" data-stllabel="<?= e($label) ?>"
           class="<?= ($stlBrowse && $cid === $stlCat) ? 'active' : '' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <?php elseif ($source === 'creality'): ?>
    <div class="navlabel">Creality Categories</div>
    <nav id="crealityCatNav" class="creality-accordion">
      <a href="javascript:void(0)" data-crealitycat="" data-crealitylabel="Trending"
         class="cat-trending <?= $crealityCat === '' ? 'active' : '' ?>">Trending</a>
      <?php foreach ($crealityCatTree as $parent):
        $pid = (string) $parent['id'];
        $hasKids = !empty($parent['children']);
        // Is this parent (or one of its children) the active category?
        $childActive = false;
        foreach ($parent['children'] as $ch) { if ((string) $ch['id'] === $crealityCat) { $childActive = true; break; } }
        $parentActive = ($pid === $crealityCat);
        $expanded = $childActive; // auto-open the group containing the active child
      ?>
        <div class="cat-group <?= $expanded ? 'open' : '' ?>">
          <button type="button" class="cat-parent <?= $parentActive ? 'active' : '' ?>"
                  data-crealitycat="<?= e($pid) ?>" data-crealitylabel="<?= e($parent['name']) ?>">
            <span class="cat-parent-name"><?= e($parent['name']) ?></span>
            <?php if ($hasKids): ?><span class="cat-caret" aria-hidden="true">▸</span><?php endif; ?>
          </button>
          <?php if ($hasKids): ?>
          <div class="cat-children">
            <?php foreach ($parent['children'] as $ch): $cid = (string) $ch['id']; ?>
              <a href="javascript:void(0)" data-crealitycat="<?= e($cid) ?>" data-crealitylabel="<?= e($parent['name'].' › '.$ch['name']) ?>"
                 class="cat-child <?= ($cid === $crealityCat) ? 'active' : '' ?>"><?= e($ch['name']) ?></a>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </nav>
    <?php else: ?>
    

    <div class="navlabel">Categories</div>
    <nav id="pbCatNav">
      <?php foreach (CATEGORIES as $slug => $label): ?>
        <a href="javascript:void(0)" data-pbcat="<?= e($slug) ?>" data-pblabel="<?= e($label) ?>"
           class="<?= $slug === $active ? 'active' : '' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <?php endif; ?>
    <div class="navlabel">Tool</div>
    <nav>
      <a href="index.php">Browse Models</a>
      <a href="jobs.php">Queue</a>
      <a href="viewer.php">3D Viewer</a>
      <a href="library.php">My Library</a>
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
    <div class="sticky-header">
    <div class="topbar">
      <h1 id="pageTitle"><?= e($title) ?></h1>
      <div class="actions">
        <div class="srcToggle" role="group" aria-label="Model source">
          <a href="?cat=<?= e($active) ?>&type=<?= e($fileType) ?>" class="srcBtn <?= $source==='printables'?'active':'' ?>">Printables</a>
          <a href="?src=makerworld&browse=all" class="srcBtn <?= $source==='makerworld'?'active':'' ?>">MakerWorld</a>
          <a href="?src=thingiverse&browse=all" class="srcBtn <?= $source==='thingiverse'?'active':'' ?>">Thingiverse</a>
          <a href="?src=cults3d&browse=all" class="srcBtn <?= $source==='cults3d'?'active':'' ?>">Cults3D</a>
          <a href="?src=stlflix&browse=all" class="srcBtn <?= $source==='stlflix'?'active':'' ?>">STLFlix</a>
          <a href="?src=creality" class="srcBtn <?= $source==='creality'?'active':'' ?>">Creality</a>
        </div>
        <?php if ($source === 'printables'): ?>
        <select id="fileType" onchange="location.href='?cat=<?= e($active) ?>&type='+this.value">
          <option value="STL" <?= $fileType==='STL'?'selected':'' ?>>STL</option>
          <option value="3MF" <?= $fileType==='3MF'?'selected':'' ?>>3MF</option>
          <option value="PACK" <?= $fileType==='PACK'?'selected':'' ?>>Whole model (ZIP)</option>
        </select>
        <?php else: ?>
        
        <?php endif; ?>
        <span class="selcount" id="selcount">0 selected</span>
        <button class="btn-ghost" id="rouletteBtn" title="Surprise me — 5 random models from this source">🎲 Random</button>
        <button class="btn-ghost" id="selectAll">Select all on page</button>
        <button class="btn-primary" id="download" disabled>Download Selected</button>
      </div>
    </div>

    <div class="searchbar">
      <input type="search" id="searchInput" placeholder="<?= match($source) {
        'makerworld'  => 'Search all of MakerWorld — e.g. airless ball, gridfinity, phone stand…',
        'thingiverse' => 'Search all of Thingiverse — e.g. cable clip, vase, articulated dragon…',
        'cults3d'     => 'Search all of Cults3D — e.g. miniature, keychain, lamp…',
        'stlflix'     => 'Search STLFlix — e.g. vista vase, dragon egg, wall light…',
        'creality'    => 'Search Creality Cloud — e.g. dice tower, bracket, dragon…',
        default       => 'Search all of Printables — e.g. belt sander, toothpick, sanding block…',
      } ?>" autocomplete="off">
      <button class="btn-primary" id="searchGo">Search</button>
      <button class="btn-ghost" id="searchClear" style="display:none;">Clear</button>
      <?php if ($source === 'makerworld'): ?>
      <label class="nsfwToggle" title="MakerWorld hosts adult content; off by default"><input type="checkbox" id="nsfwToggle"> Show NSFW</label>
      <?php endif; ?>
    </div>
    </div><!-- /.sticky-header -->

    <?php if ($banner): ?><div class="banner"><?= e($banner) ?></div><?php endif; ?>

    
    <div class="grid" id="grid">
      <?php foreach ($models as $m): ?>
        <div class="card"
             data-id="<?= e($m['id']) ?>" data-slug="<?= e($m['slug']) ?>"
             data-name="<?= e($m['name']) ?>" data-creator="<?= e($m['creator']) ?>">
          <input type="checkbox" class="pick" aria-label="Select model">
          <?php $isFav = isset($favSet[$source . ':' . $m['id']]); ?>
          <button type="button" class="fav-star <?= $isFav ? 'on' : '' ?>"
                  data-fav-source="<?= e($source) ?>" data-fav-id="<?= e($m['id']) ?>"
                  data-fav-slug="<?= e($m['slug']) ?>" data-fav-name="<?= e($m['name']) ?>"
                  data-fav-creator="<?= e($m['creator']) ?>" data-fav-thumb="<?= e($m['thumb']) ?>"
                  data-fav-price="<?= (int) ($m['price'] ?? 0) ?>"
                  aria-label="Favorite" title="Save to Favorites"><?= $isFav ? '★' : '☆' ?></button>
          <?php
            if (!empty($m['club'])) {
                echo '<span class="badge club">Club</span>';
            } elseif (!empty($m['price']) && (int) $m['price'] > 0) {
                echo '<span class="badge paid">Payment Required</span>';
            }
          ?>
          <div class="thumb">
            <?php
              $thumbUrl = (string) $m['thumb'];
              if ($thumbUrl !== '' && in_array($source, ['cults3d', 'thingiverse'], true)) {
                  $thumbUrl = 'proxy.php?url=' . urlencode($thumbUrl);
              }
            ?>
            <?php if ($thumbUrl !== ''): ?><img src="<?= e($thumbUrl) ?>" alt="" loading="lazy"><?php else: ?><span>no preview</span><?php endif; ?>
          </div>
          <div class="meta">
            <div class="mname"><?= e($m['name']) ?></div>
            <?php if (($m['creator'] ?? '') !== ''): ?><div class="mcreator">by <?= e($m['creator']) ?></div><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div style="text-align:center;margin:28px 0;">
      <button class="btn-ghost" id="loadMore" style="display:none;">Load more</button>
      <div id="loadStatus" style="font-size:13px;color:var(--muted);margin-top:8px;"></div>
    </div>
    <div id="scrollSentinel" style="height:1px;"></div>
  </main>

<script>
  const CSRF = <?= json_encode($csrf) ?>;
  const FILE_TYPE = <?= json_encode($fileType) ?>;
  const SOURCE = <?= json_encode($source) ?>;
  const ACTIVE_CAT = <?= json_encode($active) ?>;

  // ---- Toast notification (bottom-right) ------------------------------------
  let _toastTimer = null, _toastExit = null;
  function showToast(message, opts) {
    opts = opts || {};
    let t = document.getElementById('ff-toast');
    if (!t) {
      t = document.createElement('div');
      t.id = 'ff-toast';
      document.body.appendChild(t);
    }
    const link = opts.link
      ? '<a href="jobs.php" class="ff-toast-link">View queue →</a>'
      : '';
    t.innerHTML = '<span class="ff-toast-msg"></span>' + link;
    t.querySelector('.ff-toast-msg').textContent = message;

    // Reset any in-flight animation.
    if (_toastTimer) clearTimeout(_toastTimer);
    if (_toastExit)  clearTimeout(_toastExit);
    t.classList.remove('hide', 'show');

    // Enter: next frame so the transition fires from the hidden state.
    requestAnimationFrame(() => {
      requestAnimationFrame(() => t.classList.add('show'));
    });

    // Hold ~3s, then exit: slide down + fade.
    const hold = opts.duration || 3000;
    _toastTimer = setTimeout(() => {
      t.classList.remove('show');
      t.classList.add('hide');
      // Clean up classes once the exit transition finishes.
      _toastExit = setTimeout(() => t.classList.remove('hide'), 500);
    }, hold);
  }

  // ---- Favorite star toggle (delegated for both PHP- and JS-rendered tiles) --
  document.addEventListener('click', async e => {
    const star = e.target.closest('.fav-star');
    if (!star) return;
    e.preventDefault();
    e.stopPropagation();
    const payload = {
      action:  'toggle',
      source:  star.dataset.favSource,
      model_id: decodeURIComponent(star.dataset.favId || ''),
      slug:    star.dataset.favSlug || '',
      name:    star.dataset.favName || '',
      creator: star.dataset.favCreator || '',
      thumb:   star.dataset.favThumb || '',
      price:   parseInt(star.dataset.favPrice || '0', 10),
    };
    star.disabled = true;
    try {
      const res = await fetch('favorite.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (data.ok) {
        star.classList.toggle('on', data.favorited);
        star.textContent = data.favorited ? '★' : '☆';
        if (typeof favSet !== 'undefined') {
          const k = payload.source + ':' + payload.model_id;
          if (data.favorited) favSet.add(k); else favSet.delete(k);
        }
        if (typeof showToast === 'function') {
          showToast(data.favorited ? 'Added to Favorites' : 'Removed from Favorites', {});
        }
      }
    } catch (_) { /* ignore network blip */ }
    star.disabled = false;
  });
  const nsfwEl = document.getElementById('nsfwToggle');
  const showNsfw = () => (nsfwEl && nsfwEl.checked) ? '1' : '0';

  // Instant visual switch on the source toggle (the link then reloads the page,
  // which re-renders the authoritative active state server-side).
  document.querySelectorAll('.srcBtn').forEach(b => b.addEventListener('click', function () {
    document.querySelectorAll('.srcBtn').forEach(x => x.classList.remove('active'));
    this.classList.add('active');
  }));
  let nextCursor = <?= json_encode($initialCursor) ?>;
  const grid = document.getElementById('grid');
  const countEl = document.getElementById('selcount');
  const dlBtn = document.getElementById('download');
  const selAllBtn = document.getElementById('selectAll');

  // ---- 🎲 Roulette: 5 random models from the current source ----------------
  const rouletteBtn = document.getElementById('rouletteBtn');
  if (rouletteBtn) {
    rouletteBtn.addEventListener('click', async () => {
      const orig = rouletteBtn.textContent;
      rouletteBtn.disabled = true;
      rouletteBtn.textContent = '🎲 Rolling…';
      try {
        const res = await fetch('roulette.php?src=' + encodeURIComponent(SOURCE), { cache: 'no-store' });
        const data = await res.json();
        if (!data.ok) {
          rouletteBtn.textContent = '🎲 ' + (data.error || 'No luck — try again');
          setTimeout(() => { rouletteBtn.textContent = orig; rouletteBtn.disabled = false; }, 2600);
          return;
        }
        grid.innerHTML = '';
        for (const m of data.models) grid.appendChild(makeCard(m));
        const pt = document.getElementById('pageTitle');
        if (pt) pt.textContent = '🎲 Random picks';

        // Stop infinite scroll from appending the normal feed under the picks:
        // clear pagination cursors, disconnect the observer, hide the sentinel
        // and the manual "Load more" button.
        nextCursor = null;
        searchNext = null;
        if (typeof observer !== 'undefined' && observer) observer.disconnect();
        const sent = document.getElementById('scrollSentinel');
        if (sent) sent.style.display = 'none';
        const lm = document.getElementById('loadMore');
        if (lm) lm.style.display = 'none';

        window.scrollTo({ top: 0, behavior: 'smooth' });
        rouletteBtn.textContent = '🎲 Roll again';
      } catch (_) {
        rouletteBtn.textContent = '🎲 Error — try again';
        setTimeout(() => { rouletteBtn.textContent = orig; }, 2600);
      } finally {
        rouletteBtn.disabled = false;
      }
    });
  }
  const loadMoreBtn = document.getElementById('loadMore');
  const loadStatus = document.getElementById('loadStatus');

  // ---- Cross-category persistent selection store -----------------------------
  // Keyed by model id (string). Value = {id, slug, name, creator}.
  // Survives grid resets when browsing/searching between categories.
  // Persist selection across source switches (page reloads) via sessionStorage.
  const SEL_KEY = 'ff_selStore';
  function _selLoad() {
    try { const raw = sessionStorage.getItem(SEL_KEY); return raw ? new Map(JSON.parse(raw)) : new Map(); }
    catch (_) { return new Map(); }
  }
  function _selSave() {
    try { sessionStorage.setItem(SEL_KEY, JSON.stringify([...selStore.entries()])); } catch (_) {}
  }
  const selStore = _selLoad();
  function selSet(id, data) { selStore.set(String(id), data); _selSave(); }
  function selDel(id)       { selStore.delete(String(id)); _selSave(); }
  function selHas(id)       { return selStore.has(String(id)); }

  function refresh(){
    const n = selStore.size;
    if (countEl) countEl.textContent = n + ' selected';
    if (dlBtn) dlBtn.disabled = n === 0;
    // Re-sync visible card highlight to store (handles grid repaints).
    if (grid) {
      grid.querySelectorAll('.card').forEach(c => {
        const inStore = selHas(c.dataset.id);
        c.classList.toggle('sel', inStore);
        const box = c.querySelector('.pick');
        if (box) box.checked = inStore;
      });
    }
  }

  // Proxy CDN images that block cross-origin requests.
  const PROXY_SOURCES = ['cults3d', 'thingiverse'];
  function thumbSrc(url) {
    if (!url) return '';
    if (PROXY_SOURCES.includes(SOURCE)) return 'proxy.php?url=' + encodeURIComponent(url);
    return encodeURI(url);
  }

  // Build a card DOM node from a model object (same markup as the PHP render).
  function makeCard(m){
    const card = document.createElement('div');
    card.className = 'card';
    card.dataset.id = m.id; card.dataset.slug = m.slug;
    card.dataset.name = m.name; card.dataset.creator = m.creator;
    // Restore selection highlight if already in the store.
    if (selHas(m.id)) card.classList.add('sel');

    // Gallery: prefer images[]; fall back to the single thumb. Slider arrows show
    // on hover only when there's more than one image.
    const imgs = (Array.isArray(m.images) && m.images.length)
      ? m.images
      : (m.thumb ? [m.thumb] : []);
    const multi = imgs.length > 1;

    const thumb = imgs.length
      ? '<img class="thumb-img" src="'+thumbSrc(imgs[0])+'" alt="" loading="lazy">'
      : '<span>no preview</span>';

    // Badge paid/club models so you know which may not be fetchable.
    let badge = '';
    if (m.club) badge = '<span class="badge club">Club</span>';
    else if (m.price > 0) badge = '<span class="badge paid">Payment Required</span>';

    // textContent-safe insertion for name/creator
    const favKey = SOURCE + ':' + m.id;
    const isFav = favSet.has(favKey);
    const starBtn = '<button type="button" class="fav-star' + (isFav ? ' on' : '') + '"'
      + ' data-fav-source="' + SOURCE + '" data-fav-id="' + encodeURIComponent(m.id) + '"'
      + ' aria-label="Favorite" title="Save to Favorites">' + (isFav ? '★' : '☆') + '</button>';
    card.innerHTML =
      '<input type="checkbox" class="pick" aria-label="Select model"' + (selHas(m.id) ? ' checked' : '') + '>' +
      starBtn +
      '<div class="thumb" style="position:relative;">'+thumb+'</div>' + badge +
      '<div class="meta"><div class="mname"></div><div class="mcreator"></div></div>';
    // Stash the rest of the model data on the star for the toggle handler.
    const sb = card.querySelector('.fav-star');
    if (sb) {
      sb.dataset.favSlug = m.slug || '';
      sb.dataset.favName = m.name || '';
      sb.dataset.favCreator = m.creator || '';
      sb.dataset.favThumb = m.thumb || '';
      sb.dataset.favPrice = String(m.price || 0);
    }
    card.querySelector('.mname').textContent = m.name;
    if (m.creator && m.creator !== '') {
      card.querySelector('.mcreator').textContent = 'by ' + m.creator;
    } else {
      const mc = card.querySelector('.mcreator');
      if (mc) mc.remove();
    }

    if (multi) attachSlider(card, imgs, m.id);
    else if ((SOURCE === 'printables' || SOURCE === 'thingiverse') && m.id) attachSlider(card, imgs, m.id);
    return card;
  }

  function attachSlider(card, imgs, modelId){
    const wrap = card.querySelector('.thumb');
    const img  = card.querySelector('.thumb-img');
    if (!wrap || !img) return;
    let idx = 0, preloaded = false, galleryFetched = false;

    const arrowCss =
      'position:absolute;top:50%;transform:translateY(-50%);width:28px;height:28px;' +
      'border:none;border-radius:50%;background:rgba(0,0,0,.45);color:#fff;font-size:16px;' +
      'line-height:1;cursor:pointer;display:none;z-index:2;padding:0;';
    const prev = document.createElement('button');
    const next = document.createElement('button');
    prev.type = next.type = 'button';
    prev.setAttribute('aria-label','Previous image');
    next.setAttribute('aria-label','Next image');
    prev.textContent = '\u2039'; next.textContent = '\u203a';
    prev.style.cssText = arrowCss + 'left:6px;';
    next.style.cssText = arrowCss + 'right:6px;';

    function show(i){
      idx = (i + imgs.length) % imgs.length;
      img.src = thumbSrc(imgs[idx]);
    }
    function nav(delta, ev){
      ev.preventDefault(); ev.stopPropagation();
      show(idx + delta);
    }
    prev.addEventListener('click', (e)=>nav(-1, e));
    next.addEventListener('click', (e)=>nav( 1, e));

    // For Printables/Thingiverse cards: lazy-fetch the full gallery on first hover.
    async function fetchGallery() {
      if (galleryFetched || !modelId) return;
      if (SOURCE !== 'printables' && SOURCE !== 'thingiverse') return;
      galleryFetched = true;
      try {
        const endpoint = SOURCE === 'thingiverse'
          ? 'thing_images.php?id=' + encodeURIComponent(modelId)
          : 'print_images.php?id=' + encodeURIComponent(modelId);
        const res  = await fetch(endpoint);
        const urls = await res.json();
        if (Array.isArray(urls) && urls.length > 1) {
          // Filter out blank/broken images before showing slider
          const valid = await Promise.all(urls.map(u => new Promise(resolve => {
            const t = new Image();
            t.onload  = () => resolve(t.naturalWidth > 10 ? u : null);
            t.onerror = () => resolve(null);
            t.src = thumbSrc(u);
          })));
          const good = valid.filter(Boolean);
          if (good.length > 1) {
            imgs.length = 0;
            good.forEach(u => imgs.push(u));
            prev.style.display = next.style.display = 'block';
          }
          // If only 1 valid image, leave as-is (no arrows)
        }
      } catch(e) { /* fail silently */ }
    }

    card.addEventListener('mouseenter', async ()=>{
      await fetchGallery();
      if (imgs.length > 1) prev.style.display = next.style.display = 'block';
      if (!preloaded){
        preloaded = true;
        for (let i = 1; i < imgs.length; i++){ const p = new Image(); p.src = thumbSrc(imgs[i]); }
      }
    });
    card.addEventListener('mouseleave', ()=>{
      prev.style.display = next.style.display = 'none';
    });

    wrap.appendChild(prev);
    wrap.appendChild(next);
  }

  // Paging state. mode 'browse' uses the opaque cursor; mode 'search' uses a
  // numeric offset (searchPrints2). Same infinite-scroll mechanism for both.
  let loading = false;
  let mode = 'browse';
  let searchQuery = '';
  let searchNext = null;   // next offset to fetch, or null when exhausted
  const MW_CAT = <?= json_encode($mwCat) ?>;
  const MW_BROWSE = <?= json_encode($mwBrowse) ?>;
  const TV_CAT    = <?= json_encode($tvCat) ?>;
  const TV_BROWSE = <?= json_encode($tvBrowse) ?>;
  const CULTS_CAT    = <?= json_encode($cultsCat) ?>;
  const CREALITY_CAT = <?= json_encode($crealityCat ?? '') ?>;
  const FAV_SET = <?= json_encode(array_keys($favSet)) ?>;
  const favSet = new Set(FAV_SET);
  const CULTS_BROWSE = <?= json_encode($cultsBrowse) ?>;
  const STL_CAT    = <?= json_encode($stlCat) ?>;
  const STL_BROWSE = <?= json_encode($stlBrowse) ?>;
  let mwCatActive = '';
  let pbCatActive = ACTIVE_CAT;

  function hasMore() { return mode === 'search' ? (searchNext !== null) : (nextCursor !== null); }

  async function loadMore() {
    if (loading || !hasMore()) return;
    loading = true;
    if (loadMoreBtn) loadMoreBtn.disabled = true;
    loadStatus.textContent = 'Loading…';

    const url = (mode === 'search')
      ? 'search_more.php?q=' + encodeURIComponent(searchQuery) +
        '&offset=' + encodeURIComponent(searchNext) + '&paid=all' +
        '&src=' + encodeURIComponent(SOURCE) + '&nsfw=' + showNsfw() +
        (SOURCE === 'makerworld' && mwCatActive ? '&mwcat=' + encodeURIComponent(mwCatActive) : '') +
        (SOURCE === 'makerworld' && searchQuery === '' ? '&browse=1' : '') +
        (SOURCE === 'thingiverse' && (window._tvCatActive ?? TV_CAT) !== '' ? '&tvcat=' + encodeURIComponent(window._tvCatActive ?? TV_CAT) : '') +
        (SOURCE === 'thingiverse' && searchQuery === '' ? '&browse=1' : '') +
        (SOURCE === 'cults3d' && (window._cultsCatActive ?? CULTS_CAT) !== '' ? '&cultscat=' + encodeURIComponent(window._cultsCatActive ?? CULTS_CAT) : '') +
        (SOURCE === 'cults3d' && searchQuery === '' ? '&browse=1' : '') +
        (SOURCE === 'creality' && (window._crealityCatActive ?? CREALITY_CAT) !== '' ? '&crealitycat=' + encodeURIComponent(window._crealityCatActive ?? CREALITY_CAT) : '') +
        (SOURCE === 'stlflix' && (window._stlCatActive ?? STL_CAT) !== '' ? '&stlcat=' + encodeURIComponent(window._stlCatActive ?? STL_CAT) : '') +
        (SOURCE === 'stlflix' && searchQuery === '' ? '&browse=1' : '')
      : 'browse_more.php?cat=' + encodeURIComponent(pbCatActive) +
        '&cursor=' + encodeURIComponent(nextCursor || '');

    let data = null;
    try {
      const res = await fetch(url);
      const ct  = res.headers.get('content-type') || '';
      if (!ct.includes('json')) {
        throw new Error('Non-JSON response (HTTP ' + res.status + ')');
      }
      data = await res.json();
    } catch (err) {
      searchNext = null; nextCursor = null;
      loadStatus.textContent = 'Error: ' + err.message + ' — check Activity log in Settings.';
      if (loadMoreBtn) loadMoreBtn.disabled = false;
      loading = false;
      return;
    }

    if (!data || !data.ok) {
      searchNext = null; nextCursor = null;
      loadStatus.textContent = 'Error: ' + (data?.error || 'unknown');
      if (loadMoreBtn) loadMoreBtn.disabled = false;
      loading = false;
      return;
    }

    for (const m of data.models) grid.appendChild(makeCard(m));

    if (mode === 'search') {
      searchNext = data.nextOffset ?? null;
    } else {
      nextCursor = (data.cursor && data.models.length) ? data.cursor : null;
    }

    if (hasMore()) {
      if (loadMoreBtn) loadMoreBtn.disabled = false;
      loadStatus.textContent = '';
    } else {
      if (loadMoreBtn) loadMoreBtn.style.display = 'none';
      loadStatus.textContent = (mode === 'search')
        ? ('That\u2019s all ' + (data.total || grid.querySelectorAll('.card').length) + ' results.')
        : 'No more models in this category.';
    }

    loading = false;

    if (hasMore() && sentinelInView() && data.models.length > 0) {
      requestAnimationFrame(loadMore);
    }
  }

  function sentinelInView() {
    const s = document.getElementById('scrollSentinel');
    if (!s) return false;
    const r = s.getBoundingClientRect();
    // Within the viewport plus the same 600px prefetch margin the observer uses.
    return r.top <= (window.innerHeight || document.documentElement.clientHeight) + 600;
  }

  // Kick off a keyword search (or clear back to category browse if empty).
  const searchInput = document.getElementById('searchInput');
  const searchGo = document.getElementById('searchGo');
  const searchClear = document.getElementById('searchClear');
  const pageTitle = document.getElementById('pageTitle');

  async function runSearch() {
    const q = (searchInput.value || '').trim();
    if (!q) { clearSearch(); return; }
    mwCatActive = '';            // a keyword search clears any category filter
    mode = 'search';
    searchQuery = q;
    searchNext = 0;
    nextCursor = null;
    grid.innerHTML = '';
    if (loadMoreBtn) loadMoreBtn.style.display = 'none';
    if (pageTitle) pageTitle.textContent = 'Search: ' + q;
    if (searchClear) searchClear.style.display = 'inline-block';
    refresh();
    await loadMore(); // fetches offset 0
  }

  // MakerWorld category browse. All Models (no id) = popular browse (empty
  // keyword). A category = keyword search of its term (reliable + differentiated).
  // MakerWorld category browse: true taxonomy filter via the category id
  // (passed as mwcat → ?categories={id}). All Models (no id) = popular browse.
  async function browseCategory(catId, label) {
    mwCatActive = catId || '';
    mode = 'search';             // reuses the offset-paged search pipeline
    searchQuery = '';
    searchNext = 0;
    nextCursor = null;
    grid.innerHTML = '';
    if (loadMoreBtn) loadMoreBtn.style.display = 'none';
    if (pageTitle) pageTitle.textContent = label || 'All Models';
    if (searchClear) searchClear.style.display = 'inline-block';
    refresh();
    await loadMore();
  }
  function clearSearch() {
    if (SOURCE === 'makerworld')  { location.href = '?src=makerworld&browse=all';  return; }
    if (SOURCE === 'thingiverse') { location.href = '?src=thingiverse&browse=all'; return; }
    if (SOURCE === 'cults3d')     { location.href = '?src=cults3d&browse=all';     return; }
    if (SOURCE === 'stlflix')     { location.href = '?src=stlflix&browse=all';     return; }
    location.href = '?cat=' + encodeURIComponent(pbCatActive) + '&type=' + encodeURIComponent(FILE_TYPE);
  }
  if (searchGo) searchGo.addEventListener('click', runSearch);
  if (searchClear) searchClear.addEventListener('click', clearSearch);
  if (searchInput) searchInput.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); runSearch(); } });
  // Re-run the current search when the NSFW filter is toggled.
  if (nsfwEl) nsfwEl.addEventListener('change', () => { if (searchQuery) runSearch(); });

  // Infinite scroll: auto-load when the sentinel near the page bottom appears.
  const sentinel = document.getElementById('scrollSentinel');
  let observer = null;
  if ('IntersectionObserver' in window && sentinel) {
    observer = new IntersectionObserver((entries) => {
      if (entries.some(e => e.isIntersecting)) loadMore();
    }, { rootMargin: '600px 0px' }); // prefetch before the user hits the very bottom
    observer.observe(sentinel);
  } else if (loadMoreBtn && nextCursor !== null) {
    // Fallback for old browsers: show the manual button.
    loadMoreBtn.style.display = 'inline-block';
  }
  // Button still works as a manual fallback if present.
  if (loadMoreBtn) loadMoreBtn.addEventListener('click', loadMore);

  // MakerWorld: if the page loaded via a category link, browse it immediately.
  if (SOURCE === 'makerworld' && MW_BROWSE) {
    const lbl = (document.querySelector('aside nav a.active') || {}).textContent || 'All Models';
    browseCategory(MW_CAT, lbl);
  }
  if (SOURCE === 'thingiverse' && TV_BROWSE) {
    window._tvCatActive = TV_CAT;
    const lbl = (document.querySelector('#tvCatNav a.active') || {}).textContent || 'All Models';
    browseTVCategory(TV_CAT, lbl);
  }

  if (SOURCE === 'cults3d' && CULTS_BROWSE) {
    window._cultsCatActive = CULTS_CAT;
    const lbl = (document.querySelector('#cultsCatNav a.active') || {}).textContent || 'All Models';
    browseCultsCategory(CULTS_CAT, lbl);
  }

  if (SOURCE === 'creality') {
    // Server already rendered the first page; just remember the active category
    // so "Load more" and subsequent calls keep the right filter.
    window._crealityCatActive = CREALITY_CAT || '';
    mode = 'search';
  }

  if (SOURCE === 'stlflix' && STL_BROWSE) {
    window._stlCatActive = STL_CAT;
    const lbl = (document.querySelector('#stlCatNav a.active') || {}).textContent || 'All Models';
    browseSTLFlixCategory(STL_CAT, lbl);
  }

  // MW category nav
  const mwCatNav = document.getElementById('mwCatNav');
  if (mwCatNav) {
    mwCatNav.addEventListener('click', e => {
      const link = e.target.closest('a[data-mwlabel]');
      if (!link) return;
      e.preventDefault();
      mwCatNav.querySelectorAll('a').forEach(a => a.classList.remove('active'));
      link.classList.add('active');
      browseCategory(link.dataset.mwcat, link.dataset.mwlabel);
    });
  }

  // Thingiverse category nav
  const tvCatNav = document.getElementById('tvCatNav');
  if (tvCatNav) {
    tvCatNav.addEventListener('click', e => {
      const link = e.target.closest('a[data-tvlabel]');
      if (!link) return;
      e.preventDefault();
      tvCatNav.querySelectorAll('a').forEach(a => a.classList.remove('active'));
      link.classList.add('active');
      browseTVCategory(link.dataset.tvcat, link.dataset.tvlabel);
    });
  }

  async function browseTVCategory(catId, label) {
    mode = 'search'; searchQuery = ''; searchNext = 0; nextCursor = null;
    mwCatActive = '';
    grid.innerHTML = '';
    if (loadMoreBtn) loadMoreBtn.style.display = 'none';
    if (pageTitle) pageTitle.textContent = label || 'Thingiverse';
    if (searchClear) searchClear.style.display = 'none';
    refresh();
    loading = false;
    window._tvCatActive = catId;
    await loadMore();
  }

  // Cults3D category nav
  const cultsCatNav = document.getElementById('cultsCatNav');
  if (cultsCatNav) {
    cultsCatNav.addEventListener('click', e => {
      const link = e.target.closest('a[data-cultslabel]');
      if (!link) return;
      e.preventDefault();
      cultsCatNav.querySelectorAll('a').forEach(a => a.classList.remove('active'));
      link.classList.add('active');
      browseCultsCategory(link.dataset.cultscat, link.dataset.cultslabel);
    });
  }

  async function browseCultsCategory(catId, label) {
    mode = 'search'; searchQuery = ''; searchNext = 0; nextCursor = null;
    mwCatActive = '';
    grid.innerHTML = '';
    if (loadMoreBtn) loadMoreBtn.style.display = 'none';
    if (pageTitle) pageTitle.textContent = label || 'Cults3D';
    if (searchClear) searchClear.style.display = 'none';
    refresh();
    loading = false;
    window._cultsCatActive = catId;
    await loadMore();
  }

  // Creality category nav
  const crealityCatNav = document.getElementById('crealityCatNav');
  if (crealityCatNav) {
    function setCrealityActive(el) {
      crealityCatNav.querySelectorAll('.active').forEach(a => a.classList.remove('active'));
      if (el) el.classList.add('active');
    }
    crealityCatNav.addEventListener('click', e => {
      const parentBtn = e.target.closest('.cat-parent');
      const childLink = e.target.closest('.cat-child');
      const trending  = e.target.closest('.cat-trending');

      if (parentBtn) {
        e.preventDefault();
        const grp = parentBtn.closest('.cat-group');
        const wasOpen = grp && grp.classList.contains('open');
        // Clicking the parent toggles its group open/closed.
        if (grp) grp.classList.toggle('open');
        // Only browse the category when opening it (not when collapsing).
        if (!wasOpen) {
          setCrealityActive(parentBtn);
          browseCrealityCategory(parentBtn.dataset.crealitycat, parentBtn.dataset.crealitylabel);
        }
        return;
      }
      if (childLink) {
        e.preventDefault();
        setCrealityActive(childLink);
        browseCrealityCategory(childLink.dataset.crealitycat, childLink.dataset.crealitylabel);
        return;
      }
      if (trending) {
        e.preventDefault();
        setCrealityActive(trending);
        browseCrealityCategory('', 'Trending');
        return;
      }
    });
  }

  async function browseCrealityCategory(catId, label) {
    mode = 'search'; searchQuery = ''; searchNext = 0; nextCursor = null;
    grid.innerHTML = '';
    if (loadMoreBtn) loadMoreBtn.style.display = 'none';
    if (pageTitle) pageTitle.textContent = label || 'Creality Cloud';
    if (searchClear) searchClear.style.display = 'none';
    refresh();
    loading = false;
    window._crealityCatActive = catId || '';
    await loadMore();
  }

  // STLFlix category nav
  const stlCatNav = document.getElementById('stlCatNav');
  if (stlCatNav) {
    stlCatNav.addEventListener('click', e => {
      const link = e.target.closest('a[data-stllabel]');
      if (!link) return;
      e.preventDefault();
      stlCatNav.querySelectorAll('a').forEach(a => a.classList.remove('active'));
      link.classList.add('active');
      browseSTLFlixCategory(link.dataset.stlcat, link.dataset.stllabel);
    });
  }

  async function browseSTLFlixCategory(catId, label) {
    mode = 'search'; searchQuery = ''; searchNext = 0; nextCursor = null;
    mwCatActive = '';
    grid.innerHTML = '';
    if (loadMoreBtn) loadMoreBtn.style.display = 'none';
    if (pageTitle) pageTitle.textContent = label || 'STLFlix';
    if (searchClear) searchClear.style.display = 'none';
    refresh();
    loading = false;
    window._stlCatActive = catId;
    await loadMore();
  }

  // Printables category nav — same pattern: JS browse, no page reload.
  async function browsePrintablesCategory(catSlug, label) {
    pbCatActive = catSlug;
    mode = 'browse';
    searchQuery = '';
    searchNext = null;
    nextCursor = '';  // '' = first page pending (null = exhausted)
    mwCatActive = '';
    grid.innerHTML = '';
    if (loadMoreBtn) loadMoreBtn.style.display = 'none';
    if (pageTitle) pageTitle.textContent = label || 'All Models';
    if (searchClear) searchClear.style.display = 'none';
    refresh();
    loading = false;
    await loadMore();
  }

  const pbCatNav = document.getElementById('pbCatNav');
  if (pbCatNav) {
    pbCatNav.addEventListener('click', e => {
      const link = e.target.closest('a[data-pbcat]');
      if (!link) return;
      e.preventDefault();
      pbCatNav.querySelectorAll('a').forEach(a => a.classList.remove('active'));
      link.classList.add('active');
      browsePrintablesCategory(link.dataset.pbcat, link.dataset.pblabel);
    });
  }

  if (grid) grid.addEventListener('change', e => {
    if (!e.target.classList.contains('pick')) return;
    const card = e.target.closest('.card');
    if (e.target.checked) selSet(card.dataset.id, {id:card.dataset.id,slug:card.dataset.slug,name:card.dataset.name,creator:card.dataset.creator});
    else selDel(card.dataset.id);
    card.classList.toggle('sel', e.target.checked);
    refresh();
  });

  // Click anywhere on a card (image, title, blank space) to toggle selection.
  if (grid) grid.addEventListener('click', e => {
    // Let the checkbox and the favorite star handle their own clicks.
    if (e.target.classList.contains('pick')) return;
    if (e.target.closest('.fav-star')) return;
    const card = e.target.closest('.card');
    if (!card) return;
    const box = card.querySelector('.pick');
    box.checked = !box.checked;
    if (box.checked) selSet(card.dataset.id, {id:card.dataset.id,slug:card.dataset.slug,name:card.dataset.name,creator:card.dataset.creator});
    else selDel(card.dataset.id);
    card.classList.toggle('sel', box.checked);
    refresh();
  });
  if (selAllBtn) selAllBtn.addEventListener('click', () => {
    const cards = grid ? [...grid.querySelectorAll('.card')] : [];
    const on = cards.some(c => !selHas(c.dataset.id));
    cards.forEach(c => {
      if (on) selSet(c.dataset.id, {id:c.dataset.id,slug:c.dataset.slug,name:c.dataset.name,creator:c.dataset.creator});
      else selDel(c.dataset.id);
      c.classList.toggle('sel', on);
      const box = c.querySelector('.pick');
      if (box) box.checked = on;
    });
    refresh();
  });
  if (dlBtn) dlBtn.addEventListener('click', async () => {
    const models = [...selStore.values()];
    if (!models.length) return;
    dlBtn.disabled = true; dlBtn.textContent = 'Queuing…';
    try {
      const res = await fetch('enqueue.php', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ csrf: CSRF, fileType: FILE_TYPE, source: SOURCE, models })
      });
      const data = await res.json();
      if (data.ok) {
        const n = data.queued || 0;
        const skipped = data.skipped || 0;
        selStore.clear(); _selSave();
        // Clear any selected checkboxes / selection UI without leaving the page.
        document.querySelectorAll('.pick:checked').forEach(c => { c.checked = false; });
        document.querySelectorAll('.card.sel').forEach(c => c.classList.remove('sel'));
        if (typeof refresh === 'function') refresh();
        let msg;
        if (n > 0) {
          msg = n + (n === 1 ? ' model' : ' models') + ' added to the queue'
              + (skipped ? ' (' + skipped + ' already queued)' : '');
        } else {
          msg = skipped ? 'Already in the queue' : 'Nothing new to add';
        }
        showToast(msg, { link: true });
        dlBtn.disabled = false; dlBtn.textContent = 'Download Selected';
      } else {
        alert('Queue failed: ' + (data.error || 'unknown'));
        dlBtn.disabled = false; dlBtn.textContent = 'Download Selected';
      }
    } catch (err) {
      alert('Network error: ' + err.message);
      dlBtn.disabled = false; dlBtn.textContent = 'Download Selected';
    }
  });

  // ---- No-token paste-ID/URL → queue a PACK job -----------------------------
  const pasteInput = document.getElementById('pasteId');
  const pasteGo = document.getElementById('pasteGo');
  const pasteStatus = document.getElementById('pasteStatus');

  function extractModelId(s){
    s = (s || '').trim();
    if (!s) return '';
    // Bare numeric id
    if (/^\d+$/.test(s)) return s;
    // URL like printables.com/model/1743150-slug or /model/1743150
    const m = s.match(/\/model\/(\d+)/);
    if (m) return m[1];
    // Last resort: first run of digits
    const d = s.match(/(\d{3,})/);
    return d ? d[1] : '';
  }

  if (pasteGo) pasteGo.addEventListener('click', async () => {
    const id = extractModelId(pasteInput.value);
    if (!id) { pasteStatus.textContent = 'Could not find a model ID in that input.'; return; }
    pasteGo.disabled = true;
    pasteStatus.textContent = 'Queueing model ' + id + '…';
    try {
      const res = await fetch('enqueue.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ csrf: CSRF, fileType: 'PACK', models: [{ id: id, slug: '', name: '', creator: '' }] })
      });
      const data = await res.json();
      if (data.ok) {
        pasteStatus.textContent = data.queued > 0
          ? ('Queued model ' + id + ' as ZIP. The worker will download it shortly.')
          : ('Model ' + id + ' was already queued.');
        pasteInput.value = '';
      } else {
        pasteStatus.textContent = 'Queue failed: ' + (data.error || 'unknown');
      }
    } catch (err) {
      pasteStatus.textContent = 'Network error: ' + err.message;
    }
    pasteGo.disabled = false;
  });

</script>

<script>
  const toggleBtn = document.getElementById('theme-toggle');
  const toggleIcon = document.getElementById('theme-toggle-icon');
  
  // Check for saved user preference, otherwise default to dark
  const currentTheme = localStorage.getItem('theme') || 'dark';
  
  if (currentTheme === 'light') {
    document.documentElement.setAttribute('data-theme', 'light');
    toggleIcon.textContent = '☀️';
  }

  toggleBtn.addEventListener('click', () => {
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



  <script src="js/theme.js"></script>
</body>
</html>
