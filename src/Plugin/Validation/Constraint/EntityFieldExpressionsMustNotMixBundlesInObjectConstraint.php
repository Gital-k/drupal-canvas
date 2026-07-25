<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Rejects a reference object that mixes bundle-specific picks.
 *
 * Reading several fields per bundle from a multi-bundle reference now coalesces
 * into a branch of per-bundle objects. What remains unrenderable is a bundle
 * reading multiple PROPERTIES of one field (e.g. an image field's `alt` and
 * `width`): a branch member reads one field, and an entity-rooted branch object
 * holds one property per field, so neither can carry it. Those picks still
 * coalesce into a `FieldObjectPropsExpression` on the referencer field with a
 * single-bundle reference property while the object references more than one
 * bundle overall. That object is unrenderable: at render time every property is
 * evaluated against the one resolved referenced entity, and a bundle-specific
 * property throws a `\DomainException` whenever the resolved entity is of a
 * different bundle (only multi-bundle *branch* properties are bundle-matched
 * and omitted).
 *
 * @see \Drupal\canvas\PropExpressions\StructuredData\Evaluator::shouldOmitUnmatchedBundle()
 * @see \Drupal\canvas\PropExpressions\StructuredData\EntityObjectPropsExpression
 * @todo Remove this constraint (and its validator) once an entity-rooted branch object can nest a field object, in a follow-up to https://git.drupalcode.org/project/canvas/-/work_items/3591873
 */
#[Constraint(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup("A reference object must not mix bundle-specific picks.", [], ['context' => 'Validation']),
  type: "sequence",
)]
final class EntityFieldExpressionsMustNotMixBundlesInObjectConstraint extends SymfonyConstraint {

  public const string PLUGIN_ID = 'EntityFieldExpressionsMustNotMixBundlesInObject';

  /**
   * The error message.
   */
  public string $message = "The expressions on field '@field' read multiple properties of one field per bundle across bundles, which coalesces into an unrenderable object; this is not yet supported.";

}
