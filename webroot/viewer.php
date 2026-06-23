<?php
declare(strict_types=1);

/**
 * viewer.php — in-browser 3D viewer for locally downloaded STL/3MF files.
 *
 * Flow: pick a source -> pick a model -> the model's .stl/.3mf files are listed
 * (lazy-fetched from model_file.php?list=1) -> click any file to render it.
 *
 * Rendering is fully client-side (Three.js + STLLoader + 3MFLoader, ES modules
 * via CDN importmap). The server only streams raw file bytes through the
 * path-safe model_file.php endpoint. Requires internet for the CDN modules;
 * everything else is local.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth.php';
require_auth();

$csrf = csrf_token();

// Build a source -> [folder model names] map for the dropdowns. Loose .zip
// "models" are excluded: there are no extracted files to view yet.
$sources = list_sources();
$map = [];
foreach ($sources as $s) {
    $models = [];
    foreach (list_models($s['path']) as $m) {
        if ($m['kind'] === 'folder' && (int) $m['files'] > 0) {
            $models[] = $m['name'];
        }
    }
    if ($models !== []) {
        $map[$s['slug']] = $models;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Fetcher · 3D Viewer</title>
<link rel="stylesheet" href="css/styles.css">
<link rel="stylesheet" href="css/styles_viewer.css">

</head>
<body>
  <aside>
    <div class="brand"><img src="logo.svg" alt="FarFetched" style="height:1.15em;width:auto;vertical-align:-.2em;margin-right:7px"> FarFetched</div>
    <div class="navlabel">Tool</div>
    <nav>
      <a href="index.php">Browse Models</a>
      <a href="jobs.php">Queue</a>
      <a href="viewer.php" class="active">3D Viewer</a>
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

  <!-- File tree panel: sits between nav aside and the 3D canvas -->
  <div class="file-panel">
    <div class="file-panel-head">
      <h1>3D Viewer</h1>
      <div class="sub">Drag to orbit, scroll to zoom.</div>
    </div>

    <div class="fp-section">
      <div class="field">
        <label for="src">Source</label>
        <select id="src">
          <option value="">— select —</option>
          <?php
            $srcLabels = ['makerworld' => 'MakerWorld', 'printables' => 'Printables', 'stlflix' => 'STLFlix'];
            foreach (array_keys($map) as $slug):
          ?>
            <option value="<?= e($slug) ?>"><?= e($srcLabels[$slug] ?? ucfirst($slug)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field" style="margin-top:10px;">
        <label for="model">Model</label>
        <select id="model" disabled><option value="">— select a source —</option></select>
      </div>
      <div style="margin-top:10px;">
        <button id="deleteBtn" class="btn-delete" disabled>🗑 Delete</button>
      </div>
    </div>

    <div class="fp-divider"></div>

    <div id="files" class="fp-files"><span class="hint">Pick a source and model.</span></div>
  </div>

  <main>
    <!-- Delete modal -->
    <div id="deleteModal" class="modal-backdrop" hidden>
      <div class="modal">
        <div class="modal-head">
          <span id="deleteModalTitle">Delete models</span>
          <button class="modal-close" id="deleteCancel">&times;</button>
        </div>
        <div class="modal-tools">
          <label class="selall"><input type="checkbox" id="deleteSelectAll"> Select all</label>
          <span id="deleteCount" class="muted">0 selected</span>
        </div>
        <div id="deleteList" class="delete-list"></div>
        <div class="modal-foot">
          <button class="btn-ghost" id="deleteCancel2">Cancel</button>
          <button class="btn-delete" id="deleteConfirm" disabled>Delete selected</button>
        </div>
      </div>
    </div>

    <div class="stage">
      <div id="canvas-wrap"></div>
      <div id="fitBanner" class="fit-banner" hidden></div>
      <div class="overlay" id="overlay">Select a file to render it here.</div>
    </div>
    <div class="meta" id="meta"></div>
  </main>

  <?php if ($map === []): ?>
  <script>document.getElementById('files').innerHTML =
    '<span class="hint">No viewable models found yet. Download some STL/3MF files first.</span>';</script>
  <?php endif; ?>

  <script id="sources-data" type="application/json"><?= json_encode($map, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

  <script type="importmap">
  {
    "imports": {
      "three": "https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.module.js",
      "three/addons/": "https://cdn.jsdelivr.net/npm/three@0.160.0/examples/jsm/"
    }
  }
  </script>

  <script type="module">
    import * as THREE from 'three';
    import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
    import { STLLoader } from 'three/addons/loaders/STLLoader.js';
    import { ThreeMFLoader } from 'three/addons/loaders/3MFLoader.js';
    import * as fflate from 'three/addons/libs/fflate.module.js';

    const SOURCES = JSON.parse(document.getElementById('sources-data').textContent || '{}');
    const srcSel  = document.getElementById('src');
    const modelSel = document.getElementById('model');
    const filesEl = document.getElementById('files');
    const overlay = document.getElementById('overlay');
    const metaEl  = document.getElementById('meta');
    const wrap    = document.getElementById('canvas-wrap');

    // ---- Three.js scene -----------------------------------------------------
    const scene = new THREE.Scene();
    scene.background = new THREE.Color(0x1c1b1a);

    const camera = new THREE.PerspectiveCamera(45, 1, 0.1, 5000);
    camera.position.set(80, 80, 120);

    const renderer = new THREE.WebGLRenderer({ antialias: true, preserveDrawingBuffer: true });
    renderer.setPixelRatio(window.devicePixelRatio || 1);
    renderer.outputColorSpace = THREE.SRGBColorSpace;
    // Roll off bright highlights so light-coloured models (esp. the gray 3MF
    // material) shade smoothly instead of blowing out to flat white on top faces.
    renderer.toneMapping = THREE.ACESFilmicToneMapping;
    renderer.toneMappingExposure = 1.15;
    wrap.appendChild(renderer.domElement);

    const controls = new OrbitControls(camera, renderer.domElement);
    controls.enableDamping = true;
    controls.dampingFactor = 0.08;

    scene.add(new THREE.HemisphereLight(0xffffff, 0x555555, 1.6));
    const key = new THREE.DirectionalLight(0xffffff, 2.0);
    key.position.set(1, 1.4, 1);
    scene.add(key);
    const fill = new THREE.DirectionalLight(0xffffff, 0.7);
    fill.position.set(-1, -0.5, -1);
    scene.add(fill);
    // Rim from behind/above so silhouettes separate from the dark backdrop.
    const rim = new THREE.DirectionalLight(0xffffff, 0.6);
    rim.position.set(-0.5, 1, -1.5);
    scene.add(rim);

    const grid = new THREE.GridHelper(400, 40, 0x555049, 0x33302c);
    scene.add(grid);

    // STL: flat shading keeps hard mechanical edges crisp (most functional
    // prints). 3MF: smooth, since those are often organic / multi-part. Both
    // double-sided so triangles with reversed winding still render (no black
    // gaps / holes from inconsistent face orientation).
    const material    = new THREE.MeshStandardMaterial({ color: 0xff6b1a, metalness: 0.05, roughness: 0.65, flatShading: true,  side: THREE.DoubleSide });
    const material3mf = new THREE.MeshStandardMaterial({ color: 0xff6b1a, metalness: 0.05, roughness: 0.65, flatShading: false, side: THREE.DoubleSide });

    let current = null; // currently displayed mesh/group

    function resize() {
      const w = wrap.clientWidth, h = wrap.clientHeight;
      if (w === 0 || h === 0) return;
      renderer.setSize(w, h, false);
      camera.aspect = w / h;
      camera.updateProjectionMatrix();
    }
    window.addEventListener('resize', resize);
    new ResizeObserver(resize).observe(wrap);

    (function animate() {
      requestAnimationFrame(animate);
      controls.update();
      renderer.render(scene, camera);
    })();

    function clearModel() {
      if (!current) return;
      scene.remove(current);
      current.traverse?.(o => { o.geometry?.dispose?.(); });
      current = null;
    }

    function frameObject(obj) {
      // STL/3MF are authored Z-up (3D-printing convention); three.js is Y-up.
      // Rotate so the model stands upright instead of lying on its back.
      obj.rotation.x = -Math.PI / 2;
      obj.updateMatrixWorld(true);

      const box = new THREE.Box3().setFromObject(obj);
      if (box.isEmpty()) return;
      const size = box.getSize(new THREE.Vector3());
      const center = box.getCenter(new THREE.Vector3());

      // Seat the model ON the grid: centered on x/z, base resting at y = 0.
      obj.position.set(-center.x, -box.min.y, -center.z);

      // Fit the camera to the model's size, looking at its mid-height.
      const maxDim = Math.max(size.x, size.y, size.z) || 1;
      const dist = maxDim / (2 * Math.tan((Math.PI * camera.fov) / 360));
      camera.position.set(maxDim * 0.9, size.y * 0.6 + maxDim * 0.5, dist * 1.6);
      camera.near = maxDim / 100;
      camera.far = maxDim * 100;
      camera.updateProjectionMatrix();
      controls.target.set(0, size.y / 2, 0);
      controls.update();
      checkFit(size.x, size.y, size.z);
    }

    function showOverlay(text, isErr) {
      overlay.style.display = 'flex';
      overlay.className = 'overlay' + (isErr ? ' err' : '');
      overlay.innerHTML = text;
    }
    function hideOverlay() { overlay.style.display = 'none'; }

    // ===== Print-bed fit checker =====
    let enabledPrinters = null;
    const fitBanner = document.getElementById('fitBanner');
    async function ensurePrinters() {
      if (enabledPrinters !== null) return enabledPrinters;
      try {
        const r = await fetch('printers_enabled.php');
        const d = await r.json();
        enabledPrinters = d.ok ? d.printers : [];
      } catch (_) { enabledPrinters = []; }
      return enabledPrinters;
    }
    async function checkFit(x, y, z) {
      if (!fitBanner) return;
      const printers = await ensurePrinters();
      if (!printers.length) { fitBanner.hidden = true; return; }
      // A model fits a printer if its footprint fits in any orientation on X/Y
      // and height <= Z. Try both footprint rotations.
      const dims = [x, y, z].map(v => Math.round(v));
      const fitsOn = (p) => {
        const fitXY = (x <= p.x && y <= p.y) || (y <= p.x && x <= p.y);
        return fitXY && z <= p.z;
      };
      const okPrinters = printers.filter(fitsOn);
      const label = (p) => p.nickname ? (p.nickname + ' (' + p.name + ')') : p.name;
      if (okPrinters.length === printers.length) {
        fitBanner.className = 'fit-banner ok';
        fitBanner.innerHTML = '✓ Fits all your printers &nbsp;·&nbsp; model is ' + dims[0] + ' × ' + dims[1] + ' × ' + dims[2] + ' mm';
      } else if (okPrinters.length > 0) {
        fitBanner.className = 'fit-banner warn';
        fitBanner.innerHTML = '⚠ Fits: ' + okPrinters.map(label).join(', ') +
          ' &nbsp;·&nbsp; too big for ' + printers.filter(p => !fitsOn(p)).map(label).join(', ') +
          ' &nbsp;·&nbsp; ' + dims[0] + ' × ' + dims[1] + ' × ' + dims[2] + ' mm';
      } else {
        fitBanner.className = 'fit-banner bad';
        fitBanner.innerHTML = '✕ Too big for all your printers &nbsp;·&nbsp; model is ' +
          dims[0] + ' × ' + dims[1] + ' × ' + dims[2] + ' mm';
      }
      fitBanner.hidden = false;
    }

    function loadFile(url, ext, label) {      showOverlay('<div><div class="spinner"></div>Loading ' + label + '…</div>', false);
      const onErr = (e) => { showOverlay('Could not load this file.<br>' + (e?.message || ''), true); };

      if (ext === 'stl') {
        new STLLoader().load(url, (geometry) => {
          clearModel();
          // Binary STLs ship per-face normals; ASCII STLs may not. Only compute
          // when missing — flatShading on the material does the faceting, so we
          // avoid smearing hard edges into smooth ones.
          if (!geometry.attributes.normal) geometry.computeVertexNormals();
          geometry.computeBoundingBox();
          const mesh = new THREE.Mesh(geometry, material);
          scene.add(mesh);
          current = mesh;
          frameObject(mesh);
          hideOverlay();
        }, undefined, onErr);
      } else { // 3mf — try the official loader, fall back to a robust parser
        new ThreeMFLoader().load(url, (object) => {
          clearModel();
          object.traverse(o => {
            if (o.isMesh) {
              // Strip vertex colors that cause black rendering
              if (o.geometry?.attributes?.color) {
                o.geometry.deleteAttribute('color');
              }
              // Recompute missing normals so slicer 3MFs don't shade black.
              if (o.geometry && !o.geometry.attributes.normal) {
                o.geometry.computeVertexNormals();
              }
              o.material = material3mf;
              o.material.needsUpdate = true;
            }
          });
          scene.add(object);
          current = object;
          frameObject(object);
          hideOverlay();
        }, undefined, () => {
          // ThreeMFLoader chokes on some slicer 3MFs (production-extension
          // build/component graphs). Fall back to reading raw mesh geometry
          // straight from the zip — geometry-only, but it always renders.
          robust3MF(url).then((group) => {
            if (!group || group.children.length === 0) {
              showOverlay('No renderable geometry found in this 3MF.', true);
              return;
            }
            clearModel();
            scene.add(group);
            current = group;
            frameObject(group);
            hideOverlay();
          }).catch((e) => {
            showOverlay('Could not load this 3MF.<br>' + (e?.message || ''), true);
          });
        });
      }
    }

    // ---- Robust 3MF fallback ------------------------------------------------
    // Parses the 3MF (a zip of XML) directly: unzip -> find .model parts ->
    // read every <object>'s mesh, resolve <build> items + <component> refs with
    // their transforms, and skip anything missing instead of throwing.
    async function robust3MF(url) {
      const buf = new Uint8Array(await (await fetch(url)).arrayBuffer());
      const files = fflate.unzipSync(buf);
      const dec = new TextDecoder();

      // Collect every object (across all .model parts) into one id->object map,
      // and remember which doc carries the <build> section (the root).
      const objMap = {};
      let rootDoc = null;
      for (const name of Object.keys(files)) {
        if (!/\.model$/i.test(name)) continue;
        const doc = new DOMParser().parseFromString(dec.decode(files[name]), 'application/xml');
        const objs = doc.getElementsByTagNameNS('*', 'object');
        for (let i = 0; i < objs.length; i++) {
          const id = objs[i].getAttribute('id');
          if (id == null) continue;
          const meshEl = objs[i].getElementsByTagNameNS('*', 'mesh')[0] || null;
          const comps = objs[i].getElementsByTagNameNS('*', 'component');
          if (!(id in objMap)) objMap[id] = { meshEl, comps, geom: null };
        }
        if (!rootDoc && doc.getElementsByTagNameNS('*', 'build').length) rootDoc = doc;
      }

      const out = []; // {geometry, matrix}
      const resolve = (id, mat, depth) => {
        if (depth > 24) return;
        const o = objMap[id];
        if (!o) return; // missing ref -> skip (this is what the loader crashes on)
        if (o.meshEl) {
          if (!o.geom) o.geom = meshToGeometry(o.meshEl);
          if (o.geom) out.push({ geometry: o.geom, matrix: mat });
        }
        if (o.comps) {
          for (let i = 0; i < o.comps.length; i++) {
            const cid = o.comps[i].getAttribute('objectid');
            const cm = parseMatrix(o.comps[i].getAttribute('transform'));
            resolve(cid, mat.clone().multiply(cm), depth + 1);
          }
        }
      };

      const items = rootDoc ? rootDoc.getElementsByTagNameNS('*', 'item') : [];
      for (let i = 0; i < items.length; i++) {
        resolve(items[i].getAttribute('objectid'), parseMatrix(items[i].getAttribute('transform')), 0);
      }
      // Fallback: no resolvable build items -> just render every mesh at origin.
      if (out.length === 0) {
        for (const id in objMap) {
          if (objMap[id].meshEl) {
            const g = objMap[id].geom || meshToGeometry(objMap[id].meshEl);
            if (g) out.push({ geometry: g, matrix: new THREE.Matrix4() });
          }
        }
      }

      const group = new THREE.Group();
      for (const part of out) {
        const mesh = new THREE.Mesh(part.geometry, material3mf);
        mesh.applyMatrix4(part.matrix);
        group.add(mesh);
      }
      return group;
    }

    function meshToGeometry(meshEl) {
      const vEls = meshEl.getElementsByTagNameNS('*', 'vertex');
      const tEls = meshEl.getElementsByTagNameNS('*', 'triangle');
      if (vEls.length === 0 || tEls.length === 0) return null;
      const pos = new Float32Array(vEls.length * 3);
      for (let i = 0; i < vEls.length; i++) {
        pos[i * 3]     = parseFloat(vEls[i].getAttribute('x')) || 0;
        pos[i * 3 + 1] = parseFloat(vEls[i].getAttribute('y')) || 0;
        pos[i * 3 + 2] = parseFloat(vEls[i].getAttribute('z')) || 0;
      }
      const idx = new Uint32Array(tEls.length * 3);
      for (let i = 0; i < tEls.length; i++) {
        idx[i * 3]     = parseInt(tEls[i].getAttribute('v1'), 10) || 0;
        idx[i * 3 + 1] = parseInt(tEls[i].getAttribute('v2'), 10) || 0;
        idx[i * 3 + 2] = parseInt(tEls[i].getAttribute('v3'), 10) || 0;
      }
      const g = new THREE.BufferGeometry();
      g.setAttribute('position', new THREE.BufferAttribute(pos, 3));
      g.setIndex(new THREE.BufferAttribute(idx, 1));
      g.computeVertexNormals();
      return g;
    }

    // 3MF transform = 12 numbers (4x3, row-vector convention). Convert to a
    // THREE.Matrix4 the same way ThreeMFLoader does.
    function parseMatrix(str) {
      const m = new THREE.Matrix4();
      if (!str) return m;
      const v = str.trim().split(/\s+/).map(Number);
      if (v.length !== 12 || v.some((n) => Number.isNaN(n))) return m;
      m.set(
        v[0], v[3], v[6], v[9],
        v[1], v[4], v[7], v[10],
        v[2], v[5], v[8], v[11],
        0, 0, 0, 1
      );
      return m;
    }

    // ---- Pickers ------------------------------------------------------------
    // ---- Delete flow: element refs (declared before srcSel handler uses them)
    const CSRF        = <?= json_encode($csrf) ?>;
    const deleteBtn   = document.getElementById('deleteBtn');
    const modal       = document.getElementById('deleteModal');
    const listEl      = document.getElementById('deleteList');
    const selAll      = document.getElementById('deleteSelectAll');
    const countEl     = document.getElementById('deleteCount');
    const confirmBtn  = document.getElementById('deleteConfirm');
    const titleEl     = document.getElementById('deleteModalTitle');

    srcSel.addEventListener('change', () => {
      const src = srcSel.value;
      modelSel.innerHTML = '';
      filesEl.innerHTML = '<span class="hint">Pick a model to list its files.</span>';
      // Delete button is active whenever a source with at least one model is chosen.
      deleteBtn.disabled = !(src && SOURCES[src] && SOURCES[src].length);
      if (!src || !SOURCES[src]) {
        modelSel.disabled = true;
        modelSel.innerHTML = '<option value="">— select a source —</option>';
        return;
      }
      modelSel.disabled = false;
      modelSel.appendChild(new Option('— select —', ''));
      for (const name of SOURCES[src]) modelSel.appendChild(new Option(name, name));
    });

    // ---- Delete flow: behavior -----------------------------------------------
    function closeModal() { modal.hidden = true; }
    document.getElementById('deleteCancel').addEventListener('click', closeModal);
    document.getElementById('deleteCancel2').addEventListener('click', closeModal);
    modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });

    function refreshDeleteState() {
      const boxes = [...listEl.querySelectorAll('input[type=checkbox]')];
      const checked = boxes.filter(b => b.checked);
      countEl.textContent = checked.length + ' selected';
      confirmBtn.disabled = checked.length === 0;
      selAll.checked = boxes.length > 0 && checked.length === boxes.length;
      selAll.indeterminate = checked.length > 0 && checked.length < boxes.length;
    }

    deleteBtn.addEventListener('click', () => {
      const src = srcSel.value;
      if (!src || !SOURCES[src] || !SOURCES[src].length) return;
      titleEl.textContent = 'Delete models — ' + src;
      listEl.innerHTML = '';
      for (const name of SOURCES[src]) {
        const row = document.createElement('label');
        row.className = 'delete-item';
        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.value = name;
        cb.addEventListener('change', refreshDeleteState);
        const span = document.createElement('span');
        span.textContent = name;
        row.appendChild(cb);
        row.appendChild(span);
        listEl.appendChild(row);
      }
      selAll.checked = false;
      selAll.indeterminate = false;
      refreshDeleteState();
      modal.hidden = false;
    });

    selAll.addEventListener('change', () => {
      listEl.querySelectorAll('input[type=checkbox]').forEach(b => { b.checked = selAll.checked; });
      refreshDeleteState();
    });

    confirmBtn.addEventListener('click', async () => {
      const src = srcSel.value;
      const models = [...listEl.querySelectorAll('input[type=checkbox]:checked')].map(b => b.value);
      if (models.length === 0) return;

      if (!confirm('Are you sure you want to Delete? This cannot be recovered!')) return;

      confirmBtn.disabled = true;
      confirmBtn.textContent = 'Deleting…';
      try {
        const res = await fetch('model_delete.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ csrf: CSRF, src, models })
        });
        const data = await res.json();
        if (!data.ok && (!data.deleted || data.deleted.length === 0)) {
          alert('Delete failed: ' + (data.error || (data.errors && data.errors[0] && data.errors[0].error) || 'unknown error'));
          confirmBtn.disabled = false;
          confirmBtn.textContent = 'Delete selected';
          return;
        }
        // Remove deleted models from the in-memory map + dropdown.
        const deleted = new Set(data.deleted || []);
        SOURCES[src] = (SOURCES[src] || []).filter(n => !deleted.has(n));
        // Rebuild model dropdown.
        modelSel.innerHTML = '';
        if (SOURCES[src].length) {
          modelSel.disabled = false;
          modelSel.appendChild(new Option('— select —', ''));
          for (const name of SOURCES[src]) modelSel.appendChild(new Option(name, name));
        } else {
          modelSel.disabled = true;
          modelSel.innerHTML = '<option value="">— no models —</option>';
          deleteBtn.disabled = true;
        }
        filesEl.innerHTML = '<span class="hint">Pick a model to list its files.</span>';
        closeModal();
        if (data.errors && data.errors.length) {
          alert('Deleted ' + (data.deleted || []).length + ' model(s). ' + data.errors.length + ' could not be deleted.');
        }
      } catch (err) {
        alert('Delete request failed: ' + err.message);
      } finally {
        confirmBtn.disabled = false;
        confirmBtn.textContent = 'Delete selected';
      }
    });

    modelSel.addEventListener('change', async () => {
      const src = srcSel.value, model = modelSel.value;
      if (!src || !model) { filesEl.innerHTML = '<span class="hint">Pick a model to list its files.</span>'; return; }
      filesEl.innerHTML = '<span class="hint">Listing files…</span>';
      try {
        const res = await fetch('model_file.php?src=' + encodeURIComponent(src) + '&model=' + encodeURIComponent(model) + '&list=1');
        const list = await res.json();
        if (!Array.isArray(list) || list.length === 0) {
          filesEl.innerHTML = '<span class="hint">No STL/3MF files in this model.</span>';
          return;
        }
        filesEl.innerHTML = '';

        // Group files by subfolder for folder-aware display.
        const grouped = {};
        for (const f of list) {
          const slash = f.rel.lastIndexOf('/');
          const folder = slash > -1 ? f.rel.substring(0, slash) : '';
          if (!grouped[folder]) grouped[folder] = [];
          grouped[folder].push(f);
        }
        const folders = Object.keys(grouped).sort();
        const hasSubfolders = folders.some(k => k !== '');

        for (const folder of folders) {
          if (hasSubfolders && folder !== '') {
            const hdr = document.createElement('div');
            hdr.className = 'folder-hdr';
            hdr.innerHTML = '\u{1F4C1} ' + folder.replace(/</g, '&lt;');
            filesEl.appendChild(hdr);
          }
          for (const f of grouped[folder]) {
            const btn = document.createElement('button');
            btn.className = 'filebtn';
            btn.innerHTML = '<span class="ext">' + f.ext + '</span>' +
                            '<span class="fname">' + f.name.replace(/</g, '&lt;') + '</span>' +
                            '<span class="sz">' + fmtSize(f.size) + '</span>';
            btn.addEventListener('click', () => {
              if (f.size > 50 * 1024 * 1024) {
                if (!confirm('This is a very large file: ' + fmtSize(f.size) + '\n\nAre you sure you want to load it?')) return;
              }
              document.querySelectorAll('.filebtn.active').forEach(b => b.classList.remove('active'));
              btn.classList.add('active');
              const url = 'model_file.php?src=' + encodeURIComponent(src) +
                          '&model=' + encodeURIComponent(model) +
                          '&file=' + encodeURIComponent(f.rel);
              metaEl.textContent = f.rel + ' · ' + fmtSize(f.size);
              loadFile(url, f.ext, f.name);
            });
            filesEl.appendChild(btn);
          }
        }
      } catch (e) {
        filesEl.innerHTML = '<span class="hint">Failed to list files.</span>';
      }
    });

    function fmtSize(b) {
      if (!b) return '';
      const u = ['B', 'KB', 'MB', 'GB']; let i = 0, n = b;
      while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
      return n.toFixed(n < 10 && i > 0 ? 1 : 0) + ' ' + u[i];
    }

    // ---- Deep link: viewer.php?src=<slug>&model=<folder> --------------------
    // Auto-selects the source + model and renders the first file. Chains through
    // the async file-list fetch by waiting for the first .filebtn to appear.
    (function deepLink() {
      const qs = new URLSearchParams(location.search);
      const dlSrc = qs.get('src');
      const dlModel = qs.get('model');
      if (!dlSrc || !dlModel) return;
      if (!SOURCES[dlSrc] || !SOURCES[dlSrc].includes(dlModel)) return;

      // Stage 1: select source, fire change to populate the model dropdown.
      srcSel.value = dlSrc;
      srcSel.dispatchEvent(new Event('change'));

      // Stage 2: select model, fire change to fetch + render the file list.
      modelSel.value = dlModel;
      modelSel.dispatchEvent(new Event('change'));

      // Stage 3: the file list loads async — wait for the first file button,
      // then click it so the model renders without another user action.
      let tries = 0;
      const timer = setInterval(() => {
        const first = filesEl.querySelector('.filebtn');
        if (first) { clearInterval(timer); first.click(); return; }
        if (++tries > 100) clearInterval(timer); // ~10s safety cap
      }, 100);
    })();

    resize();
  </script>
<script>
  const toggleBtn = document.getElementById('theme-toggle');
  const toggleIcon = document.getElementById('theme-toggle-icon');

  // Check for saved user preference, otherwise default to dark
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

  <script src="js/theme.js"></script>
</body>
</html>
