<?php

declare(strict_types=1);

namespace Drupal\threed_assets;

use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\media\MediaInterface;

/**
 * Normalizes a 3D asset into a descriptor any three.js front-end can consume.
 *
 * Works from a static catalog entry or a media entity. The descriptor is the
 * cross-project contract:
 *   [id, kind, role, scene, url, format, bytes, resolution, lod[], manifest{}]
 *
 * `kind` is one of: model, model-buffer, texture, hdri or audio. `lod` lists
 * resolution variants (web-low / web-high / 8k) so the front-end can pick by
 * device or quality.
 */
final class AssetResolver {

  /**
   * Maps a 3d_* media bundle to a descriptor `kind`.
   */
  private const BUNDLE_KIND = [
    '3d_model' => 'model',
    '3d_buffer' => 'model-buffer',
    '3d_texture' => 'texture',
    '3d_hdri' => 'hdri',
    '3d_audio' => 'audio',
  ];

  public function __construct(
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * Build a descriptor from a static catalog entry (asset_catalog.json shape).
   *
   * @param array $entry
   *   One asset record from the catalog.
   * @param string $baseUrl
   *   Public base URL the asset is served from (files dir or module path).
   *
   * @return array
   *   Normalized descriptor.
   */
  public function fromCatalogEntry(array $entry, string $baseUrl = ''): array {
    $path = $entry['path'] ?? '';
    return [
      'id' => $this->slug($path),
      'kind' => $entry['category'] ?? 'other',
      'role' => $entry['role'] ?? '',
      'scene' => $entry['scene'] ?? '',
      'url' => rtrim($baseUrl, '/') . '/' . ltrim($path, '/'),
      'format' => $entry['format'] ?? '',
      'bytes' => $entry['bytes'] ?? 0,
      'resolution' => $entry['resolution'] ?? '',
      'lod' => [],
      'manifest' => $entry['manifest'] ?? [],
    ];
  }

  /**
   * Build a descriptor from a 3D asset media entity (platform L1).
   *
   * The entity-backed counterpart to fromCatalogEntry(): same descriptor,
   * sourced from imported Media instead of static JSON, plus a `hash` (sha256)
   * so a front-end can verify integrity (L0).
   *
   * @param \Drupal\media\MediaInterface $media
   *   A media entity of a 3d_* bundle.
   *
   * @return array
   *   Normalized descriptor.
   */
  public function fromMedia(MediaInterface $media): array {
    $file = $media->hasField('field_3d_file') ? $media->get('field_3d_file')->entity : NULL;
    $uri = $file ? $file->getFileUri() : '';
    $bundle = $media->bundle();

    $manifest = [];
    if ($bundle === '3d_model' && $media->hasField('field_3d_manifest')) {
      $decoded = json_decode((string) $media->get('field_3d_manifest')->value, TRUE);
      if (is_array($decoded)) {
        $manifest = $decoded;
      }
    }

    $field = static fn(string $name): string => $media->hasField($name)
      ? (string) $media->get($name)->value : '';

    return [
      'id' => $this->slug($uri !== '' ? $uri : ('media-' . $media->id())),
      'kind' => self::BUNDLE_KIND[$bundle] ?? 'other',
      'role' => $field('field_3d_role'),
      'scene' => $field('field_3d_scene'),
      'url' => $uri !== '' ? $this->fileUrlGenerator->generateString($uri) : '',
      'format' => $uri !== '' ? strtolower(pathinfo($uri, PATHINFO_EXTENSION)) : '',
      'bytes' => $file ? (int) $file->getSize() : 0,
      'resolution' => $field('field_3d_resolution'),
      'lod' => [],
      'manifest' => $manifest,
      'hash' => $field('field_3d_hash'),
    ];
  }

  /**
   * Attach a resolution ladder to a descriptor.
   *
   * @param array $descriptor
   *   A descriptor from fromCatalogEntry() or fromMedia().
   * @param array $variants
   *   Descriptors for the same logical asset at other resolutions.
   *
   * @return array
   *   The descriptor with its `lod` ladder populated.
   */
  public function withLod(array $descriptor, array $variants): array {
    $descriptor['lod'] = array_map(
      static fn(array $v): array => [
        'label' => $v['resolution'] ?? 'default',
        'url' => $v['url'],
        'bytes' => $v['bytes'] ?? 0,
      ],
      $variants,
    );
    return $descriptor;
  }

  /**
   * Derive a lowercase, underscore-safe id from a file path.
   *
   * @param string $path
   *   A file path or URI.
   *
   * @return string
   *   The slug: filename, lowercased, non-alphanumerics collapsed to '_'.
   */
  private function slug(string $path): string {
    $base = pathinfo($path, PATHINFO_FILENAME);
    return strtolower(preg_replace('/[^a-z0-9]+/i', '_', $base));
  }

}
