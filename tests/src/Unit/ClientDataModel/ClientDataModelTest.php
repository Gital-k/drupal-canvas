<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Unit\ClientDataModel;

use Drupal\canvas\ClientDataModel\ClientDataModel;
use Drupal\canvas\ClientDataModel\ShiftComponentDirection;
use Drupal\Component\Utility\Random;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(ClientDataModel::class)]
#[Group('canvas')]
final class ClientDataModelTest extends UnitTestCase {

  private const string NO_EXPLICIT_INPUT = 'a7db19b0-b11e-4b45-b888-e78a94da8ce3';

  protected ClientDataModel $clientDataModel;

  protected function setUp(): void {
    parent::setUp();
    $json = \file_get_contents(\sprintf('%s/ui/tests/fixtures/layout-default.json', dirname(__DIR__, 4)));
    \assert(\is_string($json));
    $data = \json_decode($json, TRUE, flags: \JSON_THROW_ON_ERROR);
    \assert(\array_key_exists('layout', $data));
    \assert(\array_key_exists('model', $data));
    \assert(\array_key_exists('entity_form_fields', $data));
    // Add a global highlighted region.
    $data['layout'][] = [
      'nodeType' => 'region',
      'id' => 'highlighted',
      'name' => 'Highlighted',
      'components' => [
        [
          // Note this component has no explicit input and hence is absent from
          // the model.
          'uuid' => self::NO_EXPLICIT_INPUT,
          'type' => 'block.system_breadcrumb_block@cec23aa503e2a02f',
          'nodeType' => 'component',
          'slots' => [],
        ],
      ],
    ];
    $this->clientDataModel = new ClientDataModel(
      $data['layout'],
      $data['model'],
      $data['entity_form_fields'],
    );
  }

  /**
   * @legacy-covers ::withModelForComponent
   */
  public function testWithModelForComponent(): void {
    $original_values = [
      'name' => 'Hero',
      'resolved' => [
        'heading' => 'There goes my hero',
        'subheading' => 'Watch him as he goes!',
        'cta1' => 'View',
        'cta1href' => 'https://example.com',
        'cta2' => 'Click',
      ],
      'source' => [
        'heading' => [
          'sourceType' => 'static:field_item:string',
          'expression' => 'ℹ︎string␟value',
        ],
        'subheading' => [
          'sourceType' => 'static:field_item:string',
          'expression' => 'ℹ︎string␟value',
        ],
        'cta1' => [
          'sourceType' => 'static:field_item:string',
          'expression' => 'ℹ︎string␟value',
        ],
        'cta1href' => [
          'sourceType' => 'static:field_item:uri',
          'expression' => 'ℹ︎uri␟value',
        ],
        'cta2' => [
          'sourceType' => 'static:field_item:string',
          'expression' => 'ℹ︎string␟value',
        ],
      ],
    ];
    self::assertEquals($original_values, ((array) $this->clientDataModel->model)['eaa37ee1-7d50-4041-b04c-c80bdbac3412']);
    $new_values = $original_values;
    $new_values['resolved']['cta1'] = 'Read more';
    $new_model = $this->clientDataModel->withModelForComponent('eaa37ee1-7d50-4041-b04c-c80bdbac3412', $new_values);
    self::assertEquals($new_values, ((array) $new_model->model)['eaa37ee1-7d50-4041-b04c-c80bdbac3412']);
    $initialize_input = ['hey' => 'there'];
    $new = $this->clientDataModel->withModelForComponent(self::NO_EXPLICIT_INPUT, $initialize_input);
    self::assertEquals($initialize_input, ((array) $new->model)[self::NO_EXPLICIT_INPUT]);
  }

  /**
   * @legacy-covers ::withClientInstanceId
   */
  public function testWithClientInstanceId(): void {
    self::assertNull($this->clientDataModel->clientInstanceId);
    $instance_id = (new Random())->string();
    self::assertEquals($instance_id, $this->clientDataModel->withClientInstanceId($instance_id)->clientInstanceId);
  }

  /**
   * @legacy-covers ::hasComponentInstance
   */
  public function testHasComponentInstance(): void {
    self::assertFalse($this->clientDataModel->hasComponentInstance('532b795f-4f0c-4b1a-bb74-871835096941'));
    self::assertTrue($this->clientDataModel->hasComponentInstance('eaa37ee1-7d50-4041-b04c-c80bdbac3412'));
    self::assertTrue($this->clientDataModel->hasComponentInstance(self::NO_EXPLICIT_INPUT));
  }

  /**
   * @legacy-covers ::getRegionForComponentInstance
   * @legacy-covers ::componentInstanceExistInComponentData
   */
  public function testGetRegionForComponentInstance(): void {
    self::assertEquals('content', $this->clientDataModel->getRegionForComponentInstance('eaa37ee1-7d50-4041-b04c-c80bdbac3412'));
    self::assertEquals('highlighted', $this->clientDataModel->getRegionForComponentInstance(self::NO_EXPLICIT_INPUT));
    self::assertNull($this->clientDataModel->getRegionForComponentInstance('532b795f-4f0c-4b1a-bb74-871835096941'));
  }

  /**
   * @legacy-covers ::withoutComponent
   * @legacy-covers ::getComponentByUuid
   * @legacy-covers ::removeComponentByUuid
   * @legacy-covers ::getChildrenFromNode
   * @legacy-covers ::setChildrenForNode
   * @legacy-covers ::nodeIsComponent
   * @legacy-covers ::collectChildComponentInstanceIds
   */
  public function testWithoutComponent(): void {
    // Test item with no children.
    self::assertTrue($this->clientDataModel->hasComponentInstance('eaa37ee1-7d50-4041-b04c-c80bdbac3412'));
    $new = $this->clientDataModel->withoutComponent('eaa37ee1-7d50-4041-b04c-c80bdbac3412');
    self::assertFalse($new->hasComponentInstance('eaa37ee1-7d50-4041-b04c-c80bdbac3412'));
    self::assertNull($new->getRegionForComponentInstance('eaa37ee1-7d50-4041-b04c-c80bdbac3412'));

    // Test item with children.
    self::assertTrue($this->clientDataModel->hasComponentInstance('a7470350-deb2-4d9f-982c-464d356403d4'));
    self::assertTrue($this->clientDataModel->hasComponentInstance('static-static-card1ab'));
    self::assertTrue($this->clientDataModel->hasComponentInstance('static-image-udf7d'));
    $new = $this->clientDataModel->withoutComponent('a7470350-deb2-4d9f-982c-464d356403d4');
    self::assertFalse($new->hasComponentInstance('a7470350-deb2-4d9f-982c-464d356403d4'));
    self::assertFalse($new->hasComponentInstance('static-static-card1ab'));
    self::assertFalse($new->hasComponentInstance('static-image-udf7d'));
    self::assertNull($new->getRegionForComponentInstance('a7470350-deb2-4d9f-982c-464d356403d4'));
    self::assertNull($new->getRegionForComponentInstance('static-static-card1ab'));
    self::assertNull($new->getRegionForComponentInstance('static-image-udf7d'));

    // Test no such component.
    $new = $this->clientDataModel->withoutComponent('532b795f-4f0c-4b1a-bb74-871835096941');
    self::assertEquals($new->model, $this->clientDataModel->model);
    self::assertEquals($new->layout, $this->clientDataModel->layout);

    // Test with no explicit input.
    $new = $this->clientDataModel->withoutComponent(self::NO_EXPLICIT_INPUT);
    self::assertEquals($new->model, $this->clientDataModel->model);
    self::assertEquals($new->layout[0], $this->clientDataModel->layout[0]);
  }

  /**
   * @legacy-covers ::withMovedComponent
   * @legacy-covers ::insertNodeAtFractal
   * @legacy-covers ::insertNodeInSlotAtFractal
   * @legacy-covers ::replaceComponent
   * @legacy-covers ::__construct
   */
  public function testWithMovedComponent(): void {
    // Move to between two existing items.
    $new = $this->clientDataModel->withMovedComponent('static-static-card3rr', 'ee07d472-a754-4427-b6d4-acfc6f92bbdc', 'column_two', 1.5);
    self::assertTrue($new->hasComponentInstance('static-static-card3rr'));
    self::assertEquals('content', $new->getRegionForComponentInstance('static-static-card3rr'));
    self::assertEquals([
      '3b709ed2-99d3-4db2-869d-ca426f69fbb9',
      'static-static-card3rr',
      'eaa37ee1-7d50-4041-b04c-c80bdbac3412',
    ], \array_column($new->layout[0]['components'][3]['slots'][1]['components'], 'uuid'));

    // Move to the first item in a slot.
    $new = $this->clientDataModel->withMovedComponent('static-static-card3rr', 'ee07d472-a754-4427-b6d4-acfc6f92bbdc', 'column_two', 0.5);
    self::assertTrue($new->hasComponentInstance('static-static-card3rr'));
    self::assertEquals('content', $new->getRegionForComponentInstance('static-static-card3rr'));
    self::assertEquals([
      'static-static-card3rr',
      '3b709ed2-99d3-4db2-869d-ca426f69fbb9',
      'eaa37ee1-7d50-4041-b04c-c80bdbac3412',
    ], \array_column($new->layout[0]['components'][3]['slots'][1]['components'], 'uuid'));

    // Move to the last item in a slot.
    $new = $this->clientDataModel->withMovedComponent('static-static-card3rr', 'ee07d472-a754-4427-b6d4-acfc6f92bbdc', 'column_two', 3.5);
    self::assertTrue($new->hasComponentInstance('static-static-card3rr'));
    self::assertEquals('content', $new->getRegionForComponentInstance('static-static-card3rr'));
    self::assertEquals([
      '3b709ed2-99d3-4db2-869d-ca426f69fbb9',
      'eaa37ee1-7d50-4041-b04c-c80bdbac3412',
      'static-static-card3rr',
    ], \array_column($new->layout[0]['components'][3]['slots'][1]['components'], 'uuid'));

    // Move to then end of the region.
    $new = $this->clientDataModel->withMovedComponent('static-static-card3rr', 'content', NULL, 5.5);
    self::assertTrue($new->hasComponentInstance('static-static-card3rr'));
    self::assertEquals('content', $new->getRegionForComponentInstance('static-static-card3rr'));
    self::assertEquals([
      'a7470350-deb2-4d9f-982c-464d356403d4',
      'static-static-card2df',
      'static-image-static-imageStyle-something7d',
      'ee07d472-a754-4427-b6d4-acfc6f92bbdc',
      'static-static-card3rr',
    ], \array_column($new->layout[0]['components'], 'uuid'));

    // Move to the start of the region.
    $new = $this->clientDataModel->withMovedComponent('static-static-card3rr', 'content', NULL, 0.5);
    self::assertTrue($new->hasComponentInstance('static-static-card3rr'));
    self::assertEquals('content', $new->getRegionForComponentInstance('static-static-card3rr'));
    self::assertEquals([
      'static-static-card3rr',
      'a7470350-deb2-4d9f-982c-464d356403d4',
      'static-static-card2df',
      'static-image-static-imageStyle-something7d',
      'ee07d472-a754-4427-b6d4-acfc6f92bbdc',
    ], \array_column($new->layout[0]['components'], 'uuid'));

    // Move to a different region.
    $new = $this->clientDataModel->withMovedComponent('static-static-card3rr', 'highlighted', NULL, 0.5);
    self::assertTrue($new->hasComponentInstance('static-static-card3rr'));
    self::assertEquals('highlighted', $new->getRegionForComponentInstance('static-static-card3rr'));
    self::assertEquals([
      'a7470350-deb2-4d9f-982c-464d356403d4',
      'static-static-card2df',
      'static-image-static-imageStyle-something7d',
      'ee07d472-a754-4427-b6d4-acfc6f92bbdc',
    ], \array_column($new->layout[0]['components'], 'uuid'));
    self::assertEquals([
      'static-static-card3rr',
      self::NO_EXPLICIT_INPUT,
    ], \array_column($new->layout[1]['components'], 'uuid'));

    // No such region.
    $new = $this->clientDataModel->withMovedComponent('static-static-card3rr', 'trousers', NULL, 0.5);
    self::assertSame($this->clientDataModel->layout, $new->layout);

    // No such component.
    $new = $this->clientDataModel->withMovedComponent('serene-demon', 'content', NULL, 0.5);
    self::assertSame($this->clientDataModel->layout, $new->layout);

    // No such parent.
    $new = $this->clientDataModel->withMovedComponent('static-static-card3rr', 'this-component-does-not-exist', 'column_two', 0.5);
    self::assertSame($this->clientDataModel->layout, $new->layout);

    // Cannot move to child of self.
    $new = $this->clientDataModel->withMovedComponent('ee07d472-a754-4427-b6d4-acfc6f92bbdc', 'ee07d472-a754-4427-b6d4-acfc6f92bbdc', 'column_one', 0.5);
    self::assertSame($this->clientDataModel->layout, $new->layout);

    // With no explicit input.
    $new = $this->clientDataModel->withMovedComponent(self::NO_EXPLICIT_INPUT, 'content', NULL, 0.5);
    self::assertSame($this->clientDataModel->model, $new->model);

    $this->expectException(\OutOfBoundsException::class);
    // No such slot.
    $this->clientDataModel->withMovedComponent('static-static-card3rr', 'ee07d472-a754-4427-b6d4-acfc6f92bbdc', 'no-such-slot', 0.5);
  }

  /**
   * @legacy-covers ::getRegion
   */
  public function testGetRegion(): void {
    self::assertEquals($this->clientDataModel->layout[0], $this->clientDataModel->getRegion('content'));
    $this->expectException(\OutOfBoundsException::class);
    $this->clientDataModel->getRegion('afterlife');
  }

  /**
   * @legacy-covers ::withShiftedComponent
   * @legacy-covers ::shiftChild
   * @legacy-covers ::nodeIsSlot
   * @legacy-covers ::nodeIsRegion
   * @legacy-covers ::getParentNodeByComponentId
   * @legacy-covers ::replaceSlot
   * @legacy-covers \Drupal\canvas\ClientDataModel\ShiftComponentDirection::apply
   */
  public function testWithShiftedComponent(): void {
    self::assertEquals($this->clientDataModel, $this->clientDataModel->withShiftedComponent('no such component', ShiftComponentDirection::Up));
    // Move up at root.
    $new = $this->clientDataModel->withShiftedComponent('static-static-card3rr', ShiftComponentDirection::Up);
    self::assertEquals([
      'a7470350-deb2-4d9f-982c-464d356403d4',
      'static-static-card3rr',
      'static-static-card2df',
      'static-image-static-imageStyle-something7d',
      'ee07d472-a754-4427-b6d4-acfc6f92bbdc',
    ], \array_column($new->layout[0]['components'], 'uuid'));

    // Move down at root.
    $new = $this->clientDataModel->withShiftedComponent('static-static-card3rr', ShiftComponentDirection::Down);
    self::assertEquals([
      'a7470350-deb2-4d9f-982c-464d356403d4',
      'static-static-card2df',
      'static-image-static-imageStyle-something7d',
      'static-static-card3rr',
      'ee07d472-a754-4427-b6d4-acfc6f92bbdc',
    ], \array_column($new->layout[0]['components'], 'uuid'));

    // Move up nested.
    $new = $this->clientDataModel->withShiftedComponent('static-static-card1ab', ShiftComponentDirection::Down);
    self::assertEquals([
      'static-image-udf7d',
      'static-static-card1ab',
    ], \array_column($new->layout[0]['components'][0]['slots'][0]['components'], 'uuid'));

    // Move down nested.
    $new = $this->clientDataModel->withShiftedComponent('static-image-udf7d', ShiftComponentDirection::Up);
    self::assertEquals([
      'static-image-udf7d',
      'static-static-card1ab',
    ], \array_column($new->layout[0]['components'][0]['slots'][0]['components'], 'uuid'));

    // Already at bottom.
    $new = $this->clientDataModel->withShiftedComponent('static-image-udf7d', ShiftComponentDirection::Down);
    self::assertEquals([
      'static-static-card1ab',
      'static-image-udf7d',
    ], \array_column($new->layout[0]['components'][0]['slots'][0]['components'], 'uuid'));

    // Already at top.
    $new = $this->clientDataModel->withShiftedComponent('static-static-card1ab', ShiftComponentDirection::Up);
    self::assertEquals([
      'static-static-card1ab',
      'static-image-udf7d',
    ], \array_column($new->layout[0]['components'][0]['slots'][0]['components'], 'uuid'));
  }

  /**
   * @legacy-covers ::withDuplicatedComponentTree
   * @legacy-covers ::insertAfter
   * @legacy-covers ::duplicate
   * @legacy-covers ::collectChildComponentInstanceIds
   * @legacy-covers ::getParentNodeByComponentId
   * @legacy-covers ::getComponentByUuid
   * @legacy-covers ::nodeIsRegion
   * @legacy-covers ::nodeIsSlot
   * @legacy-covers ::replaceSlot
   */
  public function testWithDuplicatedComponentTree(): void {
    self::assertEquals($this->clientDataModel, $this->clientDataModel->withDuplicatedComponentTree('no such component', []));

    // Single duplication (no children).
    $new = $this->clientDataModel->withDuplicatedComponentTree('static-static-card1ab', [
      'static-static-card1ab' => 'c850e3e9-3143-4cb4-b32e-6fb56f9c2b43',
    ]);
    self::assertEquals('content', $new->getRegionForComponentInstance('c850e3e9-3143-4cb4-b32e-6fb56f9c2b43'));
    \assert(\is_array($this->clientDataModel->model));
    \assert(\is_array($new->model));
    self::assertEquals($this->clientDataModel->model['static-static-card1ab'], $new->model['c850e3e9-3143-4cb4-b32e-6fb56f9c2b43']);
    self::assertSame([
      'static-static-card1ab',
      'c850e3e9-3143-4cb4-b32e-6fb56f9c2b43',
      'static-image-udf7d',
    ], \array_column($new->layout[0]['components'][0]['slots'][0]['components'], 'uuid'));

    // Duplication with children.
    $uuidMap = [
      'static-static-card1ab' => 'c850e3e9-3143-4cb4-b32e-6fb56f9c2b43',
      'a7470350-deb2-4d9f-982c-464d356403d4' => '6a723ef3-ddff-49fa-80be-574c1f6863e0',
      'static-image-udf7d' => '2ff45524-3448-46d9-affb-a1369e8ea53c',
    ];
    $new = $this->clientDataModel->withDuplicatedComponentTree('a7470350-deb2-4d9f-982c-464d356403d4', $uuidMap);
    self::assertEquals('content', $new->getRegionForComponentInstance('c850e3e9-3143-4cb4-b32e-6fb56f9c2b43'));
    self::assertEquals('content', $new->getRegionForComponentInstance('6a723ef3-ddff-49fa-80be-574c1f6863e0'));
    self::assertEquals('content', $new->getRegionForComponentInstance('2ff45524-3448-46d9-affb-a1369e8ea53c'));
    $originalModel = $this->clientDataModel->model;
    $newModel = $new->model;
    \assert(\is_array($originalModel));
    \assert(\is_array($newModel));
    foreach ($uuidMap as $original => $duplicated) {
      self::assertEquals($originalModel[$original], $newModel[$duplicated]);
    }
    self::assertEquals([
      'static-static-card1ab',
      'static-image-udf7d',
    ], \array_column($new->layout[0]['components'][0]['slots'][0]['components'], 'uuid'));
    self::assertEquals([
      'c850e3e9-3143-4cb4-b32e-6fb56f9c2b43',
      '2ff45524-3448-46d9-affb-a1369e8ea53c',
    ], \array_column($new->layout[0]['components'][1]['slots'][0]['components'], 'uuid'));
    self::assertEquals('6a723ef3-ddff-49fa-80be-574c1f6863e0/column_one', $new->layout[0]['components'][1]['slots'][0]['id']);

    // No explicit input.
    $new = $this->clientDataModel->withDuplicatedComponentTree(self::NO_EXPLICIT_INPUT, [self::NO_EXPLICIT_INPUT => 'd33bbc96-3654-47af-bffc-6dc27028b4f7']);
    self::assertSame($this->clientDataModel->model, $new->model);
    self::assertTrue($new->hasComponentInstance('d33bbc96-3654-47af-bffc-6dc27028b4f7'));

    $this->expectException(\InvalidArgumentException::class);
    $this->clientDataModel->withDuplicatedComponentTree('a7470350-deb2-4d9f-982c-464d356403d4', []);
  }

  /**
   * @legacy-covers ::withInsertedComponents
   */
  public function testWithInsertedComponents(): void {
    $uuid1 = 'afef79ad-4537-4a66-9af2-ed0e9b5fc5b9';
    $component_one = [
      'nodeType' => 'component',
      'uuid' => $uuid1,
      'type' => 'sdc.canvas_test_sdc.my-cta@9454c3bca9bbbf4b',
      'slots' => [],
    ];

    $uuid2 = '80a615e8-20a5-4661-8d96-4b83115a612c';
    $component_two = [
      'nodeType' => 'component',
      'uuid' => $uuid2,
      'type' => 'sdc.canvas_test_sdc.my-cta@9454c3bca9bbbf4b',
      'slots' => [],
    ];

    $model_one = [
      'resolved' => [
        'text' => 'glue',
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

    $model_two = [
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

    // No components.
    self::assertEquals($this->clientDataModel, $this->clientDataModel->withInsertedComponents('no-such-region', NULL, 1, [], []));
    // No such parent, slot or region.
    self::assertEquals($this->clientDataModel, $this->clientDataModel->withInsertedComponents('no-such-region', NULL, 1, [$component_one], [
      $uuid1 => $model_one,
    ]));
    self::assertEquals($this->clientDataModel, $this->clientDataModel->withInsertedComponents('no-such-parent', 'contents', 1, [$component_one], [
      $uuid1 => $model_one,
    ]));
    // Insert single item at root.
    $new = $this->clientDataModel->withInsertedComponents('content', NULL, 1.5, [$component_one], [
      $uuid1 => $model_one,
    ]);
    self::assertEquals([
      'a7470350-deb2-4d9f-982c-464d356403d4',
      $uuid1,
      'static-static-card2df',
      'static-static-card3rr',
      'static-image-static-imageStyle-something7d',
      'ee07d472-a754-4427-b6d4-acfc6f92bbdc',
    ], \array_column($new->layout[0]['components'], 'uuid'));
    self::assertEquals($model_one, ((array) $new->model)[$uuid1]);

    // Insert multiple items at root.
    $new = $this->clientDataModel->withInsertedComponents('content', NULL, 1.5, [
      $component_one,
      $component_two,
    ], [
      $uuid1 => $model_one,
      $uuid2 => $model_two,
    ]);
    self::assertEquals([
      'a7470350-deb2-4d9f-982c-464d356403d4',
      $uuid1,
      $uuid2,
      'static-static-card2df',
      'static-static-card3rr',
      'static-image-static-imageStyle-something7d',
      'ee07d472-a754-4427-b6d4-acfc6f92bbdc',
    ], \array_column($new->layout[0]['components'], 'uuid'));
    self::assertEquals($model_one, ((array) $new->model)[$uuid1]);
    self::assertEquals($model_two, ((array) $new->model)[$uuid2]);

    // Insert single item into a component.
    $new = $this->clientDataModel->withInsertedComponents('a7470350-deb2-4d9f-982c-464d356403d4', 'column_one', 1.5, [$component_one], [
      $uuid1 => $model_one,
    ]);
    self::assertEquals([
      'static-static-card1ab',
      $uuid1,
      'static-image-udf7d',
    ], \array_column($new->layout[0]['components'][0]['slots'][0]['components'], 'uuid'));
    self::assertEquals($model_one, ((array) $new->model)[$uuid1]);

    // Insert multiple item into a component.
    $new = $this->clientDataModel->withInsertedComponents('a7470350-deb2-4d9f-982c-464d356403d4', 'column_one', 1.5, [
      $component_one,
      $component_two,
    ], [
      $uuid1 => $model_one,
      $uuid2 => $model_two,
    ]);
    self::assertEquals([
      'static-static-card1ab',
      $uuid1,
      $uuid2,
      'static-image-udf7d',
    ], \array_column($new->layout[0]['components'][0]['slots'][0]['components'], 'uuid'));
    self::assertEquals($model_one, ((array) $new->model)[$uuid1]);
    self::assertEquals($model_two, ((array) $new->model)[$uuid2]);

    $this->expectException(\OutOfBoundsException::class);
    self::assertEquals($this->clientDataModel, $this->clientDataModel->withInsertedComponents('a7470350-deb2-4d9f-982c-464d356403d4', 'footer', 1, [$component_one], [
      $uuid1 => $model_one,
    ]));
  }

}
