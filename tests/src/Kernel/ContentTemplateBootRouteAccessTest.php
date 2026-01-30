<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\Core\Url;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\canvas\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;

/**
 * Tests access checking for the canvas.boot.content_template route.
 *
 * This route uses a parameter converter to enable access checking during link
 * generation (not just inbound requests). The converter loads the ContentTemplate
 * from route parameters (entity_type, bundle, view_mode_name), allowing
 * _entity_access to check permissions even when generating URLs.
 *
 * @group canvas
 */
final class ContentTemplateBootRouteAccessTest extends KernelTestBase {

  use ContribStrictConfigSchemaTestTrait;
  use ContentTypeCreationTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas',
    'system',
    'user',
    'node',
    'field',
    'text',
    'media',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['node', 'system']);
    $this->createContentType(['type' => 'article']);
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
   *
   * @covers \Drupal\canvas\Routing\ContentTemplateFromPartsParamConverter::convert
   * @covers \Drupal\canvas\Routing\ContentTemplateFromPartsParamConverter::applies
   */
  public function testContentTemplateBootRouteAccess(): void {
    // Create a ContentTemplate for node.article.full.
    $template = ContentTemplate::create([
      'id' => 'node.article.full',
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'article',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [],
    ]);
    $template->setStatus(TRUE)->save();
    $admin_user = $this->createUser(['administer content templates']);
    assert($admin_user instanceof UserInterface);

    // Test 1: User with 'administer content templates' permission.
    // This user should have access to edit ContentTemplates and Canvas UI
    // access.
    $url = Url::fromRoute('canvas.boot.content_template', [
      'entity_type' => 'node',
      'bundle' => 'article',
      'view_mode_name' => 'full',
    ]);
    $access = $url->access($admin_user, TRUE);
    $this->assertTrue($access->isAllowed());

    // Test 2: User with 'administer content templates' permission
    // but ContentTemplate does not exist.
    $url = Url::fromRoute('canvas.boot.content_template', [
      'entity_type' => 'invalid_entity_type',
      'bundle' => 'invalid_bundle',
      'view_mode_name' => 'invalid_view_mode',
    ]);
    $access = $url->access($admin_user, TRUE);
    $this->assertFalse($access->isAllowed());

    // Test 3: User with 'administer code components' permission.
    // This also grants Canvas UI access, but not edit ContentTemplates.
    $code_admin_user = $this->createUser(['administer code components']);
    assert($code_admin_user instanceof UserInterface);
    $access = $url->access($code_admin_user, TRUE);
    $this->assertFalse($access->isAllowed());
  }

}
