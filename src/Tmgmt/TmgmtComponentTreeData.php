<?php

declare(strict_types=1);

namespace Drupal\canvas\Tmgmt;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Render\Element;

/**
 * Converts between Canvas's flat translatable strings and TMGMT's data tree.
 *
 * The single place that knows TMGMT's data structure (the `#translate` /
 * `#translation` / `#text` / `#label` / `#format` nested tree, and the `∅`
 * empty-set placeholder). Canvas's translation engine — the
 * ComponentInputsTranslatablesExtractor — speaks flat dotted-prop-path leaves;
 * the TMGMT field/config processors use this class to
 * encode those leaves into TMGMT's tree and decode accepted translations back.
 *
 * @see \Drupal\canvas\Tmgmt\ComponentTreeFieldProcessor
 * @see \Drupal\canvas\Tmgmt\ComponentInputsConfigProcessor
 * @see \Drupal\canvas\Translation\ComponentInputsTranslatablesExtractor
 *
 * @internal
 */
final class TmgmtComponentTreeData {

  /**
   * Placeholder source text for a translatable-but-empty optional prop.
   *
   * TMGMT's flattening (\Drupal\tmgmt\Data::flatten()) strips empty strings, so
   * an unpopulated-but-translatable optional prop would never reach the
   * translator. Emitting a non-empty placeholder keeps it present.
   */
  public const string EMPTY_PLACEHOLDER = '∅';

  /**
   * Encodes flat translatable leaves into a TMGMT data tree.
   *
   * @param array<string, array{propPath: list<string|int>, label: string, sourceValue: string, format: string|null}> $leaves
   *   Translatable leaves keyed by dotted prop path, as returned by
   *   ComponentInputsTranslatablesExtractor::extractInstance().
   *
   * @return array
   *   A TMGMT data tree: translatable leaves carry `#label`, `#text`,
   *   `#translate` (and `#format` for rich text); intermediate nodes carry a
   *   `#label`.
   */
  public static function encode(array $leaves): array {
    $tree = [];
    foreach ($leaves as $leaf) {
      $node = [
        '#label' => $leaf['label'],
        '#text' => $leaf['sourceValue'] === '' ? self::EMPTY_PLACEHOLDER : $leaf['sourceValue'],
        '#translate' => TRUE,
      ];
      if ($leaf['format'] !== NULL) {
        $node['#format'] = $leaf['format'];
      }
      NestedArray::setValue($tree, $leaf['propPath'], $node);
      // Ensure each intermediate node carries a #label (TMGMT groups by it).
      $prefix = [];
      foreach (\array_slice($leaf['propPath'], 0, -1) as $segment) {
        $prefix[] = $segment;
        $label_path = [...$prefix, '#label'];
        if (NestedArray::getValue($tree, $label_path) === NULL) {
          NestedArray::setValue($tree, $label_path, (string) $segment);
        }
      }
    }
    return $tree;
  }

  /**
   * Decodes accepted translations from a TMGMT data tree.
   *
   * @param array $tree
   *   A single component instance's TMGMT data tree, with each translatable
   *   leaf carrying its accepted `#translation['#text']`.
   *
   * @return array<string, string>
   *   Translated strings keyed by dotted prop path, ready for
   *   ComponentInputsTranslatablesExtractor::applyToInstance().
   */
  public static function decode(array $tree): array {
    return self::collect($tree, []);
  }

  /**
   * Recursively collects accepted translations, keyed by dotted prop path.
   *
   * @param array $node
   *   The current TMGMT data node.
   * @param list<string|int> $path
   *   The prop path accumulated so far.
   *
   * @return array<string, string>
   *   Translated strings keyed by dotted prop path.
   */
  private static function collect(array $node, array $path): array {
    if (\array_key_exists('#translate', $node) && $node['#translate'] === TRUE) {
      if (isset($node['#translation']['#text'])) {
        return [\implode('.', $path) => (string) $node['#translation']['#text']];
      }
      return [];
    }
    $result = [];
    foreach (Element::children($node) as $key) {
      if (\is_array($node[$key])) {
        $result += self::collect($node[$key], [...$path, $key]);
      }
    }
    return $result;
  }

}
