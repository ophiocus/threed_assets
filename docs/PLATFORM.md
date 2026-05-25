# VirtuaBooth — the platform

The bigger picture, consolidated. `ACTOR_MODEL.md` is the canonical **engine
spec**; `DESIGN.md` is the **asset-manager spec**; this is the **platform spec**
that contains them both and names the build order. Locked 2026-05-25.

## The reframe

VirtuaBooth is not "a showroom." It is a **3D-commerce world platform**, expressed
as two modules:

- **`threed_assets`** — the substrate: binaries, asset entities, the behavior
  runtime, the world model, and the generic player. Reusable by any Drupal 3D
  project (the booth and `drupal-three-js-theme`'s site-as-world).
- **`virtuabooth`** — the Commerce storefront that *uses* the substrate: product
  types, the booth-as-PDP, the catalog.

The working booth proved the middle of the stack (L3). The platform is the layers
under it (L0–L2) and over it (L4–L6).

## The stack

Bottom to top. Each layer maps to one of the four founding themes.

| Layer | Owns | Theme | State at lock |
| --- | --- | --- | --- |
| **L0 Binary substrate** | files · integrity hash · format/LOD conversion · descriptor | binary protocols | descriptor contract exists; pipeline = 0 |
| **L1 Asset entities** | mesh · material · animation · texture · hdri · audio | content types | manifest *extraction* works; entities = 0 |
| **L2 Behavior registry** | named, parameterized callbacks / VFX | configurable callback · vfx | "actions" concept specced; registry = 0 |
| **L3 Composition** | actors · states · actions · lanes · shots · props · stages | world building | specced; hardcoded in `BoothConfigBuilder` |
| **L4 Authoring** | stage builder · configurator | stage builder | configurator-as-projection specced; builder = 0 |
| **L5 Commerce** | product types · variations · modes | commerce-compatible | modes specced; wiring = 0 |
| **L6 World client** | generic manifest player (runtime) | world client | the booth engine *is* this, but booth-specific |

## The layers in detail

### L0 — Binary substrate

The raw-bytes contract under everything. Three protocols:

- **Two texture lanes (hard rule).** Render-data binaries (normal / roughness /
  metallic / hdri / geometry buffers) are **linear data, not photos** — they are
  `file` fields, never `image` fields, and **never** pass through `image_style`.
  A derivative re-encode corrupts them. Display-data images (catalog stills,
  turntable captures, thumbnails) *are* sRGB photos and *do* get styled. Never
  cross the lanes.
- **Integrity.** A content hash (sha256) per binary, verified on upload; the hash
  is also the **dedup key** (the seed catalog already shows one `blob_sha` under
  many paths — dedup is free once hashing lands).
- **Conversion / LOD.** The pipeline that *fills the `lod[]` slot the descriptor
  already declares but never populates*: gltf-transform (Draco/meshopt —
  `DRACOLoader` is commented-out and waiting in `project.js`), KTX2/Basis
  textures, HDR→tonemapped web ladder (the 8K `.hdr` → 4K web-low/high `.jpg`
  step done by hand in the seed catalog — automated here).

### L1 — Asset entities

Decompose the monolith into addressable, recombinable, sellable things.

- **Binary leaves** (texture, hdri, audio, geometry buffer) → **Media bundles**,
  file-backed.
- **Logical composites** (mesh, material, animation; above them actor / prop /
  stage) → **content entities** that reference the leaves and each other and carry
  Commerce identity.
- **Metadata** = the descriptor `manifest{}` + provenance / license (HDRIs are
  CC-attributed) + poly count + bbox + the LOD ladder.
- **Configurable callback** = the declared bridge to L2 (an entity says *run
  behavior `water` with these params* / *on equip fire `equip:gaming`*) — data,
  not code.

### L2 — Behavior registry

Named, parameterized engine behaviors — VFX, custom-render callbacks, and the
actor-model **actions** — referenced by name + params from data. The sea shader,
icosphere, particles, post-processing stop being `if`-branches in `project.js`
(which is why `stage_set_props()` is currently gutted — it had no home) and become
registered behaviors a stage or prop binds declaratively. This is the code seam
that makes everything above it authorable without per-stage code.

### L3 — Composition

The actor model (`ACTOR_MODEL.md`), already running in `BoothConfigBuilder`:
World · Protagonist (core ← accessories) · Scenes (stages) · N Props; the three
lanes; actions; states; shots; anchors; triggers. New here only in that VFX
becomes a first-class L2-backed citizen of a stage/prop.

### L4 — Authoring

- **Configurator** — the projection of the protagonist's saleable nodes (specced
  in `ACTOR_MODEL.md`; one authoring surface drives behavior + UI + pricing).
- **Stage builder** — compose a stage from the L1 library (sky + props + lighting
  + VFX bindings) → emit a **stage manifest** the L6 client renders. Dogfood test:
  re-express grassland / snow / sea as manifests and pixel-match the live booth.

### L5 — Commerce

The actor-model **modes graduate from accessories to every asset type**: a
*material* → colorway **variation**; a *mesh* → **separate** product; an
*animation* → `display` feature. `BoothConfigBuilder` already encodes
`bundled / variation / separate / display` for accessories — it generalizes.

### L6 — World client

The booth engine reconceived as a **generic player of world manifests**, not a
bespoke booth. It already reads `drupalSettings.virtuabooth` (a manifest by
another name). Generalize that contract and one client serves the booth,
`drupal-three-js-theme`'s site-as-world, and the stage builder's live preview.

## Locked decisions (the four forks)

Resolved 2026-05-25. These are load-bearing; revisit only with cause.

### A — Decompose the monolith, *and* address into it (both, behind the descriptor)

The booth works today by pointing into one `animated.gltf` (90 meshes / 40
materials / 14 clips). "Build your own stage" with arbitrary uploads eventually
needs each asset exploded into its own hash-addressed GLB. **Decision:** the
descriptor abstracts which. Keep *address-into-monolith* so the booth never
breaks; build the *explode / normalize* pipeline (part of L0 conversion) as the
ingest path for **new** uploads. An asset's descriptor looks the same either way.

### B — The engine lives in `threed_assets` (shared world client)

It is bundled in `virtuabooth` today. The "world client" vision is a shared
player. **Decision:** migrate the engine into `threed_assets` as the shared L6
runtime; `virtuabooth` supplies only a manifest. This is what "mutual dependency"
was always going to mean.

### C — Leaves = Media · reusable composites = content entities · Paragraphs = per-product arrangement

**Decision:**
- Binary leaves (texture / hdri / audio / geometry buffer) → **Media bundles**
  (file-backed, no styling — L0 rule).
- Reusable composites (material / animation / mesh / actor / stage) → **content
  entities** (library reuse + Commerce identity).
- **Paragraphs only for the per-product *arrangement*** — the actor-tree wiring on
  the Commerce product, exactly where `ACTOR_MODEL.md` Phase 2 puts them.
- Rule of thumb: **reuse = entities; composition = Paragraphs.**

### D — Stage builder is phased (form composer first, canvas editor later)

**Decision:** ship a **form-driven manifest composer** first (entity-reference
fields → JSON manifest); a **WYSIWYG in-canvas editor** later (the L6 world client
running in "edit mode" — the same engine plays *and* authors).

## Build order

| Phase | Layers | Deliverable | Notes |
| --- | --- | --- | --- |
| **P1** | L0 / L1 | Asset entities + binary protocols: Media bundles, no-style rule, hash + dedup, manifest-extract-on-upload, catalog→entity importer. | Home for the **admin ingest + turntable capture** — capture becomes an L1 feature, not a one-off. |
| **P2** | L2 / L6 | Behavior registry + world-client generalization: lift the engine into `threed_assets`, manifest-driven boot, behavior/VFX registry. | This **is** the action interpreter — the engine spec's old "Phase 0". |
| **P3** | L3 / L4 | Composition authoring: stage builder (form tier); re-express the three scenes as manifests; pixel-match. | The dogfood proof of the model. |
| **P4** | L5 | Commerce: product types, modes, configurator → cart. | The engine spec's old "Phase 2" authoring lands here. |

## Pointers

- Engine / runtime contract → `ACTOR_MODEL.md`
- Asset-manager components + descriptor → `DESIGN.md`
- Live (interim, hardcoded) config shape → `virtuabooth` `BoothConfigBuilder`
