<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Verifies that programmatic config language overrides affect ContentTemplate.
 *
 * This test proves that Drupal core's config_translation override system works
 * correctly with Canvas ContentTemplate config entities without any
 * Canvas-specific translation code beyond the schema alteration in
 * canvas_dev_translation.
 *
 * @group canvas
 * @group canvas_dev_translation
 */
#[RunTestsInSeparateProcesses]
final class ContentTemplateConfigTranslationTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'language',
    'locale',
    'config_translation',
    'canvas',
    'canvas_dev_translation',
  ];

  /**
   * The English component tree used as the default-language baseline.
   */
  private const ENGLISH_COMPONENT_TREE = [
    [
      'uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
      'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
      'component_version' => 'b1e991f726a2a266',
      'inputs' => [
        'heading' => 'Hello from English!',
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
        'heading' => 'Bonjour depuis le français !',
      ],
    ],
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'language', 'node']);
    $this->installEntitySchema('configurable_language');
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    ConfigurableLanguage::createFromLangcode('fr')->save();
  }

  /**
   * Tests that a French LanguageConfigOverride replaces the component tree.
   */
  public function testFrenchOverrideReplacesComponentTree(): void {
    $definitions = \Drupal::service('config.typed')->getDefinitions();
    $translatable = $definitions['canvas.content_template.*.*.*']['mapping']['component_tree']['translatable'] ?? FALSE;
    self::assertTrue($translatable, 'component_tree must be marked translatable by canvas_dev_translation schema alter.');

    // 1. Create a ContentTemplate in the default language (English).
    $template = ContentTemplate::create([
      'id' => 'node.article.full',
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => self::ENGLISH_COMPONENT_TREE,
    ]);
    $template->save();

    // Sanity-check: the default config returns the English tree.
    $defaultTree = \Drupal::config('canvas.content_template.node.article.full')
      ->get('component_tree');
    self::assertSame(
      self::ENGLISH_COMPONENT_TREE,
      $defaultTree,
      'Default (English) component tree must be stored correctly.'
    );

    // 2. Programmatically create a French LanguageConfigOverride.
    \Drupal::languageManager()
      ->getLanguageConfigOverride('fr', 'canvas.content_template.node.article.full')
      ->set('component_tree', self::FRENCH_COMPONENT_TREE)
      ->save();

    // 3. Read the French override back directly and assert its contents.
    $frenchTree = \Drupal::languageManager()
      ->getLanguageConfigOverride('fr', 'canvas.content_template.node.article.full')
      ->get('component_tree');

    self::assertSame(
      self::FRENCH_COMPONENT_TREE,
      $frenchTree,
      'The French language config override must contain the French component tree.'
    );
    self::assertNotSame(
      self::ENGLISH_COMPONENT_TREE,
      $frenchTree,
      'The French override must differ from the English default tree.'
    );

    // 4. Switch the active language to French and verify the config factory
    //    transparently returns the French override.
    $french = \Drupal::languageManager()->getLanguage('fr');
    self::assertNotNull($french, 'French language must be available.');
    $originalLanguage = \Drupal::languageManager()->getCurrentLanguage();
    \Drupal::service('language.default')->set($french);
    \Drupal::configFactory()->reset('canvas.content_template.node.article.full');

    try {
      $overriddenTree = \Drupal::configFactory()
        ->get('canvas.content_template.node.article.full')
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

    $frenchOverride = \Drupal::languageManager()
      ->getLanguageConfigOverride('fr', 'canvas.content_template.node.article.full');

    self::assertNull(
      $frenchOverride->get('component_tree'),
      'Without a French override, component_tree must be null.'
    );

    $defaultTree = \Drupal::config('canvas.content_template.node.article.full')
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
    $template = ContentTemplate::create([
      'id' => 'node.article.full',
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => self::ENGLISH_COMPONENT_TREE,
    ]);
    $template->save();

    \Drupal::languageManager()
      ->getLanguageConfigOverride('fr', 'canvas.content_template.node.article.full')
      ->set('component_tree', self::FRENCH_COMPONENT_TREE)
      ->save();

    \Drupal::languageManager()
      ->getLanguageConfigOverride('fr', 'canvas.content_template.node.article.full')
      ->delete();

    $frenchTree = \Drupal::languageManager()
      ->getLanguageConfigOverride('fr', 'canvas.content_template.node.article.full')
      ->get('component_tree');

    self::assertNull(
      $frenchTree,
      'After deleting the French override, component_tree must be null.'
    );

    $defaultTree = \Drupal::config('canvas.content_template.node.article.full')
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
        'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccccc',
        'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
        'component_version' => 'b1e991f726a2a266',
        'inputs' => [
          'heading' => 'Hallo auf Deutsch!',
        ],
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

    \Drupal::languageManager()
      ->getLanguageConfigOverride('fr', 'canvas.content_template.node.article.full')
      ->set('component_tree', self::FRENCH_COMPONENT_TREE)
      ->save();

    \Drupal::languageManager()
      ->getLanguageConfigOverride('de', 'canvas.content_template.node.article.full')
      ->set('component_tree', $germanComponentTree)
      ->save();

    $frenchTree = \Drupal::languageManager()
      ->getLanguageConfigOverride('fr', 'canvas.content_template.node.article.full')
      ->get('component_tree');

    $germanTree = \Drupal::languageManager()
      ->getLanguageConfigOverride('de', 'canvas.content_template.node.article.full')
      ->get('component_tree');

    self::assertSame(self::FRENCH_COMPONENT_TREE, $frenchTree, 'French override must return the French tree.');
    self::assertSame($germanComponentTree, $germanTree, 'German override must return the German tree.');
    self::assertNotSame($frenchTree, $germanTree, 'French and German overrides must be independent.');

    $defaultTree = \Drupal::config('canvas.content_template.node.article.full')
      ->get('component_tree');
    self::assertSame(
      self::ENGLISH_COMPONENT_TREE,
      $defaultTree,
      'Default English tree must not be affected by other language overrides.'
    );
  }

}
