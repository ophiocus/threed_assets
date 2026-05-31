/**
 * @file
 * Turntable capture mini-app.
 *
 * Loaded as an ES module on /admin/content/3d/media/{media}/capture. Loads the
 * source glTF/GLB in a hidden three.js scene with neutral studio lighting,
 * renders six cardinal views (front/back/right/left/top/bottom), captures each
 * as a PNG blob, and POSTs them back to Drupal. The Controller attaches the
 * resulting File entities to the media's field_3d_render.
 *
 * three.js + GLTFLoader come from esm.sh (an ESM CDN); pinning the version
 * here keeps it deterministic. Bundling locally is a follow-up for offline
 * dev parity.
 */

import * as THREE from 'https://esm.sh/three@0.184';
import { GLTFLoader } from 'https://esm.sh/three@0.184/examples/jsm/loaders/GLTFLoader.js';

const settings = (window.drupalSettings && window.drupalSettings.threedAssetsCapture) || {};
const statusEl = document.querySelector('#status');
const stageEl = document.querySelector('#stage');

const log = (m) => {
  // eslint-disable-next-line no-console
  console.log('[turntable]', m);
  if (statusEl) {
    statusEl.textContent = m;
  }
};

const VIEWS = [
  { id: 'front', dir: [0, 0, 1], up: [0, 1, 0] },
  { id: 'back', dir: [0, 0, -1], up: [0, 1, 0] },
  { id: 'right', dir: [1, 0, 0], up: [0, 1, 0] },
  { id: 'left', dir: [-1, 0, 0], up: [0, 1, 0] },
  { id: 'top', dir: [0, 1, 0], up: [0, 0, -1] },
  { id: 'bottom', dir: [0, -1, 0], up: [0, 0, 1] },
];

(async () => {
  try {
    if (!settings.modelUrl) {
      throw new Error('Missing settings.modelUrl (no source model URL).');
    }
    if (!settings.ingestUrl) {
      throw new Error('Missing settings.ingestUrl (no POST target).');
    }
    const size = settings.size || 512;

    log('Setting up renderer…');
    const renderer = new THREE.WebGLRenderer({
      antialias: true,
      alpha: true,
      preserveDrawingBuffer: true,
    });
    renderer.setSize(size, size);
    renderer.outputColorSpace = THREE.SRGBColorSpace;
    renderer.toneMapping = THREE.ACESFilmicToneMapping;
    renderer.toneMappingExposure = 1.0;
    if (stageEl) {
      stageEl.innerHTML = '';
      stageEl.appendChild(renderer.domElement);
    }

    const scene = new THREE.Scene();
    scene.background = null;
    scene.add(new THREE.AmbientLight(0xffffff, 0.6));
    const key = new THREE.DirectionalLight(0xffffff, 1.2);
    key.position.set(5, 10, 7);
    scene.add(key);
    const fill = new THREE.DirectionalLight(0xffffff, 0.5);
    fill.position.set(-5, 4, -3);
    scene.add(fill);

    log(`Loading model: ${settings.modelUrl}`);
    const loader = new GLTFLoader();
    const gltf = await loader.loadAsync(settings.modelUrl);
    const model = gltf.scene;
    scene.add(model);

    // Frame the model by its bounding box, fit a perspective camera.
    const box = new THREE.Box3().setFromObject(model);
    const center = box.getCenter(new THREE.Vector3());
    const dim = box.getSize(new THREE.Vector3());
    const maxDim = Math.max(dim.x, dim.y, dim.z) || 1;
    const fov = 35;
    const cam = new THREE.PerspectiveCamera(fov, 1, maxDim * 0.001, maxDim * 100);
    const dist = (maxDim / (2 * Math.tan((fov * Math.PI) / 360))) * 1.5;

    log('Capturing six cardinal views…');
    const blobs = {};
    for (const view of VIEWS) {
      const [dx, dy, dz] = view.dir;
      cam.position.set(
        center.x + dx * dist,
        center.y + dy * dist,
        center.z + dz * dist,
      );
      cam.up.set(view.up[0], view.up[1], view.up[2]);
      cam.lookAt(center);
      renderer.render(scene, cam);
      const blob = await new Promise((resolve) =>
        renderer.domElement.toBlob(resolve, 'image/png'),
      );
      if (!blob) {
        throw new Error(`Capture failed for view ${view.id}.`);
      }
      blobs[view.id] = blob;
      log(`Captured ${view.id} (${(blob.size / 1024).toFixed(1)} KB)`);
    }

    log('Uploading six renders…');
    const formData = new FormData();
    for (const [id, blob] of Object.entries(blobs)) {
      formData.append(`renders[${id}]`, blob, `${id}.png`);
    }
    const res = await fetch(settings.ingestUrl, {
      method: 'POST',
      body: formData,
      credentials: 'same-origin',
    });
    if (!res.ok) {
      const text = await res.text();
      throw new Error(`Upload failed: HTTP ${res.status} — ${text.slice(0, 200)}`);
    }
    const result = await res.json();
    log(`Done — saved ${result.saved || 0} renders.`);
    if (result.redirect) {
      setTimeout(() => {
        window.location.href = result.redirect;
      }, 1500);
    }
  } catch (e) {
    log(`Error: ${e.message}`);
    // eslint-disable-next-line no-console
    console.error('[turntable]', e);
  }
})();
