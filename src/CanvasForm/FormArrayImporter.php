<?php

declare(strict_types=1);

namespace Drupal\canvas\CanvasForm;

use Drupal\canvas\Entity\CanvasForm;
use Drupal\Component\Uuid\UuidInterface;

/**
 * Parses a Drupal Form API render array into a CanvasForm entity structure.
 *
 * Only elements that could have been produced by the form builder are fully
 * supported. Unknown element types are imported with their properties preserved
 * but will render as-is without builder editing support.
 */
class FormArrayImporter {

  public function __construct(
    private readonly FormElementSchemaProvider $schemaProvider,
    private readonly UuidInterface $uuid,
  ) {}

  public function import(array $renderArray, string $label = 'Imported form'): CanvasForm {
    $elements = [];
    $this->parseElements($renderArray, NULL, 0, $elements);

    return CanvasForm::create([
      'label' => $label,
      'form_elements' => $elements,
    ]);
  }

  private function parseElements(array $items, ?string $parentUuid, int &$weight, array &$elements): void {
    foreach ($items as $key => $item) {
      if (!is_array($item) || str_starts_with((string) $key, '#')) {
        continue;
      }

      $type = $item['#type'] ?? NULL;
      if (!$type) {
        continue;
      }

      $uuid = $this->uuid->generate();
      $properties = [];

      foreach ($item as $prop => $value) {
        if (!str_starts_with($prop, '#') || $prop === '#type') {
          continue;
        }
        $propName = substr($prop, 1);
        if ($this->isStaticValue($value)) {
          $properties[$propName] = $value;
        }
      }

      $elements[] = [
        'uuid' => $uuid,
        'type' => $type,
        'machine_name' => (string) $key,
        'properties' => $properties,
        'weight' => $weight++,
        'parent' => $parentUuid,
      ];

      $schema = $this->schemaProvider->getElementSchema($type);
      if ($schema && $schema['canContainChildren']) {
        $childWeight = 0;
        $this->parseElements($item, $uuid, $childWeight, $elements);
      }
    }
  }

  private function isStaticValue(mixed $value): bool {
    if (is_object($value)) {
      return FALSE;
    }
    if (is_callable($value) && !is_string($value) && !is_array($value)) {
      return FALSE;
    }
    if (is_array($value)) {
      foreach ($value as $v) {
        if (is_callable($v) && !is_string($v)) {
          return FALSE;
        }
      }
    }
    return TRUE;
  }

}
