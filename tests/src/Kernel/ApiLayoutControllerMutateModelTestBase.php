<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\Core\Extension\ThemeInstallerInterface;
use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\PageRegion;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\block\Traits\BlockCreationTrait;
use Drupal\Tests\canvas\TestSite\CanvasTestSetup;
use Drupal\Tests\canvas\Traits\AutoSaveManagerTestTrait;
use Drupal\Tests\canvas\Traits\AutoSaveRequestTestTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\canvas\Traits\CanvasFieldTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * @group canvas
 * @group ApiLayoutController
 * @covers \Drupal\canvas\Controller\ApiLayoutController
 */
abstract class ApiLayoutControllerMutateModelTestBase extends ApiLayoutControllerTestBase {

  use AutoSaveManagerTestTrait;
  use CanvasFieldTrait;
  use GenerateComponentConfigTrait;
  use ContentTypeCreationTrait;
  use MediaTypeCreationTrait;
  use BlockCreationTrait;
  use AutoSaveRequestTestTrait;

  protected NodeInterface $node;

  abstract protected static function getValidRequestBody(string $componentUuid): array;

  abstract protected static function getOperationPathSlug(): string;

  abstract public static function providerInvalid(): iterable;

  #[DataProvider('providerValid')]
  abstract public function testOperation(bool $withAutoSave = FALSE, bool $withGlobal = FALSE): void;

  protected static $modules = [
    'canvas_test_sdc',
    'system',
    'canvas',
    'file',
    'image',
    'link',
    'path',
    'path_alias',
    'media',
    'user',
    'text',
    'options',
    'node',
    'filter',
    'field',
    'editor',
    'block',
    'ckeditor5',
    'canvas_dev_standard',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->container->get(ThemeInstallerInterface::class)->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->placeBlock('page_title_block', ['region' => 'highlighted']);
    $this->placeBlock('system_messages_block', ['region' => 'header']);
    $this->placeBlock('system_powered_by_block', ['region' => 'header']);
    $this->placeBlock('system_main_block');
    $this->installConfig('canvas');
    $this->installEntitySchema('user');
    // Create the anon user.
    User::create(['uid' => 0, 'name' => 'Bobby Dazzler'])->save();
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('node');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installSchema('file', 'file_usage');
    $this->installConfig('node');
    $this->installConfig('system');
    $this->createContentType(['type' => 'article']);
    $this->installConfig('canvas_dev_standard');
    $this->createMediaType('image', ['id' => 'image', 'label' => 'Image']);
    $this->setUpImages();
    $this->generateComponentConfig();
    $this->node = Node::create([
      'title' => 'Eyes the size of an American mile',
      'type' => 'article',
      'field_canvas_demo' => [
        [
          'component_id' => 'sdc.canvas_test_sdc.two_column',
          'uuid' => CanvasTestSetup::UUID_TWO_COLUMN_UUID,
          'inputs' => [
            'width' => 50,
          ],
        ],
        [
          'parent_uuid' => CanvasTestSetup::UUID_TWO_COLUMN_UUID,
          'slot' => 'column_one',
          'component_id' => 'sdc.canvas_test_sdc.image',
          'uuid' => CanvasTestSetup::UUID_STATIC_IMAGE,
          'inputs' => [
            'image' => [
              'target_id' => $this->referencedImage->id(),
              'alt' => NULL,
              'width' => NULL,
              'height' => NULL,
            ],
          ],
        ],
        [
          'parent_uuid' => CanvasTestSetup::UUID_TWO_COLUMN_UUID,
          'slot' => 'column_one',
          'component_id' => 'sdc.canvas_test_sdc.my-hero',
          'uuid' => CanvasTestSetup::UUID_STATIC_CARD1,
          'inputs' => [
            'heading' => 'hello, world!',
            'cta1href' => ['uri' => 'http://example.com', 'options' => []],
          ],
        ],
        [
          'parent_uuid' => CanvasTestSetup::UUID_TWO_COLUMN_UUID,
          'slot' => 'column_one',
          'uuid' => CanvasTestSetup::UUID_ALL_SLOTS_EMPTY,
          'component_id' => 'sdc.canvas_test_sdc.one_column',
          'inputs' => [
            'width' => 'full',
          ],
        ],
        [
          'parent_uuid' => CanvasTestSetup::UUID_TWO_COLUMN_UUID,
          'slot' => 'column_two',
          'uuid' => CanvasTestSetup::UUID_STATIC_CARD2,
          'component_id' => 'sdc.canvas_test_sdc.my-hero',
          'inputs' => [
            'heading' => 'This is card 2',
            'cta1href' => ['uri' => 'http://example.com', 'options' => []],
          ],
        ],
        [
          'parent_uuid' => CanvasTestSetup::UUID_TWO_COLUMN_UUID,
          'slot' => 'column_two',
          'uuid' => CanvasTestSetup::UUID_STATIC_CARD3,
          'component_id' => 'sdc.canvas_test_sdc.my-hero',
          'inputs' => [
            'heading' => 'This is card 3',
            'cta1href' => ['uri' => 'http://example.com', 'options' => []],
          ],
        ],
      ],
    ]);
    self::assertCount(0, $this->node->validate());
    $this->node->save();
  }

  /**
   * @param class-string<\Throwable> $exception
   */
  #[DataProvider('providerInvalid')]
  public function testInvalid(string $message, string $exception, array $content): void {
    $this->setUpCurrentUser([], [
      'administer url aliases',
      PageRegion::ADMIN_PERMISSION,
      'edit any article content',
      'access content',
    ]);
    $this->expectException($exception);
    $this->expectExceptionMessage($message);
    $content += $this->getClientAutoSaves([$this->node]);
    $this->parentRequest(Request::create(\sprintf('/canvas/api/v0/layout/%s/node/1', static::getOperationPathSlug()), method: 'PATCH', server: [
      'CONTENT_TYPE' => 'application/json',
      'HTTP_X_NO_OPENAPI_VALIDATION' => 'turned off because we want to validate the prod response here',
    ], content: \json_encode($content, JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR)));
  }

  public static function providerValid(): iterable {
    yield 'fresh state, no global' => [];
    yield 'fresh state, global' => [FALSE, TRUE];
    yield 'existing auto-save, no global' => [TRUE, FALSE];
    yield 'existing auto-save, global' => [TRUE, TRUE];
  }

  public function testEntityAccessRequired(): void {
    $this->setUpCurrentUser([], [
      'administer url aliases',
      'access content',
    ]);

    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage("The 'edit any article content' permission is required.");

    $this->request(
      Request::create(
        \sprintf('/canvas/api/v0/layout/%s/node/1', static::getOperationPathSlug()),
        method: 'PATCH',
        content: json_encode(static::getValidRequestBody(CanvasTestSetup::UUID_STATIC_CARD1) + $this->getPatchContentsDefaults([$this->node]), JSON_THROW_ON_ERROR)
      )
    );
  }

  public function testWithoutPageRegionPermission(): void {
    $this->setUpCurrentUser([], [
      'administer url aliases',
      'edit any article content',
      'access content',
    ]);

    $autoSave = $this->container->get(AutoSaveManager::class);
    \assert($autoSave instanceof AutoSaveManager);
    $regions = PageRegion::createFromBlockLayout('stark');
    foreach ($regions as $region) {
      $region->save();
    }
    // Load the test data from the layout controller.
    $this->request(Request::create('/canvas/api/v0/layout/node/1'))->getContent();

    // Check that content region exist and is wrapped.
    $contentRegion = $this->getRegion('content');
    self::assertNotNull($contentRegion);
    // But not the highlighted region, as we don't have access to it.
    $highlighted = $this->getRegion('highlighted');
    self::assertNull($highlighted);

    // Perform the operation on a component instance in a ("global") region.
    // We need to use the APIs to get the UUID of a valid component instance in a region.
    $component_tree_values = $regions['stark.highlighted']->getComponentTree()->getValue();
    $globalComponentUuids = \array_column($component_tree_values, 'uuid');
    // There is only one block, the title, in the highlighted region.
    self::assertCount(1, $globalComponentUuids);
    $globalComponentUuid = $globalComponentUuids[0];

    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage('Access denied for region highlighted');

    $this->request(
      Request::create(
        \sprintf('/canvas/api/v0/layout/%s/node/1', static::getOperationPathSlug()),
        method: 'PATCH',
        content: \json_encode(static::getValidRequestBody($globalComponentUuid) + $this->getPatchContentsDefaults([$this->node], FALSE), JSON_THROW_ON_ERROR)
      )
    );
  }

  protected static function setupRegions(): array {
    $regions = PageRegion::createFromBlockLayout('stark');
    foreach ($regions as $region) {
      $region->save();
    }
    return $regions;
  }

  protected function primeAutoSave(Response $getLayoutResponse, bool $withGlobal, array $regions): void {
    $autoSave = $this->container->get(AutoSaveManager::class);
    \assert($autoSave instanceof AutoSaveManager);
    $content = $getLayoutResponse->getContent();
    self::assertIsString($content);
    // Perform a POST first to trigger the auto-save manager being called.
    // This will not result in an auto-save entry because the content is the
    // same as the saved version.
    $postResponse = $this->request(Request::create('/canvas/api/v0/layout/node/1', method: 'POST', content: $this->filterLayoutForPost($content)));
    $this->assertResponseAutoSaves($postResponse, [$this->node], $withGlobal);
    self::assertEquals(Response::HTTP_OK, $postResponse->getStatusCode());
    self::assertTrue($autoSave->getAutoSaveEntity($this->node)->isEmpty());
    foreach ($regions as $region) {
      self::assertTrue($autoSave->getAutoSaveEntity($region)->isEmpty());
    }
  }

}
