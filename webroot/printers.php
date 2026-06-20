<?php
declare(strict_types=1);

/**
 * printers.php — "My Printers": pick the printers you own from a catalog (or
 * add a custom one). Enabled printers feed the print-bed fit checker. Bed
 * dimensions come baked in from the catalog, so no manual entry for known ones.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/printer_catalog.php';

$csrf = csrf_token();

// User's saved printers. Allow multiples of the same model; group by name.
$mine = [];
foreach (db()->query('SELECT * FROM printers ORDER BY name, id')->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $mine[] = $r;
}
// Names the user already owns at least one of (for a subtle catalog hint).
$ownedNames = [];
foreach ($mine as $r) { $ownedNames[$r['name']] = true; }

$catalog = printer_catalog();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>My Printers · FarFetched</title>
<link rel="stylesheet" href="css/styles.css">
<link rel="stylesheet" href="css/styles_library.css">
<script type="application/json" id="pp-csrf"><?= json_encode($csrf) ?></script>
</head>
<body>
  <aside>
    <div class="brand"><img src="logo.svg" alt="FarFetched" style="height:1.15em;width:auto;vertical-align:-.2em;margin-right:7px"> FarFetched</div>
    <div class="navlabel">Tool</div>
    <nav>
      <a href="index.php">Browse Models</a>
      <a href="jobs.php">Queue</a>
      <a href="viewer.php">3D Viewer</a>
      <a href="library.php">My Library</a>
      <a href="insights.php">Insights</a>
      <a href="printers.php" class="active">My Printers</a>
      <a href="collections_view.php">Collections</a>
      <a href="favorites.php">Favorites</a>
      <a href="settings.php">Settings</a>
      <button id="theme-toggle" aria-label="Toggle theme" class="btn-ghost">
        <span id="theme-toggle-icon">🌙</span> Change Appearance
      </button>
    </nav>
  </aside>

  <main>
    <h1>My Printers</h1>
    <div class="sub">Pick the printers you own — bed sizes are set automatically and used to flag models that won't fit.</div>

    <!-- Your printers -->
    <div class="pp-section-h">Your printers</div>
    <div id="myPrinters" class="pp-grid">
      <?php if ($mine === []): ?>
        <div class="lib-empty" id="myEmpty">No printers yet — add one from the catalog below.</div>
      <?php endif; ?>
      <?php foreach ($mine as $p):
        $img = printer_image_url($p['name']);
      ?>
        <div class="pp-card <?= $p['enabled'] ? 'on' : '' ?>" data-id="<?= (int) $p['id'] ?>">
          <div class="pp-thumb">
            <?= $img ? '<img src="' . e($img) . '" alt="">' : printer_icon_svg($p['brand']) ?>
          </div>
          <div class="pp-card-body">
            <div class="pp-nick" data-id="<?= (int) $p['id'] ?>">
              <?= ($p['nickname'] ?? '') !== '' ? e($p['nickname']) : '<span class="pp-nick-empty">+ add nickname</span>' ?>
            </div>
            <div class="pp-name"><?= e($p['name']) ?></div>
            <div class="pp-brand"><?= e($p['brand']) ?><?= $p['is_custom'] ? ' · custom' : '' ?></div>
            <div class="pp-bed"><?= (int) $p['bed_x'] ?> × <?= (int) $p['bed_y'] ?> × <?= (int) $p['bed_z'] ?> mm</div>
          </div>
          <div class="pp-card-actions">
            <button class="pp-toggle" data-id="<?= (int) $p['id'] ?>"><?= $p['enabled'] ? '✓ Enabled' : 'Enable' ?></button>
            <button class="pp-remove" data-id="<?= (int) $p['id'] ?>" title="Remove">✕</button>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Add custom -->
    <div class="pp-section-h">Add a custom printer</div>
    <div class="pp-custom">
      <input id="cName" placeholder="Name (e.g. My Voron 2.4)" class="lib-search" style="max-width:200px">
      <input id="cNick" placeholder="Nickname (optional)" class="lib-search" style="max-width:170px">
      <input id="cX" type="number" placeholder="X mm" class="pp-num">
      <input id="cY" type="number" placeholder="Y mm" class="pp-num">
      <input id="cZ" type="number" placeholder="Z mm" class="pp-num">
      <button id="addCustom" class="lib-btn lib-btn-accent lib-btn-sm" style="flex:0 0 auto">Add custom</button>
    </div>

    <!-- Catalog -->
    <div class="pp-section-h">Catalog</div>
    <input type="search" id="catSearch" class="lib-search" placeholder="🔍 Search printers…" style="margin-bottom:14px;max-width:340px">
    <div id="catalog" class="pp-grid">
      <?php foreach ($catalog as $p):
        $owned = isset($ownedNames[$p['name']]);
        $img = printer_image_url($p['name']);
      ?>
        <div class="pp-card pp-cat"
             data-name="<?= e($p['name']) ?>" data-search="<?= e(strtolower($p['name'] . ' ' . $p['brand'])) ?>">
          <div class="pp-thumb">
            <?= $img ? '<img src="' . e($img) . '" alt="">' : printer_icon_svg($p['brand']) ?>
          </div>
          <div class="pp-card-body">
            <div class="pp-name"><?= e($p['name']) ?><?= $owned ? ' <span class="pp-owned">owned</span>' : '' ?></div>
            <div class="pp-brand"><?= e($p['brand']) ?></div>
            <div class="pp-bed"><?= (int) $p['x'] ?> × <?= (int) $p['y'] ?> × <?= (int) $p['z'] ?> mm</div>
          </div>
          <div class="pp-card-actions">
            <button class="pp-add" data-name="<?= e($p['name']) ?>">+ Add</button>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </main>

<script>
  const CSRF = JSON.parse(document.getElementById('pp-csrf').textContent || '""');
  async function ppPost(payload) {
    const r = await fetch('printer_action.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(Object.assign({ csrf: CSRF }, payload)),
    });
    return r.json().catch(() => ({ ok: false }));
  }

  // Catalog search
  const catSearch = document.getElementById('catSearch');
  if (catSearch) catSearch.addEventListener('input', () => {
    const q = catSearch.value.trim().toLowerCase();
    document.querySelectorAll('#catalog .pp-cat').forEach(c => {
      c.style.display = (!q || (c.dataset.search || '').includes(q)) ? '' : 'none';
    });
  });

  // Add from catalog — prompt for an optional nickname, allow multiples.
  document.getElementById('catalog').addEventListener('click', async (e) => {
    const btn = e.target.closest('.pp-add');
    if (!btn) return;
    const nickname = (prompt('Nickname for this ' + btn.dataset.name + '? (optional)') || '').trim();
    btn.disabled = true;
    const res = await ppPost({ action: 'add_from_catalog', name: btn.dataset.name, nickname });
    btn.disabled = false;
    if (res.ok) location.reload();
  });

  // Toggle / remove / rename existing
  document.getElementById('myPrinters').addEventListener('click', async (e) => {
    const t = e.target.closest('.pp-toggle');
    const r = e.target.closest('.pp-remove');
    const nk = e.target.closest('.pp-nick');
    if (t) {
      const res = await ppPost({ action: 'toggle', id: +t.dataset.id });
      if (res.ok) {
        t.textContent = res.enabled ? '✓ Enabled' : 'Enable';
        t.closest('.pp-card').classList.toggle('on', res.enabled);
      }
    } else if (r) {
      if (!confirm('Remove this printer?')) return;
      const res = await ppPost({ action: 'remove', id: +r.dataset.id });
      if (res.ok) location.reload();
    } else if (nk) {
      const cur = nk.querySelector('.pp-nick-empty') ? '' : nk.textContent.trim();
      const name = prompt('Nickname for this printer:', cur);
      if (name === null) return;
      const res = await ppPost({ action: 'rename', id: +nk.dataset.id, nickname: name.trim() });
      if (res.ok) location.reload();
    }
  });

  // Add custom
  document.getElementById('addCustom').addEventListener('click', async () => {
    const name = document.getElementById('cName').value.trim();
    const nickname = document.getElementById('cNick').value.trim();
    const x = +document.getElementById('cX').value, y = +document.getElementById('cY').value, z = +document.getElementById('cZ').value;
    if (!name || x <= 0 || y <= 0 || z <= 0) { alert('Enter a name and positive X/Y/Z.'); return; }
    const res = await ppPost({ action: 'add_custom', name, nickname, x, y, z });
    if (res.ok) location.reload();
    else alert(res.error || 'Could not add.');
  });

  // Theme
  const root = document.documentElement;
  if (localStorage.getItem('theme') === 'light') root.setAttribute('data-theme', 'light');
  const tb = document.getElementById('theme-toggle'), ti = document.getElementById('theme-toggle-icon');
  function sync(){ if (ti) ti.textContent = root.getAttribute('data-theme') === 'light' ? '☀️' : '🌙'; }
  sync();
  if (tb) tb.addEventListener('click', () => {
    if (root.getAttribute('data-theme') === 'light') { root.removeAttribute('data-theme'); localStorage.setItem('theme','dark'); }
    else { root.setAttribute('data-theme','light'); localStorage.setItem('theme','light'); }
    sync();
  });
</script>
</body>
</html>
