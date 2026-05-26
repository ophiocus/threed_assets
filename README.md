# 3D Assets (threed_assets)

A Drupal-aware manager for 3D / WebGL media: **models** (glTF/GLB),
**textures**, **HDRIs / environment maps** and **audio** — handled as
first-class Media with LOD variants, extracted glTF manifests, and a
normalized descriptor any three.js front-end can consume.

It is a shared substrate for Drupal + three.js projects: asset handling is
defined once here and reused, rather than reinvented per project.

## Table of contents

- Requirements
- Installation
- Usage
- Architecture
- Maintainers

## Requirements

This module only requires Drupal core's **Media** and **File** modules.

## Installation

Install as you would any contributed Drupal module:

```sh
composer require drupal/threed_assets
drush en threed_assets
```

The Media data model (the binary-leaf bundles + fields) is created on
install.

## Usage

The module ships drush commands:

- `drush threed-assets:install-model` (`ta:model`) — (re)create the 3D asset
  Media data model on a running site. Runs automatically on install.
- `drush threed-assets:import` (`ta:import`) — import the binary assets
  described by an `asset_catalog.json` into Media. Each file is hashed
  (sha256) for integrity and de-duplication, and glTF models get their
  manifest (clips / materials / meshes) extracted. Use `--purge` to clear
  existing 3D media first and `--catalog=PATH` to point at a specific file.
- `drush threed-assets:catalog` (`ta:cat`) — summarize a catalog file.

Assets resolve to a normalized **descriptor** via `AssetResolver`
(`fromMedia()` or `fromCatalogEntry()`):

```
[id, kind, role, scene, url, format, bytes, resolution, lod[], manifest{}]
```

`kind` is one of: model · model-buffer · texture · hdri · audio.

Textures and HDRIs are stored as raw **file** fields and are never run
through Drupal image styles — render data (normal / roughness / environment
maps) must reach the GPU byte-for-byte.

## Architecture

See `docs/`:

- `DESIGN.md` — the descriptor contract and the module's components.
- `ACTOR_MODEL.md` — the engine / scene authoring contract.
- `PLATFORM.md` — the layered platform and build roadmap.

`data/asset_catalog.json` is an example seed catalog.

## Maintainers

- ophiocus
