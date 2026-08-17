<?php

declare(strict_types=1);

namespace Drupal\canvas_test_block\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A block whose required context is only satisfiable by a node host entity.
 *
 * Unlike CanvasTestBlockRequiresContexts (whose required context has the "any"
 * data type and is therefore satisfiable by any populated global context), this
 * block requires an "entity:node" context. That context is only available when
 * the host entity being edited is a node, so the block demonstrates host-entity
 * dependent component library filtering: present for a node host, absent for a
 * different host and absent with no host.
 */
#[Block(
  id: "canvas_test_block_requires_node_context",
  admin_label: new TranslatableMarkup("Test Block requiring a node context"),
  context_definitions: [
    'node' => new EntityContextDefinition('entity:node', new TranslatableMarkup('Node')),
  ],
)]
final class CanvasTestBlockRequiresNodeContext extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $node = $this->getContextValue('node');
    return ['#markup' => 'Test Block with node context: ' . $node->label()];
  }

}
