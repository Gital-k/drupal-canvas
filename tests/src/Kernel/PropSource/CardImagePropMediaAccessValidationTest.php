<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\PropSource;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemListInstantiatorTrait;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests validating a `card` component instance with a non-existent media ID.
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
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('file');
    $this->container->get(ComponentSourceManager::class)->generateComponents();
  }

  /**
   * A required `image` prop referencing a non-existent media ID.
   */
  public function testNonExistentMediaId(): void {
    $component = Component::load('sdc.canvas_test_sdc.card');
    $this->assertInstanceOf(Component::class, $component);

    $clientModel = [
      'source' => $component->normalizeForClientSide()->values['propSources'],
      'resolved' => [
        // No media entity has this ID: nothing was ever saved with it.
        'image' => 999999,
        'loading' => 'lazy',
      ],
    ];
    $uuid = $this->container->get('uuid')->generate();
    $inputs = $component->getComponentSource()->clientModelToInput($uuid, $component, $clientModel, NULL);

    $componentTreeItemList = $this->createDanglingComponentTreeItemList();
    $componentTreeItemList->setValue([
      [
        'uuid' => $uuid,
        'component_id' => 'sdc.canvas_test_sdc.card',
        'component_version' => $component->getActiveVersion(),
        'inputs' => $inputs,
      ],
    ]);
    $violations = $componentTreeItemList->validate();

    $this->assertGreaterThan(
      0,
      $violations->count(),
      'Expected a validation error for a required image prop referencing a non-existent media ID, got none.',
    );
  }

}
