<?php

declare(strict_types=1);

namespace Drupal\canvas_test_adapter\Plugin\Adapter\Deriver;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Derives one `canvas_test_string_case` adapter per string case transformation.
 *
 * Also keeps the base plugin available, so that a prop source referring to the
 * base plugin instead of a derivative resolves to a valid — but different —
 * adapter.
 */
final class StringCaseDeriver extends DeriverBase {

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition): array {
    \assert(\is_array($base_plugin_definition));
    // Keep `canvas_test_string_case` itself available, as a no-op adapter.
    $this->derivatives[''] = $base_plugin_definition;
    foreach (['upper' => 'Uppercase', 'lower' => 'Lowercase'] as $derivative_id => $label) {
      $this->derivatives[$derivative_id] = [
        'label' => new TranslatableMarkup('String case: @case', ['@case' => $label]),
      ] + $base_plugin_definition;
    }
    return $this->derivatives;
  }

}
