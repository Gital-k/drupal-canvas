<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource;

use Drupal\canvas\ComponentSource\ComponentInstanceUpdateAttemptResult;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponentInstanceUpdater;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests \Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponentInstanceUpdater.
 *
 * The shared scenarios (testUpdate, testCanUpdate, testIsUpdateNeeded, …) are
 * inherited from the parent test unchanged; only createUpdater() is overridden
 * so they run against JsComponentInstanceUpdater. This file only adds the
 * scenarios that are specific to JS components (prop shape changes, which the
 * default updater forbids but this one allows with data loss).
 */
#[CoversClass(JsComponentInstanceUpdater::class)]
#[Group('canvas')]
#[Group('canvas_component_sources')]
#[Group('canvas_data_model')]
class JsComponentInstanceUpdaterTest extends JsonSchemaPropsComponentInstanceUpdaterTest {

  /**
   * {@inheritdoc}
   */
  protected static function createUpdater(): JsComponentInstanceUpdater {
    return new JsComponentInstanceUpdater();
  }

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
   * Prop type change on an optional prop: update allowed, old input dropped.
   *
   * For JS components, changing a prop shape is a permitted update. The
   * existing input is dropped (data loss accepted); since the prop is optional,
   * no default is injected.
   */
  public function testOptionalPropTypeChangedDataLossAccepted(): void {
    $sut = static::createUpdater();
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
    $sut = static::createUpdater();
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

}
