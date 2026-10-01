<?php

declare(strict_types=1);

namespace Drupal\canvas\Tmgmt;

use Drupal\canvas\Config\Schema\ComponentInputsMapping;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Translation\ComponentInputsTranslatablesExtractor;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Render\Element;
use Drupal\tmgmt_content\DefaultFieldProcessor;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Thin TMGMT content adapter for component_tree fields.
 *
 * Translatable discovery and write-back are tmgmt-free and delegated to
 * ComponentInputsTranslatablesExtractor. Shaping each component instance's
 * leaves into (and back out of) TMGMT's nested data tree is delegated to
 * TmgmtComponentTreeData. This class only bridges those to the tmgmt_content
 * DefaultFieldProcessor contract so it cannot be loaded without tmgmt; the
 * shared logic can.
 *
 * @see \Drupal\canvas\Translation\ComponentInputsTranslatablesExtractor
 * @see \Drupal\canvas\Tmgmt\TmgmtComponentTreeData
 *
 * @internal
 */
final class ComponentTreeFieldProcessor extends DefaultFieldProcessor implements ContainerInjectionInterface {

  public function __construct(
    private readonly ComponentInputsTranslatablesExtractor $extractor,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(ComponentInputsTranslatablesExtractor::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function extractTranslatableData(FieldItemListInterface $field): array {
    $data = ['#label' => $field->getFieldDefinition()->getLabel()];
    foreach ($field as $delta => $item) {
      \assert($item instanceof ComponentTreeItem);
      $mapping = ComponentInputsMapping::forItem($item);
      $data[$delta] = TmgmtComponentTreeData::encode($this->extractor->extractInstance($mapping, $item->getInputs() ?? []));
    }
    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function setTranslations($field_data, FieldItemListInterface $field): void {
    foreach (Element::children($field_data) as $delta) {
      if (!$field->offsetExists($delta)) {
        continue;
      }
      $item = $field->offsetGet($delta);
      \assert($item instanceof ComponentTreeItem);
      $translations = TmgmtComponentTreeData::decode($field_data[$delta]);
      if (!$translations) {
        continue;
      }
      $mapping = ComponentInputsMapping::forItem($item);
      $item->setInput(ComponentInputsTranslatablesExtractor::applyToInstance($item->getInputs() ?? [], $translations, $mapping));
    }
  }

}
