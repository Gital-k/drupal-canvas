<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\CodeComponentDataProvider;
use Drupal\canvas\Entity\Page;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
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
    'node',
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
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);

    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->config('language.negotiation')
      ->set('url.prefixes', ['en' => '', 'fr' => 'fr'])
      ->save();
    $this->container->get('kernel')->rebuildContainer();

    $this->setUpCurrentUser([], ['access content']);
  }

  /**
   * @legacy-covers \Drupal\canvas\CodeComponentDataProvider::getCanvasDataMainEntityV0
   */
  public function testGetCanvasDataMainEntityV0Cacheability(): void {
    // A published English page with an unpublished French translation.
    $page = Page::create([
      'title' => 'Test page',
      'status' => TRUE,
    ]);
    $page->save();
    $page->addTranslation('fr', ['title' => 'Page de test'])->save();
    $page->getTranslation('fr')->setUnpublished()->save();

    $cacheability = new CacheableMetadata();
    $data = $this->getMainEntityData('entity.canvas_page.canonical', 'canvas_page', $page, $cacheability);

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

  /**
   * @legacy-covers \Drupal\canvas\CodeComponentDataProvider::getCanvasDataMainEntityV0
   */
  public function testGetCanvasDataMainEntityV0NodeTranslatability(): void {
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $node = Node::create([
      'title' => 'Some article',
      'type' => 'article',
      'status' => TRUE,
    ]);
    $node->save();

    // The `article` bundle is not marked translatable (only the
    // `content_translation` module sets that flag), but the language switcher
    // data lists every language anyway; the untranslated language reports
    // `translationAvailable: false` with a fallback URL.
    $data = $this->getMainEntityData('entity.node.canonical', 'node', $node);
    self::assertSame(
      ['en' => TRUE, 'fr' => FALSE],
      \array_column($data[CodeComponentDataProvider::V0]['mainEntity']['translations'], 'translationAvailable', 'langcode'),
    );

    // An entity that factually has translations reports them regardless of
    // bundle-level translatability.
    $node->addTranslation('fr', ['title' => 'Un article'])->save();
    $data = $this->getMainEntityData('entity.node.canonical', 'node', $node);
    self::assertSame(
      ['en' => TRUE, 'fr' => TRUE],
      \array_column($data[CodeComponentDataProvider::V0]['mainEntity']['translations'], 'translationAvailable', 'langcode'),
    );
  }

  /**
   * Calls getCanvasDataMainEntityV0() with the given route as current route.
   */
  private function getMainEntityData(string $route_name, string $parameter_name, EntityInterface $entity, ?CacheableMetadata $cacheability = NULL): array {
    $request = Request::create('/');
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route_name);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $this->container->get('router.route_provider')->getRouteByName($route_name));
    $request->attributes->set($parameter_name, $entity);
    // The pushed request must be popped afterwards: it has no session, which
    // trips KernelTestBase::tearDown().
    $request_stack = $this->container->get('request_stack');
    $request_stack->push($request);
    try {
      $provider = $this->container->get(CodeComponentDataProvider::class);
      \assert($provider instanceof CodeComponentDataProvider);
      return $provider->getCanvasDataMainEntityV0($cacheability);
    }
    finally {
      $request_stack->pop();
    }
  }

}
