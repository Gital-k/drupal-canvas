<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Translation;

// cspell:ignore Cliquez

use Drupal\canvas\Entity\Page;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\canvas\Translation\ComponentTreeTranslator;
use Drupal\canvas\Translation\TranslatableText;
use Drupal\canvas\Translation\TranslatedText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests ComponentTreeTranslator's facade API works with no tmgmt installed.
 *
 * ComponentTreeTranslator is the Canvas-owned, translation-system-agnostic
 * entry point for translating a component tree: ::extract() returns the
 * translatable strings as TranslatableText value objects, and
 * ::applyTranslations() writes TranslatedText value objects back, matched by
 * their explicit identity: component instance UUID + prop path. This test retrieves the service and exercises both paths —
 * crucially, with no tmgmt module installed — so that re-coupling it to tmgmt is
 * caught here rather than passing silently under the tmgmt-gated functional
 * tests (ContentWithComponentTreeTmgmtUiTest, ConfigWithComponentTreeTmgmtUiTest),
 * where tmgmt is always present.
 *
 * This test deliberately references no `#`-prefixed key (no `#translate`,
 * `#translation`, or `#text`). That absence is the proof: the facade API is free
 * of TMGMT's render-array-shaped data structure.
 *
 * @see \Drupal\canvas\Translation\ComponentTreeTranslator
 * @see \Drupal\canvas\Translation\TranslatableText
 * @see \Drupal\canvas\Translation\TranslatedText
 */
#[CoversClass(ComponentTreeTranslator::class)]
#[Group('canvas')]
#[Group('canvas_translation')]
final class ComponentTreeTranslatorTest extends ContentComponentTreeSymmetricalTranslationTestBase {

  private const string STRING_UUID = '11111111-1111-4111-8111-111111111111';

  /**
   * Asserts no translation system is installed; the facade is decoupled from it.
   */
  private function assertNoTmgmt(): void {
    $module_handler = $this->container->get('module_handler');
    self::assertFalse($module_handler->moduleExists('tmgmt'), 'tmgmt must not be installed.');
    self::assertFalse($module_handler->moduleExists('tmgmt_content'), 'tmgmt_content must not be installed.');
  }

  /**
   * Gets the `components` field of a Page built from the given component tree.
   */
  private function componentTreeField(array $tree): ComponentTreeItemList {
    $storage = $this->container->get('entity_type.manager')->getStorage(Page::ENTITY_TYPE_ID);
    $entity = $this->createEntityWithDefaultTranslation(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID, 'components', $storage, $tree);
    $field = $entity->get('components');
    \assert($field instanceof ComponentTreeItemList);
    return $field;
  }

  public function testExtractWithoutTmgmt(): void {
    $this->assertNoTmgmt();

    // A component instance with a single translatable plain-string prop.
    $field = $this->componentTreeField([
      [
        'uuid' => self::STRING_UUID,
        'component_id' => 'sdc.canvas_test_sdc.required-plain-string',
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'inputs' => ['title' => 'Hello'],
      ],
    ]);

    $translator = $this->container->get(ComponentTreeTranslator::class);
    \assert($translator instanceof ComponentTreeTranslator);

    // The translatable string is returned as a TranslatableText value object
    // with explicit identity: component instance UUID + prop path.
    $texts = $translator->extract($field);

    $title_texts = \array_values(\array_filter(
      $texts,
      static fn (TranslatableText $text): bool => $text->propPath === ['title'],
    ));
    self::assertCount(1, $title_texts);
    $text = $title_texts[0];
    self::assertSame(self::STRING_UUID, $text->componentInstanceUuid);
    self::assertSame('Hello', $text->sourceValue);
    self::assertNotSame('', $text->label);
    self::assertNull($text->format);

    // ::createTranslation() copies the identity, so a consumer holding the
    // extracted object cannot mismatch it.
    $translated = $text->createTranslation('Bonjour');
    self::assertSame($text->componentInstanceUuid, $translated->componentInstanceUuid);
    self::assertSame($text->propPath, $translated->propPath);
    self::assertSame('Bonjour', $translated->value);
  }

  public function testApplyTranslationsWithoutTmgmt(): void {
    $this->assertNoTmgmt();

    // A `my-cta` instance: `text` is a translatable string and `href` is a
    // translatable URI nested under a link field's `uri` property.
    $field = $this->componentTreeField([
      [
        'uuid' => self::CTA_UUID,
        'component_id' => 'sdc.canvas_test_sdc.my-cta',
        'component_version' => '::ACTIVE_VERSION_IN_SUT::',
        'inputs' => [
          'text' => 'Click here',
          'href' => ['uri' => 'https://drupal.org', 'options' => []],
        ],
      ],
    ]);

    $translator = $this->container->get(ComponentTreeTranslator::class);
    \assert($translator instanceof ComponentTreeTranslator);

    // Write back translations for a flat leaf (`text`) and a nested leaf
    // (`href.uri`), each a TranslatedText addressing uuid + prop path.
    $translator->applyTranslations($field, [
      new TranslatedText(self::CTA_UUID, ['text'], 'Cliquez ici'),
      new TranslatedText(self::CTA_UUID, ['href', 'uri'], 'https://fr.drupal.org'),
    ]);

    $item = $field->getComponentTreeItemByUuid(self::CTA_UUID);
    self::assertNotNull($item);
    // The flat and nested leaves are translated; the untranslated `options`
    // sibling is preserved and no other keys appear.
    self::assertSameInputs([
      'text' => 'Cliquez ici',
      'href' => ['uri' => 'https://fr.drupal.org', 'options' => []],
    ], $item->getInputs());

    // A translation whose component instance no longer exists is skipped,
    // leaving the existing inputs untouched.
    $translator->applyTranslations($field, [
      new TranslatedText('ffffffff-ffff-4fff-8fff-ffffffffffff', ['text'], 'x'),
    ]);
    $item = $field->getComponentTreeItemByUuid(self::CTA_UUID);
    self::assertNotNull($item);
    self::assertSameInputs([
      'text' => 'Cliquez ici',
      'href' => ['uri' => 'https://fr.drupal.org', 'options' => []],
    ], $item->getInputs());
  }

}
