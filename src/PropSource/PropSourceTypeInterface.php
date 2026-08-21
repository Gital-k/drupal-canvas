<?php

declare(strict_types=1);

namespace Drupal\canvas\PropSource;

/**
 * Interface for custom prop source types.
 *
 * Implementations are collected via the `canvas.prop_source_type` service
 * tag. `PropSource::parse()` consults the tagged registry whenever a
 * `sourceType` prefix does not match one of its own built-in enum cases,
 * allowing another module's `PropSourceBase` subclass to be reconstructed
 * from storage the same way a built-in one is.
 *
 * The `PropSourceBase` subclass this parses into MUST override
 * `::getSourceType()` to return the same prefix as ::getSourceTypePrefix()
 * below. `PropSource::getTypePrefix()` — used by e.g.
 * `ComponentInputs::getPropSourceTypes()` — falls back to calling an
 * instance's own `::getSourceType()` for any class outside its built-in set;
 * `PropSourceBase`'s own default implementation of `::getSourceType()` calls
 * back into `PropSource::getTypePrefix()`, which would recurse infinitely if
 * left un-overridden.
 *
 * @see \Drupal\canvas\PropSource\PropSource::parse()
 * @see \Drupal\canvas\PropSource\PropSource::getTypePrefix()
 * @see \Drupal\canvas\PropSource\PropSourceTypeRegistry
 * @internal
 */
interface PropSourceTypeInterface {

  /**
   * The `sourceType` prefix this type parses, e.g. `commerce-field`.
   *
   * MUST NOT collide with a built-in \Drupal\canvas\PropSource\PropSource
   * enum case value, nor with another tagged type's prefix.
   */
  public function getSourceTypePrefix(): string;

  /**
   * Reconstructs a PropSourceBase from its array representation.
   *
   * @param array $sdc_prop_source
   *   A prop source's array representation, as produced by that same
   *   PropSourceBase subclass's own ::toArray().
   */
  public function parse(array $sdc_prop_source): PropSourceBase;

}
