<?php

declare(strict_types=1);

namespace Drupal\canvas_test_linkable_props\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for canvas_test_linkable_props.
 */
class CanvasTestLinkablePropsHooks {

  /**
   * Implements hook_field_widget_info_alter().
   *
   * @todo remove this after https://drupal.org/i/3522130
   */
  #[Hook('field_widget_info_alter')]
  public function fieldWidgetInfoAlter(array &$info): void {
    // Ensure the transforms key exists for text_textfield, which is just us
    // making sure this hook runs after initial transforms are added.
    \assert($info['text_textfield']['canvas']['transforms']);
    // If daterange_default does not have transforms, add a known-useless value
    // just so we can avoid the 500 error that happens when a widget doesn't have
    // transforms at all. We don't use daterange_default in this test, and we
    // don't want the lack of transform support stopping us from using sdc_test_all_props.
    if (!isset($info['daterange_default']['canvas']['transforms'])) {
      $info['daterange_default']['canvas']['transforms'] = [
        'mainProperty' => [],
      ];
    }
  }

}
