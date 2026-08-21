<?php

declare(strict_types=1);

namespace Drupal\canvas_test_block\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

#[Block(
  id: "canvas_test_block_optional_contexts",
  admin_label: new TranslatableMarkup("Test Block with optional contexts"),
  context_definitions: [
    'name' => new ContextDefinition(required: FALSE),
  ],
)]
final class CanvasTestBlockOptionalContexts extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $context = $this->getContext('name');
    if (!$context->hasContextValue()) {
      return ['#markup' => 'Test Block with no context value.'];
    }
    $value = $context->getContextValue();
    $name = $value instanceof EntityInterface ? $value->label() : (string) $value;
    return ['#markup' => 'Test Block with optional context value: ' . $name];
  }

}
