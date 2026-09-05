<?php

declare(strict_types=1);

namespace Drupal\canvas\Validation\JsonSchema;

use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaStringFormat;
use JsonSchema\Constraints\FormatConstraint;
use JsonSchema\Entity\JsonPointer;

/**
 * Defines a custom JSON Schema "format" constraint validator.
 *
 * This applies custom validation errors for optional JSON Schema validation
 * additions to `type: string, format: …` schemas.
 *
 * Adds:
 * - `x-allowed-schemes` to `format: uri|uri-reference|iri|iri-reference`
 */
final class UriSchemeAwareFormatConstraint extends FormatConstraint {

  /**
   * {@inheritdoc}
   */
  public function check(&$element, $schema = NULL, ?JsonPointer $path = NULL, $i = NULL): void {
    if (!isset($schema->format) || $this->factory->getConfig(self::CHECK_MODE_DISABLE_FORMAT)) {
      return;
    }

    $before = $this->numErrors();
    parent::check($element, $schema, $path, $i);
    $after = $this->numErrors();

    // Expand the check for valid URIs & URI references, to also validate
    // `x-allowed-schemes`, if specified.
    // @see \Drupal\canvas\JsonSchemaInterpreter\JsonSchemaStringFormat::toDataTypeShapeRequirements()
    if ($after === $before && JsonSchemaStringFormat::from($schema->format)->isUriEsque()) {
      // A non-string value (e.g. NULL for an empty required prop sent via the
      // API) cannot be validated as a URI. The parent FormatConstraint should
      // have already added an error for non-string values; if it did not, there
      // is nothing more to check here.
      if (!\is_string($element)) {
        return;
      }
      if (!property_exists($schema, 'x-allowed-schemes')) {
        return;
      }
      $allowed_schemes = $schema->{'x-allowed-schemes'};
      \assert(\is_array($allowed_schemes));
      // If an absolute URL was given, also validate the scheme.
      // @see \Drupal\canvas\Plugin\Validation\Constraint\UriConstraintValidator
      $scheme = parse_url($element, PHP_URL_SCHEME);
      // parse_url() returns false on failure (e.g. for an empty string) and
      // null when no scheme is present. Only validate the scheme when it is a
      // non-empty string.
      if (\is_string($scheme) && !\in_array($scheme, $allowed_schemes, TRUE)) {
        // @phpstan-ignore-next-line staticMethod.notFound
        $this->addError(CustomConstraintError::X_ALLOWED_SCHEMES(), $path, ['scheme' => $scheme]);
      }
    }
  }

}
