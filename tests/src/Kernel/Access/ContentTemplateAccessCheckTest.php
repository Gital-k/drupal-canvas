<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Access;

use Drupal\canvas\Access\ContentTemplateAccessCheck;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the ContentTemplateAccessCheck for canvas.template.entity route.
 *
 * @coversDefaultClass \Drupal\canvas\Access\ContentTemplateAccessCheck
 * @group canvas
 */
final class ContentTemplateAccessCheckTest extends KernelTestBase {

  use RequestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    // The two only modules Drupal truly requires.
    'system',
    'user',
    // The module being tested.
    'canvas',
    // Content entity type and supporting modules.
    'node',
    'field',
    'text',
    // Additional dependencies.
    'path',
    'path_alias',
    'image',
    'media',
    'views',
    'filter',
    'media_library',
    'datetime',
    'file',
    'options',
    'link',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
  }

  /**
   * Tests the access check directly.
   *
   * @param array $permissions
   *   The permissions to grant to the account.
   * @param string $entity_type
   *   The entity type parameter.
   * @param string $view_mode
   *   The view mode parameter.
   * @param bool $expected_access
   *   Whether access should be granted.
   *
   * @dataProvider accessCheckProvider
   * @covers ::access
   */
  public function testAccess(array $permissions, string $entity_type, string $view_mode, bool $expected_access): void {
    $account = $this->createMock(AccountInterface::class);
    $account->expects($this->atLeastOnce())
      ->method('hasPermission')
      ->willReturnCallback(fn ($permission) => in_array($permission, $permissions, TRUE));

    $accessCheck = new ContentTemplateAccessCheck();
    $result = $accessCheck->access($account, $entity_type, $view_mode);

    self::assertEquals($expected_access, $result->isAllowed());
  }

  /**
   * Data provider for testAccess.
   *
   * @return array
   *   Test cases with permissions, parameters, and expected access.
   */
  public static function accessCheckProvider(): array {
    return [
      'with admin permission and valid params' => [
        [ContentTemplate::ADMIN_PERMISSION],
        'node',
        'full',
        TRUE,
      ],
      'without permission' => [
        [],
        'node',
        'full',
        FALSE,
      ],
      'with unrelated permission' => [
        ['access content'],
        'node',
        'full',
        FALSE,
      ],
      'with admin permission but empty entity_type' => [
        [ContentTemplate::ADMIN_PERMISSION],
        '',
        'full',
        FALSE,
      ],
      'with admin permission but empty view_mode' => [
        [ContentTemplate::ADMIN_PERMISSION],
        'node',
        '',
        FALSE,
      ],
      'with admin permission but both empty' => [
        [ContentTemplate::ADMIN_PERMISSION],
        '',
        '',
        FALSE,
      ],
    ];
  }

  /**
   * Tests access to the canvas.template.entity route.
   *
   * @param array $permissions
   *   The permissions to grant to the user.
   * @param bool $expected_access
   *   Whether access should be granted.
   *
   * @dataProvider routeAccessDataProvider
   * @covers ::access
   */
  public function testRouteAccess(array $permissions, bool $expected_access): void {
    $this->setUpCurrentUser([], $permissions);

    $url = Url::fromRoute('canvas.template.entity', [
      'entity_type' => 'node',
      'bundle' => 'article',
      'view_mode' => 'full',
    ])->toString();

    self::assertEquals('/canvas/template/node/article/full', $url);

    if (!$expected_access) {
      $this->expectException(CacheableAccessDeniedHttpException::class);
    }

    $response = $this->request(Request::create($url));

    if ($expected_access) {
      self::assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }
  }

  /**
   * Data provider for testRouteAccess.
   *
   * @return array
   *   Test cases with permissions and expected access.
   */
  public static function routeAccessDataProvider(): array {
    return [
      'with admin permission' => [
        [ContentTemplate::ADMIN_PERMISSION],
        TRUE,
      ],
      'without permission' => [
        [],
        FALSE,
      ],
      'with unrelated permission' => [
        ['access content'],
        FALSE,
      ],
    ];
  }

  /**
   * Tests that the access result has proper cache contexts.
   *
   * @covers ::access
   */
  public function testAccessCacheContexts(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->expects($this->atLeastOnce())
      ->method('hasPermission')
      ->willReturn(TRUE);

    $accessCheck = new ContentTemplateAccessCheck();
    $result = $accessCheck->access($account, 'node', 'full');

    self::assertTrue($result->isAllowed());
    // Verify the access result includes the url.path cache context.
    self::assertContains('url.path', $result->getCacheContexts());
  }

  /**
   * Tests access denied reason is set correctly.
   *
   * @covers ::access
   */
  public function testAccessDeniedReason(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->expects($this->atLeastOnce())
      ->method('hasPermission')
      ->willReturn(FALSE);

    $accessCheck = new ContentTemplateAccessCheck();
    $result = $accessCheck->access($account, 'node', 'full');

    self::assertFalse($result->isAllowed());
    self::assertInstanceOf(AccessResult::class, $result);
    self::assertEquals(
      'User does not have permission to administer content templates.',
      $result->getReason()
    );
  }

  /**
   * Tests access denied for empty parameters with proper reason.
   *
   * @covers ::access
   */
  public function testAccessDeniedForEmptyParameters(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->expects($this->atLeastOnce())
      ->method('hasPermission')
      ->willReturn(TRUE);

    $accessCheck = new ContentTemplateAccessCheck();
    $result = $accessCheck->access($account, '', 'full');

    self::assertFalse($result->isAllowed());
    self::assertEquals(
      'Entity type and view mode are required.',
      $result->getReason()
    );
  }

}

