<?php

declare(strict_types=1);

namespace Drupal\canvas\PropSource;

/**
 * Looks up custom prop source types tagged `canvas.prop_source_type`.
 *
 * @see \Drupal\canvas\PropSource\PropSource::parse()
 * @internal
 */
final class PropSourceTypeRegistry {

  /**
   * Constructs a PropSourceTypeRegistry object.
   *
   * @param \Traversable<PropSourceTypeInterface> $propSourceTypes
   *   All custom prop source types, tagged with `canvas.prop_source_type`.
   */
  public function __construct(
    private readonly \Traversable $propSourceTypes,
  ) {}

  /**
   * Finds the tagged type matching a `sourceType` prefix, if any.
   */
  public function getBySourceTypePrefix(string $prefix): ?PropSourceTypeInterface {
    foreach ($this->propSourceTypes as $type) {
      if ($type->getSourceTypePrefix() === $prefix) {
        return $type;
      }
    }
    return NULL;
  }

}
