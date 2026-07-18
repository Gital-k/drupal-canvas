<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\PageRegion;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\canvas\TestSite\CanvasTestSetup;
use Drupal\Tests\canvas\Traits\AutoSaveRequestTestTrait;
use Drupal\Tests\canvas\Traits\CanvasFieldTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

abstract class ApiLayoutControllerMutateModelTestBase extends ApiLayoutControllerTestBase {

  use CanvasFieldTrait;
  use AutoSaveRequestTestTrait;

  protected NodeInterface $node;

  abstract protected static function getValidRequestBody(string $componentUuid): array;

  abstract protected static function getOperationPathSlug(): string;

  abstract public static function providerInvalid(): iterable;

  #[DataProvider('providerValid')]
  abstract public function testOperation(bool $withAutoSave, bool $withGlobal): void;

  protected function setUp(): void {
    parent::setUp();
    $this->container->get('module_installer')->install(['system', 'block']);
    $this->container->get('theme_installer')->install(['stark']);
    $this->container->get('config.factory')->getEditable('system.theme')->set('default', 'stark')->save();

    (new CanvasTestSetup())->setup();
    $node = Node::load(1);
    \assert($node instanceof NodeInterface);
    $this->node = $node;
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
    yield 'fresh state, no global' => [FALSE, FALSE];
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
