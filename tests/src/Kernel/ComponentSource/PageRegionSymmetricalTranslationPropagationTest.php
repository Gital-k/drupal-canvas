<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\ComponentSource;

// cspell:ignore Hola opcional

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\PageRegion;
use Drupal\canvas\EntityHandlers\StagedLanguageConfigOverrideStorage;
use Drupal\Core\Extension\ThemeInstallerInterface;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\Core\Url;
use Drupal\language\ConfigurableLanguageManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests component instance update propagation to PageRegion translations.
 */
#[CoversClass(ComponentSourceManager::class)]
#[CoversClass(StagedLanguageConfigOverrideStorage::class)]
#[Group('canvas')]
#[Group('canvas_component_sources')]
#[Group('canvas_data_model')]
#[Group('canvas_translation')]
#[Group('slow')]
final class PageRegionSymmetricalTranslationPropagationTest extends ConfigEntitySymmetricalTranslationPropagationTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    \Drupal::service(ThemeInstallerInterface::class)->install(['stark']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    // Make stark the active theme so the PageRegion is loaded as a global region
    // when a Page is previewed.
    $this->config('system.theme')->set('default', 'stark')->save();
    \Drupal::service(ThemeManagerInterface::class)->resetActiveTheme();

    $this->entity = PageRegion::create([
      'theme' => 'stark',
      'region' => 'sidebar_first',
      'component_tree' => self::populateActiveComponentVersionPlaceholders($this->translatableComponentTree),
    ]);
    self::assertEntityIsValid($this->entity);
    self::assertSame(SAVED_NEW, $this->entity->save());
  }

  /**
   * {@inheritdoc}
   *
   * A PageRegion is not previewed directly: it is reconciled as a global region
   * while a Page is previewed through the layout GET endpoint.
   */
  protected function previewThroughLayoutController(string $preview_langcode): void {
    \assert($this->entity instanceof PageRegion);
    $page = Page::create(['title' => 'Preview host', 'components' => []]);
    self::assertSame(SAVED_NEW, $page->save());
    \Drupal::entityTypeManager()->getStorage(Component::ENTITY_TYPE_ID)->resetCache();
    $path = Url::fromRoute('canvas.api.layout.get', [
      'entity_type' => Page::ENTITY_TYPE_ID,
      'entity' => $page->id(),
    ])->toString();
    $prefix = $preview_langcode === 'en' ? '' : "/$preview_langcode";
    $response = $this->request(Request::create($prefix . $path));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
  }

  /**
   * {@inheritdoc}
   */
  protected function additionalPreviewPermissions(): array {
    // The PageRegion is reconciled while previewing a Page; the layout GET route
    // requires edit access to that Page.
    return [Page::EDIT_PERMISSION];
  }

  /**
   * GetTranslationLanguages() uses the entity's own langcode, not site default.
   *
   * The region was created while 'en' was the site default, so it carries
   * langcode: en. After changing the site default to 'es', the entity langcode
   * stays 'en'. getTranslationLanguages() must still treat 'en' as the default
   * (excluding it from non-default results), and return 'es' only because a
   * live config override exists for it — not because it is now the site default.
   *
   * Without the fix, getTranslationLanguages() uses getDefaultLanguage() ('es')
   * as $default_langcode, treats 'en' as a non-default translation, and calls
   * getTranslation('en'), which throws InvalidArgumentException.
   *
   * @see \Drupal\canvas\Entity\ComponentTreeConfigEntityBase::getTranslationLanguages()
   */
  public function testGetTranslationLanguagesWithEntityLangcodeDifferingFromSiteDefault(): void {
    // Write a live Spanish override so 'es' appears as a translation language.
    $this->createComponentTreeTranslation('es', self::ES_TRANSLATION_INPUTS);

    // Change the site default to 'es'; the region keeps langcode: en.
    $this->config('system.site')->set('default_langcode', 'es')->save();
    $language_manager = $this->container->get('language_manager');
    \assert($language_manager instanceof ConfigurableLanguageManagerInterface);
    $language_manager->reset();
    self::assertSame('es', $language_manager->getDefaultLanguage()->getId());
    self::assertSame('en', $this->entity->language()->getId());

    // Must not throw. 'en' is the entity default — include_default=TRUE returns
    // both; include_default=FALSE returns only 'es'.
    $all = $this->entity->getTranslationLanguages(include_default: TRUE);
    self::assertArrayHasKey('en', $all, "'en' must appear as the default translation language.");
    self::assertArrayHasKey('es', $all, "'es' must appear as a non-default translation language.");

    $non_default = $this->entity->getTranslationLanguages(include_default: FALSE);
    self::assertArrayNotHasKey('en', $non_default, "'en' must be excluded when include_default is FALSE.");
    self::assertArrayHasKey('es', $non_default, "'es' must remain when include_default is FALSE.");
  }

}
