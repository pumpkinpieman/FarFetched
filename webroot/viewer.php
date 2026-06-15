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
<style>
  :root{--bg:#0c0a08;--panel:#141009;--card:#1a140c;--ink:#f0e6d3;--muted:#7a6a52;--line:#2e2218;--clay:#ff6b1a;--clay-deep:#c44d0d;--ok:#c8a020;--err:#e05c5c;--warn:#f5c842;}
  body{background-image:radial-gradient(ellipse at 0% 100%, rgba(255,107,26,0.06) 0%, transparent 60%);}
  .brand{color:#ff6b1a !important;font-weight:800 !important;letter-spacing:-.5px;}
  nav a{color:#8a8070;}
  nav a:hover{background:#1a140c;color:#f0e6d3;}
  nav a.active{background:rgba(255,107,26,0.1);color:#ff6b1a;border:1px solid rgba(255,107,26,0.2);font-weight:600;}
  .msize{color:#f5c842 !important;}
  .btn-primary{background:#ff6b1a;color:#fff;} .btn-primary:hover{background:#c44d0d;}
  .btn-primary:disabled{background:#2e1a0a;color:#5a4a32;cursor:not-allowed;}
  .btn-ghost{color:#8a8070;border-color:#2e2218;} .btn-ghost:hover{border-color:#ff6b1a;color:#ff6b1a;}
  .srcBtn.active{background:rgba(245,200,66,0.08);color:#f5c842;}
  select{background:#1a140c;color:#f0e6d3;border-color:#2e2218;}
  .searchbar input,.pastebar-row input,textarea,input[type=text]{background:#1a140c;color:#f0e6d3;border-color:#2e2218;}
  .searchbar input:focus,textarea:focus,input:focus{border-color:#ff6b1a;box-shadow:0 0 0 2px rgba(255,107,26,0.15);}
  .card.sel{border-color:#ff6b1a;box-shadow:0 0 0 2px rgba(255,107,26,0.2);}
  .pick{accent-color:#ff6b1a;}
  .banner{background:#1a1000;color:#f5c842;border-color:#3d2800;}
  .notice.ok{background:#1a1200;color:#f5c842;}
  .notice.err{background:#1f0d0d;color:#e05c5c;}
  .badge.paid{background:#3d2000;color:#f5c842;}
  .tab-btn.active{color:#ff6b1a;border-bottom-color:#ff6b1a;}
  .stat,.panel,.src-card,.overall,table{background:#1a140c;border-color:#2e2218;}
  th{background:#141009;}
  .pill.fetch{background:#1a1200;color:#f5c842;}
  .pill{background:#1a140c;}
  a.tile:hover{border-color:#ff6b1a;box-shadow:0 0 0 2px rgba(255,107,26,0.15);}
  .track{background:#2e2218;} .fill{background:#ff6b1a;}
  .rowfill{background:#ff6b1a;} .rowfill.green{background:#c8a020;}
  .overall .live .dot{background:#ff6b1a;}
  .act button:hover{border-color:#ff6b1a;color:#ff6b1a;}
  .filebtn{background:#1a140c;border-color:#2e2218;color:#f0e6d3;}
  .filebtn:hover{border-color:#ff6b1a;}
  .filebtn.active{border-color:#f5c842;box-shadow:0 0 0 2px rgba(245,200,66,0.15);}
  .folder-hdr{border-color:#2e2218;color:#7a6a52;}
  .bar{background:#1a1000;color:#f5c842;border-color:#3d2800;}
  .file-counter{background:#2e2218;color:#f0e6d3;}
  .srcBtn{color:#7a6a52;}
  .navlabel{color:#5a4a32;}
  code{background:#1a140c;}
  .notice{background:#1a140c;}
  .step a{color:#ff6b1a;}
  .act button{background:#1a140c;border-color:#2e2218;color:#7a6a52;}
  .tag{background:#ff6b1a;}

  body{background-image:radial-gradient(circle,rgba(57,168,92,.06) 1px,transparent 1px);background-size:24px 24px;}
  .brand{color:#ff6b1a !important;font-family:ui-monospace,monospace !important;letter-spacing:-.5px;}
  nav a:hover{background:#1a140c;color:#e8ede9;}
  nav a.active{background:rgba(255,107,26,.1);color:#ff6b1a;border:1px solid rgba(57,168,92,.2);font-weight:500;}
  nav a:not(.active){color:#c8d4c9;}
  .msize{color:#f5a623 !important;}
  .btn-primary{background:#39a85c;color:#0a1a0e;} .btn-primary:hover{background:#2a7d44;}
  .btn-primary:disabled{background:#1c3023;color:#6b8070;cursor:not-allowed;}
  .btn-ghost{color:#c8d4c9;border-color:#2a3028;} .btn-ghost:hover{border-color:#ff6b1a;color:#ff6b1a;}
  .srcBtn.active{background:rgba(255,107,26,.1);color:#ff6b1a;}
  select{background:#1a140c;color:#e8ede9;border-color:#2a3028;}
  .searchbar input,.pastebar-row input,textarea,input[type=text]{background:#1a140c;color:#e8ede9;border-color:#2a3028;}
  .searchbar input:focus,textarea:focus,input:focus{border-color:#ff6b1a;box-shadow:0 0 0 2px rgba(255,107,26,.15);}
  .card.sel{border-color:#d4820a;box-shadow:0 0 0 2px rgba(255,107,26,.2);}
  .pick{accent-color:#d4820a;}
  .banner{background:#1a1500;color:#f5a623;border-color:#3d3000;}
  .notice.ok,.notice{background:#0d1f12;color:#ff6b1a;}
  .notice.err{background:#1f0d0d;color:#e05c5c;}
  .notice.warn{background:#1a1200;color:#d4820a;}
  .badge.paid{background:#3d2600;color:#f5a623;}
  .tab-btn.active{color:#ff6b1a;border-bottom-color:#ff6b1a;}
  .stat,.panel,.src-card,.overall,table{background:#1a140c;border-color:#2a3028;}
  th{background:#161a17;}
  .pill.fetch{background:#0d1f12;color:#ff6b1a;}
  .pill{background:#1a140c;}
  a.tile:hover{border-color:#ff6b1a;box-shadow:0 0 0 2px rgba(255,107,26,.15);}
  .track{background:#2a3028;} .fill{background:#39a85c;}
  .rowfill.green{background:#39a85c;}
  .overall .live .dot{background:#39a85c;}
  .act button:hover{border-color:#ff6b1a;color:#ff6b1a;}
  .filebtn{background:#1a140c;border-color:#2a3028;color:#e8ede9;}
  .filebtn:hover{border-color:#ff6b1a;}
  .filebtn.active{border-color:#d4820a;box-shadow:0 0 0 2px rgba(212,130,10,.2);}
  .folder-hdr{border-color:#2a3028;color:#6b8070;}
  .bar{background:#1a1500;color:#f5a623;border-color:#3d3000;}

  *{box-sizing:border-box;margin:0;padding:0;}
  body{font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;background:var(--bg);color:var(--ink);min-height:100vh;display:flex;}
  aside{width:240px;background:var(--panel);border-right:1px solid var(--line);padding:24px 16px;flex-shrink:0;}
  .brand{font-family:ui-serif,Georgia,serif;font-size:22px;font-weight:600;color:var(--clay-deep);letter-spacing:-0.4px;padding:0 8px 18px;}
  .navlabel{font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);padding:12px 12px 6px;}
  nav a{display:block;padding:9px 12px;margin-bottom:2px;border-radius:8px;color:var(--muted);text-decoration:none;font-size:14px;cursor:pointer;}
  nav a:hover{background:#1a140c;color:var(--ink);} nav a.active{background:var(--clay);color:#fff;font-weight:500;}
  main{flex:1;padding:28px 32px;min-width:0;display:flex;flex-direction:column;}
  h1{font-family:ui-serif,Georgia,serif;font-size:24px;font-weight:600;margin-bottom:4px;}
  .sub{color:var(--muted);font-size:14px;margin-bottom:20px;}
  .controls{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;margin-bottom:16px;}
  .field{display:flex;flex-direction:column;gap:5px;}
  .field label{font-size:12px;color:var(--muted);font-weight:600;}
  select{border:1px solid var(--line);border-radius:8px;padding:9px 11px;font-size:14px;background:var(--card);color:var(--ink);min-width:200px;}
  .files{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px;min-height:0;}
  .filebtn{border:1px solid var(--line);background:var(--card);color:var(--ink);border-radius:8px;padding:7px 12px;font-size:13px;cursor:pointer;display:flex;gap:8px;align-items:center;transition:border-color .15s,box-shadow .15s;}
  .filebtn:hover{border-color:var(--clay);}
  .filebtn.active{border-color:var(--clay);box-shadow:0 0 0 2px rgba(255,107,26,.15);}
  .filebtn .ext{font-size:10px;font-weight:700;text-transform:uppercase;color:#fff;background:var(--clay);border-radius:4px;padding:1px 5px;}
  .filebtn .sz{color:var(--muted);font-size:11px;}
  .folder-hdr{width:100%;font-size:12px;font-weight:600;color:var(--muted);padding:6px 2px 3px;border-bottom:1px solid var(--line);margin-bottom:2px;letter-spacing:.03em;}
  .hint{color:var(--muted);font-size:13px;}
  .stage{position:relative;flex:1;min-height:380px;background:#1c1b1a;border:1px solid var(--line);border-radius:12px;overflow:hidden;}
  #canvas-wrap{position:absolute;inset:0;}
  canvas{display:block;width:100%;height:100%;}
  .overlay{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#c9c6c0;font-size:14px;text-align:center;padding:20px;pointer-events:none;}
  .overlay.err{color:#ff9b86;}
  .spinner{width:30px;height:30px;border:3px solid rgba(217,119,87,.3);border-top-color:var(--clay);border-radius:50%;animation:spin .8s linear infinite;margin:0 auto 12px;}
  @keyframes spin{to{transform:rotate(360deg);}}
  .meta{margin-top:10px;font-size:12px;color:var(--muted);min-height:16px;}
  @media (max-width:640px){aside{width:170px;}main{padding:20px 16px;}select{min-width:140px;}}
</style>
</head>
<body>
  <aside>
    <div class="brand"><img src="logo.svg" alt="FarFetched" style="height:1.15em;width:auto;vertical-align:-.2em;margin-right:7px"> FarFetched</div>
    <div class="navlabel">Tool</div>
    <nav>
      <a href="home.php">← Sources</a>
      <a href="index.php">Printables</a>
      <a href="index.php?src=makerworld">MakerWorld</a>
      <a href="jobs.php">Queue</a>
      <a href="viewer.php" class="active">3D Viewer</a>
      <a href="settings.php">Settings</a>
    </nav>
  </aside>

  <main>
    <h1>3D Viewer</h1>
    <div class="sub">Preview your downloaded STL &amp; 3MF files. Drag to orbit, scroll to zoom.</div>

    <div class="controls">
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
      <div class="field">
        <label for="model">Model</label>
        <select id="model" disabled><option value="">— select a source —</option></select>
      </div>
    </div>

    <div id="files" class="files"><span class="hint">Pick a source and model to list its files.</span></div>

    <div class="stage">
      <div id="canvas-wrap"></div>
      <div class="overlay" id="overlay">Select a file above to render it here.</div>
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

    const renderer = new THREE.WebGLRenderer({ antialias: true });
    renderer.setPixelRatio(window.devicePixelRatio || 1);
    wrap.appendChild(renderer.domElement);

    const controls = new OrbitControls(camera, renderer.domElement);
    controls.enableDamping = true;
    controls.dampingFactor = 0.08;

    scene.add(new THREE.HemisphereLight(0xffffff, 0x444444, 1.05));
    const key = new THREE.DirectionalLight(0xffffff, 1.4);
    key.position.set(1, 1.4, 1);
    scene.add(key);
    const fill = new THREE.DirectionalLight(0xffffff, 0.5);
    fill.position.set(-1, -0.5, -1);
    scene.add(fill);

    const grid = new THREE.GridHelper(400, 40, 0x555049, 0x33302c);
    scene.add(grid);

    const material = new THREE.MeshStandardMaterial({ color: 0xD97757, metalness: 0.05, roughness: 0.65, flatShading: false });

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
    }

    function showOverlay(text, isErr) {
      overlay.style.display = 'flex';
      overlay.className = 'overlay' + (isErr ? ' err' : '');
      overlay.innerHTML = text;
    }
    function hideOverlay() { overlay.style.display = 'none'; }

    function loadFile(url, ext, label) {
      showOverlay('<div><div class="spinner"></div>Loading ' + label + '…</div>', false);
      const onErr = (e) => { showOverlay('Could not load this file.<br>' + (e?.message || ''), true); };

      if (ext === 'stl') {
        new STLLoader().load(url, (geometry) => {
          clearModel();
          geometry.computeVertexNormals();
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
              if (o.geometry?.attributes?.color) {
                o.geometry.deleteAttribute('color');
              }
              o.material = material;
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
        const mesh = new THREE.Mesh(part.geometry, material);
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
    srcSel.addEventListener('change', () => {
      const src = srcSel.value;
      modelSel.innerHTML = '';
      filesEl.innerHTML = '<span class="hint">Pick a model to list its files.</span>';
      if (!src || !SOURCES[src]) {
        modelSel.disabled = true;
        modelSel.innerHTML = '<option value="">— select a source —</option>';
        return;
      }
      modelSel.disabled = false;
      modelSel.appendChild(new Option('— select —', ''));
      for (const name of SOURCES[src]) modelSel.appendChild(new Option(name, name));
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
                            '<span>' + f.name.replace(/</g, '&lt;') + '</span>' +
                            '<span class="sz">' + fmtSize(f.size) + '</span>';
            btn.addEventListener('click', () => {
              if (f.size > 5 * 1024 * 1024) {
                if (!confirm('This is a large file: ' + fmtSize(f.size) + '\n\nAre you sure you want to load it?')) return;
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

    resize();
  </script>
</body>
</html>
