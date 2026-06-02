<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

// cspell:ignore titre français

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\DefaultLanguageChangeHandler;
use Drupal\canvas\Entity\Page;
use Drupal\language\Entity\ConfigurableLanguage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests DefaultLanguageChangeHandler for content entity and autosave migration.
 *
 * Covers behavior when the site's default language changes:
 *
 * AC1: Published content (default language) has its langcode updated.
 *      Published translations remain under their original langcodes.
 * AC2: Unpublished/draft content (default language) has its langcode updated.
 *      Draft translations remain untouched.
 * AC3: Canvas autosave data is updated to reflect the new default langcode.
 * AC9: When the new default language already has translations, the translation
 *      data is promoted to become the default, and the former default becomes a
 *      translation. The symmetric translation layout (component tree) is
 *      preserved.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(DefaultLanguageChangeHandler::class)]
#[Group('canvas')]
#[Group('canvas_translation')]
class DefaultLanguageChangeHandlerTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'language',
    'content_translation',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('canvas_page');
    $this->installEntitySchema('path_alias');
    $this->installConfig(['language', 'content_translation']);
    $this->config('system.theme')->set('default', 'stark')->save();

    // Add French as a configurable language so it can become the new default.
    ConfigurableLanguage::createFromLangcode('fr')->save();
  }

  /**
   * Changes the site's default language by saving system.site config.
   *
   * This is how Canvas detects a default language change: by listening on
   * ConfigEvents::SAVE for system.site with a changed default_langcode.
   */
  private function changeDefaultLanguageTo(string $new_langcode): void {
    $this->config('system.site')->set('default_langcode', $new_langcode)->save();
  }

  // ---------------------------------------------------------------------------
  // AC1: Published Canvas pages
  // ---------------------------------------------------------------------------

  /**
   * Tests that a published Canvas page's langcode is updated to the new default.
   *
   * AC1: Published content (default language) must be re-assigned to the new
   * default language code.
   */
  public function testPublishedCanvasPageLangcodeIsUpdated(): void {
    $page = Page::create([
      'title' => 'My published page',
      'langcode' => 'en',
      'status' => TRUE,
      'components' => [],
    ]);
    self::assertEntityIsValid($page);
    $page->save();
    self::assertSame('en', $page->language()->getId());
    self::assertTrue((bool) $page->get('status')->value);

    $this->changeDefaultLanguageTo('fr');

    $page = Page::load($page->id());
    \assert($page instanceof Page);
    self::assertSame('fr', $page->language()->getId(), 'Published page langcode must be updated to the new default.');
    self::assertTrue((bool) $page->get('status')->value, 'Page must remain published after language change.');
  }

  /**
   * Tests that an existing translation is not changed when the default changes.
   *
   * AC1: Existing translated variants must remain intact and retain their
   * original language codes.
   */
  public function testPublishedTranslationLangcodeIsPreserved(): void {
    $this->enableModules(['content_translation']);

    $page = Page::create([
      'title' => 'English default page',
      'langcode' => 'en',
      'status' => TRUE,
      'components' => [],
    ]);
    $page->save();

    // Add a French translation.
    $fr_page = $page->addTranslation('fr', [
      'title' => 'Page française',
      'components' => [],
    ]);
    $fr_page->save();

    $this->changeDefaultLanguageTo('fr');

    // The default (was EN, now the entity should have been promoted) is handled
    // by AC9. Here we assert the non-default translation code stays untouched.
    // Reload both translations.
    $page = Page::load($page->id());
    \assert($page instanceof Page);

    // The original 'en' translation (former default) is now a translation
    // under its own langcode — it must not silently disappear.
    self::assertTrue($page->hasTranslation('en'), 'Original EN translation must still exist after language swap.');
    self::assertSame('fr', $page->language()->getId(), 'Default translation must now be FR.');
  }

  // ---------------------------------------------------------------------------
  // AC2: Unpublished / draft Canvas pages
  // ---------------------------------------------------------------------------

  /**
   * Tests that an unpublished Canvas page's langcode is updated.
   *
   * AC2: All unpublished/draft content must also be updated to the new default
   * language code.
   */
  public function testUnpublishedDraftLangcodeIsUpdated(): void {
    $page = Page::create([
      'title' => 'My draft page',
      'langcode' => 'en',
      'status' => FALSE,
      'components' => [],
    ]);
    $page->save();
    self::assertFalse((bool) $page->get('status')->value);

    $this->changeDefaultLanguageTo('fr');

    $page = Page::load($page->id());
    \assert($page instanceof Page);
    self::assertSame('fr', $page->language()->getId(), 'Draft page langcode must be updated to the new default.');
    self::assertFalse((bool) $page->get('status')->value, 'Page must remain unpublished after language change.');
  }

  /**
   * Tests that a draft translation in a non-default language is not touched.
   *
   * AC2: Any content currently in a draft state in a non-default language
   * translation must remain untouched.
   */
  public function testDraftTranslationInNonDefaultLanguageIsUntouched(): void {
    $this->enableModules(['content_translation']);

    // Add a third language so there's clearly a "non-default non-new-default"
    // translation in play.
    ConfigurableLanguage::createFromLangcode('es')->save();

    $page = Page::create([
      'title' => 'English default page',
      'langcode' => 'en',
      'status' => TRUE,
      'components' => [],
    ]);
    $page->save();

    // Add a Spanish draft translation.
    $es_page = $page->addTranslation('es', [
      'title' => 'Página en español',
      'status' => FALSE,
      'components' => [],
    ]);
    $es_page->save();

    $this->changeDefaultLanguageTo('fr');

    $page = Page::load($page->id());
    \assert($page instanceof Page);

    // The ES translation must remain under 'es' and still be a draft.
    self::assertTrue($page->hasTranslation('es'), 'Spanish translation must still exist.');
    $es_translation = $page->getTranslation('es');
    self::assertSame('es', $es_translation->language()->getId(), 'Spanish translation must retain its langcode.');
    self::assertFalse((bool) $es_translation->get('status')->value, 'Spanish translation must remain a draft.');
  }

  // ---------------------------------------------------------------------------
  // AC3: Canvas autosave migration
  // ---------------------------------------------------------------------------

  /**
   * Tests that the autosave key and stored langcode are updated.
   *
   * Verifies the update for the default language when the site default changes.
   *
   * AC3: All Canvas autosave data must be updated to reflect the new default
   * language code.
   */
  public function testAutoSaveKeyAndLangcodeAreUpdated(): void {
    $auto_save_manager = $this->container->get(AutoSaveManager::class);
    \assert($auto_save_manager instanceof AutoSaveManager);

    $page = Page::create([
      'title' => 'Autosave page',
      'langcode' => 'en',
      'status' => TRUE,
      'components' => [],
    ]);
    $page->save();

    // Simulate an autosave entry in the default (EN) language.
    $page->set('title', 'Autosaved title change');
    $auto_save_manager->saveEntity($page);

    $old_key = AutoSaveManager::getAutoSaveKey($page);
    self::assertStringEndsWith(':en', $old_key);
    self::assertFalse($auto_save_manager->getAutoSaveEntity($page)->isEmpty());

    $this->changeDefaultLanguageTo('fr');

    // The old EN key must no longer exist.
    $all = $auto_save_manager->getAllAutoSaveList();
    self::assertArrayNotHasKey($old_key, $all, 'Old EN autosave key must be removed after language change.');

    // A new FR key must exist with the updated langcode.
    $new_key = 'canvas_page:' . $page->id() . ':fr';
    self::assertArrayHasKey($new_key, $all, 'New FR autosave key must exist after language change.');
    self::assertSame('fr', $all[$new_key]['langcode'], 'Stored langcode in autosave entry must be updated to FR.');
  }

  /**
   * Tests that an autosave entry for an existing translation is not affected.
   *
   * AC3: Canvas autosave (translations) remain unchanged under their respective
   * language keys.
   */
  public function testAutoSaveForTranslationIsUntouched(): void {
    $this->enableModules(['content_translation']);

    $auto_save_manager = $this->container->get(AutoSaveManager::class);
    \assert($auto_save_manager instanceof AutoSaveManager);

    $page = Page::create([
      'title' => 'Default page',
      'langcode' => 'en',
      'status' => TRUE,
      'components' => [],
    ]);
    $page->save();

    $fr_page = $page->addTranslation('fr', [
      'title' => 'French page',
      'components' => [],
    ]);
    $fr_page->save();

    // Simulate autosaves for both language translations.
    $page->set('title', 'Changed EN title');
    $auto_save_manager->saveEntity($page);
    $fr_page->set('title', 'Changed French title');
    $auto_save_manager->saveEntity($fr_page);

    $fr_key = AutoSaveManager::getAutoSaveKey($fr_page);
    self::assertStringEndsWith(':fr', $fr_key);

    // Add Spanish so the "new default" is a language that had no autosave.
    ConfigurableLanguage::createFromLangcode('es')->save();
    $this->changeDefaultLanguageTo('es');

    $all = $auto_save_manager->getAllAutoSaveList();

    // The FR translation autosave key must remain completely untouched.
    self::assertArrayHasKey($fr_key, $all, 'French translation autosave must remain after unrelated language change.');
    self::assertSame('fr', $all[$fr_key]['langcode'], 'French translation autosave langcode must be unchanged.');
  }

  // ---------------------------------------------------------------------------
  // AC9: Promotion of existing translation when it becomes the new default
  // ---------------------------------------------------------------------------

  /**
   * Tests that an existing FR translation is promoted to the default when EN→FR.
   *
   * AC9: If the new default language already has existing translations, the
   * translation data must be promoted to the default/source content, and the
   * former default must become a translation under its original langcode.
   */
  public function testExistingTranslationIsPromotedToDefault(): void {
    $this->enableModules(['content_translation']);

    $page = Page::create([
      'title' => 'English source title',
      'langcode' => 'en',
      'status' => TRUE,
      'components' => [],
    ]);
    $page->save();

    $fr_page = $page->addTranslation('fr', [
      'title' => 'Titre français',
      'status' => TRUE,
      'components' => [],
    ]);
    $fr_page->save();

    $this->changeDefaultLanguageTo('fr');

    $page = Page::load($page->id());
    \assert($page instanceof Page);

    // FR must now be the default translation.
    self::assertSame('fr', $page->language()->getId(), 'FR must become the default translation.');
    self::assertSame('Titre français', (string) $page->label(), 'FR title must be the default content.');

    // EN must now exist as a (non-default) translation.
    self::assertTrue($page->hasTranslation('en'), 'EN must still exist as a translation after swap.');
    $en_translation = $page->getTranslation('en');
    self::assertSame('English source title', (string) $en_translation->label(), 'EN translation must preserve the original title.');
  }

  /**
   * Tests that the symmetric component tree is preserved when translations swap.
   *
   * AC9: The symmetric translation layout (component tree structure) must be
   * preserved across this swap.
   */
  public function testSymmetricComponentTreePreservedOnPromotion(): void {
    $this->enableModules(['content_translation']);
    $this->installConfig(['canvas']);

    $component_uuid = '11111111-1111-4111-8111-111111111111';

    $page = Page::create([
      'title' => 'English source',
      'langcode' => 'en',
      'status' => TRUE,
      'components' => [
        [
          'uuid' => $component_uuid,
          'component_id' => 'sdc.canvas_test_sdc.my-cta',
          'component_version' => '89881c04a0fde367',
          'inputs' => ['text' => 'English text', 'href' => 'https://example.com'],
        ],
      ],
    ]);
    $page->save();

    $fr_page = $page->addTranslation('fr', ['title' => 'Titre français']);
    // Symmetric translation: same tree structure, only inputs differ.
    $fr_page->set('components', $page->get('components')->getValue());
    $fr_page->save();

    $this->changeDefaultLanguageTo('fr');

    $page = Page::load($page->id());
    \assert($page instanceof Page);

    // The component tree UUIDs must be preserved — same structure.
    $tree = $page->get('components')->getValue();
    self::assertNotEmpty($tree, 'Component tree must not be empty after language swap.');
    self::assertSame($component_uuid, $tree[0]['uuid'], 'Component UUID must be preserved in the promoted default.');
  }

  /**
   * Tests that multiple Canvas pages are all migrated in a single operation.
   *
   * AC1/AC2: All published and unpublished content must be re-assigned.
   */
  public function testAllCanvasPagesAreMigratedNotJustOne(): void {
    $page1 = Page::create(['title' => 'Page 1', 'langcode' => 'en', 'status' => TRUE, 'components' => []]);
    $page1->save();
    $page2 = Page::create(['title' => 'Page 2', 'langcode' => 'en', 'status' => FALSE, 'components' => []]);
    $page2->save();
    $page3 = Page::create(['title' => 'Page 3', 'langcode' => 'en', 'status' => TRUE, 'components' => []]);
    $page3->save();

    $this->changeDefaultLanguageTo('fr');

    foreach ([$page1->id(), $page2->id(), $page3->id()] as $id) {
      $page = Page::load($id);
      \assert($page instanceof Page);
      self::assertSame('fr', $page->language()->getId(), "Page $id must have its langcode updated to FR.");
    }
  }

  /**
   * Tests that pages already in the new default language are left unchanged.
   *
   * AC1: Only pages in the old default language should be re-assigned. A page
   * already saved as FR should not be double-processed.
   */
  public function testPagesAlreadyInNewDefaultLanguageAreUntouched(): void {
    // This page is already in French (perhaps created before the change).
    $fr_page = Page::create([
      'title' => 'Already French',
      'langcode' => 'fr',
      'status' => TRUE,
      'components' => [],
    ]);
    $fr_page->save();
    $fr_id = $fr_page->id();

    $en_page = Page::create([
      'title' => 'English page',
      'langcode' => 'en',
      'status' => TRUE,
      'components' => [],
    ]);
    $en_page->save();

    $this->changeDefaultLanguageTo('fr');

    $reloaded_fr = Page::load($fr_id);
    \assert($reloaded_fr instanceof Page);
    self::assertSame('fr', $reloaded_fr->language()->getId());
    self::assertSame('Already French', (string) $reloaded_fr->label(), 'Existing FR page content must not be altered.');
  }

}
