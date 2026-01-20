<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Access;

use Drupal\canvas\Access\ContentTemplateAccessCheck;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\NodeTypeInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the ContentTemplateAccessCheck for canvas.template.entity route.
 */
#[CoversClass(ContentTemplateAccessCheck::class)]
#[Group('canvas')]
final class ContentTemplateAccessCheckTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * Modules required for the test.
   *
   * @var array<int, string>
   */
  protected static $modules = [
    // Modules required for testing.
    'user',
    'system',
    'node',
    'taxonomy',
    'media',
    // The module being tested.
    'canvas',
  ];

  /**
   * The entity type manager service.
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->entityTypeManager = $this->container->get('entity_type.manager');
    // Create user 1, which has special admin privileges that could interfere
    // with access tests.
    $this->createUser();
  }

  /**
   * Tests access to the content template route.
   *
   * @param array<int, string> $permissions
   *   The permissions to assign to the test user.
   * @param bool $access_allowed
   *   Whether access should be allowed.
   * @param string $entity_type
   *   The content entity type ID (e.g., 'node').
   * @param string $bundle
   *   The content entity bundle (e.g., 'article').
   * @param string $view_mode_name
   *   The view mode name (e.g., 'full').
   * @param bool|null $template_status
   *   The status of the template to create, or NULL to skip creation.
   */
  #[DataProvider('contentTemplateRouteAccessProvider')]
  public function testContentTemplateRouteAccess(
    array $permissions,
    bool $access_allowed,
    string $entity_type,
    string $bundle,
    string $view_mode_name,
    ?bool $template_status = NULL,
  ): void {
    $url = Url::fromRoute('canvas.boot.content_template')
      ->setRouteParameters([
        'entity_type' => $entity_type,
        'bundle' => $bundle,
        'view_mode_name' => $view_mode_name,
      ]);
    $this->createNodeType(['type' => $bundle]);
    $this->createViewDisplay($entity_type, $bundle, $view_mode_name);
    if ($template_status !== NULL) {
      $this->createContentTemplate($entity_type, $bundle, $view_mode_name, $template_status);
    }
    $user = $this->createUser($permissions);
    self::assertInstanceOf(AccountInterface::class, $user);
    $access = $url->access($user, TRUE);
    self::assertSame($access_allowed, $access->isAllowed());
  }

  /**
   * Data provider for testContentTemplateRouteAccess.
   *
   * @return array<string, array<string, mixed>>
   *   Test cases with permissions, expected access, and entity parameters.
   */
  public static function contentTemplateRouteAccessProvider(): array {
    return [
      'permission with template not present' => [
        'permissions' => [ContentTemplate::ADMIN_PERMISSION],
        'access_allowed' => FALSE,
        'entity_type' => 'node',
        'bundle' => 'article',
        'view_mode_name' => 'full',
        'template_status' => NULL,
      ],
      'permission with active template' => [
        'permissions' => [ContentTemplate::ADMIN_PERMISSION],
        'access_allowed' => TRUE,
        'entity_type' => 'node',
        'bundle' => 'article',
        'view_mode_name' => 'full',
        'template_status' => TRUE,
      ],
      'permission with inactive template' => [
        'permissions' => [ContentTemplate::ADMIN_PERMISSION],
        'access_allowed' => FALSE,
        'entity_type' => 'node',
        'bundle' => 'article',
        'view_mode_name' => 'full',
        'template_status' => FALSE,
      ],
      'no permission with active template' => [
        'permissions' => [],
        'access_allowed' => FALSE,
        'entity_type' => 'node',
        'bundle' => 'article',
        'view_mode_name' => 'full',
        'template_status' => TRUE,
      ],
      'no permission with inactive template' => [
        'permissions' => [],
        'access_allowed' => FALSE,
        'entity_type' => 'node',
        'bundle' => 'article',
        'view_mode_name' => 'full',
        'template_status' => FALSE,
      ],
    ];
  }

  /**
   * Creates a content template entity.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $bundle
   *   The bundle.
   * @param string $view_mode_id
   *   The view mode ID.
   * @param bool $enabled
   *   Whether the template should be enabled.
   *
   * @return \Drupal\Core\Config\Entity\ConfigEntityInterface
   *   The created content template.
   */
  private function createContentTemplate(
    string $entity_type_id,
    string $bundle,
    string $view_mode_id,
    bool $enabled = FALSE,
  ): ConfigEntityInterface {
    $content_template_storage = $this->entityTypeManager->getStorage(ContentTemplate::ENTITY_TYPE_ID);
    $template = $content_template_storage->create([
      'id' => "$entity_type_id.$bundle.$view_mode_id",
      'content_entity_type_id' => $entity_type_id,
      'content_entity_type_bundle' => $bundle,
      'content_entity_type_view_mode' => $view_mode_id,
      'component_tree' => [],
      'status' => $enabled,
    ]);
    assert($template instanceof ConfigEntityInterface);
    $template->save();

    return $template;
  }

  /**
   * Creates a view display for the given bundle and view mode.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $bundle
   *   The bundle.
   * @param string $view_mode_id
   *   The view mode ID.
   */
  private function createViewDisplay(
    string $entity_type_id,
    string $bundle,
    string $view_mode_id,
  ): void {
    $this->entityTypeManager->getStorage('entity_view_mode')->create([
      'targetEntityType' => $entity_type_id,
      'id' => "$entity_type_id.$view_mode_id",
      'status' => TRUE,
      'label' => $this->randomString(),
    ])->save();

    $view_display_storage = $this->entityTypeManager->getStorage('entity_view_display');
    $view_display_storage->create([
      'targetEntityType' => $entity_type_id,
      'bundle' => $bundle,
      'mode' => $view_mode_id,
      'status' => TRUE,
    ])->save();
  }

  /**
   * Creates a custom content type based on default settings.
   *
   * @param array<string, string> $values
   *   An array of settings to change from the defaults.
   *   Example: 'type' => 'foo'.
   *
   * @return \Drupal\node\NodeTypeInterface
   *   The created content type.
   */
  protected function createNodeType(array $values = []): NodeTypeInterface {
    $values += [
      'type' => $this->randomMachineName(),
      'name' => $this->randomMachineName(),
    ];
    $type = $this->entityTypeManager
      ->getStorage('node_type')
      ->create($values);
    $status = $type->save();
    $this->assertSame($status, SAVED_NEW);
    return $type;
  }

}
