<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\Validator\Constraint;

/**
 * Checks that the Folder Item isn't assigned to a different Folder already.
 *
 * @internal
 */
#[\Drupal\Core\Validation\Attribute\Constraint(
  id: 'OneFolderPerItemLimitConstraint',
  label: new TranslatableMarkup('Item can only be assigned to one Folder.', [], ['context' => 'Validation']),
  type: ['string']
)]
final class OneFolderPerItemLimitConstraint extends Constraint {
  /**
   * Entity type ID of the folder config entity type to search for conflicts.
   */
  public string $configEntityTypeId;

  /**
   * ID of the folder the item is being assigned to, excluded from the search.
   */
  public string $id;

  /**
   * Violation message for an item already assigned to another folder.
   */
  public string $limitViolated = 'Folder item %item_id is already assigned to folder %folder_name';

  /**
   * {@inheritdoc}
   */
  public function getRequiredOptions(): array {
    return ['id', 'configEntityTypeId'];
  }

}
