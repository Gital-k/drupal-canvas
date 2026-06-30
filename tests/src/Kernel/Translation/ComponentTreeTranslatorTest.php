<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Translation;

// cspell:ignore Cliquez

use Drupal\canvas\Entity\Page;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Translation\ComponentTreeTranslator;
use Drupal\Core\Field\FieldItemListInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests ComponentTreeTranslator's write-back works with no tmgmt installed.
 *
 * ComponentTreeTranslator holds the reusable, tmgmt-free component-tree
 * translation logic extracted from ComponentTreeFieldProcessor (which can only
 * be loaded when tmgmt_content is installed, as it extends that module's
 * DefaultFieldProcessor). This test retrieves the service and exercises its
 * write-back path — crucially, with no tmgmt module installed — so that
 * re-coupling it to tmgmt is caught here rather than passing silently under the
 * tmgmt-gated functional tests (ContentWithComponentTreeTmgmtUiTest,
 * ConfigWithComponentTreeTmgmtUiTest), where tmgmt is always present.
 *
 * The extraction path (extractTranslatableData) is left to those functional
 * tests: it builds per-field-type config schemas that need the full set of
 * field modules installed, which is orthogonal to the tmgmt decoupling this
 * test guards.
 *
 * @see \Drupal\canvas\Translation\ComponentTreeTranslator
 * @see \Drupal\canvas\Tmgmt\ComponentTreeFieldProcessor
 */
#[CoversClass(ComponentTreeTranslator::class)]
#[Group('canvas')]
#[Group('canvas_translation')]
final class ComponentTreeTranslatorTest extends ContentComponentTreeSymmetricalTranslationTestBase {

  public function testSetTranslationsWithoutTmgmt(): void {
    // The decoupling under test is only meaningful with no tmgmt present.
    $module_handler = $this->container->get('module_handler');
    self::assertFalse($module_handler->moduleExists('tmgmt'), 'tmgmt must not be installed.');
    self::assertFalse($module_handler->moduleExists('tmgmt_content'), 'tmgmt_content must not be installed.');

    $storage = $this->container->get('entity_type.manager')->getStorage(Page::ENTITY_TYPE_ID);
    // One `my-cta` instance: `text` is a translatable string and `href` is a
    // translatable URI nested under a link field's `uri` property.
    $entity = $this->createEntityWithDefaultTranslation(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID, 'components', $storage, [
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
    $field = $entity->get('components');
    \assert($field instanceof FieldItemListInterface);

    // Retrieving the service already proves it (and its deps) construct without
    // tmgmt; the write-back below proves the logic runs without it too.
    $translator = $this->container->get(ComponentTreeTranslator::class);
    \assert($translator instanceof ComponentTreeTranslator);

    // Write back translations for a flat leaf (`text`) and a nested leaf
    // (`href.uri`); the untranslated `options` sibling is preserved.
    $translator->setTranslations([
      0 => [
        'text' => [
          '#translate' => TRUE,
          '#text' => 'Click here',
          '#translation' => ['#text' => 'Cliquez ici'],
        ],
        'href' => [
          'uri' => [
            '#translate' => TRUE,
            '#text' => 'https://drupal.org',
            '#translation' => ['#text' => 'https://fr.drupal.org'],
          ],
        ],
      ],
    ], $field);

    $item = $field->offsetGet(0);
    \assert($item instanceof ComponentTreeItem);
    // The flat (`text`) and nested (`href.uri`) leaves are translated; the
    // untranslated `options` sibling is preserved and no other keys appear.
    self::assertSame([
      'text' => 'Cliquez ici',
      'href' => ['uri' => 'https://fr.drupal.org', 'options' => []],
    ], $item->getInputs());
  }

}
