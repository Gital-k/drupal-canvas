<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Controller;

use Drupal\canvas\Controller\ApiConfigControllers;
use Drupal\canvas\Entity\Page;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Extension\ThemeInstallerInterface;
use Drupal\Tests\canvas\Kernel\ApiLayoutControllerTestBase;
use Drupal\Tests\canvas\TestSite\CanvasTestSetup;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests host-entity-aware filtering of the component list endpoint.
 *
 * @see \Drupal\canvas\Controller\ApiConfigControllers::list()
 * @see \Drupal\Tests\canvas\Functional\CanvasConfigEntityHttpApiTest::testComponentContextFiltering()
 */
#[CoversClass(ApiConfigControllers::class)]
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class ApiConfigControllersListTest extends ApiLayoutControllerTestBase {

  use GenerateComponentConfigTrait;

  /**
   * A block requiring an `entity:node` context: only a node host satisfies it.
   */
  private const REQUIRES_NODE = 'block.canvas_test_block_requires_node_context';

  /**
   * A block with an optional context: always placeable.
   */
  private const OPTIONAL_CONTEXTS = 'block.canvas_test_block_optional_contexts';

  /**
   * A block whose required "any" context is satisfiable by a global context.
   */
  private const REQUIRES_ANY = 'block.canvas_test_block_requires_contexts';

  /**
   * A block with no context definitions: always placeable.
   */
  private const NO_CONTEXT = 'block.canvas_test_block_input_none';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get(ThemeInstallerInterface::class)->install(['stark']);
    $this->container->get(ModuleInstallerInterface::class)->install([
      'system',
      'canvas_test_sdc',
      'canvas_test_block',
      'node',
    ]);
    $this->container->get(ConfigFactoryInterface::class)->getEditable('system.theme')->set('default', 'stark')->save();

    // @todo Refactor this away in https://www.drupal.org/project/canvas/issues/3531679
    (new CanvasTestSetup())->setup();
    $this->setUpCurrentUser(permissions: ['edit any article content', 'administer themes', Page::EDIT_PERMISSION]);
    $this->generateComponentConfig();
  }

  /**
   * Tests that block Components are filtered by host-entity context.
   */
  public function testFiltersBlocksByHostContext(): void {
    // No host entity: only globally satisfiable Components are returned. The
    // node-context block is absent; a block satisfiable by a global context and
    // a block without required contexts remain.
    $no_host = $this->listComponents();
    self::assertArrayNotHasKey(self::REQUIRES_NODE, $no_host);
    self::assertArrayHasKey(self::OPTIONAL_CONTEXTS, $no_host);
    self::assertArrayHasKey(self::REQUIRES_ANY, $no_host);
    self::assertArrayHasKey(self::NO_CONTEXT, $no_host);

    // Node host: the node-context block becomes satisfiable and is offered.
    $node_host = $this->listComponents(['entity_type' => 'node', 'entity_bundle' => 'article']);
    self::assertArrayHasKey(self::REQUIRES_NODE, $node_host);
    self::assertArrayHasKey(self::OPTIONAL_CONTEXTS, $node_host);
    self::assertArrayHasKey(self::REQUIRES_ANY, $node_host);
    self::assertArrayHasKey(self::NO_CONTEXT, $node_host);

    // A non-satisfying host (a user provides no node context): the node-context
    // block is absent, while the optional-context and no-context blocks remain.
    $user_host = $this->listComponents(['entity_type' => 'user']);
    self::assertArrayNotHasKey(self::REQUIRES_NODE, $user_host);
    self::assertArrayHasKey(self::OPTIONAL_CONTEXTS, $user_host);
    self::assertArrayHasKey(self::NO_CONTEXT, $user_host);

    // Components from non-block sources are unaffected by the host entity.
    self::assertSame(self::nonBlockComponentIds($no_host), self::nonBlockComponentIds($node_host));
    self::assertSame(self::nonBlockComponentIds($no_host), self::nonBlockComponentIds($user_host));
    self::assertNotEmpty(self::nonBlockComponentIds($no_host));
  }

  /**
   * Tests the cacheability of the filtered component list response.
   */
  public function testCacheability(): void {
    $response = $this->parentRequest(Request::create('/canvas/api/v0/config/component?entity_type=node&entity_bundle=article'));
    self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    self::assertInstanceOf(CacheableResponseInterface::class, $response);
    $cacheability = $response->getCacheableMetadata();

    // The only new variation axis is the request parameters, bounded to entity
    // type and bundle, so eligibility never varies per entity id.
    $contexts = $cacheability->getCacheContexts();
    self::assertContains('url.query_args:entity_type', $contexts);
    self::assertContains('url.query_args:entity_bundle', $contexts);
    self::assertNotContains('url.path', $contexts);

    // The list cache tag and the structural tags that change which contexts a
    // bundle exposes are present; the 1-hour minimum max-age is preserved.
    $tags = $cacheability->getCacheTags();
    self::assertContains('config:component_list', $tags);
    self::assertContains('entity_field_info', $tags);
    $max_age = $cacheability->getCacheMaxAge();
    self::assertTrue($max_age === Cache::PERMANENT || $max_age >= 3600, 'The 1-hour minimum max-age is preserved.');
  }

  /**
   * Tests that invalid host parameters return a JSON:API-style error.
   */
  public function testInvalidHostParameters(): void {
    // Dispatch with `$catch = TRUE` so the JSON:API-style error *response*
    // produced by the exception subscriber is what is asserted (and the OpenAPI
    // response validator must accept it), as it would be for a real HTTP
    // request. The shared trait dispatches with `$catch = FALSE` by default, so
    // other tests can inspect thrown exceptions directly.
    // An unknown entity type.
    $response = $this->parentRequest(Request::create('/canvas/api/v0/config/component?entity_type=does_not_exist'), TRUE);
    self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
    $body = self::decodeResponse($response);
    self::assertArrayHasKey('errors', $body);
    self::assertNotEmpty($body['errors']);

    // A bundleable entity type with a bundle that does not exist.
    $response = $this->parentRequest(Request::create('/canvas/api/v0/config/component?entity_type=node&entity_bundle=does_not_exist'), TRUE);
    self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
    $body = self::decodeResponse($response);
    self::assertArrayHasKey('errors', $body);
    self::assertNotEmpty($body['errors']);

    // A bundleable entity type WITHOUT a bundle is not an error: the host's
    // bundle is unknown (e.g. the page editor route carries none), so the list
    // falls back to only globally satisfiable Components instead of 400-ing.
    $response = $this->parentRequest(Request::create('/canvas/api/v0/config/component?entity_type=node'), TRUE);
    self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
  }

  /**
   * Requests the component list, optionally with host-entity query parameters.
   *
   * @param array<string, string> $query
   *   The host-entity query parameters.
   *
   * @return array<string, mixed>
   *   The decoded component list keyed by Component config entity id.
   */
  private function listComponents(array $query = []): array {
    $url = '/canvas/api/v0/config/component';
    if ($query !== []) {
      $url .= '?' . \http_build_query($query);
    }
    $response = $this->parentRequest(Request::create($url));
    self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    return self::decodeResponse($response);
  }

  /**
   * Extracts the ids of Components that are not block-sourced.
   *
   * @param array<string, mixed> $components
   *   The decoded component list.
   *
   * @return list<string>
   *   The non-block Component config entity ids.
   */
  private static function nonBlockComponentIds(array $components): array {
    return \array_values(\array_filter(
      \array_keys($components),
      static fn (string $id): bool => !\str_starts_with($id, 'block.'),
    ));
  }

}
