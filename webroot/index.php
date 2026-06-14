<?php
declare(strict_types=1);

/**
 * index.php — browse + filter + select + queue.
 * Renders real models from Printables (falls back to a clear banner if the
 * token/API isn't ready yet). Selection posts to enqueue.php.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/PrintablesService.php';

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
if (!in_array($source, ['printables', 'makerworld', 'thingiverse', 'myminifactory'], true)) {
    $source = 'printables';
}

// MakerWorld category browse state.
$mwCat    = preg_replace('/[^0-9]/', '', (string) ($_GET['mwcat'] ?? '')) ?? '';
$mwBrowse = $source === 'makerworld' && (isset($_GET['mwcat']) || isset($_GET['browse']));
if (!array_key_exists($mwCat, MW_CATEGORIES)) {
    $mwCat = '';
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
            ? 'MakerWorld — pick a category on the left, or type a keyword above to search.'
            : 'MakerWorld — search & browse work now; add your MakerWorld token in Settings to download.';
    }
} elseif ($source === 'thingiverse') {
    $svc           = null;
    $models        = [];
    $initialCursor = null;
    $tvReady       = (string) cfg('thingiverse_token') !== '';
    $banner        = $tvReady
        ? 'Thingiverse — type a keyword to search, or scroll to browse popular.'
        : 'Thingiverse — add your token in Settings to browse and download.';
} elseif ($source === 'myminifactory') {
    $svc           = null;
    $models        = [];
    $initialCursor = null;
    $mmfReady      = (string) cfg('myminifactory_token') !== '';
    $banner        = $mmfReady
        ? 'MyMiniFactory — type a keyword to search.'
        : 'MyMiniFactory — add your API key in Settings to browse and download.';
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
<style>
  :root{--bg:#FAF9F5;--panel:#F0EEE6;--card:#FFFFFF;--ink:#2B2A28;--muted:#6B6862;--line:#E5E2D8;--clay:#D97757;--clay-deep:#C2613F;}
  *{box-sizing:border-box;margin:0;padding:0;}
  body{font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;background:var(--bg);color:var(--ink);display:flex;min-height:100vh;}
  aside{width:240px;background:var(--panel);border-right:1px solid var(--line);padding:24px 16px;flex-shrink:0;position:sticky;top:0;height:100vh;overflow-y:auto;}
  .brand{font-family:ui-serif,Georgia,serif;font-size:20px;font-weight:600;color:var(--clay-deep);padding:0 8px 16px;letter-spacing:-0.3px;}
  .navlabel{font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#A9A496;padding:12px 12px 6px;}
  nav a{display:block;padding:9px 12px;margin-bottom:2px;border-radius:8px;color:var(--muted);text-decoration:none;font-size:14px;cursor:pointer;}
  nav a:hover{background:#E8E5DA;color:var(--ink);} nav a.active{background:var(--clay);color:#fff;font-weight:500;}
  main{flex:1;padding:28px 32px;}
  .topbar{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;position:sticky;top:0;z-index:20;background:var(--bg);padding:14px 0 12px;margin-bottom:10px;box-shadow:0 6px 14px -10px rgba(0,0,0,.18);}
  h1{font-family:ui-serif,Georgia,serif;font-size:24px;font-weight:600;letter-spacing:-0.4px;}
  .actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
  .selcount{font-size:13px;color:var(--muted);}
  select,button{font:inherit;cursor:pointer;border-radius:9px;font-size:14px;}
  select{padding:9px 12px;border:1px solid var(--line);background:var(--card);color:var(--ink);}
  button{border:none;padding:10px 18px;font-weight:500;}
  .btn-primary{background:var(--clay);color:#fff;} .btn-primary:hover{background:var(--clay-deep);} .btn-primary:disabled{background:#D8C9C0;cursor:not-allowed;}
  .btn-ghost{background:transparent;color:var(--muted);border:1px solid var(--line);} .btn-ghost:hover{border-color:var(--clay);color:var(--clay-deep);}
  .banner{background:#FBF1D9;color:#8A6D1F;border:1px solid #ECD9A6;padding:11px 14px;border-radius:9px;font-size:14px;margin-bottom:18px;}
  .pastebar{background:var(--card,#fff);border:1px solid var(--line,#E5E2D8);border-radius:12px;padding:14px 16px;margin-bottom:18px;}
  .pastebar-label{font-size:13px;color:var(--muted,#6B6862);margin-bottom:8px;}
  .pastebar-row{display:flex;gap:8px;}
  .pastebar-row input{flex:1;padding:9px 12px;border:1px solid var(--line,#E5E2D8);border-radius:8px;font:inherit;font-size:14px;}
  .pastebar-status{font-size:13px;color:var(--muted,#6B6862);margin-top:8px;min-height:18px;}
  .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:18px;}
  .card{background:var(--card);border:1px solid var(--line);border-radius:14px;overflow:visible;position:relative;transition:border-color .15s,box-shadow .15s;cursor:pointer;user-select:none;}
  .card.sel{border-color:var(--clay);box-shadow:0 0 0 2px rgba(217,119,87,.25);}
  .thumb{aspect-ratio:1;background:var(--panel);display:flex;align-items:center;justify-content:center;color:#B9B4A6;font-size:13px;border-radius:14px 14px 0 0;overflow:hidden;}
  .thumb img{width:100%;height:100%;object-fit:cover;display:block;}
  .meta{padding:12px 14px;} .mname{font-size:14px;font-weight:600;line-height:1.3;margin-bottom:3px;} .mcreator{font-size:12px;color:var(--muted);}
  .msize{font-size:11px;color:var(--clay-deep);font-weight:600;margin-top:5px;} .msize:empty{display:none;}
  .searchbar{display:flex;gap:8px;margin-bottom:18px;align-items:center;}
  .srcToggle{display:inline-flex;border:1px solid var(--line);border-radius:9px;overflow:hidden;}
  .srcBtn{padding:7px 12px;font-size:13px;font-weight:600;color:var(--muted);text-decoration:none;background:var(--card);}
  .srcBtn+.srcBtn{border-left:1px solid var(--line);}
  .srcBtn.active{background:var(--clay);color:#fff;}
  .ftype-fixed{font-size:13px;color:var(--muted);border:1px solid var(--line);border-radius:8px;padding:6px 10px;background:var(--card);}
  .nsfwToggle{display:inline-flex;align-items:center;gap:6px;font-size:13px;color:var(--muted);white-space:nowrap;cursor:pointer;}
  .searchbar input{flex:1;padding:11px 14px;border:1px solid var(--line);border-radius:10px;font:inherit;font-size:15px;background:var(--card);color:var(--ink);}
  .searchbar input:focus{outline:none;border-color:var(--clay);box-shadow:0 0 0 2px rgba(217,119,87,.15);}
  .badge{position:absolute;top:10px;right:10px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;padding:3px 7px;border-radius:6px;color:#fff;}
  .badge.paid{background:#C9912F;} .badge.club{background:#6E59C2;}
  .pick{position:absolute;top:10px;left:10px;width:22px;height:22px;cursor:pointer;accent-color:var(--clay);z-index:4;}
  @media (max-width:640px){aside{width:170px;}main{padding:20px 16px;}}
</style>
</head>
<body>
  <aside>
    <div class="brand"><img src="logo.svg" alt="FarFetched" style="height:1.15em;width:auto;vertical-align:-.2em;margin-right:7px"> FarFetched</div>

    <?php if ($source === 'makerworld'): ?>
    <div class="navlabel">MakerWorld Categories</div>
    <nav id="mwCatNav">
      <?php foreach (MW_CATEGORIES as $cid => $label): $cid = (string) $cid; ?>
        <a href="#" data-mwcat="<?= e($cid) ?>" data-mwlabel="<?= e($label) ?>"
           class="<?= ($mwBrowse && $cid === $mwCat) ? 'active' : '' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <?php elseif ($source === 'thingiverse' || $source === 'myminifactory'): ?>
    <div class="navlabel"><?= $source === 'thingiverse' ? 'Thingiverse' : 'MyMiniFactory' ?></div>
    <nav>
      <a href="?src=<?= e($source) ?>&browse=all" class="active">All Models</a>
    </nav>
    <?php else: ?>
    <div class="navlabel">Category ID</div>
    <form method="get" style="padding:0 8px 6px;">
      <input type="hidden" name="type" value="<?= e($fileType) ?>">
      <input type="text" name="cat" inputmode="numeric" pattern="[0-9]*"
             placeholder="paste # → Go"
             value="<?= $isRawId ? e($active) : '' ?>"
             style="width:100%;border:1px solid var(--line);border-radius:8px;padding:8px 10px;font:13px ui-monospace,monospace;background:var(--card);color:var(--ink);">
    </form>

    <div class="navlabel">Categories</div>
    <nav id="pbCatNav">
      <?php foreach (CATEGORIES as $slug => $label): ?>
        <a href="#" data-pbcat="<?= e($slug) ?>" data-pblabel="<?= e($label) ?>"
           class="<?= $slug === $active ? 'active' : '' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <?php endif; ?>
    <div class="navlabel">Tool</div>
    <nav>
      <a href="home.php">← Sources</a>
      <a href="jobs.php">Queue</a>
      <a href="viewer.php">3D Viewer</a>
      <a href="settings.php">Settings</a>
    </nav>
  </aside>

  <main>
    <div class="topbar">
      <h1 id="pageTitle"><?= e($title) ?></h1>
      <div class="actions">
        <div class="srcToggle" role="group" aria-label="Model source">
          <a href="?cat=<?= e($active) ?>&type=<?= e($fileType) ?>" class="srcBtn <?= $source==='printables'?'active':'' ?>">Printables</a>
          <a href="?src=makerworld&browse=all" class="srcBtn <?= $source==='makerworld'?'active':'' ?>">MakerWorld</a>
          <a href="?src=thingiverse&browse=all" class="srcBtn <?= $source==='thingiverse'?'active':'' ?>">Thingiverse</a>
          <a href="?src=myminifactory&browse=all" class="srcBtn <?= $source==='myminifactory'?'active':'' ?>">MyMiniFactory</a>
        </div>
        <?php if ($source === 'printables'): ?>
        <select id="fileType" onchange="location.href='?cat=<?= e($active) ?>&type='+this.value">
          <option value="STL" <?= $fileType==='STL'?'selected':'' ?>>STL</option>
          <option value="3MF" <?= $fileType==='3MF'?'selected':'' ?>>3MF</option>
          <option value="PACK" <?= $fileType==='PACK'?'selected':'' ?>>Whole model (ZIP)</option>
        </select>
        <?php else: ?>
        <span class="ftype-fixed" title="Downloads include all available formats">All formats</span>
        <?php endif; ?>
        <span class="selcount" id="selcount">0 selected</span>
        <button class="btn-ghost" id="selectAll">Select all on page</button>
        <button class="btn-primary" id="download" disabled>Download Selected</button>
      </div>
    </div>

    <div class="searchbar">
      <input type="search" id="searchInput" placeholder="<?= $source==='makerworld'
        ? 'Search all of MakerWorld — e.g. airless ball, gridfinity, phone stand…'
        : 'Search all of Printables — e.g. belt sander, toothpick, sanding block…' ?>" autocomplete="off">
      <button class="btn-primary" id="searchGo">Search</button>
      <button class="btn-ghost" id="searchClear" style="display:none;">Clear</button>
      <?php if ($source === 'makerworld'): ?>
      <label class="nsfwToggle" title="MakerWorld hosts adult content; off by default"><input type="checkbox" id="nsfwToggle"> Show NSFW</label>
      <?php endif; ?>
    </div>

    <?php if ($banner): ?><div class="banner"><?= e($banner) ?></div><?php endif; ?>

    <?php if ($source === 'printables'): ?>
    <div class="pastebar">
      <div class="pastebar-label">No token? Paste a Printables model URL or ID — downloads the whole model as a ZIP (no login needed):</div>
      <div class="pastebar-row">
        <input type="text" id="pasteId" placeholder="https://www.printables.com/model/1743150-… or just 1743150">
        <button class="btn-primary" id="pasteGo">Queue ZIP</button>
      </div>
      <div class="pastebar-status" id="pasteStatus"></div>
    </div>
    <?php endif; ?>

    <div class="grid" id="grid">
      <?php foreach ($models as $m): ?>
        <div class="card"
             data-id="<?= e($m['id']) ?>" data-slug="<?= e($m['slug']) ?>"
             data-name="<?= e($m['name']) ?>" data-creator="<?= e($m['creator']) ?>">
          <input type="checkbox" class="pick" aria-label="Select model">
          <div class="thumb">
            <?php if ($m['thumb'] !== ''): ?><img src="<?= e($m['thumb']) ?>" alt="" loading="lazy"><?php else: ?><span>no preview</span><?php endif; ?>
          </div>
          <div class="meta">
            <div class="mname"><?= e($m['name']) ?></div>
            <div class="mcreator">by <?= e($m['creator']) ?></div>
            <div class="msize"><?= !empty($m['size']) ? e(human_size((int) $m['size'])) : '' ?></div>
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
  const loadMoreBtn = document.getElementById('loadMore');
  const loadStatus = document.getElementById('loadStatus');

  // ---- Cross-category persistent selection store -----------------------------
  // Keyed by model id (string). Value = {id, slug, name, creator}.
  // Survives grid resets when browsing/searching between categories.
  const selStore = new Map();
  function selSet(id, data) { selStore.set(String(id), data); }
  function selDel(id)       { selStore.delete(String(id)); }
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
      ? '<img class="thumb-img" src="'+encodeURI(imgs[0])+'" alt="" loading="lazy">'
      : '<span>no preview</span>';

    // Badge paid/club models so you know which may not be fetchable.
    let badge = '';
    if (m.club) badge = '<span class="badge club">Club</span>';
    else if (m.price > 0) badge = '<span class="badge paid">Paid</span>';

    // textContent-safe insertion for name/creator
    card.innerHTML =
      '<input type="checkbox" class="pick" aria-label="Select model"' + (selHas(m.id) ? ' checked' : '') + '>' +
      '<div class="thumb" style="position:relative;">'+thumb+'</div>' + badge +
      '<div class="meta"><div class="mname"></div><div class="mcreator"></div><div class="msize"></div></div>';
    card.querySelector('.mname').textContent = m.name;
    card.querySelector('.mcreator').textContent = 'by ' + m.creator;
    card.querySelector('.msize').textContent = m.size ? fmtBytes(m.size) : '';

    if (multi) attachSlider(card, imgs, m.id);
    else if (SOURCE === 'printables' && m.id) attachSlider(card, imgs, m.id);
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
      img.src = encodeURI(imgs[idx]);
    }
    function nav(delta, ev){
      ev.preventDefault(); ev.stopPropagation();
      show(idx + delta);
    }
    prev.addEventListener('click', (e)=>nav(-1, e));
    next.addEventListener('click', (e)=>nav( 1, e));

    // For Printables cards: lazy-fetch the full gallery on first hover.
    async function fetchPrintablesGallery() {
      if (galleryFetched || !modelId || SOURCE !== 'printables') return;
      galleryFetched = true;
      try {
        const res = await fetch('print_images.php?id=' + encodeURIComponent(modelId));
        const urls = await res.json();
        if (Array.isArray(urls) && urls.length > 1) {
          imgs.length = 0;
          urls.forEach(u => imgs.push(u));
          // Preload all images now that we have them.
          imgs.forEach(u => { const p = new Image(); p.src = encodeURI(u); });
          // Show/hide arrows based on updated count.
          prev.style.display = next.style.display = imgs.length > 1 ? 'block' : 'none';
        }
      } catch(e) { /* fail silently */ }
    }

    card.addEventListener('mouseenter', async ()=>{
      await fetchPrintablesGallery();
      if (imgs.length > 1) prev.style.display = next.style.display = 'block';
      if (!preloaded){
        preloaded = true;
        for (let i = 1; i < imgs.length; i++){ const p = new Image(); p.src = encodeURI(imgs[i]); }
      }
    });
    card.addEventListener('mouseleave', ()=>{
      prev.style.display = next.style.display = 'none';
    });

    wrap.appendChild(prev);
    wrap.appendChild(next);
  }
  function fmtBytes(b){ if(!b) return ''; const u=['B','KB','MB','GB']; let i=0,n=b; while(n>=1024&&i<u.length-1){n/=1024;i++;} return (i===0?Math.round(n):n.toFixed(1))+' '+u[i]; }

  // Paging state. mode 'browse' uses the opaque cursor; mode 'search' uses a
  // numeric offset (searchPrints2). Same infinite-scroll mechanism for both.
  let loading = false;
  let mode = 'browse';
  let searchQuery = '';
  let searchNext = null;   // next offset to fetch, or null when exhausted
  const MW_CAT = <?= json_encode($mwCat) ?>;
  const MW_BROWSE = <?= json_encode($mwBrowse) ?>;
  let mwCatActive = '';    // current MakerWorld category id while browsing
  let pbCatActive = ACTIVE_CAT; // current Printables category slug (mutable)

  function hasMore() { return mode === 'search' ? (searchNext !== null) : (nextCursor !== null); }

  async function loadMore() {
    if (loading || !hasMore()) return;
    loading = true;
    if (loadMoreBtn) loadMoreBtn.disabled = true;
    loadStatus.textContent = 'Loading…';
    try {
      const url = (mode === 'search')
        ? 'search_more.php?q=' + encodeURIComponent(searchQuery) +
          '&offset=' + encodeURIComponent(searchNext) + '&paid=all' +
          '&src=' + encodeURIComponent(SOURCE) + '&nsfw=' + showNsfw() +
          (SOURCE === 'makerworld' && mwCatActive ? '&mwcat=' + encodeURIComponent(mwCatActive) : '') +
          (SOURCE === 'makerworld' && searchQuery === '' ? '&browse=1' : '') +
          ((SOURCE === 'thingiverse' || SOURCE === 'myminifactory') && searchQuery === '' ? '&browse=1' : '')
        : 'browse_more.php?cat=' + encodeURIComponent(pbCatActive) +
          '&cursor=' + encodeURIComponent(nextCursor || '');
      const res = await fetch(url);
      const data = await res.json();
      if (!data.ok) {
        loadStatus.textContent = 'Error: ' + (data.error || 'unknown');
        if (loadMoreBtn) loadMoreBtn.disabled = false;
        loading = false;
        return;
      }
      for (const m of data.models) grid.appendChild(makeCard(m));

      if (mode === 'search') {
        searchNext = data.nextOffset; // null when exhausted
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
    } catch (err) {
      loadStatus.textContent = 'Network error: ' + err.message;
      if (loadMoreBtn) loadMoreBtn.disabled = false;
    }
    loading = false;

    // IntersectionObserver only fires on a not-visible→visible transition. When
    // a page of results doesn't push the sentinel back out of view (e.g. 20 tall
    // cards still inside the 600px prefetch zone), that transition never happens
    // and auto-load stalls. Keep loading while the sentinel stays in range.
    if (hasMore() && sentinelInView()) {
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
    if (SOURCE === 'makerworld') {
      location.href = '?src=makerworld&browse=all';
      return;
    }
    if (SOURCE === 'thingiverse') {
      location.href = '?src=thingiverse&browse=all';
      return;
    }
    if (SOURCE === 'myminifactory') {
      location.href = '?src=myminifactory&browse=all';
      return;
    }
    // Return to the category browse the page loaded with.
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
  // Thingiverse / MMF: auto-load popular on browse=all landing.
  if ((SOURCE === 'thingiverse' || SOURCE === 'myminifactory') && <?= json_encode(isset($_GET['browse'])) ?>) {
    mode = 'search'; searchQuery = ''; searchNext = 0; nextCursor = null;
    if (pageTitle) pageTitle.textContent = SOURCE === 'thingiverse' ? 'Thingiverse' : 'MyMiniFactory';
    loadMore();
  }

  // MW category nav — intercept clicks so the page never reloads (preserves selStore).
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
    // Let the checkbox handle its own clicks (avoids double-toggle).
    if (e.target.classList.contains('pick')) return;
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
        selStore.clear();
        window.location.href = 'jobs.php';
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
</body>
</html>
