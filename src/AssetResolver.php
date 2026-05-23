<?php

declare(strict_types=1);

namespace Drupal\threed_assets;

use Drupal\Core\File\FileUrlGeneratorInterface;
use Psr\Log\LoggerInterface;

/**
 * Turns a 3D asset (catalog entry or media entity) into a normalized descriptor
 * that any three.js front-end can consume.
 *
 * The descriptor is the cross-project contract:
 *   [ id, kind, role, scene, url, format, bytes, resolution, lod[], manifest{} ]
 *
 * `kind` is one of: model | model-buffer | texture | hdri | audio.
 * `lod` lists resolution variants (web-low / web-high / 8k) so the front-end can
 * pick by device/quality — the thing the original showroom hand-managed.
 */
final class AssetResolver {

  public function __construct(
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Build a descriptor from a static catalog entry (ASSET_CATALOG.json shape).
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
   * Attach a resolution ladder to a descriptor.
   *
   * @param array $descriptor
   *   A descriptor from fromCatalogEntry().
   * @param array $variants
   *   Descriptors for the same logical asset at other resolutions.
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

  private function slug(string $path): string {
    $base = pathinfo($path, PATHINFO_FILENAME);
    return strtolower(preg_replace('/[^a-z0-9]+/i', '_', $base));
  }

}
