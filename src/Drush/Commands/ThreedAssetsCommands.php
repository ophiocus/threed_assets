<?php

declare(strict_types=1);

namespace Drupal\threed_assets\Drush\Commands;

use Drupal\threed_assets\Manifest\GltfManifestExtractor;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the 3D asset manager.
 */
final class ThreedAssetsCommands extends DrushCommands {

  /**
   * Summarize a static asset catalog (data/asset_catalog.json or a given path).
   */
  #[CLI\Command(name: 'threed-assets:catalog', aliases: ['ta:cat'])]
  #[CLI\Argument(name: 'path', description: 'Path to an ASSET_CATALOG.json.')]
  public function catalog(string $path = ''): void {
    $path = $path ?: __DIR__ . '/../../../data/asset_catalog.json';
    if (!is_file($path)) {
      $this->logger()->error("Catalog not found: $path");
      return;
    }
    $c = json_decode((string) file_get_contents($path), TRUE);
    $counts = $c['counts'] ?? [];
    $this->output()->writeln(sprintf(
      '3D catalog: %d assets (current %d, sea %d, archived %d)',
      $counts['total'] ?? 0,
      $counts['current'] ?? 0,
      $counts['sea'] ?? 0,
      $counts['archived'] ?? 0,
    ));
    $m = $c['model_manifest'] ?? [];
    $this->output()->writeln('clips: ' . implode(', ', $m['animations'] ?? []));
  }

  /**
   * Create (idempotently) the L1 Media data model — binary-leaf bundles + fields.
   *
   * Applies _threed_assets_ensure_media_model() to a running site, so an
   * already-installed module picks up the data model without a reinstall.
   */
  #[CLI\Command(name: 'threed-assets:install-model', aliases: ['ta:model'])]
  public function installModel(): void {
    $created = _threed_assets_ensure_media_model();
    $this->output()->writeln(
      $created
        ? 'Created bundles: ' . implode(', ', $created)
        : 'Media data model already present (nothing to create).'
    );

    // Report the resulting 3D Media bundles + their field counts.
    $types = \Drupal::entityTypeManager()->getStorage('media_type')->loadMultiple();
    $fields = \Drupal::service('entity_field.manager');
    foreach (array_keys(_threed_assets_leaf_bundles()) as $bundle) {
      if (!isset($types[$bundle])) {
        $this->output()->writeln(" - $bundle: MISSING");
        continue;
      }
      $defs = $fields->getFieldDefinitions('media', $bundle);
      $own = array_filter(array_keys($defs), static fn(string $n): bool => str_starts_with($n, 'field_3d_'));
      $this->output()->writeln(sprintf(' - %s: %d threed fields (%s)', $bundle, count($own), implode(', ', $own)));
    }
  }

  /**
   * Import binary-leaf catalog assets into Media entities (platform L1).
   *
   * Reads data/asset_catalog.json, imports every on-disk `current` asset as a
   * file-backed Media entity of the matching 3d_* bundle. Integrity protocol:
   * a sha256 is computed per file and stored on field_3d_hash, which is also the
   * dedup key — byte-identical assets collapse to one Media, and re-running is
   * idempotent. Models additionally get their glTF manifest extracted.
   */
  #[CLI\Command(name: 'threed-assets:import', aliases: ['ta:import'])]
  #[CLI\Option(name: 'purge', description: 'Delete existing 3D media before importing.')]
  #[CLI\Option(name: 'catalog', description: 'Path to an asset_catalog.json (defaults to the bundled seed).')]
  public function import(array $options = ['purge' => FALSE, 'catalog' => '']): void {
    _threed_assets_ensure_media_model();

    $path = $options['catalog'] ?: __DIR__ . '/../../../data/asset_catalog.json';
    if (!is_file($path)) {
      $this->logger()->error("Catalog not found: $path");
      return;
    }
    $catalog = json_decode((string) file_get_contents($path), TRUE);
    $assets = is_array($catalog) ? ($catalog['assets'] ?? []) : [];

    $etm = \Drupal::entityTypeManager();
    $fs = \Drupal::service('file_system');
    $mediaStorage = $etm->getStorage('media');
    $fileStorage = $etm->getStorage('file');

    if (!empty($options['purge'])) {
      $ids = $mediaStorage->getQuery()->accessCheck(FALSE)
        ->condition('bundle', array_keys(_threed_assets_leaf_bundles()), 'IN')->execute();
      if ($ids) {
        $mediaStorage->delete($mediaStorage->loadMultiple($ids));
      }
      $this->output()->writeln('Purged ' . count($ids) . ' existing 3D media.');
    }

    // Catalog category => Media bundle.
    $map = [
      'model' => '3d_model',
      'model-buffer' => '3d_buffer',
      'texture' => '3d_texture',
      'hdri' => '3d_hdri',
      'audio' => '3d_audio',
    ];

    $created = [];
    $dupes = 0;
    $missing = [];
    $seen = [];

    foreach ($assets as $a) {
      // Only the live, on-disk set (the 23 extracted under public://3d).
      if (($a['status'] ?? '') !== 'current') {
        continue;
      }
      $bundle = $map[$a['category'] ?? ''] ?? NULL;
      if (!$bundle) {
        continue;
      }
      $p = (string) ($a['path'] ?? '');
      if (!str_starts_with($p, 'public/')) {
        continue;
      }
      $rel = substr($p, strlen('public/'));
      $uri = 'public://3d/' . $rel;
      if (!file_exists($uri)) {
        $missing[] = $rel;
        continue;
      }

      $real = $fs->realpath($uri);
      $sha = hash_file('sha256', $real);

      // Dedup: within this run and against already-imported media.
      if (isset($seen[$sha])) {
        $dupes++;
        continue;
      }
      $exist = $mediaStorage->getQuery()->accessCheck(FALSE)
        ->condition('field_3d_hash', $sha)->range(0, 1)->execute();
      if ($exist) {
        $seen[$sha] = TRUE;
        $dupes++;
        continue;
      }

      // Managed file referencing the existing on-disk binary (reuse if present).
      $existingFiles = $fileStorage->loadByProperties(['uri' => $uri]);
      $file = $existingFiles ? reset($existingFiles) : $fileStorage->create(['uri' => $uri, 'status' => 1]);
      $file->save();

      $media = $mediaStorage->create([
        'bundle' => $bundle,
        'name' => basename($rel),
        'field_3d_file' => ['target_id' => $file->id()],
        'field_3d_hash' => $sha,
        'field_3d_role' => $a['role'] ?? '',
        'field_3d_scene' => $a['scene'] ?? '',
        'field_3d_resolution' => $a['resolution'] ?? '',
      ]);

      if ($bundle === '3d_model') {
        $extractor = \Drupal::service(GltfManifestExtractor::class);
        $manifest = $extractor->extract((string) file_get_contents($real));
        $media->set('field_3d_manifest', json_encode($manifest));
      }

      $media->save();
      $seen[$sha] = TRUE;
      $created[$bundle] = ($created[$bundle] ?? 0) + 1;
    }

    $this->output()->writeln('Imported: ' . (json_encode($created) ?: '{}'));
    $this->output()->writeln("Dupes skipped: $dupes");
    if ($missing) {
      $head = implode(', ', array_slice($missing, 0, 8));
      $this->output()->writeln('Missing on disk (' . count($missing) . '): ' . $head . (count($missing) > 8 ? ' …' : ''));
    }
  }

}
