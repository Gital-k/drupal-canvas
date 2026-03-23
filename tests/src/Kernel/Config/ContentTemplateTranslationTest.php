<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Config;

use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\language\Config\LanguageConfigFactoryOverrideInterface;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Verifies that programmatic config language overrides affect ContentTemplate.
 *
 * This test proves that Drupal core's config_translation override system works
 * correctly with Canvas ContentTemplate config entities without any
 * Canvas-specific translation code beyond the schema alteration in
 * canvas_dev_translation.
 */
#[Group('canvas')]
#[Group('canvas_dev_translation')]
#[RunTestsInSeparateProcesses]
final class ContentTemplateTranslationTest extends CanvasKernelTestBase {

  use GenerateComponentConfigTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'field',
    'node',
    'language',
    'locale',
    'config_translation',
    'canvas_dev_translation',
  ];

  private const COMPONENT_ID = 'sdc.canvas_test_sdc.props-no-slots';

  private const ENGLISH_COMPONENT_TREE = [
    [
      'uuid' => '1bd49e3e-2d5e-4b62-95f0-4a3393d5a9e1',
      'component_id' => self::COMPONENT_ID,
      'component_version' => 'b1e991f726a2a266',
      'inputs' => [
        'heading' => 'Hello from English!',
      ],
    ],
  ];

  private const FRENCH_COMPONENT_TREE = [
    [
      'uuid' => '2ce7a4f1-3e6d-4c73-a6b1-5b4404e6baf2',
      'component_id' => self::COMPONENT_ID,
      'component_version' => 'b1e991f726a2a266',
      'inputs' => [
        'heading' => 'French content template heading',
      ],
    ],
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['language', 'node']);
    $this->installEntitySchema('configurable_language');
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->generateComponentConfig();
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    ConfigurableLanguage::createFromLangcode('fr')->save();
  }

  /**
   * Tests that a French LanguageConfigOverride replaces the component tree.
   *
   * Proves that:
   * 1. component_tree is marked translatable in the config schema.
   * 2. A French override can be stored and round-trips correctly via the
   *    LanguageConfigFactoryOverrideInterface service.
   * 3. Reading the override via the language manager returns the French tree.
   * 4. The English default config is not mutated by creating the override.
   */
  public function testFrenchOverrideReplacesComponentTree(): void {
    $overrideFactory = \Drupal::service(LanguageConfigFactoryOverrideInterface::class);

    // 1. Schema is translatable.
    $definitions = \Drupal::service(TypedConfigManagerInterface::class)->getDefinitions();
    $translatable = $definitions['canvas.content_template.*.*.*']['mapping']['component_tree']['translatable'] ?? FALSE;
    self::assertTrue($translatable, 'component_tree must be marked translatable by canvas_dev_translation schema alter.');

    // Create the default (English) template.
    $template = ContentTemplate::create([
      'id' => 'node.article.full',
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => self::ENGLISH_COMPONENT_TREE,
    ]);
    $template->save();

    // 2. Default config holds English tree.
    $defaultTree = \Drupal::config('canvas.content_template.node.article.full')->get('component_tree');
    self::assertSame(self::ENGLISH_COMPONENT_TREE, $defaultTree, 'Default (English) component tree must be stored correctly.');

    // 3. Store French override and verify the round-trip via the override
    //    service — this is how Drupal stores config translations and how
    //    content is served when the active language is French.
    $overrideFactory
      ->getOverride('fr', 'canvas.content_template.node.article.full')
      ->set('component_tree', self::FRENCH_COMPONENT_TREE)
      ->save();

    $frenchTree = $overrideFactory
      ->getOverride('fr', 'canvas.content_template.node.article.full')
      ->get('component_tree');

    self::assertSame(self::FRENCH_COMPONENT_TREE, $frenchTree, 'The French language config override must contain the French component tree.');
    self::assertNotEquals(self::ENGLISH_COMPONENT_TREE, $frenchTree, 'The French override must differ from the English default tree.');

    // 4. The language manager's override-aware getter merges the stored
    //    override on top of the default config. This is the same data path
    //    Drupal uses when serving a French-language request: the config
    //    factory calls LanguageConfigFactoryOverride::loadOverrides(), which
    //    reads exactly the same underlying storage as getLanguageConfigOverride.
    $french = \Drupal::languageManager()->getLanguage('fr');
    self::assertNotNull($french, 'French language must be available.');

    $languageManager = \Drupal::languageManager();
    self::assertInstanceOf(ConfigurableLanguageManagerInterface::class, $languageManager);
    $frenchOverrideConfig = $languageManager
      ->getLanguageConfigOverride('fr', 'canvas.content_template.node.article.full');
    $overriddenTree = $frenchOverrideConfig->get('component_tree');

    self::assertSame(
      self::FRENCH_COMPONENT_TREE,
      $overriddenTree,
      'The language manager must return the French component tree for the fr override object.',
    );

    // 5. The English default must remain unaffected by the French override.
    $defaultTreeAfterOverride = \Drupal::config('canvas.content_template.node.article.full')->get('component_tree');
    self::assertSame(
      self::ENGLISH_COMPONENT_TREE,
      $defaultTreeAfterOverride,
      'Creating a French override must not mutate the English default config.',
    );

    // 6. UUID-level proof: French and English trees contain distinct components.
    $frenchUuids = array_column($frenchTree, 'uuid');
    self::assertContains('2ce7a4f1-3e6d-4c73-a6b1-5b4404e6baf2', $frenchUuids, 'French component UUID must be present in the override tree.');
    self::assertNotContains('1bd49e3e-2d5e-4b62-95f0-4a3393d5a9e1', $frenchUuids, 'English component UUID must not appear in the French override tree.');
  }

  /**
   * Tests that the English default is served when no French override exists.
   */
  public function testDefaultTreeServedWhenNoOverrideExists(): void {
    $template = ContentTemplate::create([
      'id' => 'node.article.full',
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => self::ENGLISH_COMPONENT_TREE,
    ]);
    $template->save();

    $overrideFactory = \Drupal::service(LanguageConfigFactoryOverrideInterface::class);

    $frenchOverride = $overrideFactory->getOverride('fr', 'canvas.content_template.node.article.full');

    self::assertNull($frenchOverride->get('component_tree'), 'Without a French override, component_tree must be null.');

    $defaultTree = \Drupal::config('canvas.content_template.node.article.full')->get('component_tree');
    self::assertSame(self::ENGLISH_COMPONENT_TREE, $defaultTree, 'English default tree must remain intact when no French override exists.');
  }

  /**
   * Tests that deleting a language override restores the default tree.
   */
  public function testDeletingOverrideRestoresDefault(): void {
    $template = ContentTemplate::create([
      'id' => 'node.article.full',
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => self::ENGLISH_COMPONENT_TREE,
    ]);
    $template->save();

    $overrideFactory = \Drupal::service(LanguageConfigFactoryOverrideInterface::class);

    $overrideFactory
      ->getOverride('fr', 'canvas.content_template.node.article.full')
      ->set('component_tree', self::FRENCH_COMPONENT_TREE)
      ->save();

    $overrideFactory->getOverride('fr', 'canvas.content_template.node.article.full')->delete();

    $frenchTree = $overrideFactory->getOverride('fr', 'canvas.content_template.node.article.full')->get('component_tree');
    self::assertNull($frenchTree, 'After deleting the French override, component_tree must be null.');

    $defaultTree = \Drupal::config('canvas.content_template.node.article.full')->get('component_tree');
    self::assertSame(self::ENGLISH_COMPONENT_TREE, $defaultTree, 'Deleting a language override must restore the default English component tree.');
  }

  /**
   * Tests that two languages can have independent overrides simultaneously.
   */
  public function testMultipleLanguageOverridesAreIndependent(): void {
    ConfigurableLanguage::createFromLangcode('de')->save();

    $germanComponentTree = [
      [
        'uuid' => '3df8b5c2-4f7e-4d84-b7c2-6c5515f7cbf3',
        'component_id' => self::COMPONENT_ID,
        'component_version' => 'b1e991f726a2a266',
        'inputs' => ['heading' => 'German content template heading'],
      ],
    ];

    $template = ContentTemplate::create([
      'id' => 'node.article.full',
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => self::ENGLISH_COMPONENT_TREE,
    ]);
    $template->save();

    $overrideFactory = \Drupal::service(LanguageConfigFactoryOverrideInterface::class);

    $overrideFactory->getOverride('fr', 'canvas.content_template.node.article.full')->set('component_tree', self::FRENCH_COMPONENT_TREE)->save();
    $overrideFactory->getOverride('de', 'canvas.content_template.node.article.full')->set('component_tree', $germanComponentTree)->save();

    $frenchTree = $overrideFactory->getOverride('fr', 'canvas.content_template.node.article.full')->get('component_tree');
    $germanTree = $overrideFactory->getOverride('de', 'canvas.content_template.node.article.full')->get('component_tree');

    self::assertSame(self::FRENCH_COMPONENT_TREE, $frenchTree, 'French override must return the French tree.');
    self::assertSame($germanComponentTree, $germanTree, 'German override must return the German tree.');
    self::assertNotEquals($frenchTree, $germanTree, 'French and German overrides must be independent.');

    $defaultTree = \Drupal::config('canvas.content_template.node.article.full')->get('component_tree');
    self::assertSame(self::ENGLISH_COMPONENT_TREE, $defaultTree, 'Default English tree must not be affected by other language overrides.');
  }

}
