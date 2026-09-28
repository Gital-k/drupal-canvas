<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\PropSource;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Plugin\Canvas\ComponentSource\SingleDirectoryComponent;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemListInstantiatorTrait;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests validating component instances with invalid static prop values.
 *
 * @see \Drupal\canvas_ai\AiResponseValidator::validateComponentStructure()
 * @see \Drupal\canvas\PropExpressions\StructuredData\Evaluator
 */
#[Group('canvas')]
#[Group('canvas_data_model')]
class CardImagePropMediaAccessValidationTest extends CanvasKernelTestBase {

  use ComponentTreeItemListInstantiatorTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'sdc_test_all_props',
  ];

  /**
   * {@inheritdoc}
   */
  protected static $configSchemaCheckerExclusions = [
    'canvas.' . Component::ENTITY_TYPE_ID . '.' . SingleDirectoryComponent::SOURCE_PLUGIN_ID . '.sdc_test_all_props.all-props',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('file');
    $this->installEntitySchema('path_alias');
    $this->container->get(ComponentSourceManager::class)->generateComponents();
  }

  /**
   * Static prop values of the wrong type or referencing a missing entity.
   */
  public function testInvalidStaticPropValues(): void {
    $card = $this->buildComponentTreeItem('sdc.canvas_test_sdc.card', [
      // No media entity has this ID: nothing was ever saved with it.
      'image' => 999999,
      'loading' => 'lazy',
    ]);
    $all_props = $this->buildComponentTreeItem('sdc.sdc_test_all_props.all-props', [
      'test_bool_default_false' => 'false',
      'test_integer' => 'canvas',
      'test_number' => 'canvas',
    ]);

    $componentTreeItemList = $this->createDanglingComponentTreeItemList();
    $componentTreeItemList->setValue([$card, $all_props]);
    $violations = $componentTreeItemList->validate();

    $violation_paths = [];
    foreach ($violations as $violation) {
      $violation_paths[] = $violation->getPropertyPath();
    }
    $this->assertSame([
      \sprintf('0.inputs.%s.image', $card['uuid']),
      \sprintf('1.inputs.%s.test_bool_default_false', $all_props['uuid']),
      \sprintf('1.inputs.%s.test_integer', $all_props['uuid']),
      \sprintf('1.inputs.%s.test_number', $all_props['uuid']),
    ], $violation_paths);
  }

  /**
   * Builds a component tree item from a component's default and given values.
   *
   * @param string $component_id
   *   The component config entity ID.
   * @param array $resolved
   *   Prop values that override the component's default values.
   *
   * @return array
   *   A component tree item.
   */
  private function buildComponentTreeItem(string $component_id, array $resolved): array {
    $component = Component::load($component_id);
    $this->assertInstanceOf(Component::class, $component);

    $propSources = $component->normalizeForClientSide()->values['propSources'];
    $clientModel = [
      'source' => $propSources,
      'resolved' => $resolved + \array_map(
        static fn (array $propSource): mixed => $propSource['default_values']['resolved'] ?? NULL,
        $propSources,
      ),
    ];
    $uuid = $this->container->get('uuid')->generate();

    return [
      'uuid' => $uuid,
      'component_id' => $component_id,
      'component_version' => $component->getActiveVersion(),
      'inputs' => $component->getComponentSource()->clientModelToInput($uuid, $component, $clientModel, NULL),
    ];
  }

}
