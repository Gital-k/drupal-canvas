<?php

declare(strict_types=1);

namespace Drupal\canvas_ai\Service;

/**
 * Interface for the component schema loader service.
 *
 * Provides prop alias and enum value maps derived from the active default
 * theme's SDC component YAML schemas, consumed by DirectEditMatcher for
 * deterministic edit routing.
 *
 * @internal
 *   This interface is for internal use by canvas_ai only. Its signature may
 *   change without notice between minor releases.
 */
interface ComponentSchemaLoaderInterface {

  /**
   * Returns the prop alias map for a component.
   *
   * @param string $componentName
   *   The SDC component name (e.g., 'sdc.theme_name.heading').
   *
   * @return array<string, string>
   *   Map of alias => prop_name. Empty array if component is not found.
   */
  public function getPropAliases(string $componentName): array;

  /**
   * Returns the enum value map for a prop on a specific component.
   *
   * @param string $propName
   *   The canonical prop name (e.g., 'text_color').
   * @param string $componentName
   *   The SDC component name (e.g., 'sdc.theme_name.heading').
   *
   * @return array<string, string>|null
   *   Map of alias => canonical_value, or NULL if the prop has no enum.
   */
  public function getEnumValues(string $propName, string $componentName): ?array;

  /**
   * Returns all component SDC names that have prop aliases defined.
   *
   * @return string[]
   *   List of SDC component names.
   */
  public function getSupportedComponents(): array;

  /**
   * Returns a reverse index mapping normalized enum values to prop names.
   *
   * For each enum value across all props on this component, maps the value
   * back to which props accept it. Used by bare-value inference: values with
   * exactly 1 prop match are unambiguous; multiple matches indicate collision.
   *
   * @param string $componentName
   *   The SDC component name (e.g., 'sdc.theme_name.heading').
   *
   * @return array<string, list<string>>
   *   Map of normalized_value => [prop_name, ...]. Empty array if component
   *   is not found or has no enum props.
   */
  public function getReverseEnumIndex(string $componentName): array;

  /**
   * Returns boolean prop metadata for a component.
   *
   * @param string $componentName
   *   The SDC component name (e.g., 'sdc.theme_name.section').
   *
   * @return array<string, array{aliases: list<string>, inverted: bool}>
   *   Map of prop_name => ['aliases' => [...], 'inverted' => bool].
   *   'inverted' is TRUE for props like 'disabled' where "enable" means FALSE.
   *   Empty array if component is not found or has no boolean props.
   */
  public function getBooleanProps(string $componentName): array;

  /**
   * Returns enum ordinal metadata for relative adjustments.
   *
   * Provides ordered enum values and direction metadata used by relative
   * adjustment logic ("bigger"/"smaller").
   *
   * @param string $componentName
   *   The SDC component name (e.g., 'sdc.theme_name.heading').
   *
   * @return array<string, array{values: list<string>, direction: string}>
   *   Map of prop_name => ['values' => [ordered values], 'direction' =>
   *   'ascending'|'descending']. Empty array if component is not found or
   *   has no enum props.
   */
  public function getEnumOrdinals(string $componentName): array;

  /**
   * Returns valid integer enum values for a prop on a specific component.
   *
   * Integer-typed enums (e.g., heading level [1,2,3,4,5,6]) are stored
   * separately from string enum maps and resolved via this method.
   *
   * @param string $propName
   *   The canonical prop name (e.g., 'level').
   * @param string $componentName
   *   The SDC component name (e.g., 'sdc.theme_name.heading').
   *
   * @return list<int>|null
   *   List of valid integer values, or NULL if the prop has no integer enum.
   */
  public function getIntegerEnumValues(string $propName, string $componentName): ?array;

  /**
   * Returns a reverse index mapping enum aliases to prop names.
   *
   * Similar to getReverseEnumIndex() but includes natural language aliases
   * from buildEnumAliases() and getNaturalAliasesForEnumValue(). Only
   * aliases that map to exactly one prop are included (unambiguous).
   *
   * @param string $componentName
   *   The SDC component name.
   *
   * @return array<string, list<string>>
   *   Map of alias => [prop_name, ...].
   */
  public function getReverseAliasIndex(string $componentName): array;

  /**
   * Returns per-component enum value collision data.
   *
   * Derived from the reverse enum index — any value mapping to 2+ props is
   * a collision. Useful for diagnostics and deciding whether bare-value
   * inference is safe for a component.
   *
   * @return array<string, array{orthogonal: bool, collisions: list<array{value: string, props: list<string>}>}>
   *   Map of sdc_name => ['orthogonal' => bool, 'collisions' => [...]].
   *   A component is orthogonal when it has zero collisions.
   */
  public function getOrthogonalityReport(): array;

}
