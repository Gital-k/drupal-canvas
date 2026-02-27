<?php

declare(strict_types=1);

namespace Drupal\canvas\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for Canvas block theme suggestions.
 *
 * Canvas renders blocks using component UUIDs as block IDs, which generates
 * theme suggestions containing characters that are invalid in Twig template
 * filenames. This hook replaces those UUID-based suggestions with clean,
 * region-aware alternatives.
 *
 * @see \Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant::build()
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\BlockComponent::renderComponent()
 */
class BlockThemeSuggestionsHooks {

  /**
   * Implements hook_theme_suggestions_HOOK_alter() for blocks.
   */
  #[Hook('theme_suggestions_block_alter')]
  public function themeSuggestionsBlockAlter(array &$suggestions, array $variables): void {
    $element = $variables['elements'];

    // Only act on blocks rendered by Canvas, identified by the #canvas_region
    // property stamped by CanvasPageVariant::stampCanvasRegion().
    if (!isset($element['#canvas_region'])) {
      return;
    }

    $region = $element['#canvas_region'];
    $base_plugin_id = $element['#base_plugin_id'] ?? NULL;
    $derivative_plugin_id = $element['#derivative_plugin_id'] ?? NULL;

    if ($base_plugin_id === NULL) {
      return;
    }

    // Remove the existing UUID-based suggestion that Drupal core generates from
    // the #id property (which is a component UUID in Canvas and contains
    // characters invalid for Twig template filenames).
    $suggestions = \array_values(\array_filter($suggestions, static function (string $suggestion) use ($element): bool {
      // Core adds a suggestion in the format "block__BLOCK_ID". Since Canvas
      // uses a UUID as #id, this suggestion contains dashes and is unusable.
      $id = $element['#id'] ?? '';
      return $id === '' || $suggestion !== 'block__' . $id;
    }));

    // Add region-aware suggestions following Drupal's standard priority order
    // (lowest priority first, highest priority last).
    $suggestions[] = 'block__' . $region;
    $suggestions[] = 'block__' . $region . '__' . $base_plugin_id;
    if ($derivative_plugin_id !== NULL) {
      $suggestions[] = 'block__' . $region . '__' . $base_plugin_id . '__' . $derivative_plugin_id;
    }
  }

}
