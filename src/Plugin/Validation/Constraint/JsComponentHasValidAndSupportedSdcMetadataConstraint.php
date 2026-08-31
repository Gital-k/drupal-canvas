<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Validates that a code component maps onto an SDC definition Canvas supports.
 */
#[Constraint(
  id: 'JsComponentHasValidAndSupportedSdcMetadata',
  // @see docs/shape-matching-into-field-types.md, section 3.1.2.a
  label: new TranslatableMarkup('Maps to valid SDC definition, and meets Canvas requirements.', [], ['context' => 'Validation']),
  type: [
    'canvas.js_component.*',
  ],
)]
final class JsComponentHasValidAndSupportedSdcMetadataConstraint extends SymfonyConstraint {}
