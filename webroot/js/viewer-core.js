// viewer-core.js — reusable STL/3MF viewer engine.
//
// Encapsulates the Three.js scene, lighting, materials, loaders, the robust
// 3MF fallback parser, auto-framing, and PNG capture into a single factory so
// both the full-page viewer and the library thumbnail modal share one
// implementation. ES module; requires the same import map as viewer.php
// (three + three/addons).

import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { STLLoader } from 'three/addons/loaders/STLLoader.js';
import { ThreeMFLoader } from 'three/addons/loaders/3MFLoader.js';
import * as fflate from 'three/addons/libs/fflate.module.js';

// 3MF transform = 12 numbers (4x3, row-vector convention) -> THREE.Matrix4,
// matching ThreeMFLoader's convention.
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

/**
 * Create a viewer bound to a container element.
 *
 * options:
 *   background  : scene background colour (default 0x1c1b1a)
 *   showGrid    : draw the ground grid (default true)
 *   onLoad/onError : optional callbacks
 *
 * Returns an API: { loadFile, capturePNG, resize, dispose, hasModel }.
 */
export function createViewer(container, options = {}) {
  const opts = Object.assign({ background: 0x1c1b1a, showGrid: true }, options);

  const scene = new THREE.Scene();
  scene.background = new THREE.Color(opts.background);

  const camera = new THREE.PerspectiveCamera(45, 1, 0.1, 5000);
  camera.position.set(80, 80, 120);

  const renderer = new THREE.WebGLRenderer({ antialias: true, preserveDrawingBuffer: true });
  renderer.setPixelRatio(window.devicePixelRatio || 1);
  renderer.outputColorSpace = THREE.SRGBColorSpace;
  renderer.toneMapping = THREE.ACESFilmicToneMapping;
  renderer.toneMappingExposure = 1.15;
  container.appendChild(renderer.domElement);

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
  const rim = new THREE.DirectionalLight(0xffffff, 0.6);
  rim.position.set(-0.5, 1, -1.5);
  scene.add(rim);

  let grid = null;
  if (opts.showGrid) {
    grid = new THREE.GridHelper(400, 40, 0x555049, 0x33302c);
    scene.add(grid);
  }

  // All models render in the same orange so thumbnails are consistent and no
  // 3MF comes out black from embedded dark materials/vertex colours. STL flat
  // (crisp mechanical edges); 3MF smooth. Both double-sided.
  const ORANGE = 0xff6b1a;
  const material    = new THREE.MeshStandardMaterial({ color: ORANGE, metalness: 0.05, roughness: 0.65, flatShading: true,  side: THREE.DoubleSide });
  const material3mf = new THREE.MeshStandardMaterial({ color: ORANGE, metalness: 0.05, roughness: 0.65, flatShading: false, side: THREE.DoubleSide });

  let current = null;
  let running = true;

  function resize() {
    const w = container.clientWidth, h = container.clientHeight;
    if (w === 0 || h === 0) return;
    renderer.setSize(w, h, false);
    camera.aspect = w / h;
    camera.updateProjectionMatrix();
  }
  const ro = new ResizeObserver(resize);
  ro.observe(container);

  (function animate() {
    if (!running) return;
    requestAnimationFrame(animate);
    controls.update();
    renderer.render(scene, camera);
  })();

  function clearModel() {
    if (!current) return;
    scene.remove(current);
    current.traverse?.(o => {
      o.geometry?.dispose?.();
      // Dispose any per-mesh materials the loaders attached (before we swap in
      // ours). Our shared materials are disposed once in dispose().
      const mat = o.material;
      if (Array.isArray(mat)) mat.forEach(m => m && m !== material && m !== material3mf && m.dispose?.());
      else if (mat && mat !== material && mat !== material3mf) mat.dispose?.();
    });
    current = null;
  }

  function frameObject(obj) {
    obj.rotation.x = -Math.PI / 2;
    obj.updateMatrixWorld(true);

    const box = new THREE.Box3().setFromObject(obj);
    if (box.isEmpty()) return;
    const size = box.getSize(new THREE.Vector3());
    const center = box.getCenter(new THREE.Vector3());

    obj.position.set(-center.x, -box.min.y, -center.z);

    const maxDim = Math.max(size.x, size.y, size.z) || 1;
    const dist = maxDim / (2 * Math.tan((Math.PI * camera.fov) / 360));
    camera.position.set(maxDim * 0.9, size.y * 0.6 + maxDim * 0.5, dist * 1.6);
    camera.near = maxDim / 100;
    camera.far = maxDim * 100;
    camera.updateProjectionMatrix();
    controls.target.set(0, size.y / 2, 0);
    controls.update();
  }

  async function robust3MF(url) {
    const buf = new Uint8Array(await (await fetch(url)).arrayBuffer());
    const files = fflate.unzipSync(buf);
    const dec = new TextDecoder();

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

    const out = [];
    const resolve = (id, mat, depth) => {
      if (depth > 24) return;
      const o = objMap[id];
      if (!o) return;
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

  /**
   * Load an STL or 3MF from a URL. Returns a Promise that resolves when the
   * model is in the scene and framed, or rejects on unrecoverable failure.
   */
  function loadFile(url, ext) {
    return new Promise((resolveP, rejectP) => {
      if (ext === 'stl') {
        new STLLoader().load(url, (geometry) => {
          clearModel();
          if (!geometry.attributes.normal) geometry.computeVertexNormals();
          geometry.computeBoundingBox();
          const mesh = new THREE.Mesh(geometry, material);
          scene.add(mesh);
          current = mesh;
          frameObject(mesh);
          opts.onLoad?.();
          resolveP();
        }, undefined, (e) => { opts.onError?.(e); rejectP(e); });
      } else {
        new ThreeMFLoader().load(url, (object) => {
          clearModel();
          object.traverse(o => {
            if (o.isMesh) {
              if (o.geometry?.attributes?.color) o.geometry.deleteAttribute('color');
              // Some slicer 3MFs ship absent or degenerate normals, which shade
              // pure black under any light. Recompute so they light correctly.
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
          opts.onLoad?.();
          resolveP();
        }, undefined, () => {
          robust3MF(url).then((group) => {
            if (!group || group.children.length === 0) {
              const err = new Error('No renderable geometry in this 3MF.');
              opts.onError?.(err); rejectP(err); return;
            }
            clearModel();
            scene.add(group);
            current = group;
            frameObject(group);
            opts.onLoad?.();
            resolveP();
          }).catch((e) => { opts.onError?.(e); rejectP(e); });
        });
      }
    });
  }

  /**
   * Render the current view to a PNG data URL at the given square size.
   * Temporarily hides the grid so thumbnails are model-only. Forces one render
   * so the captured frame reflects the current camera/orbit exactly.
   */
  function capturePNG(size = 512) {
    if (!current) return null;
    const prevW = renderer.domElement.width;
    const prevH = renderer.domElement.height;
    const gridWasVisible = grid ? grid.visible : false;
    if (grid) grid.visible = false;

    renderer.setSize(size, size, false);
    camera.aspect = 1;
    camera.updateProjectionMatrix();
    renderer.render(scene, camera);
    const dataUrl = renderer.domElement.toDataURL('image/png');

    // Restore live view.
    if (grid) grid.visible = gridWasVisible;
    renderer.setSize(prevW, prevH, false);
    resize();
    return dataUrl;
  }

  function dispose() {
    running = false;
    ro.disconnect();
    clearModel();

    // Dispose our shared materials and the grid so nothing survives the context.
    material.dispose();
    material3mf.dispose();
    if (grid) {
      grid.geometry?.dispose?.();
      if (Array.isArray(grid.material)) grid.material.forEach(m => m?.dispose?.());
      else grid.material?.dispose?.();
    }
    // Drop all scene children so lights/helpers don't hold references.
    while (scene.children.length) scene.remove(scene.children[0]);

    controls.dispose();
    renderer.dispose();
    renderer.forceContextLoss?.();
    if (renderer.domElement.parentNode) {
      renderer.domElement.parentNode.removeChild(renderer.domElement);
    }
  }

  resize();

  return {
    loadFile,
    capturePNG,
    resize,
    dispose,
    hasModel: () => current !== null,
  };
}
