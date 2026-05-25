# Actor model — the VirtuaBooth 3D engine

The cross-project conceptual model for Drupal-driven three.js scenes (the booth
and the site-as-world). Data is authored in Drupal; one generic engine
interprets it. This doc is the contract `threed_assets` exists to serve.

## Invariant topology

Every booth is built the same way:

```
World  ·  1 Protagonist (core ← accessories)  ·  N Props
```

- **World** — the stage: camera, controls, sky, water, lighting.
- **Protagonist** — *the product*. The only thing configured and the only thing
  sold. A **tree**: a `core` with parented **accessories**.
- **Props (N)** — scene dressing. Staged in sets, never configured, never sold.

The protagonist is privileged. Props are subordinate.

## The product tree IS the scene graph

Accessories are parented to the core at a declared **anchor** (a center relative
to the parent's center). three.js parenting = the Commerce bill-of-materials.

```
core  (RPi board)
├─ case    states: top ∈ {hexagons, portaccess, corpo} · param: tint   anchor: core.case_seat
├─ fan     states: on / off          (Active-CoolerAction)             anchor: core.fan_mount
└─ sdcard  states: in / out          (SD_CARD_Action)                  anchor: core.sd_slot
```

An accessory is a first-class asset: its own clips, its own center, optionally
its own commerce identity. Defining the anchor lets the *same* accessory asset
attach to different cores — accessories become a catalogue, not bespoke geometry.

## Vocabulary

| Term | Meaning |
| --- | --- |
| **Actor** | A logical object (core / accessory / prop). Has a center, **states**, **actions**. |
| **State** | A resting configuration of an actor. Commerce-bindable. |
| **Action** | A named, multi-lane composite animation an actor *performs*. Lands a state (or just presents). |
| **Lane** | A track an action writes: `actor-local` · `actor-world` · `world`. |
| **Trigger** | An event (menu/scene/load/idle) that fires an action. |
| **Shot** | A world-lane camera pose (pose + target + controls policy + easing) an action references. |
| **Anchor** | An actor's center, relative to its parent — the parenting mount. |

## The three lanes

| Lane | What | Space | Owner | Example |
| --- | --- | --- | --- | --- |
| **actor-local** | clips on the actor's own nodes | relative to actor center | the actor | lid swap, fan spin, sd insert |
| **actor-world** | the actor transforms its whole body in the booth | world | the actor | the **lift** ("jump"), present-rotate, a prop's spawn placement |
| **world** | camera, controls, environment, staging | world | the World | camera shots, controls lock, sky/water |

**Parenting enforces lane semantics for free:** an `actor-world` move on `core`
propagates to its children (case/fan/sdcard ride the lift); an `actor-local`
move stays inside its node. The "jump" is the protagonist moving *itself* — an
actor action in world space — not the stage acting on it.

## Actions generalize `THREE.AnimationAction`

three.js calls a played clip an `AnimationAction`; the glTF clips are literally
named `…Action` (`CaseTopHexagonsAction`, `Active-CoolerAction`). Our **action**
is the same word one level up: from *"one clip on the mixer"* to *"one
choreographed behavior across lanes."* The asset author authored the local-lane
actions in Blender; an actor's action wraps them with the actor-world and world
lanes.

```
actor: case   action: "equip:gaming"
  ├ local  : CaseTopHexagonsAction          (lid swap, case nodes)
  ├ world* : lift core                        (actor-in-world; * rides to children)
  └ world  : shot case_focus + controls lock→unlock
  ⇒ lands state "gaming"   (⇄ Commerce: case/gaming)
```

Lanes *inside* an action are choreographed — concurrent or sequenced. That is
what the legacy global `{ concurrent }` marker was groping toward: per-action
lane timing, not a scene-wide flag.

A **scene transition is just many actors performing actions on one timeline** —
World performs an action (camera retarget + sky/water swap), `core` performs
`lift`, each present prop performs `spawn`/`despawn`. No bespoke `sceneStageAnim`.

## Commerce modes (per accessory node)

"May or may not be sold separately" is a field on each accessory:

| mode | meaning | Drupal Commerce |
| --- | --- | --- |
| `bundled` | ships with core, inseparable | part of base product |
| `variation` | configures the core, mutually exclusive | product variation / attribute (case top) |
| `separate` | its own sellable product | own Commerce product + add-on line item / cross-sell |
| `display` | shown, never sold | none |

## Configurator = the protagonist's meta (only)

The configurator UI is a **projection of the protagonist tree's configurable /
saleable nodes** — never an iteration over all actors. Each state carries its own
UI/commerce payload:

```jsonc
{ "name":"gaming", "label":"Gaming", "icon":"fa-gamepad",
  "action":"equip:gaming", "commerce":"case/gaming", "priceDelta":0 }
```

Add a state in Drupal (`label:"Retro", clip:"CaseTopRetroAction", +$15`) → a new
configurator button appears, its action wired, its variation priced — zero code.
Props never emit configurator items; they are activated *en masse* by a scene
selector. One authoring surface drives **behavior + UI + pricing**.

## The interpreter (what the engine becomes)

- **World sequencer** — one lane for camera shots / controls. `Shot` library;
  `SectionTween` becomes "play a shot."
- **Per-actor lanes** — each actor runs its own local clips + actor-world
  transforms; no cross-talk with the camera.
- **Boot** — for each actor in the config: resolve its `threed_assets`
  descriptor, bind nodes/clips, parent at anchor, enter default state. Build the
  configurator from the protagonist's saleable states.
- **Dispatch** — trigger → fire the actor's action across its lanes.

The legacy `Handlers` (big switch + global `animStack`/`tweenStack`) collapses
into: a state-machine runtime + a World sequencer + per-actor lanes.

## `threed_assets`' job here

- **Validate** — an action's `…Action` clips must exist in the asset's extracted
  **manifest** (`GltfManifestExtractor`).
- **Anchors** — expose each asset's mount points so the engine can parent.
- **Descriptors** — `AssetResolver` supplies URL + LOD + manifest; the booth
  config embeds descriptors, never hardcoded paths.

## Roadmap refit

This engine spec sits inside the platform stack — see `PLATFORM.md` for the
canonical layers, the four locked forks, and the full build order. The actor
model is **L3** of that stack; its milestones land in these platform phases:

- **P2 (platform L2/L6)** — the **action interpreter**: not a "config-driven flat
  engine" but a World sequencer + per-actor lanes, booting actors from
  descriptors, consuming the booth config. Pairs with the behavior/VFX registry
  and the engine's migration into `threed_assets` as the shared world client
  (fork **B**). *(Was "Phase 0" here.)*
- **P3 (platform L3/L4)** — composition authoring + the stage builder; the three
  scenes re-expressed as manifests and pixel-matched.
- **P4 (platform L5)** — Drupal authoring: **Actor / State / Action / Trigger /
  Shot** Paragraph types on the Commerce product (per fork **C**, Paragraphs own
  the *per-product arrangement*); `BoothController` flattens the protagonist tree +
  scenes → the booth config the interpreter reads. *(Was "Phase 2" here.)*

Prerequisite: **P1 (platform L0/L1)** — asset entities + binary protocols — lands
the Media bundles and descriptors the interpreter boots actors from.
