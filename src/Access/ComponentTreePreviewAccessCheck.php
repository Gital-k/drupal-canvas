<?php

declare(strict_types=1);

namespace Drupal\canvas\Access;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TypedData\TranslatableInterface;

/**
 * Checks access for loading an entity's component tree into the Canvas editor.
 *
 * The editor edits the default translation. Loading the layout of a
 * non-default translation only previews it: it requires the same edit access
 * as the default translation plus 'view' access on the previewed translation,
 * not 'update' access on the previewed translation. Field-level access on the
 * component tree is checked on the default translation only: the previewed
 * translation's tree gets no field access check, matching how preview entities
 * are checked elsewhere.
 *
 * @see \Drupal\canvas\Access\PreviewEntityViewAccessCheck
 * @see \Drupal\canvas\Access\ComponentTreeEditAccessCheck
 *
 * @internal
 */
final class ComponentTreePreviewAccessCheck implements AccessInterface {

  public function __construct(private readonly ComponentTreeEditAccessCheck $editAccessCheck) {}

  /**
   * Checks access for loading an entity's component tree into the editor.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   An entity containing a component tree, in the translation to preview.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account being checked.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(EntityInterface $entity, AccountInterface $account): AccessResultInterface {
    if (!$entity instanceof TranslatableInterface || $entity->isDefaultTranslation()) {
      return $this->editAccessCheck->access($entity, $account);
    }
    $default_translation = $entity->getUntranslated();
    \assert($default_translation instanceof EntityInterface);
    return $this->editAccessCheck->access($default_translation, $account)
      ->andIf($entity->access('view', $account, TRUE));
  }

}
