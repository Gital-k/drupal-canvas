<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\ClientDataModel\ClientDataModel;
use Drupal\canvas\Entity\PageRegion;
use Drupal\Tests\canvas\TestSite\CanvasTestSetup;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @group canvas
 * @group ApiLayoutController
 * @covers \Drupal\canvas\ClientDataModel\Operation\MoveNode
 * @covers \Drupal\canvas\Controller\ApiLayoutController
 */
final class ApiLayoutControllerMoveNodeTest extends ApiLayoutControllerMutateModelTestBase {

  protected static function getValidRequestBody(string $componentUuid): array {
    return [
      'uuid' => $componentUuid,
      'parentRegionOrUuid' => CanvasTestSetup::UUID_TWO_COLUMN_UUID,
      'slot' => 'column_two',
      // @see https://madebyevan.com/algos/crdt-fractional-indexing/
      'fractal' => 1.5,
    ];
  }

  protected static function getOperationPathSlug(): string {
    return 'move-node';
  }

  public static function providerInvalid(): iterable {
    yield 'no component UUID' => [
      'Missing uuid',
      BadRequestHttpException::class,
      [
        'look ma' => 'no uuid',
      ],
    ];
    yield 'no parent UUID or region' => [
      'Missing parent UUID or region',
      BadRequestHttpException::class,
      [
        'uuid' => CanvasTestSetup::UUID_STATIC_CARD2,
      ],
    ];
    yield 'no fractal' => [
      'Missing position (fractal)',
      BadRequestHttpException::class,
      [
        'uuid' => CanvasTestSetup::UUID_STATIC_CARD2,
        'parentRegionOrUuid' => CanvasTestSetup::UUID_TWO_COLUMN_UUID,
      ],
    ];
    yield 'invalid fractal' => [
      'Invalid position (fractal)',
      BadRequestHttpException::class,
      [
        'uuid' => CanvasTestSetup::UUID_STATIC_CARD2,
        'parentRegionOrUuid' => CanvasTestSetup::UUID_TWO_COLUMN_UUID,
        'fractal' => 'honeycomb',
      ],
    ];
    yield 'missing slot' => [
      'Missing parent slot',
      BadRequestHttpException::class,
      [
        'uuid' => CanvasTestSetup::UUID_STATIC_CARD2,
        'parentRegionOrUuid' => CanvasTestSetup::UUID_TWO_COLUMN_UUID,
        'fractal' => 1.5,
      ],
    ];
    yield 'No such component in model' => [
      'No such component in model: a-change-of-scenery',
      NotFoundHttpException::class,
      [
        'uuid' => 'a-change-of-scenery',
        'parentRegionOrUuid' => CanvasTestSetup::UUID_TWO_COLUMN_UUID,
        'fractal' => 1.5,
        'slot' => 'column_two',
      ],
    ];
    yield 'No such region' => [
      'No such region: shell-shock',
      NotFoundHttpException::class,
      [
        'uuid' => CanvasTestSetup::UUID_STATIC_CARD2,
        'parentRegionOrUuid' => 'shell-shock',
        'fractal' => 1.5,
        'slot' => NULL,
      ],
    ];
    yield 'Missing clientInstanceId' => [
      'Missing clientInstanceId',
      BadRequestHttpException::class,
      [
        'uuid' => CanvasTestSetup::UUID_STATIC_CARD2,
        'parentRegionOrUuid' => CanvasTestSetup::UUID_TWO_COLUMN_UUID,
        'fractal' => 1.5,
        'slot' => 'content',
      ],
    ];
    yield 'No such slot in component' => [
      \sprintf('No such slot doomsday-buttercup in parent component %s', CanvasTestSetup::UUID_TWO_COLUMN_UUID),
      NotFoundHttpException::class,
      [
        'uuid' => CanvasTestSetup::UUID_STATIC_CARD2,
        'parentRegionOrUuid' => CanvasTestSetup::UUID_TWO_COLUMN_UUID,
        'fractal' => 1.5,
        'slot' => 'doomsday-buttercup',
        'clientInstanceId' => 'loop-defenders',
      ],
    ];
  }

  #[DataProvider('providerValid')]
  public function testOperation(bool $withAutoSave = FALSE, bool $withGlobal = FALSE): void {
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
    // Move from column_one to column_two.
    $response = $this->request(Request::create(\sprintf('/canvas/api/v0/layout/%s/node/1', static::getOperationPathSlug()), method: 'PATCH', content: \json_encode([
      'uuid' => CanvasTestSetup::UUID_ALL_SLOTS_EMPTY,
      'parentRegionOrUuid' => CanvasTestSetup::UUID_TWO_COLUMN_UUID,
      'slot' => 'column_two',
      'fractal' => 1.5,
    ] + $this->getPatchContentsDefaults([$this->node]), JSON_THROW_ON_ERROR)));
    $data = \json_decode($response->getContent() ?: '', TRUE, \JSON_THROW_ON_ERROR);

    $content = $this->getRegion('content');
    self::assertNotNull($content);
    $clientModel = new ClientDataModel($data['layout'], $data['model'], $data['entity_form_fields']);
    $contentRegion = $clientModel->getRegion('content');
    self::assertEquals([
      CanvasTestSetup::UUID_STATIC_CARD2,
      CanvasTestSetup::UUID_ALL_SLOTS_EMPTY,
      CanvasTestSetup::UUID_STATIC_CARD3,
    ], \array_column($contentRegion['components'][0]['slots'][1]['components'], 'uuid'));

    // Move to the content region.
    $response = $this->request(Request::create(\sprintf('/canvas/api/v0/layout/%s/node/1', static::getOperationPathSlug()), method: 'PATCH', content: \json_encode([
      'uuid' => CanvasTestSetup::UUID_ALL_SLOTS_EMPTY,
      'parentRegionOrUuid' => 'content',
      'slot' => NULL,
      'fractal' => 1.5,
    ] + $this->getPatchContentsDefaults([$this->node]), JSON_THROW_ON_ERROR)));
    $data = \json_decode($response->getContent() ?: '', TRUE, \JSON_THROW_ON_ERROR);

    $content = $this->getRegion('content');
    self::assertNotNull($content);
    $clientModel = new ClientDataModel($data['layout'], $data['model'], $data['entity_form_fields']);
    $contentRegion = $clientModel->getRegion('content');
    self::assertEquals([
      CanvasTestSetup::UUID_STATIC_CARD2,
      CanvasTestSetup::UUID_STATIC_CARD3,
    ], \array_column($contentRegion['components'][0]['slots'][1]['components'], 'uuid'));
    self::assertEquals([
      CanvasTestSetup::UUID_TWO_COLUMN_UUID,
      CanvasTestSetup::UUID_ALL_SLOTS_EMPTY,
    ], \array_column($contentRegion['components'], 'uuid'));
    if (!$withGlobal) {
      return;
    }
    $highlighted = $this->getRegion('highlighted');
    self::assertNotNull($highlighted);
    // Move a component from a region.
    $region_component_uuids = \array_column($regions['stark.highlighted']->getComponentTree()->getValue(), 'uuid');
    \assert(\count($region_component_uuids) > 0);
    $region_component_uuid = \reset($region_component_uuids);
    $move_global_component_body = [
      'uuid' => $region_component_uuid,
      'parentRegionOrUuid' => CanvasTestSetup::UUID_ALL_SLOTS_EMPTY,
      'slot' => 'content',
      'fractal' => 0.5,
    ] + $this->getPatchContentsDefaults([$this->node]);
    $response = $this->request(Request::create(\sprintf('/canvas/api/v0/layout/%s/node/1', static::getOperationPathSlug()), method: 'PATCH', content: \json_encode($move_global_component_body, JSON_THROW_ON_ERROR)));
    $data = \json_decode($response->getContent() ?: '', TRUE, \JSON_THROW_ON_ERROR);

    $content = $this->getRegion('content');
    self::assertNotNull($content);
    $clientModel = new ClientDataModel($data['layout'], $data['model'], $data['entity_form_fields']);
    $contentRegion = $clientModel->getRegion('content');
    self::assertEquals([
      CanvasTestSetup::UUID_TWO_COLUMN_UUID,
      CanvasTestSetup::UUID_ALL_SLOTS_EMPTY,
    ], \array_column($contentRegion['components'], 'uuid'));
    self::assertEquals([
      $region_component_uuid,
    ], \array_column($contentRegion['components'][1]['slots'][0]['components'], 'uuid'));
    $highlighted = $this->getRegion('highlighted');
    self::assertNull($highlighted);

    // Move a component to a global region.
    $move_global_component_body = [
      'uuid' => $region_component_uuid,
      'parentRegionOrUuid' => 'highlighted',
      'slot' => NULL,
      'fractal' => 0.5,
    ] + $this->getPatchContentsDefaults([$this->node]);
    $response = $this->request(Request::create(\sprintf('/canvas/api/v0/layout/%s/node/1', static::getOperationPathSlug()), method: 'PATCH', content: \json_encode($move_global_component_body, JSON_THROW_ON_ERROR)));
    $data = \json_decode($response->getContent() ?: '', TRUE, \JSON_THROW_ON_ERROR);

    $content = $this->getRegion('content');
    self::assertNotNull($content);
    $clientModel = new ClientDataModel($data['layout'], $data['model'], $data['entity_form_fields']);
    $contentRegion = $clientModel->getRegion('content');
    self::assertEquals([], \array_column($contentRegion['components'][1]['slots'][0]['components'], 'uuid'));
    $highlighted = $this->getRegion('highlighted');
    self::assertNotNull($highlighted);
    $highlightedRegion = $clientModel->getRegion('highlighted');
    self::assertEquals([$region_component_uuid], \array_column($highlightedRegion['components'], 'uuid'));

    $this->assertRequestAutoSaveConflict(Request::create(\sprintf('/canvas/api/v0/layout/%s/node/1', static::getOperationPathSlug()), method: 'PATCH', content: \json_encode([
      'uuid' => CanvasTestSetup::UUID_ALL_SLOTS_EMPTY,
      'parentRegionOrUuid' => CanvasTestSetup::UUID_TWO_COLUMN_UUID,
      'slot' => 'column_two',
      'fractal' => 1.5,
      'clientInstanceId' => $move_global_component_body['clientInstanceId'] . '-new-client',
      // These are out of date now.
      'autoSaves' => $move_global_component_body['autoSaves'],
    ], JSON_THROW_ON_ERROR)));
  }

}
