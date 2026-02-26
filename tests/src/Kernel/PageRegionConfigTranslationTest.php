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

  /**
   * The test theme used for PageRegion entities.
   *
   * Must be a theme that is available in the test environment with a known
   * region other than 'content'.
   */
  private const TEST_THEME = 'stark';

  /**
   * The test region within the theme.
   */
  private const TEST_REGION = 'header';

  /**
   * The config name for the PageRegion under test.
   */
  private const CONFIG_NAME = 'canvas.page_region.' . self::TEST_THEME . '.' . self::TEST_REGION;

  /**
   * The English component tree used as the default-language baseline.
   */
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

  /**
   * The French component tree used for the language override.
   */
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

  /**
   * A structurally different French component tree to test tree replacement.
   *
   * This uses two components instead of one, verifying that the override
   * can replace not just values but the entire tree structure.
   */
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
    // Ensure PHPStan knows we have access to the concrete manager with
    // getLanguageConfigOverride().
    /** @var \Drupal\Core\Language\LanguageManagerInterface|\Drupal\Core\Language\LanguageManager $language_manager */
    $language_manager = \Drupal::languageManager();

    $definitions = \Drupal::service('config.typed')->getDefinitions();
    $translatable = $definitions['canvas.content_template.*.*.*']['mapping']['component_tree']['translatable'] ?? FALSE;
    self::assertTrue($translatable, 'component_tree must be marked translatable by canvas_dev_translation schema alter.');
    // 1. Create a PageRegion in the default language (English).
    $region = PageRegion::create([
      'theme' => self::TEST_THEME,
      'region' => self::TEST_REGION,
      'component_tree' => self::ENGLISH_COMPONENT_TREE,
    ]);
    $region->save();

    // Sanity-check: the default config returns the English tree.
    $defaultTree = \Drupal::config(self::CONFIG_NAME)
      ->get('component_tree');
    self::assertSame(
      self::ENGLISH_COMPONENT_TREE,
      $defaultTree,
      'Default (English) component tree must be stored correctly.'
    );

    // 2. Programmatically create a French LanguageConfigOverride.
    $language_manager
      ->getLanguageConfigOverride('fr', self::CONFIG_NAME)
      ->set('component_tree', self::FRENCH_COMPONENT_TREE)
      ->save();

    // 3. Read the French override back directly and assert its contents.
    $frenchTree = $language_manager
      ->getLanguageConfigOverride('fr', self::CONFIG_NAME)
      ->get('component_tree');

    self::assertSame(
      self::FRENCH_COMPONENT_TREE,
      $frenchTree,
      'The French language config override must contain the French component tree.'
    );
    // @phpstan-ignore-next-line staticMethod.alreadyNarrowedType
    self::assertNotSame(
      self::ENGLISH_COMPONENT_TREE,
      $frenchTree,
      'The French override must differ from the English default tree.'
    );

    // 4. Switch the active language to French and verify the config factory
    //    transparently returns the French override.
    $french = $language_manager->getLanguage('fr');
    self::assertNotNull($french, 'French language must be available.');
    $originalLanguage = $language_manager->getCurrentLanguage();
    \Drupal::service('language.default')->set($french);
    \Drupal::configFactory()->reset(self::CONFIG_NAME);

    try {
      $overriddenTree = \Drupal::configFactory()
        ->get(self::CONFIG_NAME)
        ->get('component_tree');

      self::assertSame(
        self::FRENCH_COMPONENT_TREE,
        $overriddenTree,
        'The config factory must return the French component tree while French is active.'
      );
    }
    finally {
      \Drupal::service('language.default')->set($originalLanguage);
    }

    // 5. Confirm UUIDs: French UUID present, English UUID absent.
    $frenchUuids = array_column($frenchTree, 'uuid');
    self::assertContains(
      'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
      $frenchUuids,
      'French component UUID must be present in the override tree.'
    );
    self::assertNotContains(
      'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
      $frenchUuids,
      'English component UUID must not appear in the French override tree.'
    );
  }

  /**
   * Tests that a structurally different French tree replaces the English tree.
   *
   * The French tree has a different component count to ensure the override
   * replaces the default tree rather than merging with it.
   */
  public function testFrenchOverrideWithDifferentTreeStructure(): void {
    $region = PageRegion::create([
      'theme' => self::TEST_THEME,
      'region' => self::TEST_REGION,
      'component_tree' => self::ENGLISH_COMPONENT_TREE,
    ]);
    $region->save();

    // English tree has 1 component; French override has 2.
    \Drupal::languageManager()
      ->getLanguageConfigOverride('fr', self::CONFIG_NAME)
      ->set('component_tree', self::FRENCH_MULTI_COMPONENT_TREE)
      ->save();

    $frenchTree = \Drupal::languageManager()
      ->getLanguageConfigOverride('fr', self::CONFIG_NAME)
      ->get('component_tree');

    self::assertCount(
      2,
      $frenchTree,
      'French override with 2 components must return exactly 2 components.'
    );
    self::assertCount(
      1,
      \Drupal::config(self::CONFIG_NAME)->get('component_tree'),
      'English default tree must still have only 1 component after French override is saved.'
    );

    $frenchUuids = array_column($frenchTree, 'uuid');
    self::assertContains('cccccccc-cccc-cccc-cccc-cccccccccccc', $frenchUuids);
    self::assertContains('dddddddd-dddd-dddd-dddd-dddddddddddd', $frenchUuids);
    self::assertNotContains('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', $frenchUuids,
      'English UUID must not bleed into the French multi-component override.'
    );
  }

  /**
   * Tests that the English default is served when no French override exists.
   */
  public function testDefaultTreeServedWhenNoOverrideExists(): void {
    $region = PageRegion::create([
      'theme' => self::TEST_THEME,
      'region' => self::TEST_REGION,
      'component_tree' => self::ENGLISH_COMPONENT_TREE,
    ]);
    $region->save();

    $frenchOverride = \Drupal::languageManager()
      ->getLanguageConfigOverride('fr', self::CONFIG_NAME);

    self::assertNull(
      $frenchOverride->get('component_tree'),
      'Without a French override, component_tree must be null.'
    );

    $defaultTree = \Drupal::config(self::CONFIG_NAME)
      ->get('component_tree');
    self::assertSame(
      self::ENGLISH_COMPONENT_TREE,
      $defaultTree,
      'English default tree must remain intact when no French override exists.'
    );
  }

  /**
   * Tests that deleting a language override restores the default tree.
   */
  public function testDeletingOverrideRestoresDefault(): void {
    $region = PageRegion::create([
      'theme' => self::TEST_THEME,
      'region' => self::TEST_REGION,
      'component_tree' => self::ENGLISH_COMPONENT_TREE,
    ]);
    $region->save();

    \Drupal::languageManager()
      ->getLanguageConfigOverride('fr', self::CONFIG_NAME)
      ->set('component_tree', self::FRENCH_COMPONENT_TREE)
      ->save();

    \Drupal::languageManager()
      ->getLanguageConfigOverride('fr', self::CONFIG_NAME)
      ->delete();

    $frenchTree = \Drupal::languageManager()
      ->getLanguageConfigOverride('fr', self::CONFIG_NAME)
      ->get('component_tree');

    self::assertNull(
      $frenchTree,
      'After deleting the French override, component_tree must be null.'
    );

    $defaultTree = \Drupal::config(self::CONFIG_NAME)
      ->get('component_tree');
    self::assertSame(
      self::ENGLISH_COMPONENT_TREE,
      $defaultTree,
      'Deleting a language override must restore the default English component tree.'
    );
  }

  /**
   * Tests that two languages can have independent overrides simultaneously.
   */
  public function testMultipleLanguageOverridesAreIndependent(): void {
    ConfigurableLanguage::createFromLangcode('de')->save();

    $germanComponentTree = [
      [
        'uuid' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee',
        'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
        'component_version' => 'b1e991f726a2a266',
        'inputs' => [
          'heading' => 'Deutscher Kopfzeilenbereich',
        ],
      ],
    ];

    $region = PageRegion::create([
      'theme' => self::TEST_THEME,
      'region' => self::TEST_REGION,
      'component_tree' => self::ENGLISH_COMPONENT_TREE,
    ]);
    $region->save();

    \Drupal::languageManager()
      ->getLanguageConfigOverride('fr', self::CONFIG_NAME)
      ->set('component_tree', self::FRENCH_COMPONENT_TREE)
      ->save();

    \Drupal::languageManager()
      ->getLanguageConfigOverride('de', self::CONFIG_NAME)
      ->set('component_tree', $germanComponentTree)
      ->save();

    $frenchTree = \Drupal::languageManager()
      ->getLanguageConfigOverride('fr', self::CONFIG_NAME)
      ->get('component_tree');

    $germanTree = \Drupal::languageManager()
      ->getLanguageConfigOverride('de', self::CONFIG_NAME)
      ->get('component_tree');

    self::assertSame(self::FRENCH_COMPONENT_TREE, $frenchTree, 'French override must return the French tree.');
    self::assertSame($germanComponentTree, $germanTree, 'German override must return the German tree.');
    self::assertNotSame($frenchTree, $germanTree, 'French and German overrides must be independent.');

    $defaultTree = \Drupal::config(self::CONFIG_NAME)
      ->get('component_tree');
    self::assertSame(
      self::ENGLISH_COMPONENT_TREE,
      $defaultTree,
      'Default English tree must not be affected by other language overrides.'
    );
  }

  /**
   * Tests that updating an existing override replaces the previous override.
   */
  public function testUpdatingOverrideReplacesItCompletely(): void {
    $region = PageRegion::create([
      'theme' => self::TEST_THEME,
      'region' => self::TEST_REGION,
      'component_tree' => self::ENGLISH_COMPONENT_TREE,
    ]);
    $region->save();

    // Save initial French override.
    \Drupal::languageManager()
      ->getLanguageConfigOverride('fr', self::CONFIG_NAME)
      ->set('component_tree', self::FRENCH_COMPONENT_TREE)
      ->save();

    // Update French override to the multi-component tree.
    \Drupal::languageManager()
      ->getLanguageConfigOverride('fr', self::CONFIG_NAME)
      ->set('component_tree', self::FRENCH_MULTI_COMPONENT_TREE)
      ->save();

    $updatedTree = \Drupal::languageManager()
      ->getLanguageConfigOverride('fr', self::CONFIG_NAME)
      ->get('component_tree');

    self::assertSame(
      self::FRENCH_MULTI_COMPONENT_TREE,
      $updatedTree,
      'Updated French override must return the new multi-component tree.'
    );
    self::assertCount(2, $updatedTree,
      'Updated override must have 2 components, not a merge of old and new.'
    );

    // Confirm the single-component French tree is gone.
    $frenchUuids = array_column($updatedTree, 'uuid');
    self::assertNotContains(
      'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
      $frenchUuids,
      'Old French UUID must not persist after override is updated.'
    );
  }

}
