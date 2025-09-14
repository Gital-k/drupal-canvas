<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Component;

use Drupal\canvas\Entity\Component as ComponentEntity;
use Drupal\canvas\Entity\VersionedConfigEntityBase;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JavaScriptComponent;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the JavaScriptComponent source plugin.
 *
 * @group canvas
 */
class JavaScriptComponentTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas',
    'field',
    'text',
    'user',
    'options',
    'filter',
  ];

  /**
   * {@inheritdoc}
   *
   * Disable strict config schema for now.
   * @todo Implement config schema correctly.
   */
  // phpcs:ignore DrupalPractice.Objects.StrictSchemaDisabled.StrictConfigSchema
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('component');
    // Don't install canvas config to avoid editor dependencies.
  }

  /**
   * Tests creating a component entity using the helper method.
   */
  public function testCreateTestComponentEntities(): void {
    JavaScriptComponent::createTestComponentEntities('drupal-markup');

    // Load the created component.
    $component = ComponentEntity::load('javascript.drupal-markup');
    $this->assertNotNull($component);
    $this->assertEquals('javascript.drupal-markup', $component->id());
    $this->assertEquals('Drupal Markup', $component->label());
    $this->assertEquals('JavaScript Components', $component->get('category'));
    $this->assertEquals('javascript', $component->get('source'));
    $this->assertEquals('drupal-markup', $component->get('source_local_id'));
    $this->assertEquals('v1', $component->getActiveVersion());
    $this->assertTrue($component->status());

    // Verify prop_field_definitions were generated.
    $component->loadVersion('v1');
    $settings = $component->getSettings();
    $this->assertArrayHasKey('content', $settings['prop_field_definitions']);
    $this->assertArrayHasKey('class', $settings['prop_field_definitions']);
    $this->assertEquals('string', $settings['prop_field_definitions']['content']['field_type']);
    $this->assertEquals('string_textfield', $settings['prop_field_definitions']['content']['field_widget']);
  }

  /**
   * Tests creating a custom component entity manually.
   */
  public function testCreateCustomComponentEntity(): void {
    // Create a custom component with different props.
    $settings = [
      'label' => 'Custom Button',
      'local_source_id' => 'custom-button',
      'props' => [
        'type' => 'object',
        'required' => ['text'],
        'properties' => [
          'text' => [
            'type' => 'string',
            'title' => 'Button Text',
            'examples' => ['Click me!'],
          ],
          'variant' => [
            'type' => 'string',
            'title' => 'Button Variant',
            'enum' => ['primary', 'secondary', 'danger'],
            'examples' => ['primary'],
          ],
          'disabled' => [
            'type' => 'boolean',
            'title' => 'Disabled',
            'examples' => [FALSE],
          ],
        ],
      ],
      'slots' => [
        'icon' => [
          'title' => 'Icon Slot',
          'description' => 'Optional icon to display in the button',
        ],
      ],
      'drupal_library' => 'canvas/custom_components',
    ];

    $component = ComponentEntity::create([
      'id' => 'javascript.custom-button',
      'label' => 'Custom Button',
      'category' => 'Custom Components',
      'source' => JavaScriptComponent::SOURCE_PLUGIN_ID,
      'provider' => NULL,
      'source_local_id' => 'custom-button',
      'active_version' => 'v1',
      'versioned_properties' => [
        VersionedConfigEntityBase::ACTIVE_VERSION => ['settings' => $settings],
      ],
      'status' => TRUE,
    ]);

    // Generate prop_field_definitions before saving.
    JavaScriptComponent::ensurePropFieldDefinitions($component);
    $component->save();

    // Reload to verify persistence.
    $loaded_component = ComponentEntity::load('javascript.custom-button');
    $this->assertNotNull($loaded_component);
    $this->assertEquals('Custom Button', $loaded_component->label());
    $this->assertEquals('Custom Components', $loaded_component->get('category'));

    // Verify all props have field definitions.
    $loaded_component->loadVersion('v1');
    $loaded_settings = $loaded_component->getSettings();
    $this->assertArrayHasKey('prop_field_definitions', $loaded_settings);
    $this->assertArrayHasKey('text', $loaded_settings['prop_field_definitions']);
    $this->assertArrayHasKey('variant', $loaded_settings['prop_field_definitions']);
    $this->assertArrayHasKey('disabled', $loaded_settings['prop_field_definitions']);

    // Verify field types.
    $this->assertEquals('string', $loaded_settings['prop_field_definitions']['text']['field_type']);
    $this->assertEquals('string_textfield', $loaded_settings['prop_field_definitions']['text']['field_widget']);
    $this->assertEquals('list_string', $loaded_settings['prop_field_definitions']['variant']['field_type']);
    $this->assertEquals('boolean', $loaded_settings['prop_field_definitions']['disabled']['field_type']);

    // Verify slots are preserved.
    $this->assertArrayHasKey('icon', $loaded_settings['slots']);
    $this->assertEquals('Icon Slot', $loaded_settings['slots']['icon']['title']);
  }

}
