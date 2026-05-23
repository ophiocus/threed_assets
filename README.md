# threed_assets — Drupal 3D asset manager

A Drupal-aware manager for 3D/WebGL media: **models** (glTF/GLB), **textures**,
**HDRIs / environment maps** and **spatial audio** — as first-class Media with
LOD variants, extracted glTF manifests, and a normalized descriptor any three.js
front-end can consume.

It is the **shared substrate** for the Drupal 3D projects
(`virtuabooth-store`, `drupal-three-js-theme`) — both declare it as a dependency
so asset handling is defined once, not reinvented per project.

See `docs/DESIGN.md` for the architecture and `data/asset_catalog.json` for the
seed dataset (mined from the original VirtuaBooth creation).

## Install (consumer projects)

```jsonc
// composer.json
"repositories": {
  "threed_assets": { "type": "vcs", "url": "https://github.com/ophiocus/threed_assets" }
},
"require": { "ophiocus/threed_assets": "dev-master" }
```

```sh
ddev composer require ophiocus/threed_assets:dev-master
ddev drush en threed_assets -y
ddev drush threed-assets:catalog
```
