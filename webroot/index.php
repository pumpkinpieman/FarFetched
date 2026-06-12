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
  aside{width:240px;background:var(--panel);border-right:1px solid var(--line);padding:24px 16px;flex-shrink:0;}
  .brand{font-family:ui-serif,Georgia,serif;font-size:20px;font-weight:600;color:var(--clay-deep);padding:0 8px 16px;letter-spacing:-0.3px;}
  .navlabel{font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#A9A496;padding:12px 12px 6px;}
  nav a{display:block;padding:9px 12px;margin-bottom:2px;border-radius:8px;color:var(--muted);text-decoration:none;font-size:14px;cursor:pointer;}
  nav a:hover{background:#E8E5DA;color:var(--ink);} nav a.active{background:var(--clay);color:#fff;font-weight:500;}
  main{flex:1;padding:28px 32px;}
  .topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;gap:16px;flex-wrap:wrap;}
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
  .card{background:var(--card);border:1px solid var(--line);border-radius:14px;overflow:hidden;position:relative;transition:border-color .15s,box-shadow .15s;cursor:pointer;user-select:none;}
  .card.sel{border-color:var(--clay);box-shadow:0 0 0 2px rgba(217,119,87,.25);}
  .thumb{aspect-ratio:1;background:var(--panel);display:flex;align-items:center;justify-content:center;color:#B9B4A6;font-size:13px;}
  .thumb img{width:100%;height:100%;object-fit:cover;}
  .meta{padding:12px 14px;} .mname{font-size:14px;font-weight:600;line-height:1.3;margin-bottom:3px;} .mcreator{font-size:12px;color:var(--muted);}
  .msize{font-size:11px;color:var(--clay-deep);font-weight:600;margin-top:5px;} .msize:empty{display:none;}
  .searchbar{display:flex;gap:8px;margin-bottom:18px;}
  .searchbar input{flex:1;padding:11px 14px;border:1px solid var(--line);border-radius:10px;font:inherit;font-size:15px;background:var(--card);color:var(--ink);}
  .searchbar input:focus{outline:none;border-color:var(--clay);box-shadow:0 0 0 2px rgba(217,119,87,.15);}
  .badge{position:absolute;top:10px;right:10px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;padding:3px 7px;border-radius:6px;color:#fff;}
  .badge.paid{background:#C9912F;} .badge.club{background:#6E59C2;}
  .pick{position:absolute;top:10px;left:10px;width:22px;height:22px;cursor:pointer;accent-color:var(--clay);}
  @media (max-width:640px){aside{width:170px;}main{padding:20px 16px;}}
</style>
</head>
<body>
  <aside>
    <div class="brand">◆ FarFetched</div>

    <div class="navlabel">Category ID</div>
    <form method="get" style="padding:0 8px 6px;">
      <input type="hidden" name="type" value="<?= e($fileType) ?>">
      <input type="text" name="cat" inputmode="numeric" pattern="[0-9]*"
             placeholder="paste # → Go"
             value="<?= $isRawId ? e($active) : '' ?>"
             style="width:100%;border:1px solid var(--line);border-radius:8px;padding:8px 10px;font:13px ui-monospace,monospace;background:var(--card);color:var(--ink);">
    </form>

    <div class="navlabel">Categories</div>
    <nav>
      <?php foreach (CATEGORIES as $slug => $label): ?>
        <a href="?cat=<?= e($slug) ?>&type=<?= e($fileType) ?>" class="<?= $slug === $active ? 'active' : '' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
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
        <select id="fileType" onchange="location.href='?cat=<?= e($active) ?>&type='+this.value">
          <option value="STL" <?= $fileType==='STL'?'selected':'' ?>>STL</option>
          <option value="3MF" <?= $fileType==='3MF'?'selected':'' ?>>3MF</option>
          <option value="PACK" <?= $fileType==='PACK'?'selected':'' ?>>Whole model (ZIP)</option>
        </select>
        <span class="selcount" id="selcount">0 selected</span>
        <button class="btn-ghost" id="selectAll">Select all on page</button>
        <button class="btn-primary" id="download" disabled>Download Selected</button>
      </div>
    </div>

    <div class="searchbar">
      <input type="search" id="searchInput" placeholder="Search all of Printables — e.g. belt sander, toothpick, sanding block…" autocomplete="off">
      <button class="btn-primary" id="searchGo">Search</button>
      <button class="btn-ghost" id="searchClear" style="display:none;">Clear</button>
    </div>

    <?php if ($banner): ?><div class="banner"><?= e($banner) ?></div><?php endif; ?>

    <div class="pastebar">
      <div class="pastebar-label">No token? Paste a Printables model URL or ID — downloads the whole model as a ZIP (no login needed):</div>
      <div class="pastebar-row">
        <input type="text" id="pasteId" placeholder="https://www.printables.com/model/1743150-… or just 1743150">
        <button class="btn-primary" id="pasteGo">Queue ZIP</button>
      </div>
      <div class="pastebar-status" id="pasteStatus"></div>
    </div>

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
  const ACTIVE_CAT = <?= json_encode($active) ?>;
  let nextCursor = <?= json_encode($initialCursor) ?>;
  const grid = document.getElementById('grid');
  const countEl = document.getElementById('selcount');
  const dlBtn = document.getElementById('download');
  const selAllBtn = document.getElementById('selectAll');
  const loadMoreBtn = document.getElementById('loadMore');
  const loadStatus = document.getElementById('loadStatus');

  const selectedCards = () => grid ? [...grid.querySelectorAll('.card.sel')] : [];
  function refresh(){ const n = selectedCards().length; if (countEl) countEl.textContent = n+' selected'; if (dlBtn) dlBtn.disabled = n===0; }

  // Build a card DOM node from a model object (same markup as the PHP render).
  function makeCard(m){
    const card = document.createElement('div');
    card.className = 'card';
    card.dataset.id = m.id; card.dataset.slug = m.slug;
    card.dataset.name = m.name; card.dataset.creator = m.creator;
    const thumb = m.thumb
      ? '<img src="'+encodeURI(m.thumb)+'" alt="" loading="lazy">'
      : '<span>no preview</span>';
    // Badge paid/club models so you know which may not be fetchable.
    let badge = '';
    if (m.club) badge = '<span class="badge club">Club</span>';
    else if (m.price > 0) badge = '<span class="badge paid">Paid</span>';
    // textContent-safe insertion for name/creator
    card.innerHTML =
      '<input type="checkbox" class="pick" aria-label="Select model">' +
      '<div class="thumb">'+thumb+'</div>' + badge +
      '<div class="meta"><div class="mname"></div><div class="mcreator"></div><div class="msize"></div></div>';
    card.querySelector('.mname').textContent = m.name;
    card.querySelector('.mcreator').textContent = 'by ' + m.creator;
    card.querySelector('.msize').textContent = m.size ? fmtBytes(m.size) : '';
    return card;
  }
  function fmtBytes(b){ if(!b) return ''; const u=['B','KB','MB','GB']; let i=0,n=b; while(n>=1024&&i<u.length-1){n/=1024;i++;} return (i===0?Math.round(n):n.toFixed(1))+' '+u[i]; }

  // Paging state. mode 'browse' uses the opaque cursor; mode 'search' uses a
  // numeric offset (searchPrints2). Same infinite-scroll mechanism for both.
  let loading = false;
  let mode = 'browse';
  let searchQuery = '';
  let searchNext = null;   // next offset to fetch, or null when exhausted

  function hasMore() { return mode === 'search' ? (searchNext !== null) : !!nextCursor; }

  async function loadMore() {
    if (loading || !hasMore()) return;
    loading = true;
    if (loadMoreBtn) loadMoreBtn.disabled = true;
    loadStatus.textContent = 'Loading…';
    try {
      const url = (mode === 'search')
        ? 'search_more.php?q=' + encodeURIComponent(searchQuery) +
          '&offset=' + encodeURIComponent(searchNext) + '&paid=all'
        : 'browse_more.php?cat=' + encodeURIComponent(ACTIVE_CAT) +
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
  }

  // Kick off a keyword search (or clear back to category browse if empty).
  const searchInput = document.getElementById('searchInput');
  const searchGo = document.getElementById('searchGo');
  const searchClear = document.getElementById('searchClear');
  const pageTitle = document.getElementById('pageTitle');

  async function runSearch() {
    const q = (searchInput.value || '').trim();
    if (!q) { clearSearch(); return; }
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
  function clearSearch() {
    // Return to the category browse the page loaded with.
    location.href = '?cat=' + encodeURIComponent(ACTIVE_CAT) + '&type=' + encodeURIComponent(FILE_TYPE);
  }
  if (searchGo) searchGo.addEventListener('click', runSearch);
  if (searchClear) searchClear.addEventListener('click', clearSearch);
  if (searchInput) searchInput.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); runSearch(); } });

  // Infinite scroll: auto-load when the sentinel near the page bottom appears.
  const sentinel = document.getElementById('scrollSentinel');
  let observer = null;
  if ('IntersectionObserver' in window && sentinel) {
    observer = new IntersectionObserver((entries) => {
      if (entries.some(e => e.isIntersecting)) loadMore();
    }, { rootMargin: '600px 0px' }); // prefetch before the user hits the very bottom
    observer.observe(sentinel);
  } else if (loadMoreBtn && nextCursor) {
    // Fallback for old browsers: show the manual button.
    loadMoreBtn.style.display = 'inline-block';
  }
  // Button still works as a manual fallback if present.
  if (loadMoreBtn) loadMoreBtn.addEventListener('click', loadMore);

  if (grid) grid.addEventListener('change', e => {
    if (!e.target.classList.contains('pick')) return;
    e.target.closest('.card').classList.toggle('sel', e.target.checked);
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
    card.classList.toggle('sel', box.checked);
    refresh();
  });
  if (selAllBtn) selAllBtn.addEventListener('click', () => {
    const boxes = grid.querySelectorAll('.pick');
    const on = [...boxes].some(b => !b.checked);
    boxes.forEach(b => { b.checked = on; b.closest('.card').classList.toggle('sel', on); });
    refresh();
  });
  if (dlBtn) dlBtn.addEventListener('click', async () => {
    const models = selectedCards().map(c => ({
      id: c.dataset.id, slug: c.dataset.slug, name: c.dataset.name, creator: c.dataset.creator
    }));
    if (!models.length) return;
    dlBtn.disabled = true; dlBtn.textContent = 'Queuing…';
    try {
      const res = await fetch('enqueue.php', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ csrf: CSRF, fileType: FILE_TYPE, models })
      });
      const data = await res.json();
      if (data.ok) {
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
