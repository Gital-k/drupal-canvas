<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Rejects a reference object that mixes bundle-specific picks.
 *
 * Reading several fields per bundle from a multi-bundle reference coalesces
 * into a branch of per-bundle objects, which renders. Picks that cannot take
 * that path fall back to a single `FieldObjectPropsExpression` on the
 * referencer field, and that object is unrenderable whenever a property is a
 * single-bundle reference while the object references more than one bundle
 * overall: at render time every property is evaluated against the one resolved
 * referenced entity, and a bundle-specific property throws a `\DomainException`
 * whenever the resolved entity is of a different bundle (only multi-bundle
 * *branch* properties are bundle-matched and omitted).
 *
 * The message names the symptom, not a cause, because more than one selection
 * reaches that fallback and the constraint cannot tell them apart:
 * - a bundle reading several fields where one of them is read for multiple
 *   PROPERTIES (say an image field's `alt` and `width`, alongside another
 *   field): a branch member reads one field, and an entity-rooted branch object
 *   holds one property per field, so neither can carry the resulting field
 *   object — this is the case Canvas' own UI can produce today;
 * - per-bundle objects that cannot share a shape.
 *
 * (A bundle whose picks collapse onto one developer-facing key does NOT reach
 * this constraint: the object form hits the same collision and leaves the group
 * un-coalesced, which EntityFieldExpressionsSameFieldMustBeCoalesced reports.)
 *
 * (A bundle reading multiple properties of one field and NOTHING else is fine:
 * it is a single entry, so it becomes a plain `FieldObjectPropsExpression`
 * branch of its own.)
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
  public string $message = "The expressions on field '@field' coalesce into an object that mixes picks from more than one bundle, which cannot be rendered; this is not yet supported. Reading several fields per bundle is supported, but one of them being read for multiple properties is not.";

}
