<?php

declare(strict_types=1);

namespace Drupal\canvas_test_importmap_alter\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for canvas_test_importmap_alter.
 */
class CanvasTestImportmapAlterHooks {

  /**
   * Implements hook_canvas_importmap_alter().
   */
  #[Hook('canvas_importmap_alter')]
  public function canvasImportmapAlter(array &$import_map): void {
    // Add a new global import.
    $import_map['imports']['test-added-package'] = '/modules/test/js/test-added-package.js';
    // Override an existing global import.
    $import_map['imports']['clsx'] = '/modules/test/js/custom-clsx.js';
    // Add scoped imports.
    $import_map['scopes']['/modules/test/js/'] = [
      'preact' => '/modules/test/js/custom-preact.js',
    ];
  }

}
