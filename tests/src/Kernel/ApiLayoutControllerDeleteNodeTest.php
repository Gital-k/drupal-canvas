<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\ClientDataModel\ClientDataModel;
use Drupal\canvas\ClientDataModel\Operation\DeleteNode;
use Drupal\canvas\Controller\ApiLayoutController;
use Drupal\canvas\Entity\PageRegion;
use Drupal\Tests\canvas\TestSite\CanvasTestSetup;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[RunTestsInSeparateProcesses]
#[CoversClass(DeleteNode::class)]
#[CoversClass(ApiLayoutController::class)]
#[Group('canvas')]
#[Group('ApiLayoutController')]
final class ApiLayoutControllerDeleteNodeTest extends ApiLayoutControllerMutateModelTestBase {

  protected static function getValidRequestBody(string $componentUuid): array {
    return [
      'componentInstanceUuid' => $componentUuid,
    ];
  }

  protected static function getOperationPathSlug(): string {
    return 'delete-node';
  }

  public static function providerInvalid(): iterable {
    yield 'no component UUID' => [
      'Missing componentInstanceUuid',
      BadRequestHttpException::class,
      [
        'look ma' => 'no componentInstanceUuid',
      ],
    ];
    yield 'No such component in model' => [
      'No such component in model: e8c95423-4f22-4210-8707-08bade75ff22',
      NotFoundHttpException::class,
      [
        'componentInstanceUuid' => 'e8c95423-4f22-4210-8707-08bade75ff22',
      ],
    ];
  }

  #[DataProvider('providerValid')]
  public function testOperation(bool $withAutoSave, bool $withGlobal): void {
    $this->setUpCurrentUser([], [
      'administer url aliases',
      PageRegion::ADMIN_PERMISSION,
      'edit any article content',
      'access content',
    ]);
    $autoSave = $this->container->get(AutoSaveManager::class);
    \assert($autoSave instanceof AutoSaveManager);
    $regions = $withGlobal ? self::setupRegions() : [];
    // Load the test data from the layout controller.
    $response = $this->parentRequest(Request::create('/canvas/api/v0/layout/node/1'));
    $this->assertResponseAutoSaves($response, [$this->node], $withGlobal);
    $content = $response->getContent();
    self::assertIsString($content);
    if ($withAutoSave) {
      $this->primeAutoSave($response, $withGlobal, $regions);
    }
    $response = $this->request(Request::create(\sprintf('/canvas/api/v0/layout/%s/node/1', static::getOperationPathSlug()), method: 'PATCH', content: \json_encode([
      'componentInstanceUuid' => CanvasTestSetup::UUID_STATIC_CARD2,
    ] + $this->getPatchContentsDefaults([$this->node]), JSON_THROW_ON_ERROR)));
    $data = \json_decode((string) $response->getContent(), TRUE, flags: \JSON_THROW_ON_ERROR);

    $content = $this->getRegion('content');
    self::assertNotNull($content);
    self::assertArrayHasKey(CanvasTestSetup::UUID_TWO_COLUMN_UUID, $data['model']);
    self::assertArrayNotHasKey(CanvasTestSetup::UUID_STATIC_CARD2, $data['model']);
    $clientModel = new ClientDataModel($data['layout'], (array) $data['model'], []);
    self::assertNull($clientModel->getRegionForComponentInstance(CanvasTestSetup::UUID_STATIC_CARD2));
    if (!$withGlobal) {
      return;
    }
    $highlighted = $this->getRegion('highlighted');
    self::assertNotNull($highlighted);
    // Delete a component from a region.
    $region_component_uuids = \array_column($regions['stark.highlighted']->getComponentTree()->getValue(), 'uuid');
    \assert(\count($region_component_uuids) > 0);
    $region_component_uuid = \reset($region_component_uuids);
    $delete_region_component_body = [
      'componentInstanceUuid' => $region_component_uuid,
    ] + $this->getPatchContentsDefaults([$this->node]);
    $response = $this->request(Request::create(\sprintf('/canvas/api/v0/layout/%s/node/1', static::getOperationPathSlug()), method: 'PATCH', content: \json_encode($delete_region_component_body, JSON_THROW_ON_ERROR)));
    $data = \json_decode((string) $response->getContent(), TRUE, flags: \JSON_THROW_ON_ERROR);
    self::assertArrayHasKey(CanvasTestSetup::UUID_TWO_COLUMN_UUID, $data['model']);
    self::assertArrayNotHasKey($region_component_uuid, $data['model']);
    $clientModel = new ClientDataModel($data['layout'], (array) $data['model'], []);
    self::assertNull($clientModel->getRegionForComponentInstance($region_component_uuid));
    // The region is still rendered (its wrapper markers remain so the UI can
    // target it), but it no longer contains any component instance.
    $highlighted = $this->getRegion('highlighted');
    self::assertNotNull($highlighted);
    self::assertStringNotContainsString('canvas-start-', $highlighted);

    $this->assertRequestAutoSaveConflict(Request::create(\sprintf('/canvas/api/v0/layout/%s/node/1', static::getOperationPathSlug()), method: 'PATCH', content: \json_encode([
      'componentInstanceUuid' => CanvasTestSetup::UUID_TWO_COLUMN_UUID,
      'clientInstanceId' => $delete_region_component_body['clientInstanceId'] . '-new-client',
      // These are out of date now.
      'autoSaves' => $delete_region_component_body['autoSaves'],
    ], JSON_THROW_ON_ERROR)));
  }

}
