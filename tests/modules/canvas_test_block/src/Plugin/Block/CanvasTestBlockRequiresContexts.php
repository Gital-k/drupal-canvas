<?php

declare(strict_types=1);

namespace Drupal\canvas_test_block\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

#[Block(
  id: "canvas_test_block_requires_contexts",
  admin_label: new TranslatableMarkup("Test Block with required contexts"),
  context_definitions: [
    'name' => new ContextDefinition(),
  ],
)]
final class CanvasTestBlockRequiresContexts extends BlockBase {

  /**
   * {@inheritdoc}
   *
   * Auto-assign the required "name" context to a global context that is always
   * populated, so the block is satisfiable without a host entity. This mirrors
   * how real required-context blocks (for example field blocks) auto-assign
   * their context mapping. The value is resolved at render time, so it proves
   * context application rather than masking a missing value with a default.
   */
  public function defaultConfiguration(): array {
    return [
      'context_mapping' => [
        'name' => '@user.current_user_context:current_user',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $value = $this->getContextValue('name');
    $name = $value instanceof EntityInterface ? $value->label() : (string) $value;
    return ['#markup' => 'Test Block with required context value: ' . $name];
  }

}
