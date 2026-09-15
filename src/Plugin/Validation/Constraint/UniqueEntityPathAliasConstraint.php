<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Requires an entity's URL alias to be unused by any other path.
 *
 * Core only checks this for the `path_alias` entity that stores the alias, and
 * that entity is created by PathItem::postSave() — after the host entity was
 * saved, through a programmatic save that never validates. The only place core
 * runs the check for a host entity is its path field *widget*, on form submit.
 *
 * Canvas edits pages through its HTTP API instead of a Drupal form, so the
 * widget never runs and duplicate aliases were persisted silently. This
 * constraint restores the check where Canvas validates: on the host entity, so
 * it applies when creating, updating and publishing a page alike.
 *
 * @see \Drupal\path\Plugin\Field\FieldType\PathItem::postSave()
 * @see \Drupal\path\Plugin\Field\FieldWidget\PathWidget::validateFormElement()
 * @see \Drupal\Core\Path\Plugin\Validation\Constraint\UniquePathAliasConstraint
 */
#[Constraint(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Unique entity path alias', [], ['context' => 'Validation']),
)]
final class UniqueEntityPathAliasConstraint extends SymfonyConstraint {

  public const string PLUGIN_ID = 'UniqueEntityPathAlias';

}
