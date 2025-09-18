<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Config;

use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\canvas\Entity\EntityConstraintViolationList;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Exception\ConstraintViolationException;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\canvas\Traits\ExampleImagesTrait;

/**
 * @coversDefaultClass \Drupal\canvas\Entity\JavaScriptComponent
 * @group canvas
 */
class JavascriptComponentTest extends KernelTestBase {

  use ExampleImagesTrait;

  protected static $modules = [
    'canvas',
    'file',
    'user',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('file');
    $this->installEntitySchema('user');
    $this->installSchema('file', ['file_usage']);
  }

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

  public function testExampleImages(): void {
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
    ];
    $js_component = JavaScriptComponent::createFromClientSide($client_data);
    $this->assertSame(SAVED_NEW, $js_component->save());
    $client_data['exampleImages'] = self::getEncodedImages();
    $js_component->updateFromClientSide($client_data);
    $this->assertIsArray($js_component->get('encoded_files'));
    $this->assertSame(SAVED_UPDATED, $js_component->save());
    $encoded_files = $js_component->get('encoded_files');
    foreach ($encoded_files as $uuid => $encoded_file) {
      $file = \Drupal::service(EntityRepositoryInterface::class)->loadEntityByUuid('file', $uuid);
      $this->assertTrue($file instanceof File);
      $this->assertArrayNotHasKey('data', $encoded_file);
    }
  }

}
