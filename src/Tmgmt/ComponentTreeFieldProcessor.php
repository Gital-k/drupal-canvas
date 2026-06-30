<?php

declare(strict_types=1);

namespace Drupal\canvas\Tmgmt;

use Drupal\canvas\Translation\ComponentTreeTranslator;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\tmgmt_content\DefaultFieldProcessor;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * TMGMT field processor for component_tree fields.
 *
 * Thin adapter: the extract/write-back logic is tmgmt-free and lives in
 * \Drupal\canvas\Translation\ComponentTreeTranslator. This class only adapts it
 * to the tmgmt_content DefaultFieldProcessor contract so it cannot be loaded
 * without tmgmt; the shared logic can.
 *
 * @see \Drupal\canvas\Translation\ComponentTreeTranslator
 */
final class ComponentTreeFieldProcessor extends DefaultFieldProcessor implements ContainerInjectionInterface {

  public function __construct(
    private readonly ComponentTreeTranslator $componentTreeTranslator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(ComponentTreeTranslator::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function extractTranslatableData(FieldItemListInterface $field): array {
    return $this->componentTreeTranslator->extractTranslatableData($field);
  }

  /**
   * {@inheritdoc}
   */
  public function setTranslations($field_data, FieldItemListInterface $field): void {
    $this->componentTreeTranslator->setTranslations($field_data, $field);
  }

}
