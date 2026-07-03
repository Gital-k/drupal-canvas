<?php

declare(strict_types=1);

namespace Drupal\canvas\Translation;

use Drupal\canvas\Config\Schema\ComponentInputsMapping;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;

/**
 * Translates a component tree: the Canvas-owned, translation-agnostic API.
 *
 * The consumer-facing entry point for translating a `component_tree` (content
 * field or config entity tree). A consumer does exactly two things and never
 * sees Canvas storage internals or any translation system's data structures:
 *
 * - ::extract() returns the translatable strings as TranslatableText value
 *   objects, each with a stable, opaque $id.
 * - ::applyTranslations() writes translations back, keyed by those same $ids.
 *
 * Identity is the component instance's UUID (not its position), so translations
 * collected against one revision apply correctly to another even if components
 * were reordered in between.
 *
 * The per-instance discovery and write-back is delegated to
 * ComponentInputsTranslatablesExtractor; this class adds tree iteration and the
 * stable, tree-wide identity.
 *
 * @see \Drupal\canvas\Translation\TranslatableText
 * @see \Drupal\canvas\Translation\ComponentInputsTranslatablesExtractor
 *
 * @internal
 */
final class ComponentTreeTranslator {

  public function __construct(
    private readonly ComponentInputsTranslatablesExtractor $componentInputsTranslatablesExtractor,
  ) {}

  /**
   * Extracts the translatable strings from a component tree.
   *
   * @param \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList $tree
   *   A component tree (a content `component_tree` field, or a config entity's
   *   tree obtained via ComponentTreeEntityInterface::getComponentTree()).
   *
   * @return array<string, \Drupal\canvas\Translation\TranslatableText>
   *   Translatable strings keyed by their stable $id.
   */
  public function extract(ComponentTreeItemList $tree): array {
    $result = [];
    foreach ($tree as $item) {
      \assert($item instanceof ComponentTreeItem);
      $uuid = $item->getUuid();
      $mapping = ComponentInputsMapping::forItem($item);
      foreach ($this->componentInputsTranslatablesExtractor->extractInstance($mapping, $item->getInputs() ?? []) as $dotted_path => $leaf) {
        $id = $uuid . '.' . $dotted_path;
        $result[$id] = new TranslatableText(
          id: $id,
          componentInstanceUuid: $uuid,
          propPath: $leaf['propPath'],
          label: $leaf['label'],
          sourceValue: $leaf['sourceValue'],
          format: $leaf['format'],
        );
      }
    }
    return $result;
  }

  /**
   * Writes translations back into a component tree.
   *
   * An $id whose component instance no longer exists is skipped (e.g. a
   * component deleted after a draft was saved); untranslated inputs of every
   * instance are preserved.
   *
   * @param \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList $tree
   *   The target translation's component tree.
   * @param array<string, string> $translations
   *   Translated strings keyed by the $id produced by ::extract().
   */
  public function applyTranslations(ComponentTreeItemList $tree, array $translations): void {
    // Group by component instance UUID; an $id is "<uuid>.<dotted-prop-path>"
    // and a UUID contains no '.'.
    $by_uuid = [];
    foreach ($translations as $id => $text) {
      [$uuid, $dotted_path] = \explode('.', (string) $id, 2) + [1 => ''];
      if ($dotted_path === '') {
        continue;
      }
      $by_uuid[$uuid][$dotted_path] = $text;
    }

    foreach ($by_uuid as $uuid => $instance_translations) {
      $item = $tree->getComponentTreeItemByUuid($uuid);
      if ($item === NULL) {
        continue;
      }
      $mapping = ComponentInputsMapping::forItem($item);
      $item->setInput(ComponentInputsTranslatablesExtractor::applyToInstance(
        $item->getInputs() ?? [],
        $instance_translations,
        $mapping,
      ));
    }
  }

}
