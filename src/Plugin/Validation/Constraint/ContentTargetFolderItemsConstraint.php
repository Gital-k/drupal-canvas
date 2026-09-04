<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\Validator\Constraint;

/**
 * Checks that a Folder organizing content entities does not list items.
 *
 * Membership of content entities lives on the entity itself (e.g. Page's
 * `folder` base field), because config must not depend on content.
 *
 * @see \Drupal\canvas\Entity\FolderTargetInterface
 *
 * @internal
 */
#[\Drupal\Core\Validation\Attribute\Constraint(
  id: 'ContentTargetFolderItemsConstraint',
  label: new TranslatableMarkup('Folders of content entities have no items.', [], ['context' => 'Validation']),
  type: ['sequence']
)]
final class ContentTargetFolderItemsConstraint extends Constraint {

  public string $configEntityTypeId;
  public string $message = 'A folder of %entity_type_id entities must not list items: content entities reference their folder themselves.';

  /**
   * {@inheritdoc}
   */
  public function getRequiredOptions(): array {
    return ['configEntityTypeId'];
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOption(): string {
    return 'configEntityTypeId';
  }

}
