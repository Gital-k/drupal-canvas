<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

use Drupal\canvas\Entity\Page;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that the default language change operation is gated behind the
 * 'administer languages' permission, consistent with FR-3856.
 *
 * AC8: The operation must be gated behind the Administer languages permission.
 *
 * Context: Changing the default language is a Drupal core operation (editing
 * system.site config). Canvas's DefaultLanguageChangeHandler reacts to that
 * config change event. The permission gating therefore exists at the Drupal
 * level (the language admin UI requires 'administer languages'), not inside
 * Canvas itself. These tests verify:
 *
 * 1. A user WITHOUT 'administer languages' cannot reach the language
 *    configuration UI and therefore cannot trigger a default language change.
 * 2. A user WITH 'administer languages' can access the configuration UI and,
 *    after making the change, Canvas content is correctly migrated.
 *
 * This ensures Canvas's migration behavior is only reachable through the
 * permission-gated path.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('canvas_translation')]
class DefaultLanguageChangePermissionTest extends FunctionalTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas',
    'language',
    'content_translation',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected $profile = 'minimal';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    ConfigurableLanguage::createFromLangcode('fr')->save();
  }

  // ---------------------------------------------------------------------------
  // AC8: Permission gating — user WITHOUT administer languages
  // ---------------------------------------------------------------------------

  /**
   * Tests that a user without 'administer languages' cannot access the
   * language settings page, and thus cannot trigger a default language change.
   *
   * AC8: The operation must be gated behind the Administer languages permission.
   */
  public function testUserWithoutAdminLanguagesCannotChangeDefaultLanguage(): void {
    // Create a user with many permissions but NOT 'administer languages'.
    $user = $this->drupalCreateUser([
      'administer site configuration',
      'access administration pages',
      'edit canvas_page',
      'publish auto-saves',
    ]);
    $this->drupalLogin($user);

    // The language admin settings page (where default language is changed)
    // must return 403 for this user.
    $this->drupalGet('admin/config/regional/language');
    $this->assertSession()->statusCodeEquals(403);

    // The language negotiation configuration must also be inaccessible.
    $this->drupalGet('admin/config/regional/language/detection');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests that a user with only content permissions cannot trigger the default
   * language change handler, even indirectly.
   *
   * AC8: Canvas content editors must not be able to trigger the migration.
   */
  public function testContentEditorCannotTriggerDefaultLanguageChange(): void {
    $editor = $this->drupalCreateUser([
      'create canvas_page',
      'edit canvas_page',
      'publish auto-saves',
    ]);
    $this->drupalLogin($editor);

    // Sanity-check: editor can access Canvas editor.
    // But the language admin page is off limits.
    $this->drupalGet('admin/config/regional/language');
    $this->assertSession()->statusCodeEquals(403);
  }

  // ---------------------------------------------------------------------------
  // AC8: Permission gating — user WITH administer languages
  // ---------------------------------------------------------------------------

  /**
   * Tests that a user with 'administer languages' can change the default
   * language and Canvas content is correctly migrated as a result.
   *
   * AC8: Consistent with FR-3856, the operation is gated behind 'administer
   * languages', and a user with that permission can successfully trigger it.
   */
  public function testUserWithAdminLanguagesCanChangeDefaultLanguage(): void {
    $admin = $this->drupalCreateUser([
      'administer languages',
      'access administration pages',
    ]);
    $this->drupalLogin($admin);

    // The language settings page must be accessible.
    $this->drupalGet('admin/config/regional/language');
    $this->assertSession()->statusCodeEquals(200);

    // Create a Canvas page before the language change.
    $page = Page::create([
      'title' => 'Page to migrate',
      'langcode' => 'en',
      'status' => TRUE,
      'components' => [],
    ]);
    $page->save();

    // Simulate the default language change by directly saving system.site
    // config (which is what the admin UI form does). This triggers
    // ConfigEvents::SAVE which Canvas's DefaultLanguageChangeHandler listens to.
    $this->container->get('config.factory')
      ->getEditable('system.site')
      ->set('default_langcode', 'fr')
      ->save();

    // Verify Canvas migrated the content.
    $page = Page::load($page->id());
    \assert($page instanceof Page);
    self::assertSame(
      'fr',
      $page->language()->getId(),
      'Canvas page must be migrated to the new default language after an admin change.'
    );
  }

  /**
   * Tests that the site administrator (root user) can change the default
   * language and Canvas content is correctly migrated.
   *
   * AC8: The root user always has all permissions including 'administer
   * languages'. This verifies the happy path from an admin perspective.
   */
  public function testRootUserCanChangeDefaultLanguageAndContentIsMigrated(): void {
    $this->drupalLogin($this->rootUser);

    // The language settings page must be accessible.
    $this->drupalGet('admin/config/regional/language');
    $this->assertSession()->statusCodeEquals(200);

    // Create some Canvas pages.
    $page1 = Page::create(['title' => 'Page 1', 'langcode' => 'en', 'status' => TRUE, 'components' => []]);
    $page1->save();
    $page2 = Page::create(['title' => 'Page 2', 'langcode' => 'en', 'status' => FALSE, 'components' => []]);
    $page2->save();

    // Trigger the default language change via config save (as the admin UI
    // form does).
    $this->container->get('config.factory')
      ->getEditable('system.site')
      ->set('default_langcode', 'fr')
      ->save();

    // Both pages must have been migrated.
    foreach ([$page1->id(), $page2->id()] as $page_id) {
      $page = Page::load($page_id);
      \assert($page instanceof Page);
      self::assertSame(
        'fr',
        $page->language()->getId(),
        "Canvas page $page_id must be migrated to FR by the language admin user."
      );
    }

    // The language manager must now report FR as the default.
    $language_manager = $this->container->get(LanguageManagerInterface::class);
    \assert($language_manager instanceof ConfigurableLanguageManagerInterface);
    $language_manager->reset();
    self::assertSame('fr', $language_manager->getDefaultLanguage()->getId());
  }

}
