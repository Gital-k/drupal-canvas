<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Routing\ContentTemplateFromPartsParamConverter;
use Drupal\Core\Url;
use Drupal\Tests\canvas\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * Tests access checking for the canvas.boot.content_template route.
 *
 * This route uses a parameter converter to enable access checking during link
 * generation (not just inbound requests). The converter loads the ContentTemplate
 * from route parameters (entity_type, bundle, view_mode_name), allowing
 * _entity_access to check permissions even when generating URLs.
 */
#[Group('canvas')]
#[CoversClass(ContentTemplateFromPartsParamConverter::class)]
final class ContentTemplateBootRouteAccessTest extends CanvasKernelTestBase {

  use ContribStrictConfigSchemaTestTrait;
  use ContentTypeCreationTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'field',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installConfig(['node']);
    $this->createContentType(['type' => 'article']);
    ContentTemplate::create([
      'id' => 'node.article.full',
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [],
    ])->setStatus(TRUE)->save();
  }

  /**
   * Tests that ContentTemplateFromPartsParamConverter enables URL access checks.
   *
   * The canvas.boot.content_template route is primarily used to generate edit
   * links for ContentTemplates. Even though CanvasPathProcessor redirects all
   * inbound requests to /canvas (so this route never handles requests), the
   * access checks still work during link generation because:
   *
   * 1. The parameter converter runs during $url->access() calls
   * 2. It constructs the ContentTemplate ID from route parameters
   * 3. It loads the entity, making it available for _entity_access checks
   * 4. Access is granted/denied based on entity permissions
   */
  #[TestWith([['administer content templates'], ['entity_type' => 'node', 'bundle' => 'article', 'view_mode_name' => 'full'], TRUE], 'content template admin can access existing template')]
  #[TestWith([['administer content templates'], ['entity_type' => 'invalid_entity_type', 'bundle' => 'invalid_bundle', 'view_mode_name' => 'invalid_view_mode'], FALSE], 'content template admin cannot access non-existing template')]
  #[TestWith([['administer code components'], ['entity_type' => 'invalid_entity_type', 'bundle' => 'invalid_bundle', 'view_mode_name' => 'invalid_view_mode'], FALSE], 'code components admin cannot access content templates')]
  public function testContentTemplateBootRouteAccess(array $permissions, array $route_params, bool $expected_access): void {
    $user = $this->createUser($permissions);
    self::assertInstanceOf(UserInterface::class, $user);
    $access = Url::fromRoute('canvas.boot.content_template', $route_params)->access($user, TRUE);
    $this->assertSame($expected_access, $access->isAllowed());
  }

}
