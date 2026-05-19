<?php

declare(strict_types=1);

namespace Drupal\canvas\CanvasForm;

use Drupal\canvas\Entity\CanvasForm;

/**
 * Converts a CanvasForm entity back to a PHP Form API render array.
 *
 * Useful for migrating builder-created forms into custom form classes.
 */
class FormArrayExporter {

  public function export(CanvasForm $form): array {
    return $form->toRenderArray();
  }

  public function exportAsPhp(CanvasForm $form): string {
    $array = $form->toRenderArray();
    return $this->arrayToPhp($array, 1);
  }

  private function arrayToPhp(array $array, int $depth): string {
    $indent = str_repeat('  ', $depth);
    $innerIndent = str_repeat('  ', $depth + 1);
    $lines = [];

    foreach ($array as $key => $value) {
      $keyStr = is_string($key) ? "'" . addslashes($key) . "'" : $key;
      if (is_array($value)) {
        $lines[] = $innerIndent . $keyStr . ' => ' . $this->arrayToPhp($value, $depth + 1) . ',';
      }
      elseif (is_bool($value)) {
        $lines[] = $innerIndent . $keyStr . ' => ' . ($value ? 'TRUE' : 'FALSE') . ',';
      }
      elseif (is_null($value)) {
        $lines[] = $innerIndent . $keyStr . ' => NULL,';
      }
      elseif (is_numeric($value)) {
        $lines[] = $innerIndent . $keyStr . ' => ' . $value . ',';
      }
      else {
        $lines[] = $innerIndent . $keyStr . " => '" . addslashes((string) $value) . "',";
      }
    }

    if (empty($lines)) {
      return '[]';
    }

    return "[\n" . implode("\n", $lines) . "\n" . $indent . ']';
  }

}
