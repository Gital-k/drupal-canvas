<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\Entity\PageRegion;
use Drupal\language\Entity\ConfigurableLanguage;
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
    'canvas_dev_translation',
  ];

  private const TEST_THEME = 'stark';
  private const TEST_REGION = 'header';
  private const CONFIG_NAME = 'canvas.page_region.' . self::TEST_THEME . '.' . self::TEST_REGION;

  private const ENGLISH_COMPONENT_TREE = [
    [
      'uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
      'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
      'component_version' => 'b1e991f726a2a266',
      'inputs' => [
        'heading' => 'English header region content',
      ],
    ],
  ];

  private const FRENCH_COMPONENT_TREE = [
    [
      'uuid' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
      'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
      'component_version' => 'b1e991f726a2a266',
      'inputs' => [
        'heading' => 'Contenu de la région en-tête en français',
      ],
    ],
  ];

  private const FRENCH_MULTI_COMPONENT_TREE = [
    [
      'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccccc',
      'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
      'component_version' => 'b1e991f726a2a266',
      'inputs' => [
        'heading' => 'Premier composant français',
      ],
    ],
    [
      'uuid' => 'dddddddd-dddd-dddd-dddd-dddddddddddd',
      'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
      'component_version' => 'b1e991f726a2a266',
      'inputs' => [
        'heading' => 'Deuxième composant français',
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
    \Drupal::service('theme_installer')->install([self::TEST_THEME]);
    ConfigurableLanguage::createFromLangcode('fr')->save();
  }

  /**
   * Tests that a French LanguageConfigOverride replaces the component tree.
   */
  public function testFrenchOverrideReplacesComponentTree(): void {
    /** @var \Drupal\config_translation\LanguageConfigOverrideFactoryInterface $overrideFactory */
    $overrideFactory = \Drupal::service('config_translation.language_config_override_factory');

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
    self::assertNotSame(self::ENGLISH_COMPONENT_TREE, $frenchTree, 'French override must differ from English default.');

    $originalLanguage = \Drupal::languageManager()->getCurrentLanguage();
    \Drupal::service('language.default')->set(\Drupal::languageManager()->getLanguage('fr'));
    \Drupal::configFactory()->reset(self::CONFIG_NAME);

    try {
      $overriddenTree = \Drupal::configFactory()->get(self::CONFIG_NAME)->get('component_tree');
      self::assertSame(self::FRENCH_COMPONENT_TREE, $overriddenTree, 'Config factory must return French tree while French is active.');
    } finally {
      \Drupal::service('language.default')->set($originalLanguage);
    }

    $frenchUuids = array_column($frenchTree, 'uuid');
    self::assertContains('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', $frenchUuids, 'French UUID must exist.');
    self::assertNotContains('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', $frenchUuids, 'English UUID must not appear.');
  }

  /**
   * Tests that a structurally different French tree replaces the English tree.
   */
  public function testFrenchOverrideWithDifferentTreeStructure(): void {
    /** @var \Drupal\config_translation\LanguageConfigOverrideFactoryInterface $overrideFactory */
    $overrideFactory = \Drupal::service('config_translation.language_config_override_factory');

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
    self::assertContains('cccccccc-cccc-cccc-cccc-cccccccccccc', $frenchUuids);
    self::assertContains('dddddddd-dddd-dddd-dddd-dddddddddddd', $frenchUuids);
    self::assertNotContains('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', $frenchUuids);
  }

  /**
   * Tests that the English default is served when no French override exists.
   */
  public function testDefaultTreeServedWhenNoOverrideExists(): void {
    /** @var \Drupal\config_translation\LanguageConfigOverrideFactoryInterface $overrideFactory */
    $overrideFactory = \Drupal::service('config_translation.language_config_override_factory');

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
    /** @var \Drupal\config_translation\LanguageConfigOverrideFactoryInterface $overrideFactory */
    $overrideFactory = \Drupal::service('config_translation.language_config_override_factory');

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
    /** @var \Drupal\config_translation\LanguageConfigOverrideFactoryInterface $overrideFactory */
    $overrideFactory = \Drupal::service('config_translation.language_config_override_factory');

    ConfigurableLanguage::createFromLangcode('de')->save();

    $germanComponentTree = [
      [
        'uuid' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee',
        'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
        'component_version' => 'b1e991f726a2a266',
        'inputs' => ['heading' => 'Deutscher Kopfzeilenbereich'],
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
    self::assertNotSame($frenchTree, $germanTree);

    $defaultTree = \Drupal::config(self::CONFIG_NAME)->get('component_tree');
    self::assertSame(self::ENGLISH_COMPONENT_TREE, $defaultTree);
  }

  /**
   * Tests that updating an existing override replaces the previous override.
   */
  public function testUpdatingOverrideReplacesItCompletely(): void {
    /** @var \Drupal\config_translation\LanguageConfigOverrideFactoryInterface $overrideFactory */
    $overrideFactory = \Drupal::service('config_translation.language_config_override_factory');

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
    self::assertNotContains('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', $frenchUuids);
  }

}
