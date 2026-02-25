<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Config;

use Drupal\canvas\Entity\EntityConstraintViolationList;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Exception\ConstraintViolationException;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;

/**
 * @coversDefaultClass \Drupal\canvas\Entity\JavaScriptComponent
 * @group canvas
 */
#[RunTestsInSeparateProcesses]
class JavascriptComponentTest extends CanvasKernelTestBase {

  /**
   * @covers ::createFromClientSide
   * @covers ::updateFromClientSide
   */
  public function testAddingImportedComponentDependencies(): void {
    $client_data = [
      'machineName' => 'test',
      'name' => 'Test Code Component',
      'status' => FALSE,
      'required' => [],
      'props' => [],
      'slots' => [],
      'sourceCodeJs' => '',
      'sourceCodeCss' => '',
      'compiledJs' => '',
      'compiledCss' => '',
      'importedJsComponents' => [],
      'dataDependencies' => [],
    ];
    $js_component = JavaScriptComponent::createFromClientSide($client_data);
    $this->assertSame(SAVED_NEW, $js_component->save());
    $this->assertCount(0, $js_component->getDependencies());
    $this->assertSame([
      'config:canvas.js_component.test',
    ], $js_component->getCacheTags());

    // Create another component that will be imported by the first one.
    $client_data_2 = $client_data;
    $client_data_2['name'] = 'Test Code Component 2';
    $client_data_2['machineName'] = 'test2';
    $js_component2 = JavaScriptComponent::createFromClientSide($client_data_2);
    $this->assertSame(SAVED_NEW, $js_component2->save());
    $this->assertCount(0, $js_component2->getDependencies());
    $this->assertSame([
      'config:canvas.js_component.test2',
    ], $js_component2->getCacheTags());

    // Adding a component to `importedJsComponents` should add this component
    // to the dependencies.
    $client_data['importedJsComponents'] = [$js_component2->id()];
    $js_component->updateFromClientSide($client_data);
    $this->assertSame(SAVED_UPDATED, $js_component->save());
    $this->assertSame(
      [
        'config' => [$js_component2->getConfigDependencyName()],
      ],
      $js_component->getDependencies()
    );
    $this->assertSame([
      'config:canvas.js_component.test',
      'config:canvas.js_component.test2',
    ], $js_component->getCacheTags());

    // Ensure missing components are will throw a validation error.
    $client_data['importedJsComponents'] = [$js_component2->id(), 'missing'];
    try {
      $js_component->updateFromClientSide($client_data);
      $this->fail('Expected ConstraintViolationException not thrown.');
    }
    catch (ConstraintViolationException $exception) {
      $violations = $exception->getConstraintViolationList();
      $this->assertInstanceOf(EntityConstraintViolationList::class, $violations);
      $this->assertSame($js_component->id(), $violations->entity->id());
      $this->assertCount(1, $violations);
      $violation = $violations->get(0);
      $this->assertSame('importedJsComponents.1', $violation->getPropertyPath());
      $this->assertSame("The JavaScript component with the machine name 'missing' does not exist.", $violation->getMessage());
    }

    // Ensure not sending `importedJsComponents` will throw an error.
    unset($client_data['importedJsComponents']);
    try {
      $js_component->updateFromClientSide($client_data);
      $this->fail('Expected ConstraintViolationException not thrown.');
    }
    catch (ConstraintViolationException $exception) {
      $violations = $exception->getConstraintViolationList();
      $this->assertInstanceOf(EntityConstraintViolationList::class, $violations);
      $this->assertSame($js_component->id(), $violations->entity->id());
      $this->assertCount(1, $violations);
      $violation = $violations->get(0);
      $this->assertSame('importedJsComponents', $violation->getPropertyPath());
      $this->assertSame("The 'importedJsComponents' field is required when 'sourceCodeJs' or 'compiledJs' is provided", $violation->getMessage());
    }

    // Resetting the imported components to an empty array should remove the
    // dependencies.
    $client_data['importedJsComponents'] = [];
    $js_component->updateFromClientSide($client_data);
    $this->assertSame(SAVED_UPDATED, $js_component->save());
    $this->assertSame([], $js_component->getDependencies());
    $this->assertSame([
      'config:canvas.js_component.test',
    ], $js_component->getCacheTags());
  }

  /**
   * Tests that array prop enum/meta:enum are normalized from array to items level.
   *
   * @covers ::createFromClientSide
   * @covers ::updateFromClientSide
   */
  public function testArrayPropEnumNormalization(): void {
    // Enable canvas_dev_mode to unlock array type support.
    $this->enableModules(['canvas_dev_mode']);

    // Create component with enum/meta:enum at array level (as client sends).
    $client_data = [
      'machineName' => 'enum_array_test',
      'name' => 'Enum Array Test',
      'status' => TRUE,
      'required' => [],
      'props' => [
        'tags' => [
          'type' => 'array',
          'title' => 'Tags',
          'items' => ['type' => 'string'],
          // These are at array level (incorrect, but what client sends).
          'enum' => ['option1', 'option2'],
          'meta:enum' => ['option1' => 'Option 1', 'option2' => 'Option 2'],
          'x-translation-context' => 'Tag selection',
          'examples' => [['option1']],
        ],
      ],
      'slots' => [],
      'sourceCodeJs' => 'console.log("test")',
      'sourceCodeCss' => '',
      'compiledJs' => 'console.log("test")',
      'compiledCss' => '',
      'importedJsComponents' => [],
      'dataDependencies' => [],
    ];

    $js_component = JavaScriptComponent::createFromClientSide($client_data);
    $this->assertSame(SAVED_NEW, $js_component->save());

    // Verify enum/meta:enum/x-translation-context were moved to items level.
    $this->assertSame([
      'tags' => [
        'title' => 'Tags',
        'type' => 'array',
        'examples' => [['option1']],
        'items' => [
          'type' => 'string',
          'enum' => ['option1', 'option2'],
          'meta:enum' => ['option1' => 'Option 1', 'option2' => 'Option 2'],
          'x-translation-context' => 'Tag selection',
        ],
      ],
    ], $js_component->get('props'));
  }

}
