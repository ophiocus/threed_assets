# threed_assets — design

## Why a shared module

`virtuabooth-store` (product showroom) and `drupal-three-js-theme` (site-as-world)
are both "Drupal + three.js". Both must register, version, serve and reference 3D
assets. That substrate is identical, so it lives once here and both projects
depend on it — a mutual dependency.

## The descriptor (cross-project contract)

Every asset resolves to:

```
{ id, kind, role, scene, url, format, bytes, resolution, lod[], manifest{} }
```

- `kind` — model | model-buffer | texture | hdri | audio
- `lod[]` — resolution ladder (`web-low` / `web-high` / `8k`); the front-end
  picks by device/quality. (The original showroom hand-managed `_web` vs
  `_web_high`; this formalizes it.)
- `manifest{}` — for models: `clips[]`, `materials[]`, `meshes[]`, `images[]`,
  extracted on upload by `GltfManifestExtractor`, so configs can reference clip
  names (e.g. `CaseTopHexagonsAction`) safely.

The booth config and the 3D-theme world both embed descriptors instead of
hardcoded paths.

## Drupal data model (Media)

A `3d_asset` Media type (or per-kind bundles) with fields:

- `field_kind` — model / texture / hdri / audio
- `field_file` — managed file (private or public; CDN-offloadable)
- `field_variants` — paragraph/multi-file: label + file for the LOD ladder
- `field_manifest` — JSON; populated by the extractor for models
- `field_role`, `field_scene` — semantics

## Components

| Component | Status |
| --- | --- |
| `AssetResolver` — entity/catalog to descriptor, LOD grouping | skeleton |
| `GltfManifestExtractor` — glTF to clips/materials/meshes/images | functional |
| `threed-assets:catalog` drush — inspect a catalog | functional |
| Media type + fields (config/install) | next |
| Catalog to Media importer (migrate/drush) | next |
| Serving + cache + CDN offload | later |

## Seed

`data/asset_catalog.json` — 92 assets mined from the original VirtuaBooth
creation (model with 14 clips / 40 materials, four scene HDRIs plus low/high
LODs, the 8K sea HDR, alternates, forest ambience). The importer seeds Media
entities from this.
