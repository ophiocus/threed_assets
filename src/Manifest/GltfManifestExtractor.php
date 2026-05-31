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
   * Accepts either textual glTF (JSON) or binary glTF (.glb). For .glb the
   * embedded JSON chunk is extracted per the Khronos spec (12-byte header +
   * chunk0: uint32 length, "JSON" type, UTF-8 JSON data padded to 4 bytes).
   *
   * @param string $gltfData
   *   The raw .gltf (JSON) or .glb (binary) file contents.
   *
   * @return array
   *   Keys: clips, materials, meshes, images (string[]) and node_count (int).
   */
  public function extract(string $gltfData): array {
    $empty = ['clips' => [], 'materials' => [], 'meshes' => [], 'images' => [], 'node_count' => 0];
    $gltfJson = $this->extractJson($gltfData);
    if ($gltfJson === NULL) {
      return $empty;
    }
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

  /**
   * Return the JSON payload from a .gltf (textual) or .glb (binary) document.
   *
   * @param string $data
   *   The raw file contents.
   *
   * @return string|null
   *   The JSON string, or NULL if .glb is malformed.
   */
  private function extractJson(string $data): ?string {
    // .glb magic: 'glTF' (4 bytes), version (uint32), total length (uint32).
    if (strlen($data) >= 12 && substr($data, 0, 4) === 'glTF') {
      // First chunk: length (uint32), type ('JSON'), data, padded to 4 bytes.
      $unpacked = unpack('Vlen', substr($data, 12, 4));
      $chunkLen = (int) ($unpacked['len'] ?? 0);
      $chunkType = substr($data, 16, 4);
      if ($chunkType !== 'JSON' || $chunkLen <= 0 || strlen($data) < 20 + $chunkLen) {
        $this->logger->warning('Malformed .glb: missing JSON chunk header.');
        return NULL;
      }
      return rtrim(substr($data, 20, $chunkLen), "\0 ");
    }
    return $data;
  }

}
