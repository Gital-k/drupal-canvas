<?php

declare(strict_types=1);

namespace Drupal\canvas\ShapeMatcher;

use Drupal\canvas\PropShape\PropShape;

/**
 * Interface for prop source matchers.
 *
 * Implementations are collected via the `canvas.prop_source_matcher` service
 * tag.
 *
 * @see \Drupal\canvas\ShapeMatcher\PropSourceSuggester
 * @internal
 */
interface PropSourceMatcherInterface {

  /**
   * Matches this matcher's prop source type against a JSON schema.
   *
   * \Drupal\canvas\ShapeMatcher\PropSourceSuggester::suggest() handles
   * `entity-field` and `adapter` matches with bespoke logic; every other
   * type — including a custom matcher's — is labeled generically, which
   * requires each returned object to implement
   * \Drupal\canvas\PropSource\LinkablePropSourceInterface. A match that
   * doesn't will cause a TypeError when suggestions are labeled.
   *
   * @param bool $is_required
   *   Whether the prop shape to match is required or not.
   * @param \Drupal\canvas\PropShape\PropShape $prop_shape
   *   The prop shape to match.
   * @param string $host_entity_type_id
   *   The host entity type ID.
   * @param string $host_entity_bundle
   *   The host entity bundle.
   *
   * @return list<object>
   *   All matches, empty array if no matches.
   */
  public function match(bool $is_required, PropShape $prop_shape, string $host_entity_type_id, string $host_entity_bundle): array;

  /**
   * The identifier this matcher's matches are keyed by in the raw matches.
   *
   * Used as the array key under which ::match()'s return value is stored in
   * \Drupal\canvas\ShapeMatcher\PropSourceSuggester::getRawMatches()'s
   * result. Every built-in matcher returns one of the
   * \Drupal\canvas\PropSource\PropSource enum's case values, one per
   * matcher.
   *
   * A new, custom matcher (tagged `canvas.prop_source_matcher`) MUST NOT
   * return a value already used by an existing matcher (in practice: any
   * \Drupal\canvas\PropSource\PropSource case value already claimed by a
   * built-in matcher). Two matchers returning the same identifier causes
   * whichever is iterated over last to silently overwrite the other's array
   * of matches in the raw matches, dropping its suggestions entirely.
   *
   * @return string
   *   A unique identifier for this matcher's prop source type.
   */
  public function getPropSourceType(): string;

}
