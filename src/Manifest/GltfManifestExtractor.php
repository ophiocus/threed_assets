<?php

declare(strict_types=1);

namespace Drupal\threed_assets\Manifest;

use Psr\Log\LoggerInterface;

/**
 * Extracts a glTF model's internal manifest.
 *
 * Pulls the animation clips, materials, meshes and image URIs from a glTF
 * document, so configs can reference names without opening Blender.
 */
final class GltfManifestExtractor {

  public function __construct(private readonly LoggerInterface $logger) {}

  /**
   * Extract clips, materials, meshes and image URIs from a glTF document.
   *
   * @param string $gltfJson
   *   The raw .gltf (JSON) contents. (.glb: parse the embedded JSON chunk.)
   *
   * @return array
   *   Keys: clips, materials, meshes, images (string[]) and node_count (int).
   */
  public function extract(string $gltfJson): array {
    $empty = ['clips' => [], 'materials' => [], 'meshes' => [], 'images' => [], 'node_count' => 0];
    try {
      $g = json_decode($gltfJson, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      $this->logger->warning('glTF manifest parse failed: @m', ['@m' => $e->getMessage()]);
      return $empty;
    }
    $names = static fn(string $k): array => array_values(array_filter(
      array_map(static fn(array $x): ?string => $x['name'] ?? NULL, $g[$k] ?? [])
    ));
    return [
      'clips' => $names('animations'),
      'materials' => $names('materials'),
      'meshes' => $names('meshes'),
      'images' => array_values(array_filter(array_map(
        static fn(array $i): ?string => $i['uri'] ?? NULL, $g['images'] ?? []
      ))),
      'node_count' => count($g['nodes'] ?? []),
    ];
  }

}
