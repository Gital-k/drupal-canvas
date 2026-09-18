<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_personalization\Kernel;

use Drupal\canvas_personalization\Entity\Segment;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Condition\ConditionManager;
use Drupal\Core\Executable\ExecutableManagerInterface;
use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Drupal\Core\Http\Exception\CacheableUnauthorizedHttpException;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\canvas\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the internal HTTP API for this site's available segmentation rules.
 *
 * @see \Drupal\canvas_personalization\Controller\ApiSegmentRuleControllers
 * @see \Drupal\Tests\canvas_personalization\Functional\SegmentHttpApiTest
 */
#[Group('canvas')]
#[Group('canvas_personalization')]
#[RunTestsInSeparateProcesses]
final class SegmentRuleHttpApiTest extends CanvasKernelTestBase {

  use ContribStrictConfigSchemaTestTrait;
  use RequestTrait;
  use UserCreationTrait;

  private const string LIST_PATH = '/canvas/api/v0/personalization/segment-rules';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_personalization',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installConfig(['system']);
  }

  /**
   * Only the condition plugins personalization allows are listed.
   */
  public function testList(): void {
    $this->setUpCurrentUser(permissions: [Segment::ADMIN_PERMISSION]);

    $response = $this->request(Request::create(self::LIST_PATH));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    self::assertSame([
      'current_theme' => [
        'id' => 'current_theme',
        'label' => 'Current Theme',
      ],
      'user_role' => [
        'id' => 'user_role',
        'label' => 'User Role',
      ],
      'utm_parameters' => [
        'id' => 'utm_parameters',
        'label' => 'UTM Parameters',
      ],
    ], static::decodeResponse($response));
  }

  /**
   * A condition plugin that exists but is not allowed is not listed.
   *
   * This is the same boundary the `SequenceKeysAreFilteredPluginIds` constraint
   * enforces on a stored Segment's `rules`: a Segmentation Rule this HTTP API
   * offered but validation then rejected would be unusable.
   *
   * @see \Drupal\canvas\Plugin\Validation\Constraint\SequenceKeysAreFilteredPluginIdsConstraint
   */
  public function testListOmitsFilteredOutConditionPlugins(): void {
    $this->setUpCurrentUser(permissions: [Segment::ADMIN_PERMISSION]);
    $condition_manager = $this->container->get(ExecutableManagerInterface::class);
    self::assertInstanceOf(ConditionManager::class, $condition_manager);
    self::assertArrayHasKey('request_path', $condition_manager->getDefinitions());

    $response = $this->request(Request::create(self::LIST_PATH));
    self::assertArrayNotHasKey('request_path', static::decodeResponse($response));
  }

  /**
   * The listing is cacheable, and invalidated when the module list changes.
   */
  public function testListCacheability(): void {
    $this->setUpCurrentUser(permissions: [Segment::ADMIN_PERMISSION]);

    $response = $this->request(Request::create(self::LIST_PATH));
    self::assertInstanceOf(CacheableJsonResponse::class, $response);
    $cacheability = $response->getCacheableMetadata();
    // Installing or uninstalling a module changes which condition plugins
    // exist and which modules narrow them down for personalization.
    self::assertSame(['config:core.extension', 'http_response'], $cacheability->getCacheTags());
    // Themes can narrow them down too, and only the active theme's
    // implementations run.
    self::assertSame(['theme', 'user.permissions'], $cacheability->getCacheContexts());
    self::assertSame(Cache::PERMANENT, $cacheability->getCacheMaxAge());
  }

  /**
   * Listing requires the permission for administering Segments.
   */
  public function testListRequiresPermission(): void {
    $this->setUpCurrentUser(permissions: ['access content']);

    $this->expectException(CacheableAccessDeniedHttpException::class);
    $this->expectExceptionMessage("The 'administer personalization segments' permission is required.");
    $this->request(Request::create(self::LIST_PATH));
  }

  /**
   * Listing requires an authenticated user.
   */
  public function testListRequiresAuthentication(): void {
    $this->setCurrentUser(new AnonymousUserSession());

    $this->expectException(CacheableUnauthorizedHttpException::class);
    $this->expectExceptionMessage('You must be logged in to access this resource.');
    $this->request(Request::create(self::LIST_PATH));
  }

}
