<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\CodeComponentDataProvider;
use Drupal\canvas\Entity\Page;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests CodeComponentDataProvider.
 *
 * @see \Drupal\Tests\canvas\Functional\CodeComponentDataProviderTest
 */
#[Group('canvas')]
#[RunTestsInSeparateProcesses]
final class CodeComponentDataProviderTest extends CanvasKernelTestBase {

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
   * @legacy-covers \Drupal\canvas\CodeComponentDataProvider::getCanvasDataMainEntityV0
   */
  public function testGetCanvasDataMainEntityV0Cacheability(): void {
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->config('language.negotiation')
      ->set('url.prefixes', ['en' => '', 'fr' => 'fr'])
      ->save();
    $this->container->get('kernel')->rebuildContainer();

    $this->setUpCurrentUser([], ['access content']);

    // A published English page with an unpublished French translation.
    $page = Page::create([
      'title' => 'Test page',
      'status' => TRUE,
    ]);
    $page->save();
    $page->addTranslation('fr', ['title' => 'Page de test'])->save();
    $page->getTranslation('fr')->setUnpublished()->save();

    // Make the page's canonical route the current route.
    $request = Request::create('/page/' . $page->id());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, 'entity.canvas_page.canonical');
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $this->container->get('router.route_provider')->getRouteByName('entity.canvas_page.canonical'));
    $request->attributes->set('canvas_page', $page);
    $request_stack = $this->container->get('request_stack');
    $request_stack->push($request);

    $provider = $this->container->get(CodeComponentDataProvider::class);
    \assert($provider instanceof CodeComponentDataProvider);
    $cacheability = new CacheableMetadata();
    $data = $provider->getCanvasDataMainEntityV0($cacheability);
    // Restore the request stack: the pushed request has no session, which
    // trips KernelTestBase::tearDown().
    $request_stack->pop();

    // The unpublished French translation is not viewable by this user.
    $translations = $data[CodeComponentDataProvider::V0]['mainEntity']['translations'];
    self::assertSame(
      ['en' => TRUE, 'fr' => FALSE],
      \array_column($translations, 'translationAvailable', 'langcode'),
    );

    // The cacheability of the per-translation `view` access results is
    // collected: the outcome varies by the user's permissions and by the page
    // itself (its published state).
    self::assertContains('user.permissions', $cacheability->getCacheContexts());
    self::assertContains('canvas_page:' . $page->id(), $cacheability->getCacheTags());
  }

}
