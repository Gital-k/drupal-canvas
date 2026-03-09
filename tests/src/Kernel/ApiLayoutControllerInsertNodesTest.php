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
 * @covers \Drupal\canvas\ClientDataModel\Operation\InsertNodes
 * @covers \Drupal\canvas\Controller\ApiLayoutController
 */
final class ApiLayoutControllerInsertNodesTest extends ApiLayoutControllerMutateModelTestBase {

  private const string INSERTED_COMPONENT_UUID = 'afef79ad-4537-4a66-9af2-ed0e9b5fc5b9';

  private const array INSERTED_MODEL = [
    'resolved' => [
      'text' => 'glitter shot',
      'href' => 'https://drupal.org',
    ],
    'source' => [
      'text' => [
        'sourceType' => 'static:field_item:string',
        'expression' => 'ℹ︎string␟value',
      ],
      'href' => [
        'sourceType' => 'static:field_item:uri',
        'expression' => 'ℹ︎uri␟value',
      ],
    ],
  ];

  private const array INSERTED_COMPONENT = [
    'nodeType' => 'component',
    'uuid' => self::INSERTED_COMPONENT_UUID,
    'type' => 'sdc.canvas_test_sdc.my-cta@9454c3bca9bbbf4b',
    'slots' => [],
  ];

  protected static function getValidRequestBody(string $componentUuid): array {
    return [
      'parentRegionOrUuid' => $componentUuid,
      'slot' => 'column_two',
      // @see https://madebyevan.com/algos/crdt-fractional-indexing/
      'fractal' => 1.5,
      'layoutModel' => [
        'model' => [],
        'layout' => [],
      ],
    ];
  }

  protected static function getOperationPathSlug(): string {
    return 'insert-nodes';
  }

  public static function providerInvalid(): iterable {
    yield 'no parent UUID or region' => [
      'Missing parent UUID or region',
      BadRequestHttpException::class,
      [
        'nope' => 'nope, nope',
      ],
    ];
    yield 'no fractal' => [
      'Missing position (fractal)',
      BadRequestHttpException::class,
      [
        'parentRegionOrUuid' => CanvasTestSetup::UUID_TWO_COLUMN_UUID,
      ],
    ];
    yield 'invalid fractal' => [
      'Invalid position (fractal)',
      BadRequestHttpException::class,
      [
        'parentRegionOrUuid' => CanvasTestSetup::UUID_TWO_COLUMN_UUID,
        'fractal' => 'sneaker',
      ],
    ];
    yield 'missing slot' => [
      'Missing parent slot',
      BadRequestHttpException::class,
      [
        'parentRegionOrUuid' => CanvasTestSetup::UUID_TWO_COLUMN_UUID,
        'fractal' => 1.5,
      ],
    ];
    yield 'Missing layoutModel' => [
      'Missing layout model',
      BadRequestHttpException::class,
      [
        'parentRegionOrUuid' => CanvasTestSetup::UUID_TWO_COLUMN_UUID,
        'fractal' => 1.5,
        'slot' => 'content',
      ],
    ];
    yield 'Missing models' => [
      'Missing models',
      BadRequestHttpException::class,
      [
        'parentRegionOrUuid' => CanvasTestSetup::UUID_TWO_COLUMN_UUID,
        'fractal' => 1.5,
        'slot' => 'content',
        'layoutModel' => [
          'layout' => [self::INSERTED_COMPONENT],
        ],
      ],
    ];
    yield 'Missing components' => [
      'Missing components',
      BadRequestHttpException::class,
      [
        'parentRegionOrUuid' => CanvasTestSetup::UUID_TWO_COLUMN_UUID,
        'fractal' => 1.5,
        'slot' => 'content',
        'layoutModel' => [
          'model' => [
            self::INSERTED_COMPONENT_UUID => self::INSERTED_MODEL,
          ],
        ],
      ],
    ];
    yield 'Invalid components' => [
      'Invalid components',
      BadRequestHttpException::class,
      [
        'parentRegionOrUuid' => CanvasTestSetup::UUID_TWO_COLUMN_UUID,
        'fractal' => 1.5,
        'slot' => 'content',
        'layoutModel' => [
          'model' => [
            self::INSERTED_COMPONENT_UUID => self::INSERTED_MODEL,
          ],
          'layout' => [
            'this' => 'is not a list',
          ],
        ],
      ],
    ];
    yield 'No such region' => [
      'No such region: abomination',
      NotFoundHttpException::class,
      [
        'parentRegionOrUuid' => 'abomination',
        'fractal' => 1.5,
        'slot' => NULL,
        'layoutModel' => [
          'model' => [
            self::INSERTED_COMPONENT_UUID => self::INSERTED_MODEL,
          ],
          'layout' => [
            self::INSERTED_COMPONENT,
          ],
        ],
      ],
    ];
    yield 'No such parent component' => [
      'No such parent component in model: expectation',
      NotFoundHttpException::class,
      [
        'parentRegionOrUuid' => 'expectation',
        'fractal' => 1.5,
        'slot' => 'content',
        'layoutModel' => [
          'model' => [
            self::INSERTED_COMPONENT_UUID => self::INSERTED_MODEL,
          ],
          'layout' => [
            self::INSERTED_COMPONENT,
          ],
        ],
      ],
    ];
    yield 'Missing clientInstanceId' => [
      'Missing clientInstanceId',
      BadRequestHttpException::class,
      [
        'parentRegionOrUuid' => CanvasTestSetup::UUID_TWO_COLUMN_UUID,
        'fractal' => 1.5,
        'slot' => 'column_one',
        'layoutModel' => [
          'model' => [
            self::INSERTED_COMPONENT_UUID => self::INSERTED_MODEL,
          ],
          'layout' => [
            self::INSERTED_COMPONENT,
          ],
        ],
      ],
    ];
    yield 'No such slot in component' => [
      \sprintf('Unknown slot: fight-simulator for component %s', CanvasTestSetup::UUID_TWO_COLUMN_UUID),
      BadRequestHttpException::class,
      [
        'uuid' => CanvasTestSetup::UUID_STATIC_CARD2,
        'parentRegionOrUuid' => CanvasTestSetup::UUID_TWO_COLUMN_UUID,
        'fractal' => 1.5,
        'slot' => 'fight-simulator',
        'clientInstanceId' => 'infected-chrome',
        'layoutModel' => [
          'model' => [
            self::INSERTED_COMPONENT_UUID => self::INSERTED_MODEL,
          ],
          'layout' => [
            self::INSERTED_COMPONENT,
          ],
        ],
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
    // Insert into column one.
    $response = $this->request(Request::create(\sprintf('/canvas/api/v0/layout/%s/node/1', static::getOperationPathSlug()), method: 'PATCH', content: \json_encode([
      'parentRegionOrUuid' => CanvasTestSetup::UUID_TWO_COLUMN_UUID,
      'fractal' => 1.5,
      'slot' => 'column_two',
      'layoutModel' => [
        'model' => [
          self::INSERTED_COMPONENT_UUID => self::INSERTED_MODEL,
        ],
        'layout' => [
          self::INSERTED_COMPONENT,
        ],
      ],
    ] + $this->getPatchContentsDefaults([$this->node]), JSON_THROW_ON_ERROR)));
    $data = \json_decode($response->getContent() ?: '', TRUE, \JSON_THROW_ON_ERROR);

    $content = $this->getRegion('content');
    self::assertNotNull($content);
    $clientModel = new ClientDataModel($data['layout'], $data['model'], $data['entity_form_fields']);
    $contentRegion = $clientModel->getRegion('content');
    self::assertEquals([
      CanvasTestSetup::UUID_STATIC_CARD2,
      self::INSERTED_COMPONENT_UUID,
      CanvasTestSetup::UUID_STATIC_CARD3,
    ], \array_column($contentRegion['components'][0]['slots'][1]['components'], 'uuid'));
    self::assertEquals(self::INSERTED_MODEL, $data['model'][self::INSERTED_COMPONENT_UUID]);

    if (!$withGlobal) {
      return;
    }
    $highlighted = $this->getRegion('highlighted');
    self::assertNotNull($highlighted);

    $uuid = '80a615e8-20a5-4661-8d96-4b83115a612c';
    $component = [
      'nodeType' => 'component',
      'uuid' => $uuid,
      'type' => 'sdc.canvas_test_sdc.my-cta@9454c3bca9bbbf4b',
      'slots' => [],
    ];

    $model = [
      'resolved' => [
        'text' => 'coffin wax',
        'href' => 'https://drupal.org',
      ],
      'source' => [
        'text' => [
          'sourceType' => 'static:field_item:string',
          'expression' => 'ℹ︎string␟value',
        ],
        'href' => [
          'sourceType' => 'static:field_item:uri',
          'expression' => 'ℹ︎uri␟value',
        ],
      ],
    ];

    // Insert component into global region.
    $region_component_uuids = \array_column($regions['stark.highlighted']->getComponentTree()->getValue(), 'uuid');
    \assert(\count($region_component_uuids) > 0);
    $insert_global_component = [
      'parentRegionOrUuid' => 'highlighted',
      'fractal' => 0.5,
      'slot' => NULL,
      'layoutModel' => [
        'model' => [
          $uuid => $model,
        ],
        'layout' => [
          $component,
        ],
      ],
    ] + $this->getPatchContentsDefaults([$this->node]);
    $response = $this->request(Request::create(\sprintf('/canvas/api/v0/layout/%s/node/1', static::getOperationPathSlug()), method: 'PATCH', content: \json_encode($insert_global_component, JSON_THROW_ON_ERROR)));
    $data = \json_decode($response->getContent() ?: '', TRUE, \JSON_THROW_ON_ERROR);

    $highlighted = $this->getRegion('highlighted');
    self::assertNotNull($highlighted);
    $clientModel = new ClientDataModel($data['layout'], $data['model'], $data['entity_form_fields']);
    $highlightedRegion = $clientModel->getRegion('highlighted');
    self::assertEquals([
      $uuid,
      ...$region_component_uuids,
    ], \array_column($highlightedRegion['components'], 'uuid'));
    self::assertEquals($model, $data['model'][$uuid]);

    $this->assertRequestAutoSaveConflict(Request::create(\sprintf('/canvas/api/v0/layout/%s/node/1', static::getOperationPathSlug()), method: 'PATCH', content: \json_encode([
      'parentRegionOrUuid' => 'highlighted',
      'fractal' => 1.5,
      'slot' => NULL,
      'layoutModel' => [
        'model' => [
          $uuid => $model,
        ],
        'layout' => [
          $component,
        ],
      ],
      'clientInstanceId' => $insert_global_component['clientInstanceId'] . '-new-client',
      // These are out of date now.
      'autoSaves' => $insert_global_component['autoSaves'],
    ], JSON_THROW_ON_ERROR)));
  }

}
