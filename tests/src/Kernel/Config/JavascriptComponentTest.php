<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Config;

use Drupal\canvas\Entity\EntityConstraintViolationList;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Exception\ConstraintViolationException;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Drupal\canvas\Entity\JavaScriptComponent.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(JavaScriptComponent::class)]
#[Group('canvas')]
class JavascriptComponentTest extends CanvasKernelTestBase {

  /**
   * A valid `react` Code Component payload, with every optional key sent.
   *
   * @return array<string, mixed>
   */
  private static function clientData(): array {
    return [
      'machineName' => 'test_optional',
      'name' => 'Test optional properties',
      'status' => FALSE,
      'required' => [],
      'props' => [],
      'slots' => [],
      'sourceCodeJs' => 'export default function Test() {}',
      'sourceCodeCss' => '',
      'compiledJs' => 'export default function Test() {}',
      'compiledCss' => '',
      'importedJsComponents' => [],
      'dataDependencies' => [],
    ];
  }

  /**
   * Tests that omitted properties are accepted and get the canonical default.
   *
   * Config schema requires `dataDependencies` to be present, but a Code
   * Component that depends on no data has nothing to send. The HTTP API
   * therefore accepts its absence and stores the canonical empty value.
   *
   * `status`, `type`, `required`, `props` and `slots` were already optional;
   * they are asserted here to lock that in, because openapi.yml now advertises
   * them as optional.
   *
   * @see https://www.drupal.org/i/3553427
   *
   * @legacy-covers ::createFromClientSide
   */
  public function testOmittedPropertiesAreAccepted(): void {
    $client_data = self::clientData();
    foreach (['dataDependencies', 'status', 'type', 'required', 'props', 'slots'] as $omitted_key) {
      unset($client_data[$omitted_key]);
    }

    $component = JavaScriptComponent::createFromClientSide($client_data);

    // The canonical empty value, matching what CanvasConfigUpdater back-fills.
    // @see \Drupal\canvas\CanvasConfigUpdater::updateDataDependencies()
    $this->assertSame([], $component->get('dataDependencies'));
    // Flexibility on the wire must not loosen config validity.
    self::assertEntityIsValid($component);
  }

  /**
   * Tests that empty values are normalized to omitted ones.
   *
   * Config schema marks these keys `requiredKey: false` *and* `NotBlank`: they
   * must be absent rather than empty. Clients should not have to make that
   * distinction, so the server drops empty values instead of rejecting them.
   *
   * @see https://www.drupal.org/i/3553427
   *
   * @legacy-covers ::createFromClientSide
   * @legacy-covers ::updateFromClientSide
   */
  public function testEmptyValuesAreNormalizedToOmittedOnes(): void {
    $client_data = self::clientData();
    $client_data['dataDependencies'] = [
      'urls' => [],
      'drupalSettings' => [],
      'entityFields' => [],
    ];
    $client_data['slots'] = [
      'the-slot' => [
        'title' => 'The slot',
        'description' => '',
        'examples' => [],
      ],
    ];

    $component = JavaScriptComponent::createFromClientSide($client_data);

    $this->assertSame([], $component->get('dataDependencies'));
    $this->assertSame(['the-slot' => ['title' => 'The slot']], $component->get('slots'));
    self::assertEntityIsValid($component);

    // Non-empty values are untouched.
    $client_data['dataDependencies']['drupalSettings'] = ['v0.pageTitle'];
    $client_data['slots']['the-slot']['description'] = 'A description.';
    $component->updateFromClientSide($client_data);
    $this->assertSame(['drupalSettings' => ['v0.pageTitle']], $component->get('dataDependencies'));
    $this->assertSame([
      'the-slot' => [
        'title' => 'The slot',
        'description' => 'A description.',
      ],
    ], $component->get('slots'));
    self::assertEntityIsValid($component);

    // Emptying a previously set value still clears it.
    $client_data['dataDependencies']['drupalSettings'] = [];
    $component->updateFromClientSide($client_data);
    $this->assertSame([], $component->get('dataDependencies'));
    self::assertEntityIsValid($component);
  }

  /**
   * Tests that omitting a key in a PATCH payload leaves the stored value alone.
   *
   * Normalizing empty values must not turn ::updateFromClientSide() into a full
   * replacement: absent keys still mean "unchanged".
   *
   * @see https://www.drupal.org/i/3553427
   *
   * @legacy-covers ::updateFromClientSide
   */
  public function testOmittedPropertiesArePreservedOnUpdate(): void {
    $client_data = self::clientData();
    $client_data['dataDependencies'] = ['drupalSettings' => ['v0.pageTitle']];
    $component = JavaScriptComponent::createFromClientSide($client_data);

    $component->updateFromClientSide(['name' => 'Renamed']);

    $this->assertSame('Renamed', $component->get('name'));
    $this->assertSame(['drupalSettings' => ['v0.pageTitle']], $component->get('dataDependencies'));
    self::assertEntityIsValid($component);
  }

  /**
   * Tests the empty values that deliberately remain validation errors.
   *
   * An empty value is normalized to an omitted one only where omitting is
   * valid. Where it is not, the client gets a validation error rather than a
   * silently different Code Component.
   *
   * @param array<string, mixed> $override
   *   The client-side payload keys to override.
   * @param array<string, string> $expected_errors
   *   The expected validation errors, keyed by property path.
   *
   * @see https://www.drupal.org/i/3553427
   *
   * @legacy-covers ::createFromClientSide
   */
  #[DataProvider('providerEmptyValuesThatRemainInvalid')]
  public function testEmptyValuesThatRemainInvalid(array $override, array $expected_errors): void {
    $component = JavaScriptComponent::createFromClientSide($override + self::clientData());

    $actual_errors = [];
    foreach ($component->getTypedData()->validate() as $violation) {
      $actual_errors[$violation->getPropertyPath()] = (string) $violation->getMessage();
    }
    $this->assertSame($expected_errors, $actual_errors);
  }

  /**
   * @return iterable<string, array{array<string, mixed>, array<string, string>}>
   */
  public static function providerEmptyValuesThatRemainInvalid(): iterable {
    // `name` is the component's label: a Code Component must have one.
    yield 'empty name' => [
      ['name' => ''],
      ['name' => 'This value should not be blank.'],
    ];
    // `type` may be omitted, but an empty string is not one of its choices.
    // Omitting it means `react`.
    yield 'empty type' => [
      ['type' => ''],
      ['type' => 'The value you selected is not a valid choice.'],
    ];
    // A slot's `title` is required, so an empty one is reported as blank rather
    // than dropped the way its optional siblings are.
    yield 'empty slot title' => [
      ['slots' => ['the-slot' => ['title' => '', 'description' => '', 'examples' => []]]],
      ['slots.the-slot.title' => 'This value should not be blank.'],
    ];
    // An unsupported key sent empty still gets the error naming it, instead of
    // being silently dropped.
    yield 'unsupported dataDependencies key' => [
      ['dataDependencies' => ['unsupported' => []]],
      ['dataDependencies.unsupported' => "'unsupported' is not a supported key."],
    ];
  }

  /**
   * Tests minItems enforcement in updateFromClientSide.
   *
   * @legacy-covers ::createFromClientSide
   * @legacy-covers ::updateFromClientSide
   */
  public function testUpdateFromClientSideMinItemsEnforcement(): void {
    $client_data = [
      'machineName' => 'test_min_items',
      'name' => 'Test minItems Component',
      'status' => FALSE,
      'required' => ['required_array_prop', 'required_string_prop'],
      'props' => [
        'required_array_prop' => [
          'type' => 'array',
          'title' => 'Required Array Prop',
          'items' => ['type' => 'string'],
        ],
        'optional_array_prop' => [
          'type' => 'array',
          'title' => 'Optional Array Prop',
          'items' => ['type' => 'string'],
        ],
        'required_string_prop' => [
          'type' => 'string',
          'title' => 'Required String Prop',
        ],
      ],
      'slots' => [],
      'sourceCodeJs' => '',
      'sourceCodeCss' => '',
      'compiledJs' => '',
      'compiledCss' => '',
      'importedJsComponents' => [],
      'dataDependencies' => [],
    ];

    $component = JavaScriptComponent::createFromClientSide($client_data);
    $props = $component->get('props');

    // Required array prop gets minItems: 1 even when client does not send it.
    $this->assertSame(1, $props['required_array_prop']['minItems']);
    // Optional array prop does not get minItems.
    $this->assertArrayNotHasKey('minItems', $props['optional_array_prop']);
    // Required non-array prop does not get minItems.
    $this->assertArrayNotHasKey('minItems', $props['required_string_prop']);

    // minItems sent by client on optional array prop is removed by server.
    $client_data['props']['optional_array_prop']['minItems'] = 1;
    $component->updateFromClientSide($client_data);
    $props = $component->get('props');
    $this->assertArrayNotHasKey('minItems', $props['optional_array_prop']);
  }

  /**
   * Tests adding imported component dependencies.
   *
   * @legacy-covers ::createFromClientSide
   * @legacy-covers ::updateFromClientSide
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
    $this->assertFalse($js_component->hasFallbackImplementation());
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
   * Tests the client-side representation of an external component.
   *
   * @legacy-covers ::createFromClientSide
   * @legacy-covers ::updateFromClientSide
   * @legacy-covers ::normalizeForClientSide
   */
  public function testExternalComponent(): void {
    $client_data = [
      'machineName' => 'external_test',
      'name' => 'External test',
      'status' => TRUE,
      'type' => 'external',
      'required' => [],
      'props' => [],
      'slots' => [],
      'dataDependencies' => [],
    ];
    $component = JavaScriptComponent::createFromClientSide($client_data);

    self::assertTrue($component->isExternal());
    self::assertSame('external', $component->getComponentType());
    self::assertNull($component->get('js'));
    self::assertNull($component->get('css'));
    self::assertEntityIsValid($component);

    $representation = $component->normalizeForClientSide();
    self::assertNull($representation->preview);
    self::assertSame('external', $representation->values['type']);
    self::assertArrayNotHasKey('sourceCodeJs', $representation->values);
    self::assertArrayNotHasKey('sourceCodeCss', $representation->values);
    self::assertArrayNotHasKey('compiledJs', $representation->values);
    self::assertArrayNotHasKey('compiledCss', $representation->values);

    $js = [
      'original' => 'export default function ExternalTest() {}',
      'compiled' => 'export default function ExternalTest() {}',
    ];
    $css = [
      'original' => '.external-test { display: block; }',
      'compiled' => '.external-test{display:block}',
    ];
    $component->set('js', $js);
    $component->set('css', $css);
    $dependency = JavaScriptComponent::create([
      'machineName' => 'external_dependency',
      'name' => 'External dependency',
      'status' => TRUE,
      'required' => [],
      'props' => [],
      'slots' => [],
      'js' => ['original' => '', 'compiled' => ''],
      'css' => ['original' => '', 'compiled' => ''],
      'dataDependencies' => [],
    ]);
    $dependency->save();
    $component->set('dependencies', [
      'enforced' => [
        'config' => [$dependency->getConfigDependencyName()],
      ],
    ]);
    self::assertTrue($component->hasFallbackImplementation());
    self::assertEntityIsValid($component);
    self::assertSame($js, $component->toArray()['js']);
    self::assertSame($css, $component->toArray()['css']);

    // Metadata-only client updates must not discard a retained implementation.
    $component->updateFromClientSide($client_data);
    self::assertSame($js, $component->get('js'));
    self::assertSame($css, $component->get('css'));
    self::assertContains($dependency->getConfigDependencyName(), $component->getDependencies()['config']);
    $representation = $component->normalizeForClientSide();
    self::assertArrayNotHasKey('sourceCodeJs', $representation->values);
    self::assertArrayNotHasKey('sourceCodeCss', $representation->values);

    $this->expectException(ConstraintViolationException::class);
    $this->expectExceptionMessage('External code components cannot contain JavaScript or CSS.');
    $component->updateFromClientSide([
      ...$client_data,
      'sourceCodeJs' => 'export default function ExternalTest() {}',
    ]);
  }

  /**
   * Tests that the client side may change a component's name, status, and type.
   *
   * These changes are accepted so that tools such as the CLI can reconcile
   * components with a decoupled application: an external component's name and
   * status may change, and a react component may be converted to external.
   *
   * @legacy-covers ::updateFromClientSide
   */
  public function testClientSideMayChangeComponentIdentity(): void {
    // The external application's name and status are no longer locked.
    $external_data = [
      'machineName' => 'external_mutable',
      'name' => 'External mutable',
      'status' => TRUE,
      'type' => 'external',
      'required' => [],
      'props' => [],
      'slots' => [],
      'dataDependencies' => [],
    ];
    $component = JavaScriptComponent::createFromClientSide($external_data);
    $component->save();
    $component->updateFromClientSide([
      ...$external_data,
      'name' => 'Renamed',
      'status' => FALSE,
    ]);
    self::assertSame('Renamed', $component->label());
    self::assertFalse($component->status());

    // A react component may be converted to an external component.
    $react_component = JavaScriptComponent::createFromClientSide([
      'machineName' => 'react_mutable',
      'name' => 'React mutable',
      'status' => TRUE,
      'required' => [],
      'props' => [],
      'slots' => [],
      'sourceCodeJs' => 'console.log("hey");',
      'sourceCodeCss' => '.big { font-size: 3rem; }',
      'compiledJs' => 'console.log("hey");',
      'compiledCss' => '.big{font-size:3rem;}',
      'importedJsComponents' => [],
      'dataDependencies' => [],
    ]);
    $react_component->save();
    $react_component->updateFromClientSide(['type' => 'external']);
    self::assertSame('external', $react_component->getComponentType());
    self::assertTrue($react_component->isExternal());
  }

}
