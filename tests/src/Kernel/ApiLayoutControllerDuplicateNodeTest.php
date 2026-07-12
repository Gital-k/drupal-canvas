<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\ClientDataModel\ClientDataModel;
use Drupal\canvas\ClientDataModel\Operation\DuplicateNode;
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
#[CoversClass(DuplicateNode::class)]
#[CoversClass(ApiLayoutController::class)]
#[Group('canvas')]
#[Group('ApiLayoutController')]
final class ApiLayoutControllerDuplicateNodeTest extends ApiLayoutControllerMutateModelTestBase {

  protected static function getValidRequestBody(string $componentUuid): array {
    return [
      'uuid' => $componentUuid,
      'uuidMap' => [
        CanvasTestSetup::UUID_STATIC_CARD1 => 'df611fb2-6f28-4a5e-a8f0-f4cc937ba408',
      ],
    ];
  }

  protected static function getOperationPathSlug(): string {
    return 'duplicate-node';
  }

  public static function providerInvalid(): iterable {
    yield 'no component UUID' => [
      'Missing UUID',
      BadRequestHttpException::class,
      [
        'look ma' => 'no uuid',
      ],
    ];
    yield 'no map' => [
      'Missing UUID map',
      BadRequestHttpException::class,
      [
        'uuid' => CanvasTestSetup::UUID_STATIC_CARD1,
      ],
    ];
    yield 'invalid map' => [
      'Invalid UUID map',
      BadRequestHttpException::class,
      [
        'uuid' => CanvasTestSetup::UUID_STATIC_CARD1,
        'uuidMap' => 'switch over',
      ],
    ];
    yield 'invalid map values' => [
      'Invalid UUID map',
      BadRequestHttpException::class,
      [
        'uuid' => CanvasTestSetup::UUID_STATIC_CARD1,
        'uuidMap' => [
          CanvasTestSetup::UUID_STATIC_CARD1 => ['information consent'],
        ],
      ],
    ];
    yield 'missing map values' => [
      'Missing UUID map entries for ' . CanvasTestSetup::UUID_STATIC_CARD1,
      BadRequestHttpException::class,
      [
        'uuid' => CanvasTestSetup::UUID_STATIC_CARD1,
        'clientInstanceId' => 'sport-meets-sound',
        'uuidMap' => [
          // Missing entry for UUID_STATIC_CARD1.
          'in twos' => 'rock city',
        ],
      ],
    ];
    yield 'No such component in model' => [
      'No such component in model: a distant life',
      NotFoundHttpException::class,
      [
        'clientInstanceId' => 'love-of-country',
        'uuid' => 'a distant life',
        'uuidMap' => [
          CanvasTestSetup::UUID_STATIC_CARD1 => 'words lost meaning',
        ],
      ],
    ];
    yield 'Missing clientInstanceId' => [
      'Missing clientInstanceId',
      BadRequestHttpException::class,
      [
        'uuid' => CanvasTestSetup::UUID_STATIC_CARD2,
        'uuidMap' => [
          CanvasTestSetup::UUID_STATIC_CARD1 => 'words lost meaning',
        ],
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
    if ($withAutoSave) {
      $this->primeAutoSave($response, $withGlobal, $regions);
    }

    $response = $this->request(Request::create(\sprintf('/canvas/api/v0/layout/%s/node/1', static::getOperationPathSlug()), method: 'PATCH', content: \json_encode([
      'uuid' => CanvasTestSetup::UUID_STATIC_CARD1,
      'uuidMap' => [
        CanvasTestSetup::UUID_STATIC_CARD1 => 'dc65da5b-4ec1-40d9-8b05-8542daf9bc55',
      ],
    ] + $this->getPatchContentsDefaults([$this->node]), JSON_THROW_ON_ERROR)));
    $data = \json_decode((string) $response->getContent(), TRUE, flags: \JSON_THROW_ON_ERROR);

    $content = $this->getRegion('content');
    self::assertNotNull($content);
    $clientModel = new ClientDataModel($data['layout'], $data['model'], $data['entity_form_fields']);
    $contentRegion = $clientModel->getRegion('content');
    self::assertEquals($data['model'][CanvasTestSetup::UUID_STATIC_CARD1], $data['model']['dc65da5b-4ec1-40d9-8b05-8542daf9bc55']);
    self::assertEquals([
      CanvasTestSetup::UUID_STATIC_IMAGE,
      CanvasTestSetup::UUID_STATIC_CARD1,
      'dc65da5b-4ec1-40d9-8b05-8542daf9bc55',
      CanvasTestSetup::UUID_CODE_COMPONENT,
      CanvasTestSetup::UUID_ALL_SLOTS_EMPTY,
    ], \array_column($contentRegion['components'][0]['slots'][0]['components'], 'uuid'));

    if (!$withGlobal) {
      return;
    }
    $highlighted = $this->getRegion('highlighted');
    self::assertNotNull($highlighted);
    // Duplicate a component from a region.
    $region_component_uuids = \array_column($regions['stark.highlighted']->getComponentTree()->getValue(), 'uuid');
    \assert(\count($region_component_uuids) > 0);
    $region_component_uuid = \end($region_component_uuids);
    $duplicate_global_component_body = [
      'uuid' => $region_component_uuid,
      'uuidMap' => [
        $region_component_uuid => 'b19095cb-a258-479f-8353-c79de3220580',
      ],
    ] + $this->getPatchContentsDefaults([$this->node]);
    $response = $this->request(Request::create(\sprintf('/canvas/api/v0/layout/%s/node/1', static::getOperationPathSlug()), method: 'PATCH', content: \json_encode($duplicate_global_component_body, JSON_THROW_ON_ERROR)));
    $data = \json_decode((string) $response->getContent(), TRUE, flags: \JSON_THROW_ON_ERROR);

    $content = $this->getRegion('highlighted');
    self::assertNotNull($content);
    $clientModel = new ClientDataModel($data['layout'], $data['model'], $data['entity_form_fields']);
    $headerRegion = $clientModel->getRegion('highlighted');
    \array_push($region_component_uuids, 'b19095cb-a258-479f-8353-c79de3220580');
    self::assertEquals($region_component_uuids, \array_column($headerRegion['components'], 'uuid'));
    self::assertEquals($data['model'][$region_component_uuid], $data['model']['b19095cb-a258-479f-8353-c79de3220580']);

    $this->assertRequestAutoSaveConflict(Request::create(\sprintf('/canvas/api/v0/layout/%s/node/1', static::getOperationPathSlug()), method: 'PATCH', content: \json_encode([
      'uuid' => $region_component_uuid,
      'uuidMap' => [
        $region_component_uuid => 'd4cc80e6-a649-4f69-a1ff-617e4a8dd099',
      ],
      'clientInstanceId' => $duplicate_global_component_body['clientInstanceId'] . '-new-client',
      // These are out of date now.
      'autoSaves' => $duplicate_global_component_body['autoSaves'],
    ], JSON_THROW_ON_ERROR)));
  }

}
