<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\PropSource;

use Drupal\canvas\PropSource\EntityFieldPropSource;
use Drupal\canvas\PropSource\PropSource;
use Drupal\canvas\PropSource\StaticPropSource;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[CoversClass(StaticPropSource::class)]
#[CoversClass(EntityFieldPropSource::class)]
#[Group('canvas')]
#[Group('canvas_data_model')]
#[Group('canvas_data_model__prop_expressions')]
#[RunTestsInSeparateProcesses]
class PropSourceMultilingualTest extends PropSourceTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'field',
    'node',
    'datetime_range',
    'media_test_source',
    'language',
    'content_translation',
  ];

  /**
   * The media entity used for translation tests.
   */
  protected Media $media;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['language']);
    ConfigurableLanguage::createFromLangcode('es')->save();
    ConfigurableLanguage::createFromLangcode('fr')->save();

    // Not enabled by default: mirrors language recipe config.
    \Drupal::service('content_translation.manager')
      ->setEnabled('media', 'image', TRUE);

    $this->media = Media::create([
      'bundle' => 'image',
      'name' => 'English Image',
      'langcode' => 'en',
      'field_media_image' => [
        'target_id' => 1,
        'alt' => 'Media Alt EN',
      ],
    ]);
    $this->media->save();
    $this->media->addTranslation('es', [
      'name' => 'Spanish Image',
      'field_media_image' => [
        'target_id' => 1,
        'alt' => 'Media Alt ES',
      ],
    ]);
    $this->media->save();

    $this->installEntitySchema('node');
    NodeType::create(['type' => 'page', 'name' => 'page'])->save();
    $this->createEntityReferenceField('node', 'page', 'field_hero_image', 'Hero Image', 'media',
      selection_handler_settings: ['target_bundles' => ['image' => 'image']],
    );

    $this->setUpCurrentUser(permissions: ['access content', 'view media']);
  }

  /**
   * Tests that a static entity reference resolves to the correct translation.
   *
   * Verifies that when a StaticPropSource contains an entity reference to a
   * translated Media image entity, evaluating the prop expression returns the
   * alt text matching the active content language. The Evaluator calls
   * getTranslationFromContext() on the referenced entity, which picks the
   * translation based on the current content language.
   *
   * Also verifies language fallback: evaluating in a language for which the
   * entity has no translation falls back to the default language.
   *
   * @see \Drupal\canvas\PropExpressions\StructuredData\Evaluator::doEvaluate()
   */
  #[DataProvider('providerTranslation')]
  public function testStaticEntityReferenceTranslation(string $langcode, string $expected_alt): void {
    $this->switchContentLanguage($langcode);

    // Expression: entity_reference → entity → media:image →
    // field_media_image → alt.
    $prop_source = StaticPropSource::parse([
      'sourceType' => 'static:field_item:entity_reference',
      'value' => ['target_id' => $this->media->id()],
      'expression' => 'ℹ︎entity_reference␟entity␜␜entity:media:image␝field_media_image␞␟alt',
      'sourceTypeSettings' => [
        'storage' => ['target_type' => 'media'],
        'instance' => [
          'handler' => 'default:media',
          'handler_settings' => [
            'target_bundles' => ['image' => 'image'],
          ],
        ],
      ],
    ]);

    $result = $prop_source->evaluate(User::create(), is_required: TRUE);
    self::assertSame($expected_alt, $result->value);
    // getTranslationFromContext() adds this cache context when the entity
    // has multiple translations.
    self::assertContains('languages:language_content', $result->getCacheContexts());
  }

  /**
   * Tests that an EntityFieldPropSource resolves translated entity references.
   *
   * Verifies that when a node's entity reference field points to a translated
   * Media image entity, evaluating the prop expression via EntityFieldPropSource
   * returns the alt text matching the active content language. The Evaluator
   * calls getTranslationFromContext() on the referenced entity, which picks the
   * translation based on the current content language.
   *
   * Also verifies language fallback: evaluating in a language for which the
   * entity has no translation falls back to the default language.
   *
   * @see \Drupal\canvas\PropExpressions\StructuredData\Evaluator::doEvaluate()
   */
  #[DataProvider('providerTranslation')]
  public function testEntityFieldTranslatedReference(string $langcode, string $expected_alt): void {
    $node = $this->createNode([
      'type' => 'page',
      'langcode' => 'en',
      'field_hero_image' => ['target_id' => $this->media->id()],
    ]);

    $this->switchContentLanguage($langcode);

    // Expression: node:page → field_hero_image → entity → media:image →
    // field_media_image → alt.
    $expression = 'ℹ︎␜entity:node:page␝field_hero_image␞␟entity␜␜entity:media:image␝field_media_image␞␟alt';
    $prop_source = EntityFieldPropSource::parse([
      'sourceType' => PropSource::EntityField->value,
      'expression' => $expression,
    ]);

    $result = $prop_source->evaluate(clone $node, is_required: TRUE);
    self::assertSame($expected_alt, $result->value);
    // getTranslationFromContext() adds this cache context when the entity
    // has multiple translations.
    self::assertContains('languages:language_content', $result->getCacheContexts());
  }

  /**
   * Switches the active content language if it differs from the site default.
   */
  private function switchContentLanguage(string $langcode): void {
    $default_langcode = \Drupal::languageManager()->getDefaultLanguage()->getId();
    if ($langcode !== $default_langcode) {
      $language = \Drupal::languageManager()->getLanguage($langcode);
      \assert($language !== NULL);
      \Drupal::service('language.default')->set($language);
      \Drupal::languageManager()->reset();
    }
  }

  /**
   * Data provider for multilingual prop source tests.
   */
  public static function providerTranslation(): \Generator {
    yield 'English (default language)' => [
      'langcode' => 'en',
      'expected_alt' => 'Media Alt EN',
    ];
    yield 'Spanish (translated)' => [
      'langcode' => 'es',
      'expected_alt' => 'Media Alt ES',
    ];
    // Language fallback: no FR translation exists, should return EN default.
    yield 'French (fallback to default)' => [
      'langcode' => 'fr',
      'expected_alt' => 'Media Alt EN',
    ];
  }

}
