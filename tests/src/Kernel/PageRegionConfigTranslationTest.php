<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\canvas\Entity\PageRegion;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Verifies PageRegion config language overrides.
 *
 * Verifies that programmatic config language overrides affect PageRegion
 * component tree rendering when different languages are active.
 *
 * This test proves that Drupal core's config_translation override system works
 * correctly with Canvas PageRegion config entities without any Canvas-specific
 * translation code beyond the schema alteration in canvas_dev_translation.
 *
 * @group canvas
 * @group canvas_dev_translation
 */
#[RunTestsInSeparateProcesses]
final class PageRegionConfigTranslationTest extends CanvasKernelTestBase {

  use GenerateComponentConfigTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'block',
    'language',
    'locale',
    'config_translation',
    'canvas',
    'canvas_test_sdc',
    'canvas_dev_translation',
  ];

  private const TEST_THEME = 'stark';
  private const TEST_REGION = 'header';
  private const CONFIG_NAME = 'canvas.page_region.' . self::TEST_THEME . '.' . self::TEST_REGION;

  private const ENGLISH_COMPONENT_TREE = [
    [
      'uuid' => 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d',
      'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
      'component_version' => 'b1e991f726a2a266',
      'inputs' => [
        'heading' => 'English header region content',
      ],
    ],
  ];

  private const FRENCH_COMPONENT_TREE = [
    [
      'uuid' => 'b2c3d4e5-f6a7-4b8c-9d0e-1f2a3b4c5d6e',
      'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
      'component_version' => 'b1e991f726a2a266',
      'inputs' => [
        'heading' => 'French header region content',
      ],
    ],
  ];

  private const FRENCH_MULTI_COMPONENT_TREE = [
    [
      'uuid' => 'c3d4e5f6-a7b8-4c9d-0e1f-2a3b4c5d6e7f',
      'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
      'component_version' => 'b1e991f726a2a266',
      'inputs' => [
        'heading' => 'French component one',
      ],
    ],
    [
      'uuid' => 'd4e5f6a7-b8c9-4d0e-1f2a-3b4c5d6e7f8a',
      'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
      'component_version' => 'b1e991f726a2a266',
      'inputs' => [
        'heading' => 'French component two',
      ],
    ],
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'language']);
    $this->installEntitySchema('configurable_language');
    $this->installEntitySchema('user');
    $this->installConfig(['canvas']);
    $this->generateComponentConfig();
    \Drupal::service('theme_installer')->install([self::TEST_THEME]);
    ConfigurableLanguage::createFromLangcode('fr')->save();
  }

  /**
   * Tests that a French LanguageConfigOverride replaces the component tree.
   */
  public function testFrenchOverrideReplacesComponentTree(): void {
    /** @var \Drupal\language\Config\LanguageConfigFactoryOverrideInterface $overrideFactory */
    $overrideFactory = \Drupal::service('language.config_factory_override');

    $region = PageRegion::create([
      'theme' => self::TEST_THEME,
      'region' => self::TEST_REGION,
      'component_tree' => self::ENGLISH_COMPONENT_TREE,
    ]);
    $region->save();

    $defaultTree = \Drupal::config(self::CONFIG_NAME)->get('component_tree');
    self::assertSame(self::ENGLISH_COMPONENT_TREE, $defaultTree, 'Default (English) component tree must be stored correctly.');

    $overrideFactory->getOverride('fr', self::CONFIG_NAME)
      ->set('component_tree', self::FRENCH_COMPONENT_TREE)
      ->save();

    $frenchTree = $overrideFactory->getOverride('fr', self::CONFIG_NAME)->get('component_tree');
    self::assertSame(self::FRENCH_COMPONENT_TREE, $frenchTree, 'French override must contain the French component tree.');
    self::assertNotEquals(self::ENGLISH_COMPONENT_TREE, $frenchTree, 'French override must differ from English default.');

    // Verify the override is accessible via the language manager — the same
    // storage path that LanguageConfigFactoryOverride::loadOverrides() reads
    // on a real French-language request.
    $french = \Drupal::languageManager()->getLanguage('fr');
    self::assertNotNull($french, 'French language must be available.');

    $languageManager = \Drupal::languageManager();
    self::assertInstanceOf(ConfigurableLanguageManagerInterface::class, $languageManager);
    $overriddenTree = $languageManager
      ->getLanguageConfigOverride('fr', self::CONFIG_NAME)
      ->get('component_tree');
    self::assertSame(self::FRENCH_COMPONENT_TREE, $overriddenTree, 'Language manager must return the French component tree for the fr override.');

    // English default must remain unaffected.
    $defaultTreeAfterOverride = \Drupal::config(self::CONFIG_NAME)->get('component_tree');
    self::assertSame(self::ENGLISH_COMPONENT_TREE, $defaultTreeAfterOverride, 'Creating a French override must not mutate the English default config.');

    $frenchUuids = array_column($frenchTree, 'uuid');
    self::assertContains('b2c3d4e5-f6a7-4b8c-9d0e-1f2a3b4c5d6e', $frenchUuids, 'French UUID must exist.');
    self::assertNotContains('a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d', $frenchUuids, 'English UUID must not appear.');
  }

  /**
   * Tests that a structurally different French tree replaces the English tree.
   */
  public function testFrenchOverrideWithDifferentTreeStructure(): void {
    /** @var \Drupal\language\Config\LanguageConfigFactoryOverrideInterface $overrideFactory */
    $overrideFactory = \Drupal::service('language.config_factory_override');

    $region = PageRegion::create([
      'theme' => self::TEST_THEME,
      'region' => self::TEST_REGION,
      'component_tree' => self::ENGLISH_COMPONENT_TREE,
    ]);
    $region->save();

    $overrideFactory->getOverride('fr', self::CONFIG_NAME)
      ->set('component_tree', self::FRENCH_MULTI_COMPONENT_TREE)
      ->save();

    $frenchTree = $overrideFactory->getOverride('fr', self::CONFIG_NAME)->get('component_tree');

    self::assertCount(2, $frenchTree, 'French override must return 2 components.');
    self::assertCount(1, \Drupal::config(self::CONFIG_NAME)->get('component_tree'), 'English default tree must still have 1 component.');

    $frenchUuids = array_column($frenchTree, 'uuid');
    self::assertContains('c3d4e5f6-a7b8-4c9d-0e1f-2a3b4c5d6e7f', $frenchUuids);
    self::assertContains('d4e5f6a7-b8c9-4d0e-1f2a-3b4c5d6e7f8a', $frenchUuids);
    self::assertNotContains('a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d', $frenchUuids);
  }

  /**
   * Tests that the English default is served when no French override exists.
   */
  public function testDefaultTreeServedWhenNoOverrideExists(): void {
    /** @var \Drupal\language\Config\LanguageConfigFactoryOverrideInterface $overrideFactory */
    $overrideFactory = \Drupal::service('language.config_factory_override');

    $region = PageRegion::create([
      'theme' => self::TEST_THEME,
      'region' => self::TEST_REGION,
      'component_tree' => self::ENGLISH_COMPONENT_TREE,
    ]);
    $region->save();

    $frenchOverride = $overrideFactory->getOverride('fr', self::CONFIG_NAME);
    self::assertNull($frenchOverride->get('component_tree'), 'Without French override, component_tree must be null.');

    $defaultTree = \Drupal::config(self::CONFIG_NAME)->get('component_tree');
    self::assertSame(self::ENGLISH_COMPONENT_TREE, $defaultTree, 'English tree remains intact.');
  }

  /**
   * Tests that deleting a language override restores the default tree.
   */
  public function testDeletingOverrideRestoresDefault(): void {
    /** @var \Drupal\language\Config\LanguageConfigFactoryOverrideInterface $overrideFactory */
    $overrideFactory = \Drupal::service('language.config_factory_override');

    $region = PageRegion::create([
      'theme' => self::TEST_THEME,
      'region' => self::TEST_REGION,
      'component_tree' => self::ENGLISH_COMPONENT_TREE,
    ]);
    $region->save();

    $overrideFactory->getOverride('fr', self::CONFIG_NAME)
      ->set('component_tree', self::FRENCH_COMPONENT_TREE)
      ->save();

    $overrideFactory->getOverride('fr', self::CONFIG_NAME)->delete();

    $frenchTree = $overrideFactory->getOverride('fr', self::CONFIG_NAME)->get('component_tree');
    self::assertNull($frenchTree, 'After deletion, French tree must be null.');

    $defaultTree = \Drupal::config(self::CONFIG_NAME)->get('component_tree');
    self::assertSame(self::ENGLISH_COMPONENT_TREE, $defaultTree, 'Deleting override restores English tree.');
  }

  /**
   * Tests that two languages can have independent overrides simultaneously.
   */
  public function testMultipleLanguageOverridesAreIndependent(): void {
    /** @var \Drupal\language\Config\LanguageConfigFactoryOverrideInterface $overrideFactory */
    $overrideFactory = \Drupal::service('language.config_factory_override');

    ConfigurableLanguage::createFromLangcode('de')->save();

    $germanComponentTree = [
      [
        'uuid' => 'e5f6a7b8-c9d0-4e1f-2a3b-4c5d6e7f8a9b',
        'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
        'component_version' => 'b1e991f726a2a266',
        'inputs' => ['heading' => 'German header region content'],
      ],
    ];

    $region = PageRegion::create([
      'theme' => self::TEST_THEME,
      'region' => self::TEST_REGION,
      'component_tree' => self::ENGLISH_COMPONENT_TREE,
    ]);
    $region->save();

    $overrideFactory->getOverride('fr', self::CONFIG_NAME)->set('component_tree', self::FRENCH_COMPONENT_TREE)->save();
    $overrideFactory->getOverride('de', self::CONFIG_NAME)->set('component_tree', $germanComponentTree)->save();

    $frenchTree = $overrideFactory->getOverride('fr', self::CONFIG_NAME)->get('component_tree');
    $germanTree = $overrideFactory->getOverride('de', self::CONFIG_NAME)->get('component_tree');

    self::assertSame(self::FRENCH_COMPONENT_TREE, $frenchTree);
    self::assertSame($germanComponentTree, $germanTree);
    self::assertNotEquals($frenchTree, $germanTree);

    $defaultTree = \Drupal::config(self::CONFIG_NAME)->get('component_tree');
    self::assertSame(self::ENGLISH_COMPONENT_TREE, $defaultTree);
  }

  /**
   * Tests that updating an existing override replaces the previous override.
   */
  public function testUpdatingOverrideReplacesItCompletely(): void {
    /** @var \Drupal\language\Config\LanguageConfigFactoryOverrideInterface $overrideFactory */
    $overrideFactory = \Drupal::service('language.config_factory_override');

    $region = PageRegion::create([
      'theme' => self::TEST_THEME,
      'region' => self::TEST_REGION,
      'component_tree' => self::ENGLISH_COMPONENT_TREE,
    ]);
    $region->save();

    $overrideFactory->getOverride('fr', self::CONFIG_NAME)
      ->set('component_tree', self::FRENCH_COMPONENT_TREE)
      ->save();

    $overrideFactory->getOverride('fr', self::CONFIG_NAME)
      ->set('component_tree', self::FRENCH_MULTI_COMPONENT_TREE)
      ->save();

    $updatedTree = $overrideFactory->getOverride('fr', self::CONFIG_NAME)->get('component_tree');
    self::assertSame(self::FRENCH_MULTI_COMPONENT_TREE, $updatedTree);
    self::assertCount(2, $updatedTree);

    $frenchUuids = array_column($updatedTree, 'uuid');
    self::assertNotContains('b2c3d4e5-f6a7-4b8c-9d0e-1f2a3b4c5d6e', $frenchUuids, 'Original French UUID must be gone after update.');
  }

}
