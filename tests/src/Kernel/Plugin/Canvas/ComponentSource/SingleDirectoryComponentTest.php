<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource;

use Drupal\canvas\Plugin\ComponentPluginManager as CanvasComponentPluginManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\canvas\Entity\Component;
use Drupal\Core\Plugin\Component as SdcPlugin;
use Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\canvas\Plugin\Canvas\ComponentSource\SingleDirectoryComponent;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\canvas\PropExpressions\StructuredData\FieldTypePropExpression;
use Drupal\canvas\PropSource\PropSource;
use Drupal\canvas\PropSource\StaticPropSource;
use Drupal\file\Entity\File;
use Drupal\link\LinkItemInterface;
use Drupal\media\Entity\Media;
use Drupal\media\Entity\MediaType;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\BrokenComponentManager;
use Drupal\Tests\canvas\Kernel\BrokenPluginManagerInterface;
use Drupal\Tests\canvas\Kernel\Traits\CiModulePathTrait;
use Drupal\Tests\canvas\Traits\ConstraintViolationsTestTrait;
use Drupal\Tests\canvas\Traits\SingleDirectoryComponentTreeTestTrait;
use Drupal\Tests\canvas\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\canvas\Traits\CrawlerTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\TestFileCreationTrait;
use Spatie\Snapshots\MatchesSnapshots;
use Twig\Error\Error;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

/**
 * @coversDefaultClass \Drupal\canvas\Plugin\Canvas\ComponentSource\SingleDirectoryComponent
 * @group canvas
 * @phpstan-import-type ComponentConfigEntityId from \Drupal\canvas\Entity\Component
 * @phpstan-import-type SingleComponentInputArray from \Drupal\canvas\Plugin\DataType\ComponentInputs
 */
final class SingleDirectoryComponentTest extends ComponentSourceTestBase {

  use ConstraintViolationsTestTrait;
  use ContribStrictConfigSchemaTestTrait;
  use SingleDirectoryComponentTreeTestTrait;
  use GenerateComponentConfigTrait;
  use CiModulePathTrait;
  use CrawlerTrait;
  use MediaTypeCreationTrait;
  use TestFileCreationTrait;
  use ContentTypeCreationTrait;
  use MatchesSnapshots;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_test_sdc',
    'image',
    'media',
    'node',
    'path',
    'user',
    'field',
    'text',
  ];

  /**
   * Setup tests.
   */
  public function setUp(): void {
    parent::setUp();
    // Fixate the private key & hash salt to get predictable `itok`.
    $this->container->get('state')->set('system.private_key', 'dynamic_image_style_private_key');
    $settings_class = new \ReflectionClass(Settings::class);
    $instance_property = $settings_class->getProperty('instance');
    $settings = new Settings([
      'hash_salt' => 'dynamic_image_style_hash_salt',
    ]);
    $instance_property->setValue(NULL, $settings);

    // We need to ensure the public://balloons.png image exists in the test
    // environment for the "Card with stream wrapper image" tests.
    $file_system = \Drupal::service('file_system');
    $extension_path_resolver = \Drupal::service('extension.path.resolver');
    $module_path = $extension_path_resolver->getPath('module', 'canvas_test_sdc');
    $source = $module_path . '/components/card/balloons.png';
    $destination = 'public://balloons.png';
    $directory = 'public://';
    $file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    $file_system->copy($source, $destination, FileExists::Replace);

    $this->container->get('theme_installer')->install(['sdc_theme_test']);
  }

  /**
   * @depends testDiscovery
   */
  public function testGetClientSideInfo(array $component_ids): void {
    $this->installEntitySchema('node');
    $this->installConfig('node');
    $this->createContentType(['type' => 'article']);
    parent::testGetClientSideInfo($component_ids);
  }

  /**
   * All test module SDCs must either have a Component or a reason why not.
   *
   * @covers ::checkRequirements()
   * @covers \Drupal\canvas\Plugin\ComponentPluginManager::setCachedDefinitions()
   */
  public function testDiscovery(): array {
    // Nothing discovered initially.
    self::assertSame([], $this->findIneligibleComponents(SingleDirectoryComponent::SOURCE_PLUGIN_ID, 'canvas_test_sdc'));
    self::assertSame([], $this->findCreatedComponentConfigEntities(SingleDirectoryComponent::SOURCE_PLUGIN_ID, 'canvas_test_sdc'));

    self::assertSame([], $this->findIneligibleComponents(SingleDirectoryComponent::SOURCE_PLUGIN_ID, 'sdc_theme_test'));
    self::assertSame([], $this->findCreatedComponentConfigEntities(SingleDirectoryComponent::SOURCE_PLUGIN_ID, 'sdc_theme_test'));

    // Trigger component generation, as if the test module was just installed.
    // (Kernel tests don't trigger all hooks that are triggered in reality.)
    $this->generateComponentConfig();

    self::assertSame([
      'sdc.canvas_test_sdc.component-mismatch-meta-enum' => [
        'The "meta:enum" keys for the "style" prop enum cannot contain a dot. Offending key: "contains.dots"',
        'The "meta:enum" keys for the "numbers" prop enum cannot contain a dot. Offending key: "3.14"',
        'The values for the "numbers" prop enum must be defined in "meta:enum". Missing keys: "3_14"',
      ],
      'sdc.canvas_test_sdc.empty-enum' => [
        'Prop "pets" has an empty enum value.',
      ],
      'sdc.canvas_test_sdc.html-invalid-format' => [
        'Invalid value "invalid" for "x-formatting-context". Valid values are "inline" and "block".',
      ],
      'sdc.canvas_test_sdc.image-required-with-invalid-example' => [
        'Prop "image" has invalid example value: [src] The property src is required',
      ],
      'sdc.canvas_test_sdc.image-required-without-example' => [
        'Prop "image" is required, but does not have example value',
      ],
      'sdc.canvas_test_sdc.image-srcset-candidate-template-uri' => [
        // This test SDC does indeed not have a corresponding StaticPropSource,
        // because its purpose is to test the ability for components to opt in
        // to consuming additional computed properties on a field instance.
        // See the `the image-srcset-candidate-template-uri component` test case
        // in PropSourceSuggesterTest.
        // @see \Drupal\Tests\canvas\Kernel\PropSourceSuggesterTest
        'Drupal Canvas does not know of a field type/widget to allow populating the <code>srcSetCandidateTemplate</code> prop, with the shape <code>{"type":"string","format":"uri-template","x-required-variables":["width"]}</code>.',
      ],
      'sdc.canvas_test_sdc.obsolete' => [
        'Component has "obsolete" status',
      ],
      'sdc.canvas_test_sdc.props-invalid-shapes' => [
        'Prop "invalid_shape" is of type "object" without a $ref, which is not supported',
      ],
      'sdc.canvas_test_sdc.props-no-examples' => [
        'Prop "heading" is required, but does not have example value',
      ],
      'sdc.canvas_test_sdc.props-no-title' => [
        'Prop "heading" must have title',
      ],
      'sdc.canvas_test_sdc.shoe_button' => [
        'Drupal Canvas does not know of a field type/widget to allow populating the <code>icon</code> prop, with the shape <code>{"type":"object","$ref":"json-schema-definitions://canvas.module/shoe-icon"}</code>.',
      ],
      'sdc.canvas_test_sdc.shoe_details' => [
        'Drupal Canvas does not know of a field type/widget to allow populating the <code>expand_icon</code> prop, with the shape <code>{"type":"object","$ref":"json-schema-definitions://canvas.module/shoe-icon"}</code>.',
      ],
      'sdc.canvas_test_sdc.shoe_icon' => [
        'Prop "size" has an empty enum value.',
        'Prop "color" has an empty enum value.',
      ],
      'sdc.canvas_test_sdc.slots-no-title' => [
        'Slot "the_footer" must have title',
      ],
      'sdc.canvas_test_sdc.sparkline_min_2' => [
        // Drupal core's Field API only supports specifying "required or not",
        // and required means ">=1 value". There's no (native) ability to
        // configure a minimum number of values for a field.
        // @see https://www.drupal.org/project/unlimited_field_settings
        'Drupal Canvas does not know of a field type/widget to allow populating the <code>data</code> prop, with the shape <code>{"type":"array","items":{"type":"integer","minimum":-100,"maximum":100},"maxItems":100,"minItems":2}</code>.',
      ],
    ], $this->findIneligibleComponents(SingleDirectoryComponent::SOURCE_PLUGIN_ID, 'canvas_test_sdc'));
    self::assertSame([
      'sdc.sdc_theme_test.css-load-order' => ['Prop "dummy" must have title'],
      'sdc.sdc_theme_test.foo' => ['Prop "prop1" must have title'],
    ], $this->findIneligibleComponents(SingleDirectoryComponent::SOURCE_PLUGIN_ID, 'sdc_theme_test'));
    $auto_created_components = [
      ...$this->findCreatedComponentConfigEntities(SingleDirectoryComponent::SOURCE_PLUGIN_ID, 'canvas_test_sdc'),
      ...$this->findCreatedComponentConfigEntities(SingleDirectoryComponent::SOURCE_PLUGIN_ID, 'sdc_theme_test'),
    ];
    self::assertSame([
      'sdc.canvas_test_sdc.attributes',
      'sdc.canvas_test_sdc.banner',
      'sdc.canvas_test_sdc.card',
      'sdc.canvas_test_sdc.card-with-local-image',
      'sdc.canvas_test_sdc.card-with-remote-image',
      'sdc.canvas_test_sdc.card-with-stream-wrapper-image',
      'sdc.canvas_test_sdc.columns',
      'sdc.canvas_test_sdc.component-no-meta-enum',
      'sdc.canvas_test_sdc.crash',
      'sdc.canvas_test_sdc.deprecated',
      'sdc.canvas_test_sdc.druplicon',
      'sdc.canvas_test_sdc.experimental',
      'sdc.canvas_test_sdc.grid-container',
      'sdc.canvas_test_sdc.heading',
      'sdc.canvas_test_sdc.image',
      'sdc.canvas_test_sdc.image-gallery',
      'sdc.canvas_test_sdc.image-optional-with-example',
      'sdc.canvas_test_sdc.image-optional-with-example-and-additional-prop',
      'sdc.canvas_test_sdc.image-optional-without-example',
      'sdc.canvas_test_sdc.image-required-with-example',
      'sdc.canvas_test_sdc.my-cta',
      'sdc.canvas_test_sdc.my-hero',
      'sdc.canvas_test_sdc.my-section',
      'sdc.canvas_test_sdc.one_column',
      'sdc.canvas_test_sdc.props-no-slots',
      'sdc.canvas_test_sdc.props-slots',
      'sdc.canvas_test_sdc.shoe_badge',
      'sdc.canvas_test_sdc.shoe_tab',
      'sdc.canvas_test_sdc.shoe_tab_group',
      'sdc.canvas_test_sdc.shoe_tab_panel',
      'sdc.canvas_test_sdc.sparkline',
      'sdc.canvas_test_sdc.tags',
      'sdc.canvas_test_sdc.two_column',
      'sdc.canvas_test_sdc.video',
      'sdc.sdc_theme_test.bar',
      'sdc.sdc_theme_test.lib-overrides',
      'sdc.sdc_theme_test.my-card',
      'sdc.sdc_theme_test_base.my-card-no-schema',
    ], $auto_created_components);

    return array_combine($auto_created_components, $auto_created_components);
  }

  /**
   * Tests the shape-matched `prop_field_definitions` for the eligible SDCs.
   *
   * @depends testDiscovery
   */
  public function testSettings(array $component_ids): void {
    $settings = $this->getAllSettings($component_ids);
    self::assertMatchesYamlSnapshot($settings);

    // Slightly more scrutiny for ComponentSources with a generated field-based
    // input UX: verifying this results in working `StaticPropSource`s is
    // sufficient, everything beyond that is covered by PropShapeRepositoryTest.
    // @see \Drupal\Tests\canvas\Kernel\PropShapeRepositoryTest::testPropShapesYieldWorkingStaticPropSources()
    // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase
    $components = $this->componentStorage->loadMultiple($component_ids);
    foreach ($components as $component_id => $component) {
      // Use reflection to test the private ::getDefaultStaticPropSource() method.
      assert($component instanceof Component);
      $source = $component->getComponentSource();
      $private_method = new \ReflectionMethod($source, 'getDefaultStaticPropSource');
      $private_method->setAccessible(TRUE);
      foreach (array_keys($settings[$component_id]['prop_field_definitions']) as $prop) {
        $static_prop_source = $private_method->invoke($source, $prop, TRUE);
        $this->assertInstanceOf(StaticPropSource::class, $static_prop_source);
      }
    }
  }

  /**
   * Tests that all SDCs use the SdcPlugin class.
   *
   * @param array<ComponentConfigEntityId> $component_ids
   *
   * @covers ::getReferencedPluginClass()
   * @depends testDiscovery
   */
  public function testGetReferencedPluginClass(array $component_ids): void {
    self::assertSame(
      // All SDCs use the same plugin class!
      array_fill_keys($component_ids, SdcPlugin::class),
      $this->getReferencedPluginClasses($component_ids)
    );
  }

  /**
   * Tests rendering an SDC component.
   *
   * @param array<ComponentConfigEntityId> $component_ids
   *
   * @covers ::renderComponent()
   * @depends testDiscovery
   */
  public function testRenderComponentLive(array $component_ids): void {
    $this->installEntitySchema('node');
    $this->installConfig('node');
    $this->createContentType(['type' => 'article']);
    $this->assertNotEmpty($component_ids);

    $rendered = $this->renderComponentsLive(
      $component_ids,
      self::getDefaultInputForGeneratedInputUx(...),
    );
    // Convert the CacheMetadata objects to arrays, which is how they are
    // encoded in the snapshot we're comparing against.
    foreach ($rendered as &$r) {
      $r['cacheability']->applyTo($r);
      $r['cacheability'] = $r['#cache'];
      unset($r['#cache']);
    }
    self::assertMatchesYamlSnapshot($rendered);
  }

  /**
   * Tests that relative file URLs are rewritten to reference the correct file path.
   */
  public function testRewriteExampleUrl(): void {
    $plugin = \Drupal::service(ComponentPluginManager::class)->createInstance('canvas_test_sdc:image');
    $component = SingleDirectoryComponent::createConfigEntity($plugin);
    $source = $component->getComponentSource();
    self::assertInstanceOf(SingleDirectoryComponent::class, $source);
    // Assert that existing files are rewritten to include the module path.
    $canvas_test_sdc_module_path = \Drupal::service(ModuleExtensionList::class)->getPath('canvas_test_sdc');

    self::assertStringEndsWith($canvas_test_sdc_module_path . '/components/image/600x400.png', $source->rewriteExampleUrl('600x400.png'));
    self::assertStringEndsWith($canvas_test_sdc_module_path . '/components/image/600x400.png', $source->rewriteExampleUrl('/600x400.png'));
    self::assertStringEndsWith('/tests/fixtures/600x400.png', $source->rewriteExampleUrl('../../tests/fixtures/600x400.png'));

    // Assert that non-existing links have a leading slash but do not include the module nor SDC path.
    $url = $source->rewriteExampleUrl('test/path');
    self::assertStringEndsWith('/test/path', $url);
    self::assertStringNotContainsString($canvas_test_sdc_module_path, $url);
    self::assertStringNotContainsString('components', $url);

    // Assert that non-existing links with a leading slash are not doubled.
    $url = $source->rewriteExampleUrl('/test/path');
    self::assertStringEndsWith('/test/path', $url);
    self::assertStringNotContainsString('//', $url);

    // Assert that full URLs are left alone.
    self::assertSame('https://www.example.com/', $source->rewriteExampleUrl('https://www.example.com/'));
  }

  /**
   * @covers ::getExplicitInput()
   * @dataProvider providerComponentResolving
   */
  public function testGetExplicitInput(array $component_item_value, array $expected_props_for_uuids, ?array $permissions = NULL): void {
    $this->generateComponentConfig();
    $this->installEntitySchema('node');
    $this->container->get('module_installer')->install(['canvas_test_config_node_article']);
    $node = Node::create([
      'title' => 'Test node',
      'type' => 'article',
      'field_canvas_test' => $component_item_value,
    ]);
    $canvas_field_item = $node->field_canvas_test[0];
    if ($permissions !== NULL) {
      // If we are setting permissions to check access, we need to save the node,
      // but we cannot use $permissions for the user saving the node because we
      // may be testing insufficient permissions. So we temporarily set a user with
      // 'access content' permission to save the node, then use the permissions
      // we are testing with.
      $this->setUpCurrentUser(permissions: ['access content']);
      $node->save();
      $this->setUpCurrentUser(permissions: $permissions);
    }

    $this->assertInstanceOf(ComponentTreeItem::class, $canvas_field_item);
    $actual_props = array_combine(
      array_keys($expected_props_for_uuids),
      array_map(
        fn (string $uuid) => $canvas_field_item->getComponent()?->getComponentSource()->getExplicitInput($uuid, $canvas_field_item)['resolved'],
        array_keys($expected_props_for_uuids)
      )
    );
    $this->assertSame($expected_props_for_uuids, $actual_props);
  }

  public static function providerComponentResolving(): array {
    $test_cases = static::getValidTreeTestCases();
    $invalid_test_cases = static::getInvalidTreeTestCases();
    // Only 1 invalid case will allow to call
    // \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem::resolveComponentProps()
    // without an exception.
    $test_cases['invalid UUID, missing component_id key'] = $invalid_test_cases['invalid UUID, missing component_id key'];
    $test_cases['invalid UUID, missing component_id key'][] = [];
    $test_cases['valid values using static inputs'][] = [
      'dynamic-static-card2df' => [
        'heading' => 'They say I am static, but I want to believe I can change!',
      ],
    ];
    $test_cases['valid values for propless component'][] = [
      'propless-component-uuid' => [],
    ];
    $test_cases['valid value for optional explicit input using an URL prop shape, with default value'][] = [
      'optional-url-with-default-value' => [
        'heading' => 'Gracie says hi!',
        'image' => [
          'src' => self::getCiModulePath() . '/tests/modules/canvas_test_sdc/components/image-optional-with-example-and-additional-prop/gracie.jpg',
          'alt' => 'A good dog',
          'width' => 601,
          'height' => 402,
        ],
      ],
    ];
    $hero_with_dynamic_sources = [
      'uuid' => 'partly-dynamic-hero',
      'component_id' => 'sdc.canvas_test_sdc.my-hero',
      'component_version' => '888412021fbcc837',
      'inputs' => [
        'heading' => [
          'sourceType' => 'static:field_item:string',
          'value' => 'hello, world!',
          'expression' => 'ℹ︎string␟value',
        ],
        'subheading' => [
          'sourceType' => 'dynamic',
          'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
        ],
        'cta1href' => [
          'sourceType' => 'static:field_item:uri',
          'value' => 'https://drupal.org',
          'expression' => 'ℹ︎uri␟value',
        ],
        'cta1' => [
          'sourceType' => 'dynamic',
          'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
        ],
      ],
    ];
    $test_cases['values values, dynamic sources WITH access'] = [
      [
        $hero_with_dynamic_sources,
      ],
      [
        'partly-dynamic-hero' => [
          'heading' => 'hello, world!',
          'subheading' => 'Test node',
          'cta1href' => 'https://drupal.org',
          'cta1' => 'Test node',
        ],
      ],
      ['access content'],
    ];
    $test_cases['values values, dynamic sources WITHOUT access'] = [
      [
        $hero_with_dynamic_sources,
      ],
      [
        'partly-dynamic-hero' => [
          'heading' => 'hello, world!',
          'subheading' => NULL,
          'cta1href' => 'https://drupal.org',
          'cta1' => NULL,
        ],
      ],
      [],
    ];
    return $test_cases;
  }

  protected function generateCrashTestDummyComponentTree(string $component_id, array $inputs, bool $assertCount = TRUE): ComponentTreeItemList {
    if (str_starts_with($component_id, 'sdc.canvas_broken_sdcs.')) {
      // This component needs an extra module.
      $this->assertCount(0, $this->componentStorage->loadMultiple());
      \Drupal::service(ModuleInstallerInterface::class)->install(['canvas_broken_sdcs']);

      // Now call the parent, but don't assert the count of components, as we've
      // done it here, and installing a module will generate component config.
      return parent::generateCrashTestDummyComponentTree($component_id, $inputs, assertCount: FALSE);
    }
    return parent::generateCrashTestDummyComponentTree($component_id, $inputs);
  }

  public static function providerRenderComponentFailure(): \Generator {
    yield "SDC with valid props, without exception" => [
      'component_id' => 'sdc.canvas_test_sdc.crash',
      'inputs' => [
        'crash' => FALSE,
      ],
      'expected_validation_errors' => [],
      'expected_exception' => NULL,
      'expected_output_selector' => 'h1:contains("test")',
    ];

    yield "SDC with valid props, with exception" => [
      'component_id' => 'sdc.canvas_test_sdc.crash',
      'inputs' => [
        'crash' => TRUE,
      ],
      'expected_validation_errors' => [],
      'expected_exception' => [
        'class' => Error::class,
        'message' => 'Intentional test exception in "canvas_test_sdc:crash" at line 2.',
      ],
      'expected_output_selector' => NULL,
    ];

    yield "SDC with invalid prop, with exception" => [
      'component_id' => 'sdc.canvas_test_sdc.crash',
      'inputs' => [
        'crash' => [
          'sourceType' => "static:field_item:string",
          'value' => 'this is an invalid value for the SDC prop',
          'expression' => (string) new FieldTypePropExpression('string', 'value'),
        ],
      ],
      'expected_validation_errors' => [
        \sprintf('2.inputs.%s.crash', self::UUID_CRASH_TEST_DUMMY) => 'String value found, but a boolean or an object is required. The provided value is: "this is an invalid value for the SDC prop".',
      ],
      'expected_exception' => [
        'class' => RuntimeError::class,
        'message' => 'An exception has been thrown during the rendering of a template ("[canvas_test_sdc:crash/crash] String value found, but a boolean or an object is required. The provided value is: "this is an invalid value for the SDC prop".") in "canvas_test_sdc:crash" at line 1.',
      ],
      'expected_output_selector' => NULL,
    ];

    // @see \Drupal\canvas\Validation\JsonSchema\UriSchemeAwareFormatConstraint
    yield "SDC with invalid value for `type: string, format: uri, x-allowed-schemes: ['public']`-shaped prop " => [
      'component_id' => 'sdc.canvas_test_sdc.card-with-stream-wrapper-image',
      'inputs' => [
        'alt' => 'Majestic creature',
        // Do not use the default StaticPropSource: the `image` field type. Use
        // the `uri` field type so we can test what happens if an invalid value
        // makes its way to the point where the SDC is rendered with a value not
        // complying with the JSON Schema for the SDC prop.
        'src' => [
          'sourceType' => "static:field_item:uri",
          'value' => 'https://example.com/llama.jpg',
          'expression' => (string) new FieldTypePropExpression('uri', 'value'),
        ],
      ],
      'expected_validation_errors' => [
        \sprintf('2.inputs.%s.src', self::UUID_CRASH_TEST_DUMMY) => 'The "https" URI scheme is not allowed. The provided value is: "https://example.com/llama.jpg".',
      ],
      'expected_exception' => [
        'class' => RuntimeError::class,
        'message' => 'An exception has been thrown during the rendering of a template ("[canvas_test_sdc:card-with-stream-wrapper-image/src] The "https" URI scheme is not allowed. The provided value is: "https://example.com/llama.jpg".") in "canvas_test_sdc:card-with-stream-wrapper-image" at line 1.',
      ],
      'expected_output_selector' => NULL,
    ];

    yield "SDC with missing prop, with exception" => [
      'component_id' => 'sdc.canvas_test_sdc.crash',
      'inputs' => [],
      'expected_validation_errors' => [
        \sprintf('2.inputs.%s.crash', self::UUID_CRASH_TEST_DUMMY) => 'The property crash is required.',
      ],
      'expected_exception' => [
        'class' => RuntimeError::class,
        'message' => 'An exception has been thrown during the rendering of a template ("[canvas_test_sdc:crash/crash] The property crash is required.") in "canvas_test_sdc:crash" at line 1.',
      ],
      'expected_output_selector' => NULL,
    ];

    yield "SDC with invalid Twig (due to filter)" => [
      'component_id' => 'sdc.canvas_broken_sdcs.invalid-filter',
      'inputs' => [
        'name' => 'Gaia',
      ],
      'expected_validation_errors' => [],
      'expected_exception' => [
        'class' => SyntaxError::class,
        'message' => 'Unknown "invalidFilter" filter',
      ],
      'expected_output_selector' => NULL,
    ];

    yield "SDC with valid prop, but invalid Twig (due to printing an object-shaped prop)" => [
      'component_id' => 'sdc.canvas_broken_sdcs.malformed-image',
      'inputs' => [
        'image' => [
          // TRICKY: Intentionally use a StaticPropSource powered by the `uri`
          // field type (instead of the `image` field type), because it allows
          // this test to specify an arbitrary URL as the image URL, instead of
          // having to create a File entity that is referenced by the `image`
          // field. Hence also use a FieldTypeObjectPropsExpression to transform
          // it to the `type: object` prop shape expected by the SDC.
          'sourceType' => "static:field_item:uri",
          'value' => 'https://example.com/llama.jpg',
          'expression' => 'ℹ︎uri␟{src↠value}',
        ],
      ],
      // Note there's no validation error: the evaluated StaticPropSource yields
      // a valid value for the SDC prop.
      'expected_validation_errors' => [],
      'expected_exception' => [
        'class' => RuntimeError::class,
        'message' => 'An exception has been thrown during the rendering of a template (""src" is an invalid render array key. Value should be an array but got a string.") in "canvas_broken_sdcs:malformed-image" at line 2.',
      ],
      'expected_output_selector' => NULL,
    ];
  }

  /**
   * @covers ::calculateDependencies()
   * @depends testDiscovery
   */
  public function testCalculateDependencies(array $component_ids): void {
    $dependencies = $this->callSourceMethodForEach('calculateDependencies', $component_ids);
    self::assertMatchesYamlSnapshot($dependencies);
  }

  /**
   * {@inheritdoc}
   */
  public static function getExpectedClientSideInfo(): array {
    return [
      'sdc.canvas_test_sdc.attributes' => [
        'expected_output_selectors' => [
          'div>div:contains("The not-attributes SDC prop!")',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'not_attributes' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => ['value' => 'The not-attributes SDC prop!'],
              ],
              'resolved' => 'The not-attributes SDC prop!',
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.banner' => [
        'expected_output_selectors' => [
          'article.banner',
          'article.banner img.banner--image',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'heading' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'My banner title',
                ],
              ],
              'resolved' => 'My banner title',
            ],
          ],
          'text' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
              'contentMediaType' => 'text/html',
              'x-formatting-context' => 'block',
            ],
            'sourceType' => 'static:field_item:text_long',
            'expression' => 'ℹ︎text_long␟processed',
            'sourceTypeSettings' => [
              'instance' => [
                'allowed_formats' => [
                  'canvas_html_block',
                ],
              ],
            ],
            'default_values' => [
              'source' => [
                0 => [
                  'value' => '<p>In a curious work, published in <em>Paris</em> in 1863 by <strong>Delaville Dedreux</strong>, there is a suggestion for reaching the North Pole by an aerostat.</p>',
                  'format' => 'canvas_html_block',
                ],
              ],
              'resolved' => '<p>In a curious work, published in <em>Paris</em> in 1863 by <strong>Delaville Dedreux</strong>, there is a suggestion for reaching the North Pole by an aerostat.</p>',
            ],
          ],
          'image' => [
            'required' => FALSE,
            'jsonSchema' => [
              'title' => 'image',
              'type' => 'object',
              'required' => [
                0 => 'src',
              ],
              'properties' => [
                'src' => [
                  'title' => 'Image URL',
                  'type' => 'string',
                  'format' => 'uri-reference',
                  'contentMediaType' => 'image/*',
                  'x-allowed-schemes' => ['http', 'https'],
                ],
                'alt' => [
                  'title' => 'Alternative text',
                  'type' => 'string',
                ],
                'width' => [
                  'title' => 'Image width',
                  'type' => 'integer',
                ],
                'height' => [
                  'title' => 'Image height',
                  'type' => 'integer',
                ],
              ],
            ],
            'sourceType' => 'static:field_item:image',
            'expression' => 'ℹ︎image␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
            'default_values' => [
              'source' => [],
              'resolved' => [
                'src' => '/' . \Drupal::service(ExtensionPathResolver::class)->getPath('module', 'canvas_test_sdc') . '/components/banner/balloons.png',
                'alt' => 'Hot air balloons',
                'width' => 640,
                'height' => 427,
              ],
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.card' => [
        'expected_output_selectors' => [
          'article.card',
          'article.card img.card--image',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'heading' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'Card',
                ],
              ],
              'resolved' => 'Card',
            ],
          ],
          'content' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'In a curious work, published in Paris in 1863 by Delaville Dedreux, there is a suggestion for reaching the North Pole by an aerostat.',
                ],
              ],
              'resolved' => 'In a curious work, published in Paris in 1863 by Delaville Dedreux, there is a suggestion for reaching the North Pole by an aerostat.',
            ],
          ],
          'footer' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'I have a footer!',
                ],
              ],
              'resolved' => 'I have a footer!',
            ],
          ],
          'image' => [
            'required' => TRUE,
            'jsonSchema' => [
              'title' => 'image',
              'type' => 'object',
              'required' => [
                0 => 'src',
              ],
              'properties' => [
                'src' => [
                  'title' => 'Image URL',
                  'type' => 'string',
                  'format' => 'uri-reference',
                  'contentMediaType' => 'image/*',
                  'x-allowed-schemes' => ['http', 'https'],
                ],
                'alt' => [
                  'title' => 'Alternative text',
                  'type' => 'string',
                ],
                'width' => [
                  'title' => 'Image width',
                  'type' => 'integer',
                ],
                'height' => [
                  'title' => 'Image height',
                  'type' => 'integer',
                ],
              ],
            ],
            'sourceType' => 'static:field_item:image',
            'expression' => 'ℹ︎image␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
            'default_values' => [
              'source' => [],
              'resolved' => [
                'src' => '/' . \Drupal::service(ExtensionPathResolver::class)->getPath('module', 'canvas_test_sdc') . '/components/card/balloons.png',
                'alt' => 'Hot air balloons',
                'width' => 640,
                'height' => 427,
              ],
            ],
          ],
          'sizes' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'auto 50vw',
                ],
              ],
              'resolved' => 'auto 50vw',
            ],
          ],
          'loading' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
              'enum' => [
                0 => 'lazy',
                1 => 'eager',
              ],
            ],
            'sourceType' => 'static:field_item:list_string',
            'expression' => 'ℹ︎list_string␟value',
            'sourceTypeSettings' => [
              'storage' => [
                'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
              ],
            ],
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'eager',
                ],
              ],
              'resolved' => 'eager',
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.card-with-local-image' => [
        'expected_output_selectors' => [
          'article.card--with-local-image',
          'article.card--with-local-image img.card--image',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'heading' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'Card with local image',
                ],
              ],
              'resolved' => 'Card with local image',
            ],
          ],
          'content' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'In a curious work, published in Paris in 1863 by Delaville Dedreux, there is a suggestion for reaching the North Pole by an aerostat.',
                ],
              ],
              'resolved' => 'In a curious work, published in Paris in 1863 by Delaville Dedreux, there is a suggestion for reaching the North Pole by an aerostat.',
            ],
          ],
          'footer' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'I have a footer!',
                ],
              ],
              'resolved' => 'I have a footer!',
            ],
          ],
          'src' => [
            'required' => TRUE,
            'jsonSchema' => [
              'title' => 'Image URL',
              'type' => 'string',
              'format' => 'uri-reference',
              'contentMediaType' => 'image/*',
              'x-allowed-schemes' => ['http', 'https'],
            ],
            'sourceType' => 'static:field_item:image',
            'expression' => 'ℹ︎image␟src_with_alternate_widths',
            'default_values' => [
              'source' => [],
              'resolved' => '/core/misc/druplicon.png',
            ],
          ],
          'alt' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                [
                  'value' => 'A classic druplicon',
                ],
              ],
              'resolved' => 'A classic druplicon',
            ],
          ],
          'loading' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
              'enum' => [
                0 => 'lazy',
                1 => 'eager',
              ],
            ],
            'sourceType' => 'static:field_item:list_string',
            'expression' => 'ℹ︎list_string␟value',
            'sourceTypeSettings' => [
              'storage' => [
                'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
              ],
            ],
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'lazy',
                ],
              ],
              'resolved' => 'lazy',
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.card-with-remote-image' => [
        'expected_output_selectors' => [
          'article.card--with-remote-image',
          'article.card--with-remote-image img.card--image',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'heading' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'Card with remote image',
                ],
              ],
              'resolved' => 'Card with remote image',
            ],
          ],
          'content' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'In a curious work, published in Paris in 1863 by Delaville Dedreux, there is a suggestion for reaching the North Pole by an aerostat.',
                ],
              ],
              'resolved' => 'In a curious work, published in Paris in 1863 by Delaville Dedreux, there is a suggestion for reaching the North Pole by an aerostat.',
            ],
          ],
          'footer' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'I have a footer!',
                ],
              ],
              'resolved' => 'I have a footer!',
            ],
          ],
          'src' => [
            'required' => TRUE,
            'jsonSchema' => [
              'title' => 'Image URL',
              'type' => 'string',
              'format' => 'uri-reference',
              'contentMediaType' => 'image/*',
              'x-allowed-schemes' => ['http', 'https'],
            ],
            'sourceType' => 'static:field_item:image',
            'expression' => 'ℹ︎image␟src_with_alternate_widths',
            'default_values' => [
              'source' => [],
              'resolved' => 'https://mdn.github.io/shared-assets/images/examples/balloons.jpg',
            ],
          ],
          'alt' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                [
                  'value' => 'Hot air balloons',
                ],
              ],
              'resolved' => 'Hot air balloons',
            ],
          ],
          'width' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'integer',
            ],
            'sourceType' => 'static:field_item:integer',
            'expression' => 'ℹ︎integer␟value',
            'default_values' => [
              'source' => [
                [
                  'value' => 640,
                ],
              ],
              'resolved' => 640,
            ],
          ],
          'height' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'integer',
            ],
            'sourceType' => 'static:field_item:integer',
            'expression' => 'ℹ︎integer␟value',
            'default_values' => [
              'source' => [
                [
                  'value' => 427,
                ],
              ],
              'resolved' => 427,
            ],
          ],
          'loading' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
              'enum' => [
                0 => 'lazy',
                1 => 'eager',
              ],
            ],
            'sourceType' => 'static:field_item:list_string',
            'expression' => 'ℹ︎list_string␟value',
            'sourceTypeSettings' => [
              'storage' => [
                'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
              ],
            ],
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'lazy',
                ],
              ],
              'resolved' => 'lazy',
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.card-with-stream-wrapper-image' => [
        'expected_output_selectors' => [
          'article.card--with-stream-wrapper-image',
          'article.card--with-stream-wrapper-image img.card--image',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'heading' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'Card with stream wrapper',
                ],
              ],
              'resolved' => 'Card with stream wrapper',
            ],
          ],
          'content' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'In a curious work, published in Paris in 1863 by Delaville Dedreux, there is a suggestion for reaching the North Pole by an aerostat.',
                ],
              ],
              'resolved' => 'In a curious work, published in Paris in 1863 by Delaville Dedreux, there is a suggestion for reaching the North Pole by an aerostat.',
            ],
          ],
          'footer' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'I have a footer!',
                ],
              ],
              'resolved' => 'I have a footer!',
            ],
          ],
          'src' => [
            'required' => TRUE,
            'jsonSchema' => [
              'title' => 'Stream wrapper image URI',
              'type' => 'string',
              'format' => 'uri',
              'contentMediaType' => 'image/*',
              'x-allowed-schemes' => ['public'],
            ],
            'sourceType' => 'static:field_item:image',
            'expression' => 'ℹ︎image␟entity␜␜entity:file␝uri␞␟value',
            'default_values' => [
              'source' => [],
              'resolved' => 'public://balloons.png',
            ],
          ],
          'alt' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                [
                  'value' => 'Hot air balloons',
                ],
              ],
              'resolved' => 'Hot air balloons',
            ],
          ],
          'loading' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
              'enum' => [
                0 => 'lazy',
                1 => 'eager',
              ],
            ],
            'sourceType' => 'static:field_item:list_string',
            'expression' => 'ℹ︎list_string␟value',
            'sourceTypeSettings' => [
              'storage' => [
                'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
              ],
            ],
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'lazy',
                ],
              ],
              'resolved' => 'lazy',
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.columns' => [
        'expected_output_selectors' => [
          // TRICKY: there's no content to assert, because the preview by
          // default doe snot use slot values.
          'div[data-component-id="canvas_test_sdc:columns"]',
        ],
        'source' => 'Module component',
        'metadata' => [
          'slots' => [
            'column_1' => [
              'title' => 'Column One',
              'description' => 'The contents of the first column.',
              'examples' => ['<p>This is column 1 content</p>'],
            ],
            'column_2' => [
              'title' => 'Column Two',
              'description' => 'The contents of the second column.',
              'examples' => ['<p>This is column 2 content</p>'],
            ],
            'column_3' => [
              'title' => 'Column Three',
              'description' => 'The contents of the third column.',
              'examples' => ['<p>This is column 3 content</p>'],
            ],
            'column_4' => [
              'title' => 'Column Four',
              'description' => 'The contents of the fourth column.',
              'examples' => ['<p>This is column 4 content</p>'],
            ],
            'column_5' => [
              'title' => 'Column Five',
              'description' => 'The contents of the fifth column.',
              'examples' => ['<p>This is column 5 content</p>'],
            ],
            'column_6' => [
              'title' => 'Column Six',
              'description' => 'The contents of the sixth column.',
              'examples' => ['<p>This is column 6 content</p>'],
            ],
          ],
        ],
        'propSources' => [
          'columns' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'integer',
              'enum' => [1, 2, 3, 4, 5, 6],
            ],
            'sourceType' => 'static:field_item:list_integer',
            'expression' => 'ℹ︎list_integer␟value',
            'sourceTypeSettings' => [
              'storage' => [
                'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
              ],
            ],
            'default_values' => [
              'source' => [
                0 => ['value' => 2],
              ],
              'resolved' => 2,
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.component-no-meta-enum' => [
        'expected_output_selectors' => [
          'span:contains("me")',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'style' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
              'enum' => [
                'small',
                'big',
                'huge',
              ],
            ],
            'sourceType' => 'static:field_item:list_string',
            'expression' => 'ℹ︎list_string␟value',
            'sourceTypeSettings' => [
              'storage' => [
                'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
              ],
            ],
            'default_values' => [
              'source' => [
                0 => ['value' => 'small'],
              ],
              'resolved' => 'small',
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.crash' => [
        'expected_output_selectors' => [
          'h1:contains("test")',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'crash' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'boolean',
            ],
            'sourceType' => 'static:field_item:boolean',
            'expression' => 'ℹ︎boolean␟value',
            'default_values' => [
              'source' => [
                0 => ['value' => FALSE],
              ],
              'resolved' => FALSE,
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.deprecated' => [
        'expected_output_selectors' => [
          'h1:contains("Deprecated SDC component")',
          'div[data-component-id="canvas_test_sdc:deprecated"]',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'text' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => ['value' => 'A text field'],
              ],
              'resolved' => 'A text field',
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.druplicon' => [
        'expected_output_selectors' => [
          'svg',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.experimental' => [
        'expected_output_selectors' => [
          'h1:contains("Experimental SDC component")',
          'div[data-component-id="canvas_test_sdc:experimental"]',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'text' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => ['value' => 'A text field'],
              ],
              'resolved' => 'A text field',
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.grid-container' => [
        'expected_output_selectors' => [
          'div[data-component-id="canvas_test_sdc:grid-container"]',
        ],
        'source' => 'Module component',
        'metadata' => [
          'slots' => [
            'content' => [
              'title' => 'Content',
              'description' => 'The contents of the grid container.',
              'examples' => [
                '<div class="empty-slot">Empty grid slot</div>',
              ],
            ],
          ],
        ],
        'propSources' => [
          'direction' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
              'enum' => [
                0 => 'horizontal',
                1 => 'vertical',
              ],
            ],
            'sourceType' => 'static:field_item:list_string',
            'expression' => 'ℹ︎list_string␟value',
            'sourceTypeSettings' => [
              'storage' => [
                'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
              ],
            ],
            'default_values' => [
              'source' => [
                0 => ['value' => 'horizontal'],
              ],
              'resolved' => 'horizontal',
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.heading' => [
        'expected_output_selectors' => [
          'h1:contains("A heading element")',
        ],
        'source' => 'Module component',
        'metadata' => [
          'slots' => [],
        ],
        'propSources' => [
          'text' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'A heading element',
                ],
              ],
              'resolved' => 'A heading element',
            ],
          ],
          'style' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
              'enum' => [
                0 => 'primary',
                1 => 'secondary',
              ],
            ],
            'sourceType' => 'static:field_item:list_string',
            'expression' => 'ℹ︎list_string␟value',
            'sourceTypeSettings' => [
              'storage' => [
                'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
              ],
            ],
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'primary',
                ],
              ],
              'resolved' => 'primary',
            ],
          ],
          'element' => [
            'required' => TRUE,
            'jsonSchema' => [
              'title' => 'Heading element',
              'type' => 'string',
              'enum' => [
                0 => 'div',
                1 => 'h1',
                2 => 'h2',
                3 => 'h3',
                4 => 'h4',
                5 => 'h5',
                6 => 'h6',
              ],
            ],
            'sourceType' => 'static:field_item:list_string',
            'expression' => 'ℹ︎list_string␟value',
            'sourceTypeSettings' => [
              'storage' => [
                'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
              ],
            ],
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'h1',
                ],
              ],
              'resolved' => 'h1',
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.image' => [
        'expected_output_selectors' => [
          'img',
        ],
        'source' => 'Module component',
        'metadata' => [
          'slots' => [],
        ],
        'propSources' => [
          'image' => [
            'required' => TRUE,
            'jsonSchema' => [
              'title' => 'image',
              'type' => 'object',
              'required' => [
                0 => 'src',
              ],
              'properties' => [
                'src' => [
                  'title' => 'Image URL',
                  'type' => 'string',
                  'format' => 'uri-reference',
                  'contentMediaType' => 'image/*',
                  'x-allowed-schemes' => ['http', 'https'],
                ],
                'alt' => [
                  'title' => 'Alternative text',
                  'type' => 'string',
                ],
                'width' => [
                  'title' => 'Image width',
                  'type' => 'integer',
                ],
                'height' => [
                  'title' => 'Image height',
                  'type' => 'integer',
                ],
              ],
            ],
            'sourceType' => 'static:field_item:image',
            'expression' => 'ℹ︎image␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
            'default_values' => [
              'source' => [],
              'resolved' => [
                'src' => self::getCiModulePath() . '/tests/modules/canvas_test_sdc/components/image/600x400.png',
                'alt' => 'Boring placeholder',
                'width' => 600,
                'height' => 400,
              ],
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.image-gallery' => [
        'expected_output_selectors' => [
          'figure > img[src="' . self::getCiModulePath() . '/tests/modules/canvas_test_sdc/components/image-gallery/gracie.jpg"][alt="A good dog"]',
          'figure > img[src="' . self::getCiModulePath() . '/tests/modules/canvas_test_sdc/components/image-gallery/gracie.jpg"][alt="Still a good dog"]',
          'figure > img[src="' . self::getCiModulePath() . '/tests/modules/canvas_test_sdc/components/image-gallery/UPPERCASE-GRACIE.JPG"][alt="THE BEST DOG!"]',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'caption' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
          ],
          'images' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'array',
              'items' => [
                'title' => 'image',
                'type' => 'object',
                'required' => [
                  'src',
                ],
                'properties' => [
                  'src' => [
                    'title' => 'Image URL',
                    'type' => 'string',
                    'format' => 'uri-reference',
                    'contentMediaType' => 'image/*',
                    'x-allowed-schemes' => ['http', 'https'],
                  ],
                  'alt' => [
                    'title' => 'Alternative text',
                    'type' => 'string',
                  ],
                  'width' => [
                    'title' => 'Image width',
                    'type' => 'integer',
                  ],
                  'height' => [
                    'title' => 'Image height',
                    'type' => 'integer',
                  ],
                ],
              ],
            ],
            'sourceType' => 'static:field_item:image',
            'expression' => 'ℹ︎image␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
            'sourceTypeSettings' => [
              'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
            ],
            'default_values' => [
              'source' => [],
              'resolved' => [
                0 => [
                  'src' => self::getCiModulePath() . '/tests/modules/canvas_test_sdc/components/image-gallery/gracie.jpg',
                  'alt' => 'A good dog',
                  'width' => 601,
                  'height' => 402,
                ],
                1 => [
                  'src' => self::getCiModulePath() . '/tests/modules/canvas_test_sdc/components/image-gallery/gracie.jpg',
                  'alt' => 'Still a good dog',
                  'width' => 601,
                  'height' => 402,
                ],
                2 => [
                  'src' => self::getCiModulePath() . '/tests/modules/canvas_test_sdc/components/image-gallery/UPPERCASE-GRACIE.JPG',
                  'alt' => 'THE BEST DOG!',
                  'width' => 601,
                  'height' => 402,
                ],
              ],
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.image-optional-with-example' => [
        'expected_output_selectors' => [
          'img[src="https://example.com/cat.jpg"]',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'image' => [
            'required' => FALSE,
            'jsonSchema' => [
              'title' => 'image',
              'type' => 'object',
              'required' => [
                'src',
              ],
              'properties' => [
                'src' => [
                  'title' => 'Image URL',
                  'type' => 'string',
                  'format' => 'uri-reference',
                  'contentMediaType' => 'image/*',
                  'x-allowed-schemes' => ['http', 'https'],
                ],
                'alt' => [
                  'title' => 'Alternative text',
                  'type' => 'string',
                ],
                'width' => [
                  'title' => 'Image width',
                  'type' => 'integer',
                ],
                'height' => [
                  'title' => 'Image height',
                  'type' => 'integer',
                ],
              ],
            ],
            'sourceType' => 'static:field_item:image',
            'expression' => 'ℹ︎image␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
            'default_values' => [
              'source' => [],
              'resolved' => [
                'src' => 'https://example.com/cat.jpg',
                'alt' => 'Boring placeholder',
                'width' => 600,
                'height' => 400,
              ],
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.image-optional-with-example-and-additional-prop' => [
        'expected_output_selectors' => [
          'img[src="' . self::getCiModulePath() . '/tests/modules/canvas_test_sdc/components/image-optional-with-example-and-additional-prop/gracie.jpg"]',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'heading' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
          ],
          'image' => [
            'required' => FALSE,
            'jsonSchema' => [
              'title' => 'image',
              'type' => 'object',
              'required' => [
                'src',
              ],
              'properties' => [
                'src' => [
                  'title' => 'Image URL',
                  'type' => 'string',
                  'format' => 'uri-reference',
                  'contentMediaType' => 'image/*',
                  'x-allowed-schemes' => ['http', 'https'],
                ],
                'alt' => [
                  'title' => 'Alternative text',
                  'type' => 'string',
                ],
                'width' => [
                  'title' => 'Image width',
                  'type' => 'integer',
                ],
                'height' => [
                  'title' => 'Image height',
                  'type' => 'integer',
                ],
              ],
            ],
            'sourceType' => 'static:field_item:image',
            'expression' => 'ℹ︎image␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
            'default_values' => [
              'source' => [],
              'resolved' => [
                'src' => self::getCiModulePath() . '/tests/modules/canvas_test_sdc/components/image-optional-with-example-and-additional-prop/gracie.jpg',
                'alt' => 'A good dog',
                'width' => 601,
                'height' => 402,
              ],
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.image-optional-without-example' => [
        'expected_output_selectors' => [],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'image' => [
            'required' => FALSE,
            'jsonSchema' => [
              'title' => 'image',
              'type' => 'object',
              'required' => [
                'src',
              ],
              'properties' => [
                'src' => [
                  'title' => 'Image URL',
                  'type' => 'string',
                  'format' => 'uri-reference',
                  'contentMediaType' => 'image/*',
                  'x-allowed-schemes' => ['http', 'https'],
                ],
                'alt' => [
                  'title' => 'Alternative text',
                  'type' => 'string',
                ],
                'width' => [
                  'title' => 'Image width',
                  'type' => 'integer',
                ],
                'height' => [
                  'title' => 'Image height',
                  'type' => 'integer',
                ],
              ],
            ],
            'sourceType' => 'static:field_item:image',
            'expression' => 'ℹ︎image␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.image-required-with-example' => [
        'expected_output_selectors' => [
          'img[src="https://example.com/cat.jpg"]',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'image' => [
            'required' => TRUE,
            'jsonSchema' => [
              'title' => 'image',
              'type' => 'object',
              'required' => [
                'src',
              ],
              'properties' => [
                'src' => [
                  'title' => 'Image URL',
                  'type' => 'string',
                  'format' => 'uri-reference',
                  'contentMediaType' => 'image/*',
                  'x-allowed-schemes' => ['http', 'https'],
                ],
                'alt' => [
                  'title' => 'Alternative text',
                  'type' => 'string',
                ],
                'width' => [
                  'title' => 'Image width',
                  'type' => 'integer',
                ],
                'height' => [
                  'title' => 'Image height',
                  'type' => 'integer',
                ],
              ],
            ],
            'sourceType' => 'static:field_item:image',
            'expression' => 'ℹ︎image␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
            'default_values' => [
              'source' => [],
              'resolved' => [
                'src' => 'https://example.com/cat.jpg',
                'alt' => 'Boring placeholder',
                'width' => 600,
                'height' => 400,
              ],
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.my-cta' => [
        'expected_output_selectors' => [
          'a:contains("Press")',
          'a[data-component-id="canvas_test_sdc:my-cta"]',
        ],
        'source' => 'Module component',
        'metadata' => [
          'slots' => [],
        ],
        'propSources' => [
          'text' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'Press',
                ],
              ],
              'resolved' => 'Press',
            ],
          ],
          'href' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
              'format' => 'uri',
            ],
            'sourceType' => 'static:field_item:link',
            'expression' => 'ℹ︎link␟url',
            'sourceTypeSettings' => [
              'instance' => [
                'title' => 0,
                'link_type' => LinkItemInterface::LINK_EXTERNAL,
              ],
            ],
            'default_values' => [
              'source' => [
                0 => [
                  'uri' => 'https://www.drupal.org',
                  'options' => [],
                ],
              ],
              'resolved' => 'https://www.drupal.org',
            ],
          ],
          'target' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
              'enum' => [
                0 => '_self',
                1 => '_blank',
              ],
            ],
            'sourceType' => 'static:field_item:list_string',
            'expression' => 'ℹ︎list_string␟value',
            'sourceTypeSettings' => [
              'storage' => [
                'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
              ],
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.my-hero' => [
        'expected_output_selectors' => [
          'h1:contains("There goes my hero")',
          'p:contains("Watch him as he goes!")',
          'div[data-component-id="canvas_test_sdc:my-hero"]',
        ],
        'source' => 'Module component',
        'metadata' => [
          'slots' => [],
        ],
        'propSources' => [
          'heading' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'There goes my hero',
                ],
              ],
              'resolved' => 'There goes my hero',
            ],
          ],
          'subheading' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'Watch him as he goes!',
                ],
              ],
              'resolved' => 'Watch him as he goes!',
            ],
          ],
          'cta1' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'View',
                ],
              ],
              'resolved' => 'View',
            ],
          ],
          'cta1href' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
              'format' => 'uri-reference',
            ],
            'sourceType' => 'static:field_item:link',
            'expression' => 'ℹ︎link␟url',
            'sourceTypeSettings' => [
              'instance' => [
                'title' => 0,
                'link_type' => LinkItemInterface::LINK_GENERIC,
              ],
            ],
            'default_values' => [
              'source' => [
                0 => [
                  'uri' => 'https://example.com',
                  'options' => [],
                ],
              ],
              'resolved' => 'https://example.com',
            ],
          ],
          'cta2' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'Click',
                ],
              ],
              'resolved' => 'Click',
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.my-section' => [
        'expected_output_selectors' => [
          'h2:contains("Our Mission")',
        ],
        'source' => 'Module component',
        'metadata' => [
          'slots' => [],
        ],
        'propSources' => [
          'text' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
              'minLength' => 2,
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'Our mission is to deliver the best products and services to our customers. We strive to exceed expectations and continuously improve our offerings.',
                ],
              ],
              'resolved' => 'Our mission is to deliver the best products and services to our customers. We strive to exceed expectations and continuously improve our offerings.',
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.one_column' => [
        'expected_output_selectors' => [
          'div[data-component-id="canvas_test_sdc:one_column"]',
        ],
        'source' => 'Module component',
        'metadata' => [
          'slots' => [
            'content' => [
              'title' => 'Content',
              'description' => 'The contents of the column.',
            ],
          ],
        ],
        'propSources' => [
          'width' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
              'enum' => [
                0 => 'full',
                1 => 'wide',
                2 => 'normal',
                3 => 'narrow',
              ],
            ],
            'sourceType' => 'static:field_item:list_string',
            'expression' => 'ℹ︎list_string␟value',
            'sourceTypeSettings' => [
              'storage' => [
                'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
              ],
            ],
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'full',
                ],
              ],
              'resolved' => 'full',
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.props-no-slots' => [
        'expected_output_selectors' => [
          'h1:contains("There goes my hero")',
          'div[data-component-id="canvas_test_sdc:props-no-slots"]',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'heading' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
              'minLength' => 2,
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => ['value' => 'There goes my hero'],
              ],
              'resolved' => 'There goes my hero',
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.props-slots' => [
        'expected_output_selectors' => [
          'div[data-component-id="canvas_test_sdc:props-slots"]',
          'h1:contains("There goes my hero")',
          'div.component--props-slots--body',
          'div.component--props-slots--footer',
          'div.component--props-slots--colophon',
        ],
        'source' => 'Module component',
        'metadata' => [
          'slots' => [
            'the_body' => [
              'title' => 'The Body',
              'description' => 'The contents of the body.',
              'examples' => [
                '<p>Example value for <strong>the_body</strong> slot in <strong>prop-slots</strong> component.</p>',
              ],
            ],
            'the_footer' => [
              'title' => 'The Footer',
              'description' => 'The contents of the footer.',
              'examples' => [
                'Example value for <strong>the_footer</strong>.',
              ],
            ],
            'the_colophon' => [
              'title' => 'The Colophon',
              'description' => 'The contents of the colophon.',
              'examples' => [],
            ],
          ],
        ],
        'propSources' => [
          'heading' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
              'minLength' => 2,
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => ['value' => 'There goes my hero'],
              ],
              'resolved' => 'There goes my hero',
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.shoe_badge' => [
        'expected_output_selectors' => [
          'sl-badge[data-component-variant="primary"]',
        ],
        'source' => 'Module component',
        'metadata' => [
          'slots' => [
            'content' => [
              'title' => 'Content',
              'description' => 'The contents of the badge.',
              'examples' => [
                0 => 'Badge',
              ],
            ],
          ],
        ],
        'propSources' => [
          'variant' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
              'enum' => [
                0 => 'primary',
                1 => 'success',
                2 => 'neutral',
                3 => 'warning',
                4 => 'danger',
              ],
            ],
            'sourceType' => 'static:field_item:list_string',
            'expression' => 'ℹ︎list_string␟value',
            'sourceTypeSettings' => [
              'storage' => [
                'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
              ],
            ],
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'primary',
                ],
              ],
              'resolved' => 'primary',
            ],
          ],
          'pill' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'boolean',
            ],
            'sourceType' => 'static:field_item:boolean',
            'expression' => 'ℹ︎boolean␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => TRUE,
                ],
              ],
              'resolved' => TRUE,
            ],
          ],
          'pulse' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'boolean',
            ],
            'sourceType' => 'static:field_item:boolean',
            'expression' => 'ℹ︎boolean␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => TRUE,
                ],
              ],
              'resolved' => TRUE,
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.shoe_tab' => [
        'expected_output_selectors' => [
          'sl-tab[data-component-id="canvas_test_sdc:shoe_tab"]',
        ],
        'source' => 'Module component',
        'metadata' => [
          'slots' => [],
        ],
        'propSources' => [
          'label' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'Tab 1',
                ],
              ],
              'resolved' => 'Tab 1',
            ],
          ],
          'panel' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'tab_1',
                ],
              ],
              'resolved' => 'tab_1',
            ],
          ],
          'active' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'boolean',
            ],
            'sourceType' => 'static:field_item:boolean',
            'expression' => 'ℹ︎boolean␟value',
          ],
          'closable' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'boolean',
            ],
            'sourceType' => 'static:field_item:boolean',
            'expression' => 'ℹ︎boolean␟value',
          ],
          'disabled' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'boolean',
            ],
            'sourceType' => 'static:field_item:boolean',
            'expression' => 'ℹ︎boolean␟value',
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.shoe_tab_group' => [
        'expected_output_selectors' => [
          'sl-tab-group[data-component-id="canvas_test_sdc:shoe_tab_group"]',
        ],
        'source' => 'Module component',
        'metadata' => [
          'slots' => [
            'tabs' => [
              'title' => 'Tab Nav',
              'description' => 'The tabs.',
            ],
            'tab_panels' => [
              'title' => 'Tab Panels',
              'description' => 'The tab panels.',
            ],
          ],
        ],
        'propSources' => [
          'placement' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
              'enum' => [
                0 => 'top',
                1 => 'bottom',
                2 => 'start',
                3 => 'end',
              ],
            ],
            'sourceType' => 'static:field_item:list_string',
            'expression' => 'ℹ︎list_string␟value',
            'sourceTypeSettings' => [
              'storage' => [
                'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
              ],
            ],
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'top',
                ],
              ],
              'resolved' => 'top',
            ],
          ],
          'activation' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'string',
              'enum' => [
                0 => 'auto',
                1 => 'manual',
              ],
            ],
            'sourceType' => 'static:field_item:list_string',
            'expression' => 'ℹ︎list_string␟value',
            'sourceTypeSettings' => [
              'storage' => [
                'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
              ],
            ],
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'auto',
                ],
              ],
              'resolved' => 'auto',
            ],
          ],
          'no_scroll' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'boolean',
            ],
            'sourceType' => 'static:field_item:boolean',
            'expression' => 'ℹ︎boolean␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => FALSE,
                ],
              ],
              'resolved' => FALSE,
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.shoe_tab_panel' => [
        'expected_output_selectors' => [
          'sl-tab-panel[data-component-id="canvas_test_sdc:shoe_tab_panel"]',
        ],
        'source' => 'Module component',
        'metadata' => [
          'slots' => [
            'content' => [
              'title' => 'Tab Content',
              'description' => 'The contents of the tab.',
              'examples' => [
                0 => '<p>This is tab content</p>',
              ],
            ],
          ],
        ],
        'propSources' => [
          'name' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 'tab_name',
                ],
              ],
              'resolved' => 'tab_name',
            ],
          ],
          'active' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'boolean',
            ],
            'sourceType' => 'static:field_item:boolean',
            'expression' => 'ℹ︎boolean␟value',
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.sparkline' => [
        'expected_output_selectors' => [
          'div.sparkline-container > svg > polygon[points="0,20 0,7.5 12.5,5 25,2.5 37.5,0 50,17.5 62.5,20 75,6.25 87.5,5.75 100,5.25 100,20"]',
        ],
        'source' => 'Module component',
        'metadata' => ['slots' => []],
        'propSources' => [
          'data' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'array',
              'items' => [
                'type' => 'integer',
                'minimum' => -100,
                'maximum' => 100,
              ],
              'maxItems' => 100,
            ],
            'sourceType' => 'static:field_item:integer',
            'expression' => 'ℹ︎integer␟value',
            'sourceTypeSettings' => [
              'instance' => ['min' => -100, 'max' => 100],
              'cardinality' => 100,
            ],
            'default_values' => [
              'source' => [
                0 => ['value' => 0],
                1 => ['value' => 10],
                2 => ['value' => 20],
                3 => ['value' => 30],
                4 => ['value' => -40],
                5 => ['value' => -50],
                6 => ['value' => 5],
                7 => ['value' => 7],
                8 => ['value' => 9],
              ],
              'resolved' => [
                0, 10, 20, 30, -40, -50, 5, 7, 9,
              ],
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.tags' => [
        'expected_output_selectors' => [
          'div.tag-list > span.tag:nth-child(3)',
        ],
        'source' => 'Module component',
        'metadata' => [
          'slots' => [],
        ],
        'propSources' => [
          'tags' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'array',
              'items' => [
                'type' => 'string',
              ],
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'sourceTypeSettings' => [
              'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
            ],
            'default_values' => [
              'source' => [
                0 => ['value' => 'foo'],
                1 => ['value' => 'bar'],
                2 => ['value' => 'baz'],
              ],
              'resolved' => ['foo', 'bar', 'baz'],
            ],
          ],
        ],
        'transforms' => [],
      ],

      'sdc.canvas_test_sdc.two_column' => [
        'expected_output_selectors' => [
          'div[data-component-id="canvas_test_sdc:two_column"]',
        ],
        'source' => 'Module component',
        'metadata' => [
          'slots' => [
            'column_one' => [
              'title' => 'Column One',
              'description' => 'The contents of the first column.',
              'examples' => [
                0 => '<p>This is column 1 content</p>',
              ],
            ],
            'column_two' => [
              'title' => 'Column Two',
              'description' => 'The contents of the second column.',
              'examples' => [
                0 => '<p>This is column 2 content</p>',
              ],
            ],
          ],
        ],
        'propSources' => [
          'width' => [
            'required' => TRUE,
            'jsonSchema' => [
              'title' => 'Column Width',
              'type' => 'integer',
              'enum' => [
                0 => 25,
                1 => 33,
                2 => 50,
                3 => 66,
                4 => 75,
              ],
            ],
            'sourceType' => 'static:field_item:list_integer',
            'expression' => 'ℹ︎list_integer␟value',
            'sourceTypeSettings' => [
              'storage' => [
                'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
              ],
            ],
            'default_values' => [
              'source' => [
                0 => [
                  'value' => 25,
                ],
              ],
              'resolved' => 25,
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.canvas_test_sdc.video' => [
        'expected_output_selectors' => [
          'video[poster="https://example.com/600x400.png"]',
          'video > source[src="https://media.istockphoto.com/id/1340051874/video/aerial-top-down-view-of-a-container-cargo-ship.mp4?s=mp4-640x640-is&k=20&c=5qPpYI7TOJiOYzKq9V2myBvUno6Fq2XM3ITPGFE8Cd8="]',
        ],
        'source' => 'Module component',
        'metadata' => [
          'slots' => [],
        ],
        'propSources' => [
          'video' => [
            'required' => TRUE,
            'jsonSchema' => [
              'title' => 'video',
              'type' => 'object',
              'required' => [
                0 => 'src',
              ],
              'properties' => [
                'src' => [
                  'title' => 'Video URL',
                  'type' => 'string',
                  'format' => 'uri-reference',
                  'contentMediaType' => 'video/*',
                  'x-allowed-schemes' => ['http', 'https'],
                ],
                'poster' => [
                  'title' => 'Image URL',
                  'type' => 'string',
                  'format' => 'uri-reference',
                  'contentMediaType' => 'image/*',
                  'x-allowed-schemes' => ['http', 'https'],
                ],
              ],
            ],
            'sourceType' => 'static:field_item:file',
            'expression' => 'ℹ︎file␟{src↝entity␜␜entity:file␝uri␞␟url}',
            'sourceTypeSettings' => [
              'instance' => [
                'file_extensions' => 'mp4',
              ],
            ],
            'default_values' => [
              'source' => [],
              'resolved' => [
                'src' => 'https://media.istockphoto.com/id/1340051874/video/aerial-top-down-view-of-a-container-cargo-ship.mp4?s=mp4-640x640-is&k=20&c=5qPpYI7TOJiOYzKq9V2myBvUno6Fq2XM3ITPGFE8Cd8=',
                'poster' => 'https://example.com/600x400.png',
              ],
            ],
          ],
          'display_width' => [
            'required' => FALSE,
            'jsonSchema' => [
              'type' => 'integer',
              'minimum' => 1,
            ],
            'sourceType' => 'static:field_item:integer',
            'expression' => 'ℹ︎integer␟value',
            'sourceTypeSettings' => [
              'instance' => [
                'min' => 1,
                'max' => NULL,
              ],
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.sdc_theme_test.bar' => [
        'expected_output_selectors' => [
          'h1',
        ],
        'source' => 'Theme component',
        'metadata' => ['slots' => []],
        'propSources' => [],
        'transforms' => [],
      ],
      'sdc.sdc_theme_test.lib-overrides' => [
        'expected_output_selectors' => [
          ':root ',
        ],
        'source' => 'Theme component',
        'metadata' => ['slots' => []],
        'propSources' => [],
        'transforms' => [],
      ],
      'sdc.sdc_theme_test.my-card' => [
        'expected_output_selectors' => [
          '[data-component-id="sdc_theme_test:my-card"]',
        ],
        'source' => 'Theme component',
        'metadata' => [
          'slots' => [
            'card_body' => [
              'title' => 'Body',
              'description' => 'The contents of the card.',
              'examples' => [
                '<p>Foo is <strong>NOT</strong> bar.</p>',
              ],
            ],
          ],
        ],
        'propSources' => [
          'header' => [
            'required' => TRUE,
            'jsonSchema' => [
              'type' => 'string',
            ],
            'sourceType' => 'static:field_item:string',
            'expression' => 'ℹ︎string␟value',
            'default_values' => [
              'source' => [
                0 => ['value' => 'I am a header!'],
              ],
              'resolved' => 'I am a header!',
            ],
          ],
        ],
        'transforms' => [],
      ],
      'sdc.sdc_theme_test_base.my-card-no-schema' => [
        'expected_output_selectors' => [
          '[data-component-id="sdc_theme_test_base:my-card-no-schema"]',
        ],
        'source' => 'Theme component',
        'metadata' => ['slots' => []],
        'propSources' => [],
        'transforms' => [],
      ],
    ];
  }

  /**
   * @covers ::inputToClientModel()
   * @dataProvider explicitsInputsProvider
   */
  public function testInputToClientModel(string $component_id, array $explicit_input, array $expected_client_model):void {
    $this->generateComponentConfig();

    // Ensure the explicit input is valid; helps catch incorrect test cases that
    // could lead to misleading test results.
    foreach ($explicit_input['source'] as $prop_name => $prop_source_array) {
      $resolved_source = PropSource::parse($prop_source_array)->evaluate(NULL, is_required: TRUE);
      self::assertSame($resolved_source, $explicit_input['resolved'][$prop_name]);
    }

    $component = Component::load($component_id);
    \assert($component instanceof Component);
    $component_source = $component->getComponentSource();
    // It can only be Code components or SDC.
    \assert($component_source instanceof GeneratedFieldExplicitInputUxComponentSourceBase);
    $actual_model_client = $component_source->inputToClientModel($explicit_input);
    $this->assertEquals($expected_client_model, $actual_model_client);
  }

  /**
   * @param array{source: SingleComponentInputArray, resolved: array<string, mixed>} $clientModel
   * @param ?array $nodeValues
   * @param ?array $expectedInput
   * @param ?class-string<\Throwable> $expectedExceptionClass
   * @param ?string $expectedExceptionMessage
   *
   * @covers ::clientModelToInput
   * @dataProvider providerClientModelToInput
   */
  public function testClientModelToInput(array $clientModel, ?array $nodeValues, ?array $expectedInput, ?string $expectedExceptionClass, ?string $expectedExceptionMessage): void {
    $this->generateComponentConfig();
    $component = Component::load('sdc.canvas_test_sdc.my-hero');
    self::assertInstanceOf(Component::class, $component);

    $this->installEntitySchema('node');
    $this->installSchema('node', 'node_access');
    NodeType::create([
      'type' => 'page',
      'name' => 'Page',
    ])->save();
    if ($nodeValues !== NULL) {
      $hostEntity = Node::create($nodeValues);
      self::assertCount(0, $hostEntity->validate());
      $hostEntity->save();
    }
    else {
      $hostEntity = NULL;
    }

    // Ensure the permissions required to evaluate dynamic expressions are not
    // needed.
    // @see \Drupal\canvas\PropExpressions\StructuredData\Evaluator::validateAccess()
    $this->setUpCurrentUser();

    if ($expectedExceptionClass !== NULL) {
      $this->expectException($expectedExceptionClass);
      self::assertNotNull($expectedExceptionMessage);
      $this->expectExceptionMessage($expectedExceptionMessage);
      self::assertNull($expectedInput);
    }
    else {
      self::assertNull($expectedExceptionMessage);
      self::assertNotNull($expectedInput);
    }
    $input = $component->getComponentSource()->clientModelToInput('a-uuid-for-testing', $component, $clientModel, $hostEntity);
    self::assertSame($expectedInput, $input);
  }

  public static function providerClientModelToInput(): \Generator {
    $clientModel = [
      'source' => [
        'heading' => [
          'sourceType' => 'dynamic',
          'expression' => 'ℹ︎␜entity:node:page␝title␞␟value',
          'value' => 'Some value, will be ignored by server',
        ],
        'cta1' => [
          'sourceType' => 'static:field_item:string',
          'expression' => 'ℹ︎string␟value',
          'value' => 'Witty test value',
        ],
        'cta1href' => [
          'sourceType' => 'static:field_item:link',
          'value' => 'https://example.com',
          'expression' => 'ℹ︎link␟url',
          'sourceTypeSettings' => [
            'instance' => [
              'title' => 0,
            ],
          ],
        ],
        'cta2' => [
          'sourceType' => 'static:field_item:string',
          'expression' => 'ℹ︎string␟value',
          'value' => 'Inside developer joke',
        ],
        'subheading' => [
          'sourceType' => 'dynamic',
          'expression' => 'ℹ︎␜entity:node:page␝revision_log␞␟value',
          'value' => NULL,
        ],
      ],
      'resolved' => [
        'heading' => 'Does not have to match',
        'cta1' => 'Is what server previously sent',
        'cta1href' => 'https://example.com',
        'cta2' => 'Click, or don\'t',
        'subheading' => NULL,
      ],
    ];
    $expectedInput = [
      'heading' => [
        'sourceType' => 'dynamic',
        'expression' => 'ℹ︎␜entity:node:page␝title␞␟value',
      ],
      'cta1' => 'Witty test value',
      'cta1href' => [
        'sourceType' => 'static:field_item:link',
        'value' => [
          'uri' => 'https://example.com',
          'options' => [],
        ],
        'expression' => 'ℹ︎link␟url',
        'sourceTypeSettings' => [
          'instance' => [
            'title' => 0,
          ],
        ],
      ],
      'cta2' => 'Inside developer joke',
      'subheading' => [
        'sourceType' => 'dynamic',
        'expression' => 'ℹ︎␜entity:node:page␝revision_log␞␟value',
      ],
    ];
    $nodeValues = ['type' => 'page', 'title' => 'Test page for inputToClientModel'];
    // Explicit failure when no host entity is provided.
    yield "Invalid: DynamicPropSource without host entity" => [
      'clientModel' => $clientModel,
      'nodeValues' => NULL,
      'expectedInput' => NULL,
      'expectedExceptionClass' => \InvalidArgumentException::class,
      'expectedExceptionMessage' => 'A host entity is required to set dynamic prop sources.',
    ];
    // Expected (server-side) component instance input for the given client model when provided with a host entity.
    // The non-required property "subheading" is linked to an empty field, revision_log.
    yield "Valid: DynamicPropSource with host entity, empty non-required property" => [
      'clientModel' => $clientModel,
      'nodeValues' => $nodeValues,
      'expectedInput' => $expectedInput,
      'expectedExceptionClass' => NULL,
      'expectedExceptionMessage' => NULL,
    ];
    // Expected (server-side) component instance input for the given client model when provided with a host entity.
    // The non-required property "subheading" is linked to a non-empty field, revision_log.
    $nodeValues['revision_log'] = 'This is the revision log.';
    yield "Valid: DynamicPropSource with host entity, not empty non-required property" => [
      'clientModel' => $clientModel,
      'nodeValues' => $nodeValues,
      'expectedInput' => $expectedInput,
      'expectedExceptionClass' => NULL,
      'expectedExceptionMessage' => NULL,
    ];
    // Modifying the client model to use an expression requiring a different bundle triggers an exception.
    $clientModel['source']['heading']['expression'] = 'ℹ︎␜entity:node:article␝title␞␟value';
    yield "Invalid: DynamicPropSource, expression with non-matching bundle" => [
      'clientModel' => $clientModel,
      'nodeValues' => $nodeValues,
      'expectedInput' => NULL,
      'expectedExceptionClass' => \DomainException::class,
      'expectedExceptionMessage' => '`ℹ︎␜entity:node:article␝title␞␟value` is an expression for entity type `node`, bundle(s) `article`, but the provided entity is of the bundle `page`.',
    ];
  }

  public static function explicitsInputsProvider(): \Generator {
    yield 'image' => [
      'sdc.canvas_test_sdc.image',
      [
        'source' => [
          'image' => [
            'sourceType' => 'default-relative-url',
            'value' => [
              'src' => '/modules/contrib/canvas_test_sdc/components/image/600x400.png',
              'alt' => 'Boring placeholder',
              'width' => 600,
              'height' => 400,
            ],
            'jsonSchema' => [
              'type' => 'object',
              'properties' => [
                'src' => [
                  'type' => 'string',
                  'contentMediaType' => 'image/*',
                  'format' => 'uri-reference',
                  'x-allowed-schemes' => ['http', 'https'],
                ],
                'alt' => [
                  'type' => 'string',
                ],
                'width' => [
                  'type' => 'integer',
                ],
                'height' => [
                  'type' => 'integer',
                ],
              ],
              'required' => [
                'src',
              ],
            ],
            'componentId' => 'sdc.canvas_test_sdc.image',
          ],
        ],
        'resolved' => [
          'image' => [
            'src' => '/modules/contrib/canvas_test_sdc/components/image/600x400.png',
            'alt' => 'Boring placeholder',
            'width' => 600,
            'height' => 400,
          ],
        ],
      ],
      [
        'source' => [
          'image' => [
            'sourceType' => 'static:field_item:image',
            'expression' => 'ℹ︎image␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
            'value' => NULL,
          ],
        ],
        'resolved' => [
          'image' => [
            'src' => '/modules/contrib/canvas_test_sdc/components/image/600x400.png',
            'alt' => 'Boring placeholder',
            'width' => 600,
            'height' => 400,
          ],
        ],
      ],
    ];
  }

  protected function createAndSaveInUseComponentForFallbackTesting(): ComponentInterface {
    // Media library depends on the views module and media depends on field
    // config.
    $this->enableModules(['media', 'media_library', 'views', 'field']);
    $this->installEntitySchema('file');
    $this->installSchema('file', 'file_usage');
    $this->installEntitySchema('media');
    $this->createMediaType('image', ['id' => 'image', 'label' => 'Image']);
    /** @var \Drupal\canvas\Entity\ComponentInterface */
    return Component::load('sdc.canvas_test_sdc.image');
  }

  protected function createAndSaveUnusedComponentForFallbackTesting(): ComponentInterface {
    /** @var \Drupal\canvas\Entity\ComponentInterface */
    return Component::load('sdc.canvas_test_sdc.image-optional-without-example');
  }

  protected function deleteConfigAndTriggerComponentFallback(ComponentInterface $used_component, ComponentInterface $unused_component): void {
    $type = MediaType::load('image');
    \assert($type instanceof MediaType);
    $type->delete();
  }

  protected static function getPropsForComponentFallbackTesting(): array {
    /** @var \Drupal\Core\File\FileSystemInterface $file_system */
    $file_system = \Drupal::service('file_system');
    $file_uri = 'public://image-2.jpg';
    if (!\file_exists($file_uri)) {
      $file_system->copy(\Drupal::root() . '/core/tests/fixtures/files/image-2.jpg', PublicStream::basePath(), FileExists::Replace);
    }
    $file = File::create([
      'uri' => $file_uri,
      'status' => 1,
    ]);
    $file->save();
    $image = Media::create([
      'bundle' => 'image',
      'name' => 'Amazing image',
      'field_media_image' => [
        [
          'target_id' => $file->id(),
          'alt' => 'An image so amazing that to gaze upon it would melt your face',
          'title' => 'This is an amazing image, just look at it and you will be amazed',
        ],
      ],
    ]);
    $image->save();
    return [
      'image' => [
        'sourceType' => 'static:field_item:entity_reference',
        'value' => ['target_id' => $image->id()],
        // This expression resolves `src` to the image's public URL.
        'expression' => 'ℹ︎entity_reference␟{src↝entity␜␜entity:media:image␝field_media_image␞␟src_with_alternate_widths,alt↝entity␜␜entity:media:image␝field_media_image␞␟alt,width↝entity␜␜entity:media:image␝field_media_image␞␟width,height↝entity␜␜entity:media:image␝field_media_image␞␟height}',
        'sourceTypeSettings' => [
          'storage' => ['target_type' => 'media'],
          'instance' => [
            'handler' => 'default:media',
            'handler_settings' => [
              'target_bundles' => ['image' => 'image'],
            ],
          ],
        ],
      ],
    ];
  }

  protected function recoverComponentFallback(ComponentInterface $component): void {
    $this->createMediaType('image', ['id' => 'image', 'label' => 'Image']);
    $this->generateComponentConfig();
  }

  protected function createAndSaveInUseComponentForUninstallValidationTesting(): ComponentInterface {
    $this->enableModules(['sdc_test']);
    $this->generateComponentConfig();
    /** @var \Drupal\canvas\Entity\ComponentInterface */
    return Component::load('sdc.sdc_test.no-props');
  }

  protected function createAndSaveUnusedComponentForUninstallValidationTesting(): ComponentInterface {
    /** @var \Drupal\canvas\Entity\ComponentInterface */
    return Component::load('sdc.canvas_test_sdc.props-slots');
  }

  protected function getNotAllowedModuleForUninstallValidatorTesting(): string {
    return 'sdc_test';
  }

  protected function getAllowedModuleForUninstallValidatorTesting(): string {
    return 'canvas_test_sdc';
  }

  protected function triggerBrokenComponent(ComponentInterface $component): BrokenPluginManagerInterface {
    /** @var \Drupal\Tests\canvas\Kernel\BrokenPluginManagerInterface */
    return \Drupal::service(CanvasComponentPluginManager::class);
  }

  public function alter(ContainerBuilder $container): void {
    // Swap in the broken version of this class.
    // @see ::triggerBrokenComponent()
    // @see ::testIsBroken()
    $container->getDefinition(CanvasComponentPluginManager::class)->setClass(BrokenComponentManager::class);
  }

}
