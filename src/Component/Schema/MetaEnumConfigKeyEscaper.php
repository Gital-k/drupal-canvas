<?php

declare(strict_types=1);

namespace Drupal\canvas\Component\Schema;

/**
 * Escapes `meta:enum` keys so that prop schemas can be stored in config.
 *
 * A prop's `enum` values double as the keys of its `meta:enum` label map. JSON
 * Schema allows any value there, but Drupal's config system forbids a dot in a
 * config key — so an enum offering `3.14` labeled `Pi` could not be stored by
 * the config entity that persists a code component's prop schemas.
 *
 * ⚠️ A dot is the only character a config key cannot contain, so it is the only
 * character that needs escaping. In particular a colon does not: `meta:enum` is
 * itself a config key.
 *
 * Escaping is percent-encoding restricted to two sequences: `%` becomes `%25`
 * and `.` becomes `%2E`. Escaping `%` first is what makes this lossless for an
 * enum value that itself contains a `%2E` or `%25` sequence: after escaping, a
 * `%` only ever occurs as the first character of one of those two sequences, so
 * unescaping in a single left-to-right pass recovers the original exactly.
 *
 * Code components hold prop schemas in config, so they hold the escaped form —
 * both in storage and in memory. This keeps the representation identical across
 * every boundary that cannot tell which direction data is flowing (config save
 * and load, auto-save snapshots, `::create()`). Escaping happens where a prop
 * schema arrives from outside config, and unescaping where one leaves for a
 * consumer that reads `enum` values, which is why neither the client nor the
 * rest of Canvas ever encounters an escaped key.
 *
 * @see \Drupal\Core\Config\ConfigBase::validateKeys()
 * @see \Drupal\canvas\Entity\JavaScriptComponent::updateFromClientSide()
 * @see \Drupal\canvas\Entity\JavaScriptComponent::toSdcDefinition()
 *
 * @internal
 */
final class MetaEnumConfigKeyEscaper {

  /**
   * The escape sequences, keyed by the character each one replaces.
   *
   * ⚠️ The order matters: `%` MUST be escaped before `.`, because escaping `.`
   * introduces a `%`.
   */
  private const ESCAPE_SEQUENCES = [
    '%' => '%25',
    '.' => '%2E',
  ];

  /**
   * Escapes every `meta:enum` key in the given prop schemas.
   *
   * @param array<string, mixed> $props
   *   Prop schemas, keyed by prop name.
   *
   * @return array<string, mixed>
   *   The same prop schemas, with config-safe `meta:enum` keys.
   */
  public static function escape(array $props): array {
    return self::convert($props, TRUE);
  }

  /**
   * Unescapes every `meta:enum` key in the given prop schemas.
   *
   * @param array<string, mixed> $props
   *   Prop schemas, keyed by prop name, with config-safe `meta:enum` keys.
   *
   * @return array<string, mixed>
   *   The same prop schemas, with the original `meta:enum` keys.
   */
  public static function unescape(array $props): array {
    return self::convert($props, FALSE);
  }

  /**
   * Rewrites the keys of every `meta:enum` found in a (sub-)schema.
   *
   * TRICKY: `meta:enum` occurs at the root of a prop's schema, and inside the
   * `items` of an array prop. Recursing instead of checking those two spots
   * keeps this correct for whichever shapes Canvas supports.
   *
   * @param array<string, mixed> $schema
   *   The (sub-)schema to rewrite.
   * @param bool $escape
   *   TRUE to escape, FALSE to unescape.
   *
   * @return array<string, mixed>
   *   The rewritten (sub-)schema.
   */
  private static function convert(array $schema, bool $escape): array {
    foreach ($schema as $key => $value) {
      if (!\is_array($value)) {
        continue;
      }
      if ($key !== 'meta:enum') {
        $schema[$key] = self::convert($value, $escape);
        continue;
      }
      // TRICKY: a `meta:enum` may also be a list, in which case its labels are
      // matched to `enum` values by position. Integer keys survive this
      // unchanged, so such a list stays a list.
      // @see \Drupal\canvas\Component\Schema\PropChoiceOptionsResolver::resolveEnumOptions()
      $converted = [];
      foreach ($value as $enum_value => $label) {
        $converted[$escape ? self::escapeKey((string) $enum_value) : self::unescapeKey((string) $enum_value)] = $label;
      }
      $schema[$key] = $converted;
    }
    return $schema;
  }

  /**
   * Escapes a single `meta:enum` key.
   *
   * @param string $key
   *   An enum value, stringified.
   *
   * @return string
   *   A key that Drupal's config system accepts.
   */
  private static function escapeKey(string $key): string {
    return \str_replace(\array_keys(self::ESCAPE_SEQUENCES), \array_values(self::ESCAPE_SEQUENCES), $key);
  }

  /**
   * Unescapes a single `meta:enum` key.
   *
   * ⚠️ This MUST NOT be a \str_replace() undoing ::escapeKey(): replacing the
   * sequences one after the other would unescape what the other sequence just
   * produced. A single pass over the key cannot.
   *
   * @param string $key
   *   A key produced by ::escapeKey().
   *
   * @return string
   *   The enum value it was produced from.
   */
  private static function unescapeKey(string $key): string {
    $unescaped_by_sequence = \array_flip(self::ESCAPE_SEQUENCES);
    return \preg_replace_callback(
      '/' . \implode('|', \array_map(static fn (string $sequence): string => \preg_quote($sequence, '/'), \array_values(self::ESCAPE_SEQUENCES))) . '/',
      static fn (array $matches): string => $unescaped_by_sequence[$matches[0]],
      $key,
    ) ?? $key;
  }

}
