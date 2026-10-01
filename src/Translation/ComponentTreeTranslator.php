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
 *   objects.
 * - ::applyTranslations() writes TranslatedText value objects back, matched by
 *   their explicit identity: component instance UUID + prop path.
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
 * @see \Drupal\canvas\Translation\TranslatedText
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
   * @return list<\Drupal\canvas\Translation\TranslatableText>
   *   Translatable strings, in tree order.
   */
  public function extract(ComponentTreeItemList $tree): array {
    $result = [];
    foreach ($tree as $item) {
      \assert($item instanceof ComponentTreeItem);
      $uuid = $item->getUuid();
      $mapping = ComponentInputsMapping::forItem($item);
      foreach ($this->componentInputsTranslatablesExtractor->extractInstance($mapping, $item->getInputs() ?? []) as $leaf) {
        $result[] = new TranslatableText(
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
   * A translation whose component instance no longer exists is skipped (e.g. a
   * component deleted after a draft was saved); untranslated inputs of every
   * instance are preserved.
   *
   * @param \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList $tree
   *   The target translation's component tree.
   * @param list<\Drupal\canvas\Translation\TranslatedText> $translated_texts
   *   The translations to write back.
   */
  public static function applyTranslations(ComponentTreeItemList $tree, array $translated_texts): void {
    $by_uuid = [];
    foreach ($translated_texts as $translated_text) {
      if ($translated_text->propPath === []) {
        continue;
      }
      // ComponentInputsTranslatablesExtractor::applyToInstance() addresses
      // leaves by the same dotted prop path its ::extractInstance() emits.
      $by_uuid[$translated_text->componentInstanceUuid][\implode('.', $translated_text->propPath)] = $translated_text->value;
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
