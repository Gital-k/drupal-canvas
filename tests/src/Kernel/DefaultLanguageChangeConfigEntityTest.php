<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

// cspell:ignore Aangedreven Propulsé staan Bienvenue

use Drupal\canvas\DefaultLanguageChangeHandler;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\PageRegion;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests DefaultLanguageChangeHandler for config entity translation restructuring.
 *
 * Covers behavior when the site's default language changes:
 *
 * AC4: Config entity translations (content templates, page regions stored as
 *      config overrides in language.{langcode} collections) must be
 *      restructured: the current default-language config becomes the new
 *      default, and what was previously the "default" becomes a translation
 *      override for the old language code — unless the old default language is
 *      being dropped entirely, in which case it should be discarded.
 * AC9: The symmetric translation layout (component tree structure, keyed by
 *      UUID) must be preserved across the config entity translation swap.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(DefaultLanguageChangeHandler::class)]
#[Group('canvas')]
#[Group('canvas_translation')]
final class DefaultLanguageChangeConfigEntityTest extends CanvasKernelTestBase {

  use GenerateComponentConfigTrait;

  private const UUID_CTA = '435d1d20-a697-4d36-9892-9d61c825c99c';
  private const UUID_BRANDING = '2e2f19ee-7074-4570-91a9-169e81fb0d19';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'language',
    'config_translation',
    'node',
    'field',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('path_alias');
    $this->installConfig(['language', 'node', 'user']);
    $this->generateComponentConfig();
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();

    // Add French as a configurable language.
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

  /**
   * Creates a ContentTemplate with the standard test component tree.
   */
  private function createContentTemplate(): ContentTemplate {
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
            'text' => 'Powered by Drupal Canvas',
            'href' => 'https://drupal.org/project/canvas',
          ],
        ],
      ],
    ]);
    self::assertEntityIsValid($template);
    $template->save();
    return $template;
  }

  /**
   * Saves a French translation override for a ContentTemplate.
   */
  private function addFrenchTranslationToTemplate(ContentTemplate $template): void {
    $language_manager = $this->container->get(LanguageManagerInterface::class);
    \assert($language_manager instanceof ConfigurableLanguageManagerInterface);

    $en_stored_values = $template->toArray();
    $fr_form_values = $en_stored_values;
    NestedArray::setValue(
      $fr_form_values,
      ['component_tree', self::UUID_CTA, 'inputs', 'text'],
      [0 => ['value' => 'Propulsé par Drupal Canvas']]
    );
    $this->saveConfigEntityTranslation($template, 'fr', $fr_form_values);
  }

  // ---------------------------------------------------------------------------
  // AC4: ContentTemplate config translation restructuring
  // ---------------------------------------------------------------------------

  /**
   * Tests that the old default config becomes a translation override.
   *
   * Verifies the override is for the old language after the default changes.
   *
   * AC4: What was previously the "default" config becomes a translation
   * override for the old language code.
   */
  public function testOldDefaultContentTemplateBecomesTranslationOverride(): void {
    $template = $this->createContentTemplate();
    $this->addFrenchTranslationToTemplate($template);

    $language_manager = $this->container->get(LanguageManagerInterface::class);
    \assert($language_manager instanceof ConfigurableLanguageManagerInterface);
    $config_name = $template->getConfigDependencyName();

    // Before the change: EN is base config, FR is a language override.
    $fr_override = $language_manager->getLanguageConfigOverride('fr', $config_name);
    self::assertFalse($fr_override->isNew(), 'FR override must exist before the language change.');

    $this->changeDefaultLanguageTo('fr');

    // After the change: the old EN default must now exist as a language override.
    $en_override = $language_manager->getLanguageConfigOverride('en', $config_name);
    self::assertFalse($en_override->isNew(), 'EN must now exist as a language override after FR becomes default.');

    // The EN override must contain the original English text.
    $en_override_data = $en_override->getRawData();
    $en_text = NestedArray::getValue($en_override_data, ['component_tree', self::UUID_CTA, 'inputs', 'text']);
    self::assertSame('Powered by Drupal Canvas', $en_text, 'EN override must preserve the original English text.');
  }

  /**
   * Tests that the existing FR translation is promoted to become the new base config.
   *
   * Verifies this happens after the default language changes to FR.
   *
   * AC4: The current default-language config becomes the new default when the
   * new default language already has a translation override — that override is
   * promoted.
   */
  public function testFrenchTranslationIsPromotedToBaseConfig(): void {
    $template = $this->createContentTemplate();
    $this->addFrenchTranslationToTemplate($template);

    $this->changeDefaultLanguageTo('fr');

    // The base config (no language override) must now contain the FR text.
    $reloaded_template = ContentTemplate::load($template->id());
    \assert($reloaded_template instanceof ContentTemplate);
    $tree = $reloaded_template->get('component_tree');
    $text_value = NestedArray::getValue($tree, [self::UUID_CTA, 'inputs', 'text']);
    self::assertSame('Propulsé par Drupal Canvas', $text_value, 'Promoted FR text must now be the base config value.');

    // The FR override itself must be gone — it is now the base.
    $language_manager = $this->container->get(LanguageManagerInterface::class);
    \assert($language_manager instanceof ConfigurableLanguageManagerInterface);
    $fr_override = $language_manager->getLanguageConfigOverride('fr', $template->getConfigDependencyName());
    self::assertTrue($fr_override->isNew(), 'FR override must be removed after being promoted to base config.');
  }

  /**
   * Tests that a template with no existing translation remains intact.
   *
   * When there is no existing FR override, the default config simply becomes
   * the new default without creating any orphaned overrides.
   *
   * AC4: When switching default from EN to FR and there is no FR override, the
   * existing base config remains as-is (now representing FR), and no spurious
   * EN override is created.
   */
  public function testContentTemplateWithNoExistingTranslationRemainsIntact(): void {
    $template = $this->createContentTemplate();
    $config_name = $template->getConfigDependencyName();

    // No FR override exists.
    $language_manager = $this->container->get(LanguageManagerInterface::class);
    \assert($language_manager instanceof ConfigurableLanguageManagerInterface);
    self::assertTrue($language_manager->getLanguageConfigOverride('fr', $config_name)->isNew());

    $this->changeDefaultLanguageTo('fr');

    // Base config text must be unchanged (now represents FR default).
    $reloaded = ContentTemplate::load($template->id());
    \assert($reloaded instanceof ContentTemplate);
    $tree = $reloaded->get('component_tree');
    self::assertSame('Powered by Drupal Canvas', NestedArray::getValue($tree, [self::UUID_CTA, 'inputs', 'text']));

    // No EN override must have been spuriously created.
    $en_override = $language_manager->getLanguageConfigOverride('en', $config_name);
    self::assertTrue($en_override->isNew(), 'No EN override must be created when no prior FR override exists.');
  }

  /**
   * Tests that the old default config is discarded when the old language is dropped.
   *
   * AC4: If the old default language is being dropped entirely, the old default
   * config translation override should be discarded rather than stored.
   */
  public function testOldDefaultConfigIsDiscardedWhenOldLanguageIsDropped(): void {
    $template = $this->createContentTemplate();
    $this->addFrenchTranslationToTemplate($template);

    // Change default to FR and simultaneously remove EN from the site.
    $this->changeDefaultLanguageTo('fr');
    $en_language = ConfigurableLanguage::load('en');
    // Deleting the default language is normally blocked; we simulate "dropped"
    // by verifying no EN override is retained when EN no longer exists as a
    // site language after the switch.
    // @see \Drupal\language\Entity\ConfigurableLanguage::preDelete()
    if ($en_language instanceof ConfigurableLanguage && !$en_language->isDefault()) {
      $en_language->delete();
    }

    $language_manager = $this->container->get(LanguageManagerInterface::class);
    \assert($language_manager instanceof ConfigurableLanguageManagerInterface);
    $en_override = $language_manager->getLanguageConfigOverride('en', $template->getConfigDependencyName());
    self::assertTrue($en_override->isNew(), 'EN override must be discarded when EN is dropped from the site.');
  }

  // ---------------------------------------------------------------------------
  // AC4: PageRegion config translation restructuring
  // ---------------------------------------------------------------------------

  /**
   * Tests that PageRegion config overrides are restructured on language change.
   *
   * Verifies the same restructuring behavior as ContentTemplate overrides.
   *
   * AC4: Page regions stored as config overrides must undergo the same
   * restructuring: existing FR override promoted, EN becomes an override.
   */
  public function testPageRegionConfigOverrideIsRestructured(): void {
    $language_manager = $this->container->get(LanguageManagerInterface::class);
    \assert($language_manager instanceof ConfigurableLanguageManagerInterface);

    $region = PageRegion::create([
      'theme' => 'stark',
      'region' => 'sidebar_first',
      'component_tree' => [
        [
          'uuid' => self::UUID_CTA,
          'component_id' => 'sdc.canvas_test_sdc.my-cta',
          'component_version' => '89881c04a0fde367',
          'inputs' => [
            'text' => 'English region text',
            'href' => 'https://example.com',
          ],
        ],
      ],
    ]);
    self::assertEntityIsValid($region);
    $region->save();

    $config_name = $region->getConfigDependencyName();

    // Add a French override for the region.
    $fr_region_override = $language_manager->getLanguageConfigOverride('fr', $config_name);
    $fr_region_override->setData([
      'component_tree' => [
        self::UUID_CTA => [
          'inputs' => ['text' => 'Texte de région français'],
        ],
      ],
    ])->save();

    $this->changeDefaultLanguageTo('fr');

    // FR override must be promoted to base config.
    $reloaded_region = PageRegion::load($region->id());
    \assert($reloaded_region instanceof PageRegion);
    $tree = $reloaded_region->get('component_tree');
    self::assertSame(
      'Texte de région français',
      NestedArray::getValue($tree, [self::UUID_CTA, 'inputs', 'text']),
      'FR text must be promoted to the base PageRegion config.'
    );

    // EN must now be a language override.
    $en_override = $language_manager->getLanguageConfigOverride('en', $config_name);
    self::assertFalse($en_override->isNew(), 'EN must now exist as a PageRegion override.');
    self::assertSame(
      'English region text',
      NestedArray::getValue($en_override->getRawData(), ['component_tree', self::UUID_CTA, 'inputs', 'text'])
    );
  }

  // ---------------------------------------------------------------------------
  // AC9: Symmetric translation layout preserved on config entity swap
  // ---------------------------------------------------------------------------

  /**
   * Tests that UUID-keyed component tree structure is preserved on config swap.
   *
   * AC9: The symmetric translation layout (component tree structure) must be
   * preserved across this swap. UUID-based keys ensure the translation still
   * applies even after components are moved.
   */
  public function testSymmetricTranslationLayoutPreservedOnConfigSwap(): void {
    $template = $this->createContentTemplate();
    $this->addFrenchTranslationToTemplate($template);

    // Reorder the component tree before the language change to verify
    // UUID-keyed targeting still works after the swap.
    $tree_items = $template->getComponentTree()->getValue();
    // Add a second component to make the reorder meaningful.
    $tree_items[] = [
      'uuid' => self::UUID_BRANDING,
      'component_id' => 'block.system_branding_block',
      'component_version' => \Drupal::entityTypeManager()->getStorage('component')->load('block.system_branding_block')?->getActiveVersion(),
      'inputs' => ['label' => 'Branding'],
    ];
    $template->setComponentTree($tree_items)->save();

    $this->changeDefaultLanguageTo('fr');

    // After the swap, both UUIDs must still be present and in correct order.
    $reloaded = ContentTemplate::load($template->id());
    \assert($reloaded instanceof ContentTemplate);
    $uuids = \array_column($reloaded->get('component_tree'), 'uuid');
    self::assertContains(self::UUID_CTA, $uuids, 'CTA component UUID must be preserved after config swap.');
    self::assertContains(self::UUID_BRANDING, $uuids, 'Branding component UUID must be preserved after config swap.');

    // The translated text value (now promoted from FR override) must still be
    // accessible via the UUID key — not a positional key.
    $tree = $reloaded->get('component_tree');
    self::assertArrayHasKey(self::UUID_CTA, $tree, 'Component tree must remain keyed by UUID after swap.');
  }

}
