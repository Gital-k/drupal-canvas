<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;

/**
 * Checks every key of the validated sequence is an available plugin ID.
 *
 * Config schema cannot attach constraints to a sequence's keys, only to its
 * values. This applies the `FilteredPluginExists` constraint — and hence all of
 * its options — to each key of the validated sequence instead.
 *
 * Violations are reported on the sequence itself rather than on the offending
 * item, because a key is not a validatable config object of its own. The
 * offending key is named in the message.
 *
 * @see \Drupal\canvas\Plugin\Validation\Constraint\FilteredPluginExistsConstraint
 * @see \Drupal\canvas\Plugin\Validation\Constraint\SequenceKeysMatchRegexConstraint
 */
#[Constraint(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Sequence keys are filtered plugin IDs', [], ['context' => 'Validation']),
  type: ['sequence'],
)]
final class SequenceKeysAreFilteredPluginIdsConstraint extends FilteredPluginExistsConstraint {

  public const string PLUGIN_ID = 'SequenceKeysAreFilteredPluginIds';

}
