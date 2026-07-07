<?php
declare(strict_types=1);

/**
 * filament.php — "My Filament": manage spool inventory (brands, materials,
 * colors, remaining weight). Custom-allowed: add a
 * spool in one step; the type is upserted inline.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth.php';
require_auth();
require_once __DIR__ . '/FilamentService.php';
require_once __DIR__ . '/filament_catalog.php';

$csrf      = csrf_token();
$inventory = FilamentService::inventory();
$stats     = FilamentService::stats();
$materials = filament_materials();
$brands    = filament_brands();
$colors    = filament_colors();
$statuses  = filament_statuses();

/** Pick readable text color for a given hex background. */
function fil_text_on(string $hex): string
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) { return '#fff'; }
    $r = hexdec(substr($hex, 0, 2)); $g = hexdec(substr($hex, 2, 2)); $b = hexdec(substr($hex, 4, 2));
    // Perceived luminance.
    return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 150 ? '#1a1a1a' : '#ffffff';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>My Filament · FarFetched</title>
<link rel="stylesheet" href="css/styles.css">
<link rel="stylesheet" href="css/styles_library.css">
<script type="application/json" id="fil-csrf"><?= json_encode($csrf) ?></script>
<style>
  .fil-stats{display:flex;gap:14px;flex-wrap:wrap;margin:6px 0 18px;}
  .fil-stat{background:var(--panel,#181b20);border:1px solid var(--line,#2a2f37);border-radius:10px;padding:10px 16px;}
  .fil-stat b{font-size:20px;display:block;}
  .fil-stat span{font-size:12px;color:var(--muted,#8a8f98);}
  .fil-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px;}
  .fil-card{background:var(--panel,#181b20);border:1px solid var(--line,#2a2f37);border-radius:12px;overflow:hidden;display:flex;flex-direction:column;}
  .fil-head{display:flex;gap:12px;padding:12px;align-items:center;}
  .fil-swatch{width:52px;height:52px;border-radius:9px;flex:0 0 auto;border:1px solid rgba(255,255,255,.15);}
  .fil-title{flex:1;min-width:0;}
  .fil-title b{display:block;font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .fil-title span{font-size:12px;color:var(--muted,#8a8f98);}
  .fil-badge{display:inline-block;font-size:10px;padding:1px 7px;border-radius:20px;font-weight:600;}
  .fil-spools{border-top:1px solid var(--line,#2a2f37);padding:8px 12px;display:flex;flex-direction:column;gap:8px;}
  .fil-spool{font-size:12px;}
  .fil-bar{height:7px;border-radius:4px;background:#2a2f37;overflow:hidden;margin-top:3px;}
  .fil-bar i{display:block;height:100%;border-radius:4px;}
  .fil-spool-row{display:flex;justify-content:space-between;align-items:center;gap:8px;}
  .fil-mini{background:none;border:1px solid var(--line,#2a2f37);color:var(--muted,#8a8f98);border-radius:6px;
            padding:1px 7px;font-size:12px;cursor:pointer;}
  .fil-mini:hover{border-color:var(--clay,#d0883f);color:var(--ink,#e6e6e6);}
  .fil-actions{margin-top:auto;display:flex;gap:6px;padding:8px 12px;border-top:1px solid var(--line,#2a2f37);}
  .fil-modal{position:fixed;inset:0;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;z-index:50;}
  .fil-modal-card{background:var(--panel,#181b20);border:1px solid var(--line,#2a2f37);border-radius:14px;
                  padding:20px;width:520px;max-width:94vw;max-height:90vh;overflow-y:auto;}
  .fil-row{display:grid;grid-template-columns:110px 1fr;gap:10px;align-items:center;margin-bottom:10px;}
  .fil-row label{font-size:13px;color:var(--muted,#8a8f98);}
  .fil-row input,.fil-row select,.fil-row textarea{width:100%;box-sizing:border-box;padding:6px 9px;font-size:13px;
        background:#14171c;color:#e6e6e6;border:1px solid #333b45;border-radius:7px;}
  .fil-two{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
  .fil-empty{color:var(--muted,#8a8f98);padding:40px;text-align:center;border:1px dashed var(--line,#2a2f37);border-radius:12px;}
</style>
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
      <a href="customize.php">Customize</a>
      <a href="insights.php">Insights</a>
      <a href="printers.php">My Printers</a>
      <a href="filament.php" class="active">My Filament</a>
      <a href="collections_view.php">Collections</a>
      <a href="favorites.php">Favorites</a>
      <a href="settings.php">Settings</a>
      <button id="theme-toggle" aria-label="Toggle theme" class="btn-ghost">
        <span id="theme-toggle-icon">🌙</span> Change Appearance
      </button>
    </nav>
  </aside>

  <main>
    <h1>My Filament</h1>
    <p class="lib-sub" style="color:var(--muted,#8a8f98);margin-top:-6px;">Track your spools — brand, material, color, and how much is left.</p>

    <div class="fil-stats">
      <div class="fil-stat"><b><?= (int) $stats['types'] ?></b><span>types</span></div>
      <div class="fil-stat"><b><?= (int) $stats['spools'] ?></b><span>active spools</span></div>
      <div class="fil-stat"><b><?= number_format((float) $stats['kg'], 2) ?> kg</b><span>remaining</span></div>
      <div class="fil-stat" style="display:flex;align-items:center;">
        <button class="lib-btn lib-btn-primary" id="filAdd" type="button">+ Add spool</button>
      </div>
    </div>

    <?php if (!$inventory): ?>
      <div class="fil-empty">No filament yet. Click <strong>+ Add spool</strong> to log your first roll.</div>
    <?php else: ?>
      <div class="fil-grid" id="filGrid">
        <?php foreach ($inventory as $t):
            $hex = e($t['color_hex']); $txt = fil_text_on($t['color_hex']);
            $title = trim(($t['brand'] ? $t['brand'] . ' ' : '') . $t['material']);
            if ($title === '') { $title = 'Filament'; }
        ?>
        <div class="fil-card" data-type='<?= e(json_encode($t, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>
          <div class="fil-head">
            <div class="fil-swatch" style="background-color:<?= $hex ?>"></div>
            <div class="fil-title">
              <b><?= e($title) ?></b>
              <span><?= e($t['color_name'] ?: 'color') ?> · <?= number_format($t['diameter_mm'], 2) ?>mm</span><br>
              <span class="fil-badge" style="background:<?= $hex ?>;color:<?= $txt ?>;"><?= e($t['material']) ?></span>
              <?php if ($t['temp_nozzle']): ?><span class="fil-badge" style="background:#2a2f37;color:#e6e6e6;"><?= (int) $t['temp_nozzle'] ?>°/<?= (int) $t['temp_bed'] ?>°</span><?php endif; ?>
            </div>
          </div>

          <div class="fil-spools">
            <?php if (!$t['spools']): ?>
              <div class="fil-spool" style="color:var(--muted,#8a8f98);">No spools logged.</div>
            <?php else: foreach ($t['spools'] as $s):
                $pct = $s['total_g'] > 0 ? max(0, min(100, ($s['remaining_g'] / $s['total_g']) * 100)) : 0;
                $barCol = $pct > 40 ? '#27ae60' : ($pct > 15 ? '#e67e22' : '#c0392b');
                $dim = in_array($s['status'], ['archived', 'empty'], true) ? 'opacity:.5;' : '';
            ?>
              <div class="fil-spool" style="<?= $dim ?>" data-spool='<?= e(json_encode($s, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>
                <div class="fil-spool-row">
                  <span><?= number_format($s['remaining_g']) ?> / <?= number_format($s['total_g']) ?> g
                    <?php if ($s['location']): ?><span style="color:var(--muted,#8a8f98);">· <?= e($s['location']) ?></span><?php endif; ?>
                  </span>
                  <span style="display:flex;gap:4px;">
                    <button class="fil-mini" data-act="use" data-id="<?= (int) $s['id'] ?>" title="Subtract grams used">− use</button>
                    <button class="fil-mini" data-act="edit-spool" data-id="<?= (int) $s['id'] ?>" title="Edit spool">✎</button>
                    <button class="fil-mini" data-act="del-spool" data-id="<?= (int) $s['id'] ?>" title="Delete spool">🗑</button>
                  </span>
                </div>
                <div class="fil-bar"><i style="width:<?= $pct ?>%;background:<?= $barCol ?>;"></i></div>
              </div>
            <?php endforeach; endif; ?>
          </div>

          <div class="fil-actions">
            <button class="fil-mini" data-act="add-to" data-id="<?= (int) $t['id'] ?>">+ spool</button>
            <button class="fil-mini" data-act="edit-type" data-id="<?= (int) $t['id'] ?>" style="margin-left:auto;">Edit type</button>
            <button class="fil-mini" data-act="del-type" data-id="<?= (int) $t['id'] ?>">Delete</button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </main>

  <script type="application/json" id="fil-catalog"><?= json_encode([
      'materials' => $materials, 'brands' => $brands, 'colors' => $colors, 'statuses' => $statuses,
  ], JSON_UNESCAPED_SLASHES) ?></script>

  <script>
  (function () {
    const CSRF = JSON.parse(document.getElementById('fil-csrf').textContent || '""');
    const CAT  = JSON.parse(document.getElementById('fil-catalog').textContent || '{}');
    const e = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    async function api(payload) {
      try {
        const r = await fetch('filament_action.php', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(Object.assign({ csrf: CSRF }, payload)),
        });
        if (r.status === 403) { location.reload(); return { ok: false }; }
        return await r.json();
      } catch (_) { return { ok: false, error: 'Request failed.' }; }
    }
    const reload = () => location.reload();

    // ---- Modal builder ----------------------------------------------------
    function modal(html) {
      const wrap = document.createElement('div');
      wrap.className = 'fil-modal';
      wrap.innerHTML = '<div class="fil-modal-card">' + html + '</div>';
      wrap.addEventListener('click', ev => { if (ev.target === wrap) wrap.remove(); });
      document.body.appendChild(wrap);
      return wrap;
    }
    const matOptions = sel => Object.keys(CAT.materials || {}).map(m => `<option ${m === sel ? 'selected' : ''}>${e(m)}</option>`).join('');
    const statusOptions = sel => (CAT.statuses || []).map(s => `<option ${s === sel ? 'selected' : ''}>${e(s)}</option>`).join('');

    // Brand <select> from the catalog, with a Custom… escape (keeps custom-allowed).
    function brandOptions(sel) {
      const brands = (CAT.brands || []).map(b => b.trim()).filter(Boolean);
      const known = brands.includes(sel);
      let html = '<option value="">— brand —</option>';
      html += brands.map(b => `<option ${b === sel ? 'selected' : ''}>${e(b)}</option>`).join('');
      html += `<option value="__custom__" ${(sel && !known) ? 'selected' : ''}>Custom…</option>`;
      return html;
    }
    // Color <select> of known names; picking one sets the swatch hex.
    function colorOptions(selName) {
      const colors = CAT.colors || {};
      let html = '<option value="">— color —</option>';
      html += Object.keys(colors).map(n => `<option ${n === selName ? 'selected' : ''}>${e(n)}</option>`).join('');
      html += `<option value="__custom__" ${(selName && !colors[selName]) ? 'selected' : ''}>Custom…</option>`;
      return html;
    }

    function typeFields(t) {
      t = t || {};
      const brands = (CAT.brands || []).map(b => b.trim());
      const brandCustom = t.brand && !brands.includes(t.brand);
      const colors = CAT.colors || {};
      const colorCustom = t.color_name && !colors[t.color_name];
      return `
        <div class="fil-row"><label>Brand</label>
          <div class="fil-two">
            <select id="f_brand_sel">${brandOptions(t.brand || '')}</select>
            <input id="f_brand_custom" value="${e(brandCustom ? t.brand : '')}" placeholder="Custom brand"
                   style="${brandCustom ? '' : 'display:none;'}">
          </div>
        </div>
        <div class="fil-row"><label>Material</label><select id="f_material">${matOptions(t.material || 'PLA')}</select></div>
        <div class="fil-row"><label>Color</label>
          <div class="fil-two">
            <select id="f_color_sel">${colorOptions(t.color_name || '')}</select>
            <input id="f_color_hex" type="color" value="${e(t.color_hex || '#cccccc')}" style="height:34px;padding:2px;" title="Swatch color">
          </div>
        </div>
        <div class="fil-row"><label>Diameter</label>
          <div class="fil-two">
            <select id="f_diameter"><option ${(+t.diameter_mm === 1.75 || !t.diameter_mm) ? 'selected' : ''}>1.75</option><option ${+t.diameter_mm === 2.85 ? 'selected' : ''}>2.85</option><option ${+t.diameter_mm === 3 ? 'selected' : ''}>3.00</option></select>
            <input id="f_density" type="number" step="0.01" value="${e(t.density || '')}" placeholder="density g/cm³">
          </div>
        </div>
        <div class="fil-row"><label>Temps °C</label>
          <div class="fil-two">
            <input id="f_nozzle" type="number" value="${e(t.temp_nozzle || '')}" placeholder="nozzle">
            <input id="f_bed" type="number" value="${e(t.temp_bed || '')}" placeholder="bed">
          </div>
        </div>
        <div class="fil-row"><label>Cost</label><input id="f_cost" type="number" step="0.01" value="${e(t.cost || '')}" placeholder="per spool"></div>`;
    }
    function readType() {
      const bsel = document.getElementById('f_brand_sel').value;
      const brand = bsel === '__custom__' ? document.getElementById('f_brand_custom').value : bsel;
      const csel = document.getElementById('f_color_sel').value;
      const color_name = (csel === '__custom__' || csel === '') ? '' : csel;
      return {
        brand,
        material: document.getElementById('f_material').value,
        color_name,
        color_hex: document.getElementById('f_color_hex').value,
        diameter_mm: document.getElementById('f_diameter').value,
        density: document.getElementById('f_density').value,
        temp_nozzle: document.getElementById('f_nozzle').value,
        temp_bed: document.getElementById('f_bed').value,
        cost: document.getElementById('f_cost').value,
      };
    }
    function spoolFields(s) {
      s = s || {};
      return `
        <div class="fil-row"><label>Total / left g</label>
          <div class="fil-two">
            <input id="s_total" type="number" value="${e(s.total_g || 1000)}">
            <input id="s_rem" type="number" value="${e(s.remaining_g != null ? s.remaining_g : (s.total_g || 1000))}">
          </div>
        </div>
        <div class="fil-row"><label>Location</label><input id="s_loc" value="${e(s.location || '')}" placeholder="Dry box A"></div>
        <div class="fil-row"><label>Purchased</label><input id="s_date" type="date" value="${e(s.purchase_date || '')}"></div>
        <div class="fil-row"><label>Status</label><select id="s_status">${statusOptions(s.status || 'active')}</select></div>`;
    }
    function readSpool() {
      return {
        total_g: document.getElementById('s_total').value,
        remaining_g: document.getElementById('s_rem').value,
        location: document.getElementById('s_loc').value,
        purchase_date: document.getElementById('s_date').value,
        status: document.getElementById('s_status').value,
      };
    }

    // Wire the type-form dynamics: material presets auto-fill diameter/density/
    // temps; the brand + color selects toggle their Custom… inputs and the color
    // select drives the swatch hex.
    function wireTypeForm() {
      const mat = document.getElementById('f_material');
      const applyMat = (force) => {
        const p = (CAT.materials || {})[mat.value];
        if (!p) return;
        const d = document.getElementById('f_density'), n = document.getElementById('f_nozzle'),
              b = document.getElementById('f_bed'), dia = document.getElementById('f_diameter');
        if (d && (force || !d.value)) d.value = p.density;
        if (n && (force || !n.value)) n.value = p.nozzle || '';
        if (b && (force || !b.value)) b.value = p.bed || '';
        if (dia && !dia.value) dia.value = '1.75';
      };
      if (mat) mat.addEventListener('change', () => applyMat(true));

      const bsel = document.getElementById('f_brand_sel'), bcustom = document.getElementById('f_brand_custom');
      if (bsel) bsel.addEventListener('change', () => {
        const custom = bsel.value === '__custom__';
        bcustom.style.display = custom ? '' : 'none';
        if (custom) bcustom.focus();
      });

      const csel = document.getElementById('f_color_sel'), chex = document.getElementById('f_color_hex');
      if (csel) csel.addEventListener('change', () => {
        const hex = (CAT.colors || {})[csel.value];
        if (hex && chex) chex.value = hex;   // known color sets the swatch
      });
    }

    // ---- Add spool (custom-allowed: type + spool in one step) --------------
    function openAddSpool(existingType) {
      const w = modal(`
        <h2 style="margin-top:0;">Add spool</h2>
        ${existingType ? '' : typeFields()}
        <hr style="border-color:var(--line,#2a2f37);margin:14px 0;">
        ${spoolFields()}
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px;">
          <button class="fil-mini" id="s_cancel">Cancel</button>
          <button class="lib-btn lib-btn-primary" id="s_save">Save</button>
        </div>`);
      wireTypeForm();
      w.querySelector('#s_cancel').onclick = () => w.remove();
      w.querySelector('#s_save').onclick = async () => {
        const payload = existingType
          ? { action: 'add_spool', type: existingType, spool: readSpool() }
          : { action: 'add_spool', type: readType(), spool: readSpool() };
        const d = await api(payload);
        if (d.ok) reload(); else alert(d.error || 'Save failed.');
      };
    }

    function openEditType(t) {
      const w = modal(`
        <h2 style="margin-top:0;">Edit type</h2>
        ${typeFields(t)}
        <div class="fil-row"><label>Notes</label><textarea id="f_notes" rows="2">${e(t.notes || '')}</textarea></div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px;">
          <button class="fil-mini" id="t_cancel">Cancel</button>
          <button class="lib-btn lib-btn-primary" id="t_save">Save</button>
        </div>`);
      wireTypeForm();
      w.querySelector('#t_cancel').onclick = () => w.remove();
      w.querySelector('#t_save').onclick = async () => {
        const body = Object.assign({ action: 'update_type', id: t.id, notes: document.getElementById('f_notes').value }, readType());
        const d = await api(body);
        if (d.ok) reload(); else alert(d.error || 'Save failed.');
      };
    }

    function openEditSpool(s) {
      const w = modal(`
        <h2 style="margin-top:0;">Edit spool</h2>
        ${spoolFields(s)}
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px;">
          <button class="fil-mini" id="e_cancel">Cancel</button>
          <button class="lib-btn lib-btn-primary" id="e_save">Save</button>
        </div>`);
      w.querySelector('#e_cancel').onclick = () => w.remove();
      w.querySelector('#e_save').onclick = async () => {
        const d = await api(Object.assign({ action: 'update_spool', id: s.id }, readSpool()));
        if (d.ok) reload(); else alert(d.error || 'Save failed.');
      };
    }

    // ---- Event delegation -------------------------------------------------
    document.getElementById('filAdd')?.addEventListener('click', () => openAddSpool(null));

    document.body.addEventListener('click', async ev => {
      const btn = ev.target.closest('[data-act]');
      if (btn) {
        const act = btn.dataset.act;
        const card = btn.closest('.fil-card');
        const type = card ? JSON.parse(card.dataset.type) : null;

        if (act === 'add-to' && type) return openAddSpool(type);
        if (act === 'edit-type' && type) return openEditType(type);
        if (act === 'del-type') {
          if (confirm('Delete this filament type? Its spools are archived, not lost.')) {
            const d = await api({ action: 'delete_type', id: +btn.dataset.id });
            if (d.ok) reload(); else alert(d.error || 'Delete failed.');
          }
          return;
        }
        if (act === 'edit-spool') {
          const s = JSON.parse(btn.closest('.fil-spool').dataset.spool);
          return openEditSpool(s);
        }
        if (act === 'del-spool') {
          if (confirm('Delete this spool?')) {
            const d = await api({ action: 'delete_spool', id: +btn.dataset.id });
            if (d.ok) reload(); else alert(d.error || 'Delete failed.');
          }
          return;
        }
        if (act === 'use') {
          const g = prompt('Grams used on this spool?');
          const n = parseFloat(g);
          if (Number.isFinite(n) && n > 0) {
            const d = await api({ action: 'adjust', id: +btn.dataset.id, delta: -n });
            if (d.ok) reload(); else alert(d.error || 'Update failed.');
          }
          return;
        }
      }
    });
  })();
  </script>
  <script src="js/theme.js"></script>
</body>
</html>
