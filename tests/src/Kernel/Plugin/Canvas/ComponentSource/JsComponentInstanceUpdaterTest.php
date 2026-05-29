<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource;

use Drupal\canvas\ComponentSource\ComponentInstanceUpdateAttemptResult;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponentInstanceUpdater;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests \Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponentInstanceUpdater.
 */
#[CoversClass(JsComponentInstanceUpdater::class)]
#[Group('canvas')]
#[Group('canvas_component_sources')]
#[Group('canvas_data_model')]
class JsComponentInstanceUpdaterTest extends JsonSchemaPropsComponentInstanceUpdaterTest {

  /**
   * {@inheritdoc}
   *
   * Removes the "Component prop type changed" scenario — for JS components
   * that case is allowed (returns Latest), so it is tested separately.
   *
   * @see self::testOptionalPropTypeChangedDataLossAccepted()
   * @see self::testRequiredPropTypeChangedDefaultInjected()
   */
  public static function providerUpdate(): \Generator {
    foreach (parent::providerUpdate() as $label => $args) {
      if ($label === 'Component prop type changed') {
        continue;
      }
      yield $label => $args;
    }
  }

  /**
   * {@inheritdoc}
   */
  #[DataProvider('providerUpdate')]
  public function testUpdate(string $new_latest_version, ?callable $setup_callback, ComponentInstanceUpdateAttemptResult $update_result, ?callable $assertion_callback, array $bc_break_violations): void {
    $sut = new JsComponentInstanceUpdater();
    $component_tree_value = [
      [
        'uuid' => self::COMPONENT_INSTANCE_UUID,
        'component_id' => 'js.test',
        'component_version' => self::ORIGINAL_VERSION_HASH,
        'parent_uuid' => NULL,
        'inputs' => [
          'required_text' => 'Canvas is large and in charge!',
          'optional_text' => 'shouting',
          'features' => ['Alpha', 'Beta', 'Gamma', 'Delta'],
          'favorite_animals' => ['cat', 'dog'],
        ],
      ],
      [
        'uuid' => 'b1f6e1d4-B3c4-4d5e-8f6a-1234567890ab',
        'component_id' => 'js.test',
        'component_version' => self::ORIGINAL_VERSION_HASH,
        'parent_uuid' => self::COMPONENT_INSTANCE_UUID,
        'slot' => 'test-slot',
        'inputs' => [
          'required_text' => 'Slot instance text',
        ],
      ],
    ];
    if ($setup_callback !== NULL) {
      call_user_func_array($setup_callback, [&$component_tree_value]);
    }
    $original_component_tree = self::generateComponentTree($component_tree_value, $bc_break_violations);
    self::assertCount(2, $original_component_tree);
    $component_instance = $original_component_tree->getComponentTreeItemByUuid(self::COMPONENT_INSTANCE_UUID);
    self::assertNotNull($component_instance);

    $this->assertSame($update_result, $sut->update($component_instance));
    $this->assertSame($new_latest_version, $component_instance->getComponentVersion());

    $component = Component::load('js.test');
    self::assertNotNull($component);

    if ($assertion_callback !== NULL) {
      $assertion_callback($component_instance);
    }

    $this->assertCount($setup_callback === NULL ? 1 : 2, $component->getVersions());
  }

  /**
   * {@inheritdoc}
   */
  #[DataProvider('providerUpdate')]
  public function testCanUpdate(
    string $new_latest_version,
    ?callable $setup_callback,
    ComponentInstanceUpdateAttemptResult $update_result,
    ?callable $assertion_callback,
    array $bc_break_violations,
  ): void {
    $sut = new JsComponentInstanceUpdater();
    $component_tree_value = [
      [
        'uuid' => self::COMPONENT_INSTANCE_UUID,
        'component_id' => 'js.test',
        'component_version' => self::ORIGINAL_VERSION_HASH,
        'parent_uuid' => NULL,
        'inputs' => [
          'required_text' => 'Canvas is large and in charge!',
          'optional_text' => 'shouting',
          'features' => ['Alpha', 'Beta', 'Gamma', 'Delta'],
          'favorite_animals' => ['cat', 'dog'],
        ],
      ],
    ];
    if ($setup_callback !== NULL) {
      call_user_func_array($setup_callback, [&$component_tree_value]);
    }
    $component_instance = self::generateComponentTree($component_tree_value, $bc_break_violations)->getComponentTreeItemByUuid(self::COMPONENT_INSTANCE_UUID);
    self::assertNotNull($component_instance);
    $this->assertSame($update_result === ComponentInstanceUpdateAttemptResult::Latest, $sut->canUpdate($component_instance));
    $component = Component::load('js.test');
    self::assertNotNull($component);
    $this->assertCount($setup_callback === NULL ? 1 : 2, $component->getVersions());
  }

  /**
   * Prop type change on an optional prop: update allowed, old input dropped.
   *
   * For JS components, changing a prop shape is a permitted update. The
   * existing input is dropped (data loss accepted); since the prop is optional,
   * no default is injected.
   */
  public function testOptionalPropTypeChangedDataLossAccepted(): void {
    $sut = new JsComponentInstanceUpdater();
    $this->changePropType();

    $component = Component::load('js.test');
    self::assertNotNull($component);
    self::assertCount(2, $component->getVersions());

    $component_tree_value = [
      [
        'uuid' => self::COMPONENT_INSTANCE_UUID,
        'component_id' => 'js.test',
        'component_version' => self::ORIGINAL_VERSION_HASH,
        'parent_uuid' => NULL,
        'inputs' => [
          'required_text' => 'Canvas is large and in charge!',
          'optional_text' => 'shouting',
        ],
      ],
    ];
    $component_instance = self::generateComponentTree($component_tree_value, [])->getComponentTreeItemByUuid(self::COMPONENT_INSTANCE_UUID);
    self::assertNotNull($component_instance);

    $this->assertTrue($sut->canUpdate($component_instance));
    $this->assertSame(ComponentInstanceUpdateAttemptResult::Latest, $sut->update($component_instance));
    $this->assertSame($component->getActiveVersion(), $component_instance->getComponentVersion());

    $inputs = $component_instance->getInputs() ?? [];
    // required_text is unchanged.
    self::assertArrayHasKey('required_text', $inputs);
    self::assertSame('Canvas is large and in charge!', $inputs['required_text']);
    // optional_text shape changed — input dropped (data loss).
    self::assertArrayNotHasKey('optional_text', $inputs);
  }

  /**
   * Prop type change on a required prop: update allowed, default injected.
   *
   * The old input is dropped (data loss). Because the prop is still required in
   * the new version, the default value from prop_field_definitions is injected.
   */
  public function testRequiredPropTypeChangedDefaultInjected(): void {
    $sut = new JsComponentInstanceUpdater();
    $this->changeRequiredPropType();

    $component = Component::load('js.test');
    self::assertNotNull($component);
    self::assertCount(2, $component->getVersions());

    $component_tree_value = [
      [
        'uuid' => self::COMPONENT_INSTANCE_UUID,
        'component_id' => 'js.test',
        'component_version' => self::ORIGINAL_VERSION_HASH,
        'parent_uuid' => NULL,
        'inputs' => [
          'required_text' => 'Canvas is large and in charge!',
          'optional_text' => 'shouting',
        ],
      ],
    ];
    $component_instance = self::generateComponentTree($component_tree_value, [])->getComponentTreeItemByUuid(self::COMPONENT_INSTANCE_UUID);
    self::assertNotNull($component_instance);

    $this->assertTrue($sut->canUpdate($component_instance));
    $this->assertSame(ComponentInstanceUpdateAttemptResult::Latest, $sut->update($component_instance));
    $this->assertSame($component->getActiveVersion(), $component_instance->getComponentVersion());

    $inputs = $component_instance->getInputs() ?? [];
    // required_text shape changed — old input dropped, default (first example)
    // injected because the prop is still required.
    self::assertArrayHasKey('required_text', $inputs);
    self::assertSame('Another value', $inputs['required_text']);
    // optional_text shape did not change — existing value preserved.
    self::assertArrayHasKey('optional_text', $inputs);
    self::assertSame('shouting', $inputs['optional_text']);
  }

  /**
   * Changes required_text from a plain string to an enum string.
   *
   * Adding an enum changes the field type mapping (string → list_string), so
   * fieldDataFitsIn() returns false and the input must be dropped.
   */
  protected function changeRequiredPropType(): void {
    $props = $this->jsComponent->getProps();
    \assert(!\is_null($props));
    $props['required_text']['enum'] = ['Canvas is large and in charge!', 'Another value'];
    $props['required_text']['examples'] = ['Another value'];
    $this->jsComponent->setProps($props)->save();
  }

  /**
   * {@inheritdoc}
   *
   * Uses JsComponentInstanceUpdater instead of the parent's SUT.
   */
  public function testAddingOptionalArrayPropToInUseComponentCanUpdate(): void {
    $sut = new JsComponentInstanceUpdater();
    $component_tree_value = [
      [
        'uuid' => self::COMPONENT_INSTANCE_UUID,
        'component_id' => 'js.test',
        'component_version' => self::ORIGINAL_VERSION_HASH,
        'parent_uuid' => NULL,
        'inputs' => [
          'required_text' => 'Canvas is large and in charge!',
        ],
      ],
    ];

    $this->addOptionalArrayProp();

    $component_instance = self::generateComponentTree($component_tree_value, [])->getComponentTreeItemByUuid(self::COMPONENT_INSTANCE_UUID);
    self::assertNotNull($component_instance);

    $this->assertTrue($sut->canUpdate($component_instance));
    $this->assertSame(ComponentInstanceUpdateAttemptResult::Latest, $sut->update($component_instance));

    $component = Component::load('js.test');
    self::assertNotNull($component);
    $this->assertSame($component->getActiveVersion(), $component_instance->getComponentVersion());
  }

  /**
   * {@inheritdoc}
   *
   * Uses JsComponentInstanceUpdater instead of the parent's SUT.
   */
  #[DataProvider('providerUpdateWithGarbageInput')]
  public function testUpdateWithGarbageInput(string $setup_method, callable $assertion_callback): void {
    $sut = new JsComponentInstanceUpdater();
    $this->{$setup_method}();

    $component_tree_value = [
      [
        'uuid' => self::COMPONENT_INSTANCE_UUID,
        'component_id' => 'js.test',
        'component_version' => self::ORIGINAL_VERSION_HASH,
        'parent_uuid' => NULL,
        'inputs' => [
          'required_text' => 'Canvas is large and in charge!',
          'optional_text' => 'shouting',
          'voice' => 'garbage value',
        ],
      ],
    ];
    $component_tree = self::staticallyCreateDanglingComponentTreeItemList(\Drupal::typedDataManager());
    $component_tree->setValue($component_tree_value);
    $component_instance = $component_tree->getComponentTreeItemByUuid(self::COMPONENT_INSTANCE_UUID);
    self::assertNotNull($component_instance);

    $this->assertSame(ComponentInstanceUpdateAttemptResult::Latest, $sut->update($component_instance));
    $assertion_callback($component_instance);
  }

  /**
   * {@inheritdoc}
   *
   * Uses JsComponentInstanceUpdater instead of the parent's SUT.
   */
  #[DataProvider('providerUpdateNeeded')]
  public function testIsUpdateNeeded(array $component_instance_value, ?callable $setup_callback, bool $expected): void {
    $sut = new JsComponentInstanceUpdater();
    if ($setup_callback !== NULL) {
      call_user_func_array($setup_callback, []);
    }

    $component_tree = self::staticallyCreateDanglingComponentTreeItemList(\Drupal::typedDataManager());
    $component_tree->setValue($component_instance_value);
    if ($component_instance_value['component_id'] !== 'sdc.canvas_test_sdc.missing-component') {
      self::assertCount(0, $component_tree->validate(), (string) $component_tree->validate());
    }
    $component_instance = $component_tree->first();
    \assert($component_instance instanceof ComponentTreeItem);

    $this->assertSame($expected, $sut->isUpdateNeeded($component_instance));
  }

}
