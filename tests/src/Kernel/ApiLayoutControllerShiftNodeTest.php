<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\ClientDataModel\ClientDataModel;
use Drupal\canvas\ClientDataModel\ShiftComponentDirection;
use Drupal\canvas\Entity\PageRegion;
use Drupal\Tests\canvas\TestSite\CanvasTestSetup;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @group canvas
 * @group ApiLayoutController
 * @covers \Drupal\canvas\ClientDataModel\Operation\ShiftNode
 * @covers \Drupal\canvas\Controller\ApiLayoutController
 */
final class ApiLayoutControllerShiftNodeTest extends ApiLayoutControllerMutateModelTestBase {

  protected static function getValidRequestBody(string $componentUuid): array {
    return [
      'uuid' => $componentUuid,
      'direction' => ShiftComponentDirection::Down->value,
    ];
  }

  protected static function getOperationPathSlug(): string {
    return 'shift-node';
  }

  public static function providerInvalid(): iterable {
    yield 'no component UUID' => [
      'Missing uuid',
      BadRequestHttpException::class,
      [
        'pay the rent' => 'no uuid',
      ],
    ];
    yield 'no direction' => [
      'Missing direction',
      BadRequestHttpException::class,
      [
        'uuid' => CanvasTestSetup::UUID_STATIC_CARD2,
      ],
    ];
    yield 'invalid direction' => [
      'Invalid direction',
      BadRequestHttpException::class,
      [
        'uuid' => CanvasTestSetup::UUID_STATIC_CARD2,
        'direction' => 'dancing-in-transit',
      ],
    ];
    yield 'No such component in model' => [
      'No such component in model: one foot',
      NotFoundHttpException::class,
      [
        'uuid' => 'one foot',
        'direction' => ShiftComponentDirection::Down->value,
      ],
    ];
    yield 'Missing clientInstanceId' => [
      'Missing clientInstanceId',
      BadRequestHttpException::class,
      [
        'uuid' => CanvasTestSetup::UUID_STATIC_CARD2,
        'direction' => ShiftComponentDirection::Down->value,
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
    if ($withAutoSave) {
      $this->primeAutoSave($response, $withGlobal, $regions);
    }
    // Move up.
    $response = $this->request(Request::create(\sprintf('/canvas/api/v0/layout/%s/node/1', static::getOperationPathSlug()), method: 'PATCH', content: \json_encode([
      'uuid' => CanvasTestSetup::UUID_ALL_SLOTS_EMPTY,
      'direction' => ShiftComponentDirection::Up->value,
    ] + $this->getPatchContentsDefaults([$this->node]), JSON_THROW_ON_ERROR)));
    $data = \json_decode($response->getContent() ?: '', TRUE, \JSON_THROW_ON_ERROR);

    $content = $this->getRegion('content');
    self::assertNotNull($content);
    $clientModel = new ClientDataModel($data['layout'], $data['model'], $data['entity_form_fields']);
    $contentRegion = $clientModel->getRegion('content');
    self::assertEquals([
      CanvasTestSetup::UUID_STATIC_IMAGE,
      CanvasTestSetup::UUID_ALL_SLOTS_EMPTY,
      CanvasTestSetup::UUID_STATIC_CARD1,
    ], \array_column($contentRegion['components'][0]['slots'][0]['components'], 'uuid'));

    // Move down.
    $response = $this->request(Request::create(\sprintf('/canvas/api/v0/layout/%s/node/1', static::getOperationPathSlug()), method: 'PATCH', content: \json_encode([
      'uuid' => CanvasTestSetup::UUID_ALL_SLOTS_EMPTY,
      'direction' => ShiftComponentDirection::Down->value,
    ] + $this->getPatchContentsDefaults([$this->node]), JSON_THROW_ON_ERROR)));
    $data = \json_decode($response->getContent() ?: '', TRUE, \JSON_THROW_ON_ERROR);

    $content = $this->getRegion('content');
    self::assertNotNull($content);
    $clientModel = new ClientDataModel($data['layout'], $data['model'], $data['entity_form_fields']);
    $contentRegion = $clientModel->getRegion('content');
    self::assertEquals([
      CanvasTestSetup::UUID_STATIC_IMAGE,
      CanvasTestSetup::UUID_STATIC_CARD1,
      CanvasTestSetup::UUID_ALL_SLOTS_EMPTY,
    ], \array_column($contentRegion['components'][0]['slots'][0]['components'], 'uuid'));
    if (!$withGlobal) {
      return;
    }
    $header = $this->getRegion('header');
    self::assertNotNull($header);
    // Move a component from a region.
    $region_component_uuids = \array_column($regions['stark.header']->getComponentTree()->getValue(), 'uuid');
    \assert(\count($region_component_uuids) > 0);
    $region_component_uuid = \array_shift($region_component_uuids);
    $shift_global_component_body = [
      'uuid' => $region_component_uuid,
      'direction' => ShiftComponentDirection::Down->value,
    ] + $this->getPatchContentsDefaults([$this->node]);
    $response = $this->request(Request::create(\sprintf('/canvas/api/v0/layout/%s/node/1', static::getOperationPathSlug()), method: 'PATCH', content: \json_encode($shift_global_component_body, JSON_THROW_ON_ERROR)));
    $data = \json_decode($response->getContent() ?: '', TRUE, \JSON_THROW_ON_ERROR);

    $content = $this->getRegion('header');
    self::assertNotNull($content);
    $clientModel = new ClientDataModel($data['layout'], $data['model'], $data['entity_form_fields']);
    $headerRegion = $clientModel->getRegion('header');
    \array_push($region_component_uuids, $region_component_uuid);
    self::assertEquals($region_component_uuids, \array_column($headerRegion['components'], 'uuid'));

    $this->assertRequestAutoSaveConflict(Request::create(\sprintf('/canvas/api/v0/layout/%s/node/1', static::getOperationPathSlug()), method: 'PATCH', content: \json_encode([
      'uuid' => $region_component_uuid,
      'direction' => ShiftComponentDirection::Up->value,
      'clientInstanceId' => $shift_global_component_body['clientInstanceId'] . '-new-client',
      // These are out of date now.
      'autoSaves' => $shift_global_component_body['autoSaves'],
    ], JSON_THROW_ON_ERROR)));
  }

}
