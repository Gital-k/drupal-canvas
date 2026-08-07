<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\Entity\Page;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests Canvas URLs with a language prefix.
 */
#[Group('canvas')]
#[RunTestsInSeparateProcesses]
final class CanvasLanguageRoutesTest extends CanvasKernelTestBase {

  use RequestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'language',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['language']);
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('configurable_language');
    $this->installEntitySchema('user');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
  }

  /**
   * Tests that language-prefixed Canvas URLs are supported.
   */
  public function testLanguagePrefixedCanvasUrlsAreSupported(): void {
    ConfigurableLanguage::createFromLangcode('es')->save();

    $this->config('language.negotiation')
      ->set('url.prefixes', ['en' => '', 'es' => 'es'])
      ->save();

    $this->container->get('kernel')->rebuildContainer();

    $this->setUpCurrentUser([], [Page::EDIT_PERMISSION]);

    $page = Page::create([
      'title' => 'Test page',
      'path' => '/test-page',
      'status' => TRUE,
    ]);
    $page->save();
    $page_id = $page->id();

    // Assert /es/canvas is supported.
    $response = $this->request(Request::create('/es/canvas'));
    self::assertSame(
      200,
      $response->getStatusCode(),
      'A language-prefixed /canvas URL must be supported.',
    );

    $editor_path = "/canvas/editor/canvas_page/$page_id";
    // Assert /es/canvas/editor/canvas_page/{id} is supported.
    $response = $this->request(Request::create("/es$editor_path"));
    self::assertSame(
      200,
      $response->getStatusCode(),
      'A language-prefixed /canvas/editor URL must be supported.',
    );

    // Assert /es/canvas/api/v0/layout/canvas_page/$page_id is supported.
    $api_layout_path = "/canvas/api/v0/layout/canvas_page/$page_id";
    $response = $this->request(Request::create("/es$api_layout_path"));
    self::assertSame(
      200,
      $response->getStatusCode(),
      'A language-prefixed /canvas/api URL must be supported.',
    );
  }

  /**
   * Tests Canvas URLs with custom language prefixes.
   */
  public function testLanguageWithCustomPrefixIsSupported(): void {
    ConfigurableLanguage::createFromLangcode('fr')->save();

    // Setting up a configurable language uses the langcode as the prefix by
    // default. This works fine.
    $negotiation_config = $this->config('language.negotiation');
    self::assertSame('fr', $negotiation_config->get('url.prefixes.fr'));

    // Customizing the prefix to not match the langcode is what causes problems.
    $negotiation_config->set('url.prefixes.fr', 'francais')->save();
    self::assertSame('francais', $negotiation_config->get('url.prefixes.fr'));
    $this->container->get('kernel')->rebuildContainer();

    $this->setUpCurrentUser([], [Page::EDIT_PERMISSION]);
    $response = $this->request(Request::create('/francais/canvas'));

    self::assertSame(
      200,
      $response->getStatusCode(),
      'A /canvas URL with a custom language prefix must be supported.',
    );
  }

}
