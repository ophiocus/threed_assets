<?php

declare(strict_types=1);

namespace Drupal\threed_assets\Drush\Commands;

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
    // TODO(phase 2): create Media entities from each catalog entry.
  }

}
