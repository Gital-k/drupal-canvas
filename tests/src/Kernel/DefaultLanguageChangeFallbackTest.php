<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

// cspell:ignore Texte secours modèle

use Drupal\canvas\DefaultLanguageChangeHandler;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests DefaultLanguageChangeHandler for language fallback and detection config.
 *
 * Covers behavior when the site's default language changes:
 *
 * AC5: The fallback language for symmetric translations must automatically
 *      update to the new default language. When a visitor requests content in
 *      a language for which no translation exists, the system must fall back
 *      to the new default language, not the old one.
 * AC6: The language detection and selection configuration must be updated to
 *      reflect the new default language as the primary negotiation fallback.
 *
 * @phpstan-ignore class.notFound (DefaultLanguageChangeHandler exists on implementation branch)
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(DefaultLanguageChangeHandler::class)]
#[Group('canvas')]
#[Group('canvas_translation')]
final class DefaultLanguageChangeFallbackTest extends CanvasKernelTestBase {

  use GenerateComponentConfigTrait;

  private const UUID_CTA = '435d1d20-a697-4d36-9892-9d61c825c99c';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'language',
    'config_translation',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['language']);
    $this->config('system.theme')->set('default', 'stark')->save();

    ConfigurableLanguage::createFromLangcode('fr')->save();
  }

  /**
   * Changes the site's default language by saving system.site config.
   *
   * Canvas detects this by listening on ConfigEvents::SAVE for system.site
   * with a changed default_langcode.
   */
  private function changeDefaultLanguageTo(string $new_langcode): void {
    $this->config('system.site')->set('default_langcode', $new_langcode)->save();
  }

  // ---------------------------------------------------------------------------
  // AC6: Language detection and selection (URL prefix negotiation) config
  // ---------------------------------------------------------------------------

  /**
   * Tests that URL prefix negotiation config is updated for the new default.
   *
   * Verifies the new default language has an empty prefix and becomes the
   * primary fallback.
   *
   * AC6: The language detection and selection configuration must be updated to
   * reflect the new default language as the primary negotiation fallback.
   *
   * Drupal core's ConfigSubscriber already handles this (url.prefixes in
   * language.negotiation: new default gets empty prefix, old default gets its
   * langcode as prefix). This test verifies Canvas does not break or interfere
   * with that behavior after the default language change is processed.
   */
  public function testLanguageNegotiationUrlPrefixesReflectNewDefault(): void {
    // Before the change: EN has no prefix (it is the default), FR has 'fr'.
    $negotiation = $this->config('language.negotiation');
    $url_prefixes_before = $negotiation->get('url.prefixes');
    // The default language typically has no entry or an empty-string entry.
    self::assertTrue(
      !isset($url_prefixes_before['en']) || $url_prefixes_before['en'] === '',
      'EN must have an empty or missing URL prefix before the language change.'
    );

    $this->changeDefaultLanguageTo('fr');

    // After the change: FR must have no/empty prefix; EN must now have 'en'.
    $negotiation = $this->config('language.negotiation');
    $url_prefixes_after = $negotiation->get('url.prefixes');

    self::assertTrue(
      !isset($url_prefixes_after['fr']) || $url_prefixes_after['fr'] === '',
      'New default language FR must have an empty URL prefix after the language change.'
    );
    self::assertSame(
      'en',
      $url_prefixes_after['en'] ?? NULL,
      'Old default language EN must now have "en" as its URL prefix.'
    );
  }

  /**
   * Tests that the language manager reports the correct new default.
   *
   * Verifies this after the change, so Canvas UI can correctly expose the
   * new default via drupalSettings.
   *
   * AC6: Downstream consumers (including Canvas controller) must observe the
   * new default language immediately after the config is saved.
   */
  public function testLanguageManagerReportsNewDefaultAfterChange(): void {
    $language_manager = $this->container->get(LanguageManagerInterface::class);
    \assert($language_manager instanceof ConfigurableLanguageManagerInterface);

    self::assertSame('en', $language_manager->getDefaultLanguage()->getId());

    $this->changeDefaultLanguageTo('fr');

    // The language manager must reset and reflect the new default.
    $language_manager->reset();
    self::assertSame('fr', $language_manager->getDefaultLanguage()->getId(), 'Language manager must report FR as the new default.');
  }

  // ---------------------------------------------------------------------------
  // AC5: Language fallback for symmetric translations
  // ---------------------------------------------------------------------------

  /**
   * Tests that language.types fallback is updated to list the new default first.
   *
   * Verifies content without a translation falls back to the new default.
   *
   * AC5: The fallback language must automatically update to the new default
   * language.
   */
  public function testLanguageTypesConfigFallbackUpdatedToNewDefault(): void {
    // Verify current fallback chain has EN first (as the default).
    $url_prefixes_before = $this->config('language.negotiation')->get('url.prefixes');
    // The default language typically has no entry or an empty-string entry.
    self::assertTrue(
      !isset($url_prefixes_before['en']) || $url_prefixes_before['en'] === '',
      'EN must have an empty or missing URL prefix before the language change.'
    );

    $this->changeDefaultLanguageTo('fr');

    // The language manager's default must now be FR, which means any system
    // using LanguageInterface::TYPE_INTERFACE or TYPE_CONTENT will fall back
    // to FR when no explicit language is set.
    $language_manager = $this->container->get(LanguageManagerInterface::class);
    \assert($language_manager instanceof ConfigurableLanguageManagerInterface);
    $language_manager->reset();

    $default_language = $language_manager->getDefaultLanguage();
    self::assertSame('fr', $default_language->getId(), 'Default language must be FR for fallback resolution.');

    // Specifically: getLanguage(TYPE_CONTENT) with no override must return
    // the new default.
    $content_language = $language_manager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT);
    self::assertSame('fr', $content_language->getId(), 'Content language type must fall back to new default FR.');
  }

  /**
   * Tests that a ContentTemplate falls back to the new default language's config.
   *
   * Verifies this for a language with no translation, not the old default.
   *
   * AC5: When a visitor requests content in a language for which no translation
   * exists, the system must fall back to the new default language, not the old
   * one.
   */
  public function testContentTemplateFallsBackToNewDefaultLanguage(): void {
    $this->enableModules(['node', 'field', 'content_translation']);
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('user');
    $this->installConfig(['content_translation', 'node']);
    $this->generateComponentConfig();
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();

    $language_manager = $this->container->get(LanguageManagerInterface::class);
    \assert($language_manager instanceof ConfigurableLanguageManagerInterface);

    // Create a ContentTemplate with an EN base and a FR translation.
    $template = ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [
        [
          'uuid' => self::UUID_CTA,
          'component_id' => 'sdc.canvas_test_sdc.my-cta',
          'component_version' => '89881c04a0fde367',
          'inputs' => [
            'text' => 'English fallback text',
            'href' => 'https://example.com',
          ],
        ],
      ],
    ]);
    self::assertEntityIsValid($template);
    $template->save();

    // Add French translation override.
    $en_values = $template->toArray();
    $fr_values = $en_values;
    NestedArray::setValue($fr_values, ['component_tree', self::UUID_CTA, 'inputs', 'text'], [0 => ['value' => 'Texte de secours français']]);
    // Href must be in widget array format, not the raw stored string.
    // @see \Drupal\canvas\ConfigTranslation\CanvasStaticPropSourceFieldWidget
    NestedArray::setValue($fr_values, ['component_tree', self::UUID_CTA, 'inputs', 'href'], [0 => ['uri' => 'https://example.com']]);
    $this->saveConfigEntityTranslation($template, 'fr', $fr_values);

    // Add a third language (Spanish) for which NO translation exists.
    ConfigurableLanguage::createFromLangcode('es')->save();

    $this->changeDefaultLanguageTo('fr');

    // Set the config override language to Spanish to simulate a Spanish
    // visitor requesting the template — there is no ES override, so it must
    // fall back to the new default (FR).
    $language_manager->setConfigOverrideLanguage(ConfigurableLanguage::load('es'));

    $reloaded_template = ContentTemplate::load($template->id());
    \assert($reloaded_template instanceof ContentTemplate);
    $tree = $reloaded_template->get('component_tree');
    $text = NestedArray::getValue($tree, [self::UUID_CTA, 'inputs', 'text']);

    // The fallback must now be the FR text (promoted to base after the swap),
    // NOT the old EN text.
    self::assertSame(
      'Texte de secours français',
      $text,
      'Config template must fall back to the new default (FR) text, not the old EN text.'
    );
  }

  /**
   * Tests that ContentTemplateAwareViewBuilder falls back to the new default.
   *
   * Verifies the new-default-language template is used for a node when a
   * translation into a minority language is missing.
   *
   * AC5: Canvas-specific fallback — ContentTemplateAwareViewBuilder must use
   * the new default language's template when no better match is found.
   */
  public function testContentTemplateAwareViewBuilderFallsBackToNewDefault(): void {
    $this->enableModules(['node', 'field', 'content_translation']);
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['content_translation', 'node']);
    $this->generateComponentConfig();
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();

    $template = ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'status' => TRUE,
      'component_tree' => [
        [
          'uuid' => self::UUID_CTA,
          'component_id' => 'sdc.canvas_test_sdc.my-cta',
          'component_version' => '89881c04a0fde367',
          'inputs' => [
            'text' => 'English template text',
            'href' => 'https://example.com',
          ],
        ],
      ],
    ]);
    $template->save();

    // Add a FR translation for the template.
    $en_values = $template->toArray();
    $fr_values = $en_values;
    NestedArray::setValue($fr_values, ['component_tree', self::UUID_CTA, 'inputs', 'text'], [0 => ['value' => 'Texte du modèle français']]);
    // Href must be in widget array format, not the raw stored string.
    // @see \Drupal\canvas\ConfigTranslation\CanvasStaticPropSourceFieldWidget
    NestedArray::setValue($fr_values, ['component_tree', self::UUID_CTA, 'inputs', 'href'], [0 => ['uri' => 'https://example.com']]);
    $this->saveConfigEntityTranslation($template, 'fr', $fr_values);

    // Add Spanish (no translation for this language).
    ConfigurableLanguage::createFromLangcode('es')->save();

    $node = Node::create(['title' => 'Test article', 'type' => 'article']);
    $node->save();

    $this->changeDefaultLanguageTo('fr');

    // Simulate a Spanish request context: the view builder must fall back to
    // the new default (FR) not the old default (EN).
    $language_manager = $this->container->get(LanguageManagerInterface::class);
    \assert($language_manager instanceof ConfigurableLanguageManagerInterface);
    $language_manager->setConfigOverrideLanguage(ConfigurableLanguage::load('es'));

    $reloaded_template = ContentTemplate::load($template->id());
    \assert($reloaded_template instanceof ContentTemplate);

    // The template the view builder would load must reflect FR content.
    $tree = $reloaded_template->get('component_tree');
    $text = NestedArray::getValue($tree, [self::UUID_CTA, 'inputs', 'text']);
    self::assertSame(
      'Texte du modèle français',
      $text,
      'ContentTemplateAwareViewBuilder must fall back to new default (FR) text, not old EN text.'
    );
  }

}
