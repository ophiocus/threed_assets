<?php

declare(strict_types=1);

namespace Drupal\threed_assets;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\threed_assets\Manifest\GltfManifestExtractor;

/**
 * Ingests a binary 3D asset (File) into a Media entity of the given bundle.
 *
 * Computes sha256 of the file (the L0 integrity + dedup key); if an existing
 * Media carries the same hash, returns it instead of creating a duplicate.
 * Model uploads (3d_model) additionally get their glTF manifest extracted.
 *
 * Shared by the admin upload form and the catalog importer (drush ta:import),
 * so the dedup + creation rules live in exactly one place.
 */
final class AssetIngestor {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileSystemInterface $fileSystem,
    private readonly GltfManifestExtractor $manifestExtractor,
  ) {}

  /**
   * Look up an existing 3D media by its content hash.
   *
   * @param string $sha
   *   The sha256 hex digest.
   *
   * @return \Drupal\media\MediaInterface|null
   *   The matching media, or NULL.
   */
  public function findByHash(string $sha): ?MediaInterface {
    $storage = $this->entityTypeManager->getStorage('media');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_3d_hash', $sha)
      ->range(0, 1)
      ->execute();
    if (!$ids) {
      return NULL;
    }
    /** @var \Drupal\media\MediaInterface $media */
    $media = $storage->load(reset($ids));
    return $media;
  }

  /**
   * Ingest a file as a 3D Media of the given bundle (idempotent by sha256).
   *
   * @param \Drupal\file\FileInterface $file
   *   The managed file (will be marked permanent if not already).
   * @param string $bundle
   *   A 3d_* media bundle machine name.
   * @param array $metadata
   *   Optional keys: name, role, scene, resolution.
   * @param string|null $sha
   *   Optional precomputed sha256 (avoids a re-hash if the caller already did).
   *
   * @return \Drupal\media\MediaInterface
   *   The new media, or an existing one if the hash matched.
   */
  public function ingestFile(FileInterface $file, string $bundle, array $metadata = [], ?string $sha = NULL): MediaInterface {
    $real = $this->fileSystem->realpath($file->getFileUri());
    $sha ??= hash_file('sha256', $real);

    $existing = $this->findByHash($sha);
    if ($existing) {
      return $existing;
    }

    if (!$file->isPermanent()) {
      $file->setPermanent();
      $file->save();
    }

    $values = [
      'bundle' => $bundle,
      'name' => $metadata['name'] ?? $file->getFilename(),
      'field_3d_file' => ['target_id' => $file->id()],
      'field_3d_hash' => $sha,
      'field_3d_role' => $metadata['role'] ?? '',
      'field_3d_scene' => $metadata['scene'] ?? '',
      'field_3d_resolution' => $metadata['resolution'] ?? '',
    ];
    if ($bundle === '3d_model') {
      $manifest = $this->manifestExtractor->extract((string) file_get_contents($real));
      $values['field_3d_manifest'] = json_encode($manifest);
    }

    $media = $this->entityTypeManager->getStorage('media')->create($values);
    $media->save();
    return $media;
  }

}
