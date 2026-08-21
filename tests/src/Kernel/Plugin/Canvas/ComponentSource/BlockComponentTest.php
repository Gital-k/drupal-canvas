<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource;

// cspell:ignore gitane

use Drupal\canvas\Block\BlockManagerDecorator;
use Drupal\canvas\ComponentSource\ContextGatherer;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Plugin\Canvas\ComponentSource\BlockComponent;
use Drupal\canvas\Plugin\Canvas\ComponentSource\BlockComponentDiscovery;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\canvas_test_block\Plugin\Block\CanvasTestBlockInputNone;
use Drupal\canvas_test_block\Plugin\Block\CanvasTestBlockInputSchemaChangePoc;
use Drupal\canvas_test_block\Plugin\Block\CanvasTestBlockInputTranslatability;
use Drupal\canvas_test_block\Plugin\Block\CanvasTestBlockInputValidatable;
use Drupal\canvas_test_block\Plugin\Block\CanvasTestBlockInputValidatableCrash;
use Drupal\canvas_test_block\Plugin\Block\CanvasTestBlockOptionalContexts;
use Drupal\canvas_test_block\Plugin\Block\CanvasTestBlockRequiresContexts;
use Drupal\canvas_test_block\Plugin\Block\CanvasTestBlockRequiresNodeContext;
use Drupal\canvas_test_block_form\Plugin\Block\CanvasTestBlockForm;
use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Form\FormState;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\system\Entity\Menu;
use Drupal\Tests\canvas\Kernel\BrokenBlockManager;
use Drupal\Tests\canvas\Kernel\BrokenPluginManagerInterface;
use Drupal\Tests\canvas\Traits\BlockComponentTreeTestTrait;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Validator\ConstraintViolationInterface;

/**
 * Tests Drupal\canvas\Plugin\Canvas\ComponentSource\BlockComponent.
 *
 * @phpstan-import-type ComponentConfigEntityId from \Drupal\canvas\Entity\Component
 * @legacy-covers \Drupal\canvas\Plugin\Canvas\ComponentSource\BlockComponentDiscovery
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(BlockComponent::class)]
#[Group('canvas')]
#[Group('canvas_component_sources')]
final class BlockComponentTest extends ComponentSourceTestBase {

  /**
   * {@inheritdoc}
   *
   * 8 additional Block Component config entities due to the additional modules.
   */
  protected int $expectedDefaultComponentInstallCount = self::DEFAULT_COMPONENT_INSTALL_COUNT + 8;

  use BlockComponentTreeTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_test_block',
    'node',
  ];

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    parent::setUp();
    // Set up a test user "bob".
    $this->setUpCurrentUser(['name' => 'bob', 'uid' => 2]);
  }

  /**
   * All test module blocks must either have a Component or a reason why not.
   *
   * @legacy-covers \Drupal\canvas\Plugin\Canvas\ComponentSource\BlockComponentDiscovery::discover
   * @legacy-covers \Drupal\canvas\Plugin\Canvas\ComponentSource\BlockComponentDiscovery::checkRequirements
   */
  public function testDiscovery(): array {
    $components = Component::loadMultiple();
    foreach ($components as $component) {
      if ($component->getComponentSource() instanceof BlockComponent) {
        if (\in_array($component->get('source_local_id'), BlockComponentDiscovery::BLOCKS_TO_KEEP_ENABLED, TRUE)) {
          self::assertTrue($component->status());
        }
      }
    }

    $this->assertCount($this->expectedDefaultComponentInstallCount, $this->componentStorage->loadMultiple());

    // Some Block Components may already be discovered at this point due to the
    // BlockManagerDecorator reacting to earlier block definition cache clears.
    self::assertSame([
      'block.canvas_test_block_input_invalid_default' => [
        'Block plugin has invalid default setting "crash": This value should be of the correct primitive type.',
      ],
      'block.canvas_test_block_input_unvalidatable' => [
        'Block plugin settings must opt into strict validation. Use the FullyValidatable constraint. See https://www.drupal.org/node/3404425',
      ],
    ], $this->findIneligibleComponents(BlockComponent::SOURCE_PLUGIN_ID, 'canvas_test_block'));
    $auto_created_components = $this->findCreatedComponentConfigEntities(BlockComponent::SOURCE_PLUGIN_ID, 'canvas_test_block');
    self::assertSame([
      'block.canvas_test_block_input_none',
      'block.canvas_test_block_input_schema_change_poc',
      BlockComponent::SOURCE_PLUGIN_ID . '.' . CanvasTestBlockInputTranslatability::PLUGIN_ID,
      'block.canvas_test_block_input_validatable',
      'block.canvas_test_block_input_validatable_crash',
      'block.canvas_test_block_optional_contexts',
      'block.canvas_test_block_requires_contexts',
      'block.canvas_test_block_requires_node_context',
    ], $auto_created_components);

    // Trigger component generation, as if the test module was just installed.
    // Due to BlockManagerDecorator, this should result in zero extra Block
    // Components being discovered.
    $this->generateComponentConfig();
    // Minus one: the shipped "Page content" marker is not a block component.
    $this->assertCount($this->expectedDefaultComponentInstallCount - 1, \array_filter(
      $this->componentStorage->loadMultiple(),
      static function (EntityInterface $component) {
        \assert($component instanceof Component);
        return $component->get('source') === BlockComponent::SOURCE_PLUGIN_ID;
      }
    ));

    return array_combine($auto_created_components, $auto_created_components);
  }

  /**
   * Tests the 'default_settings' generated for the eligible Block plugins.
   */
  #[Depends('testDiscovery')]
  public function testSettings(array $component_ids): void {
    self::assertSame([
      'block.canvas_test_block_input_none' => [
        'default_settings' => [
          'id' => 'canvas_test_block_input_none',
          'label' => 'Test block with no settings.',
          'label_display' => '0',
          'provider' => 'canvas_test_block',
        ],
      ],
      'block.canvas_test_block_input_schema_change_poc' => [
        'default_settings' => [
          'id' => 'canvas_test_block_input_schema_change_poc',
          'label' => 'Test block for Input Schema Change POC.',
          'label_display' => '0',
          'provider' => 'canvas_test_block',
          'foo' => 'bar',
        ],
      ],
      BlockComponent::SOURCE_PLUGIN_ID . '.' . CanvasTestBlockInputTranslatability::PLUGIN_ID => [
        'default_settings' => [
          'id' => CanvasTestBlockInputTranslatability::PLUGIN_ID,
          'label' => 'Canvas Test Block for testing input translatability',
          'label_display' => '0',
          'provider' => 'canvas_test_block',
          ...CanvasTestBlockInputTranslatability::DEFAULT_CONFIGURATION,
        ],
      ],
      'block.canvas_test_block_input_validatable' => [
        'default_settings' => [
          'id' => 'canvas_test_block_input_validatable',
          'label' => 'Test Block with settings',
          'label_display' => '0',
          'provider' => 'canvas_test_block',
          // This block has a single setting.
          'name' => 'Canvas',
        ],
      ],
      'block.canvas_test_block_input_validatable_crash' => [
        'default_settings' => [
          'id' => 'canvas_test_block_input_validatable_crash',
          'label' => "Test Block with settings, crashes when 'crash' setting is TRUE",
          'label_display' => '0',
          'provider' => 'canvas_test_block',
          // This block has two settings.
          'name' => 'Canvas',
          'crash' => FALSE,
        ],
      ],
      'block.canvas_test_block_optional_contexts' => [
        'default_settings' => [
          'id' => 'canvas_test_block_optional_contexts',
          'label' => 'Test Block with optional contexts',
          'label_display' => '0',
          'provider' => 'canvas_test_block',
        ],
      ],
      'block.canvas_test_block_requires_contexts' => [
        'default_settings' => [
          'id' => 'canvas_test_block_requires_contexts',
          'label' => 'Test Block with required contexts',
          'label_display' => '0',
          'provider' => 'canvas_test_block',
          // The required "name" context is auto-assigned to a global context.
          // @see \Drupal\canvas_test_block\Plugin\Block\CanvasTestBlockRequiresContexts::defaultConfiguration()
          'context_mapping' => [
            'name' => '@user.current_user_context:current_user',
          ],
        ],
      ],
      'block.canvas_test_block_requires_node_context' => [
        'default_settings' => [
          'id' => 'canvas_test_block_requires_node_context',
          'label' => 'Test Block requiring a node context',
          'label_display' => '0',
          'provider' => 'canvas_test_block',
        ],
      ],
    ], $this->getAllSettings($component_ids));
  }

  /**
   * Tests get referenced plugin class.
   *
   * @param array<ComponentConfigEntityId> $component_ids
   *
   * @legacy-covers ::getReferencedPluginClass
   */
  #[Depends('testDiscovery')]
  public function testGetReferencedPluginClass(array $component_ids): void {
    self::assertSame([
      'block.canvas_test_block_input_none' => CanvasTestBlockInputNone::class,
      'block.canvas_test_block_input_schema_change_poc' => CanvasTestBlockInputSchemaChangePoc::class,
      BlockComponent::SOURCE_PLUGIN_ID . '.' . CanvasTestBlockInputTranslatability::PLUGIN_ID => CanvasTestBlockInputTranslatability::class,
      'block.canvas_test_block_input_validatable' => CanvasTestBlockInputValidatable::class,
      'block.canvas_test_block_input_validatable_crash' => CanvasTestBlockInputValidatableCrash::class,
      'block.canvas_test_block_optional_contexts' => CanvasTestBlockOptionalContexts::class,
      'block.canvas_test_block_requires_contexts' => CanvasTestBlockRequiresContexts::class,
      'block.canvas_test_block_requires_node_context' => CanvasTestBlockRequiresNodeContext::class,
    ], $this->getReferencedPluginClasses($component_ids));
  }

  /**
   * Tests component id from block plugin id.
   *
   * @legacy-covers \Drupal\canvas\Plugin\Canvas\ComponentSource\BlockComponentDiscovery::getComponentConfigEntityId
   */
  #[TestWith(["foo", "block.foo"])]
  #[TestWith(["system_menu_block:footer", "block.system_menu_block.footer"])]
  public function testComponentIdFromBlockPluginId(string $input, string $expected_output): void {
    self::assertSame($expected_output, BlockComponentDiscovery::getComponentConfigEntityId($input));
  }

  /**
   * Tests render component live.
   *
   * @param array<ComponentConfigEntityId> $component_ids
   *
   * @legacy-covers ::renderComponent
   */
  #[Depends('testDiscovery')]
  public function testRenderComponentLive(array $component_ids): void {
    $this->assertNotEmpty($component_ids);
    // The node-context block cannot be rendered live here: it requires an
    // `entity:node` context that only a node host entity provides, and live
    // rendering (unlike the component-tree render path) does not degrade an
    // unsatisfiable required context to a placeholder. Whether it is offered at
    // all is decided by host-entity library filtering, so a host-less live
    // render is never a real scenario for it.
    // @see \Drupal\canvas\Controller\ApiConfigControllers::list()
    // @see \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList::renderify()
    unset($component_ids['block.canvas_test_block_requires_node_context']);
    $rendered = $this->renderComponentsLive(
      $component_ids,
      get_default_input: fn (Component $component) => [BlockComponent::EXPLICIT_INPUT_NAME => $component->getSettings()['default_settings']],
    );

    $default_render_cache_contexts = [
      'languages:language_interface',
      'theme',
      'user.permissions',
    ];
    $default_cacheability = (new CacheableMetadata())
      ->setCacheContexts($default_render_cache_contexts);
    $this->assertEquals([
      'block.canvas_test_block_input_none' => [
        'html' => <<<HTML
<div id="block-some-uuid">


      <div>Hello bob, from Canvas!</div>
  </div>

HTML,
        'cacheability' => (clone $default_cacheability)
          // @phpstan-ignore-next-line
          ->addCacheableDependency(User::load(2))
          ->setCacheContexts([
            'languages:language_interface',
            'theme',
            'user',
            'user.permissions',
          ]),
        'attachments' => [],
      ],
      'block.canvas_test_block_input_schema_change_poc' => [
        'html' => <<<HTML
<div id="block-some-uuid--2">


      Current foo value: bar
  </div>

HTML,
        'cacheability' => $default_cacheability,
        'attachments' => [],
      ],
      BlockComponent::SOURCE_PLUGIN_ID . '.' . CanvasTestBlockInputTranslatability::PLUGIN_ID => [
        'html' => <<<HTML
<div id="block-some-uuid--3">


      First bar: Gitane
  </div>

HTML,
        'cacheability' => $default_cacheability,
        'attachments' => [],
      ],
      'block.canvas_test_block_input_validatable' => [
        'html' => <<<HTML
<div id="block-some-uuid--4">


      <div>Hello, Canvas!</div>
  </div>

HTML,
        'cacheability' => $default_cacheability,
        'attachments' => [],
      ],
      'block.canvas_test_block_input_validatable_crash' => [
        'html' => <<<HTML
<div id="block-some-uuid--5">


      <div>Hello, Canvas!</div>
  </div>

HTML,
        'cacheability' => $default_cacheability,
        'attachments' => [],
      ],
      'block.canvas_test_block_optional_contexts' => [
        // Live rendering passes no context_mapping and no host entity, so the
        // optional context stays unset and the block renders its no-value path.
        'html' => <<<HTML
<div id="block-some-uuid--6">


      Test Block with no context value.
  </div>

HTML,
        'cacheability' => $default_cacheability,
        'attachments' => [],
      ],
      'block.canvas_test_block_requires_contexts' => [
        'html' => <<<HTML
<div id="block-some-uuid--7">


      Test Block with required context value: bob
  </div>

HTML,
        // The required "name" context is auto-assigned to the current user, so
        // the applied context's cacheability (the "user" cache context and the
        // user entity's cache tag) bubbles into the render.
        'cacheability' => (clone $default_cacheability)
          // @phpstan-ignore-next-line
          ->addCacheableDependency(User::load(2))
          ->setCacheContexts([
            'languages:language_interface',
            'theme',
            'user',
            'user.permissions',
          ]),
        'attachments' => [],
      ],
    ], $rendered);
  }

  /**
   * {@inheritdoc}
   */
  public static function getExpectedClientSideInfo(): array {
    return [
      'block.canvas_test_block_input_none' => [
        'expected_output_selectors' => ['div:contains("Hello bob, from Canvas!")'],
      ],
      'block.canvas_test_block_input_schema_change_poc' => [
        'expected_output_selectors' => ['div:contains("Current foo value: bar")'],
      ],
      BlockComponent::SOURCE_PLUGIN_ID . '.' . CanvasTestBlockInputTranslatability::PLUGIN_ID => [
        'expected_output_selectors' => ['div:contains("First bar: Gitane")'],
      ],
      'block.canvas_test_block_input_validatable' => [
        'expected_output_selectors' => ['div:contains("Hello, Canvas!")'],
      ],
      'block.canvas_test_block_input_validatable_crash' => [
        'expected_output_selectors' => ['div:contains("Hello, Canvas!")'],
      ],
      'block.canvas_test_block_optional_contexts' => [
        // The preview build passes no context_mapping, so the optional context
        // stays unset and the block renders its no-value path.
        'expected_output_selectors' => ['div:contains("Test Block with no context value.")'],
      ],
      'block.canvas_test_block_requires_contexts' => [
        'expected_output_selectors' => ['div:contains("Test Block with required context value:")'],
      ],
      'block.canvas_test_block_requires_node_context' => [
        // The component library preview is rendered without a host entity, so
        // this block's required `entity:node` context is unsatisfiable and
        // getClientSideInfo() returns an empty build (no preview). An empty
        // selector list asserts the preview renders to nothing.
        // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\BlockComponent::getClientSideInfo()
        'expected_output_selectors' => [],
      ],
    ];
  }

  /**
   * Tests get explicit input.
   *
   * @legacy-covers ::getExplicitInput
   */
  #[DataProvider('getValidTreeTestCases')]
  public function testGetExplicitInput(array $componentItemValue): void {
    $this->generateComponentConfig();

    $this->installEntitySchema('node');
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_test_config_node_article']);
    $node = Node::create([
      'title' => 'Test node',
      'type' => 'article',
      'field_canvas_test' => $componentItemValue,
    ]);
    self::assertEntityIsValid($node);
    $node->save();
    $canvas_field_item = $node->field_canvas_test[0];
    $this->assertInstanceOf(ComponentTreeItem::class, $canvas_field_item);

    $component = $canvas_field_item->getComponent();
    \assert($component instanceof Component);

    $explicit = $component->getComponentSource()->getExplicitInput($canvas_field_item->getUuid(), $canvas_field_item);
    $componentSettings = $explicit;
    $componentSettingsOriginal = $componentItemValue[0]['inputs'];

    $this->assertSame($componentSettingsOriginal, $componentSettings);
  }

  public static function providerRenderComponentFailure(): \Generator {
    $block_settings = [
      'label' => 'crash dummy',
      'label_display' => '0',
      'name' => 'Canvas',
    ];

    yield "Block with valid props, without exception" => [
      'component_id' => 'block.canvas_test_block_input_validatable_crash',
      'inputs' => [
        'crash' => FALSE,
      ] + $block_settings,
      'expected_validation_errors' => [],
      'expected_exception' => NULL,
      'expected_output_selector' => \sprintf('[id*="block-%s"]:contains("Hello, Canvas!")', static::UUID_CRASH_TEST_DUMMY),
    ];

    // @todo Add a "hydration exception" test case in https://www.drupal.org/i/3524399

    yield "Block with valid props, with exception" => [
      'component_id' => 'block.canvas_test_block_input_validatable_crash',
      'inputs' => [
        'crash' => TRUE,
      ] + $block_settings,
      'expected_validation_errors' => [],
      'expected_exception' => [
        'class' => \Exception::class,
        'message' => "Intentional test exception.",
      ],
      'expected_output_selector' => NULL,
    ];
  }

  /**
   * Tests calculate dependencies.
   *
   * @legacy-covers ::calculateDependencies
   */
  #[Depends('testDiscovery')]
  public function testCalculateDependencies(array $component_ids): void {
    // Note: the module providing the Block plugin is depended upon directly.
    // @see \Drupal\canvas\Entity\Component::$provider
    $dependencies = ['module' => ['canvas_test_block']];
    self::assertSame([
      'block.canvas_test_block_input_none' => $dependencies,
      'block.canvas_test_block_input_schema_change_poc' => $dependencies,
      BlockComponent::SOURCE_PLUGIN_ID . '.' . CanvasTestBlockInputTranslatability::PLUGIN_ID => $dependencies,
      'block.canvas_test_block_input_validatable' => $dependencies,
      'block.canvas_test_block_input_validatable_crash' => $dependencies,
      'block.canvas_test_block_optional_contexts' => $dependencies,
      'block.canvas_test_block_requires_contexts' => $dependencies,
      'block.canvas_test_block_requires_node_context' => $dependencies,
    ], $this->callSourceMethodForEach('calculateDependencies', $component_ids));
  }

  protected function createAndSaveInUseComponentForFallbackTesting(): ComponentInterface {
    $this->generateComponentConfig();
    /** @var \Drupal\canvas\Entity\ComponentInterface */
    return Component::load('block.system_menu_block.footer');
  }

  protected function createAndSaveUnusedComponentForFallbackTesting(): ComponentInterface {
    /** @var \Drupal\canvas\Entity\ComponentInterface */
    return Component::load('block.system_menu_block.admin');
  }

  protected static function getPropsForComponentFallbackTesting(): array {
    return [
      'label' => 'Main navigation',
      'label_display' => '0',
      'level' => 1,
      'depth' => NULL,
      'expand_all_items' => TRUE,
    ];
  }

  protected function deleteConfigAndTriggerComponentFallback(ComponentInterface $used_component, ComponentInterface $unused_component): void {
    $menu = Menu::load('footer');
    \assert($menu instanceof Menu);
    $menu->delete();

    $menu = Menu::load('admin');
    \assert($menu instanceof Menu);
    $menu->delete();
  }

  protected function recoverComponentFallback(ComponentInterface $component): void {
    $menu = Menu::create([
      'id' => 'footer',
      'label' => 'Footer',
      'description' => 'Site information links',
    ]);
    $menu->save();
    $this->generateComponentConfig();
  }

  /**
   * Tests dependency update.
   *
   * @legacy-covers \Drupal\canvas\Plugin\Canvas\ComponentSource\BlockComponentDiscovery::computeCurrentComponentMetadata
   */
  public function testDependencyUpdate(): void {
    $this->generateComponentConfig();

    $config = 'canvas.component.block.system_menu_block.footer';
    $this->assertSame('Footer', $this->config($config)->get('label'));

    $menu = Menu::load('footer');
    \assert($menu instanceof Menu);
    $label = 'Old footer menu';
    $menu->set('label', $label)->save();

    $this->generateComponentConfig();

    $this->assertSame($label, $this->config($config)->get('label'));
  }

  public function testVersionDeterminability(): void {
    $this->generateComponentConfig();
    $original_component = Component::load('block.canvas_test_block_input_validatable');
    \assert($original_component instanceof Component);
    $original_version = $original_component->getActiveVersion();

    // Trigger an alter to the schema which should result in a new version as
    // validation may make previous versions no longer valid.
    // @see \Drupal\canvas_test_block\Hook\CanvasTestBlockHooks::configSchemaInfoAlter
    \Drupal::keyValue('canvas_test_block')->set('i_can_haz_alter?', TRUE);
    \Drupal::service(TypedConfigManagerInterface::class)->clearCachedDefinitions();
    $this->generateComponentConfig();

    $new_component = Component::load('block.canvas_test_block_input_validatable');
    \assert($new_component instanceof Component);

    $new_version = $new_component->getActiveVersion();
    self::assertNotEquals($new_version, $original_version);
  }

  protected function createAndSaveInUseComponentForUninstallValidationTesting(): ComponentInterface {
    $this->enableModules(['help']);
    $this->generateComponentConfig();
    /** @var \Drupal\canvas\Entity\ComponentInterface */
    return Component::load('block.canvas_test_block_input_none');
  }

  protected function createAndSaveUnusedComponentForUninstallValidationTesting(): ComponentInterface {
    /** @var \Drupal\canvas\Entity\ComponentInterface */
    return Component::load('block.help_block');
  }

  protected function getAllowedModuleForUninstallValidatorTesting(): string {
    return 'help';
  }

  protected function getNotAllowedModuleForUninstallValidatorTesting(): string {
    return 'canvas_test_block';
  }

  public function testBlockFormValidationAndSubmit(): void {
    $this->enableModules(['canvas_test_block_form']);
    $this->generateComponentConfig();
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $page1 = Page::create(['title' => 'Forever, feels like never']);
    $page1->save();
    $page2 = Page::create(['title' => 'Chatter']);
    $page2->save();
    $component = Component::load('block.' . CanvasTestBlockForm::PLUGIN_ID);
    \assert($component instanceof ComponentInterface);
    $source = $component->getComponentSource();
    \assert($source instanceof BlockComponent);
    $uuid = '07875b1b-b68c-4b90-955c-d6136ff8af93';
    // @phpstan-ignore-next-line
    $input = $source->clientModelToInput($uuid, $component, [
      // Behavior when component is first added to the layout.
      // @see addNewComponentToLayout AppThunk in layoutModelSlice.ts
      'resolved' => [],
    ], NULL);
    self::assertSame([
      'label' => 'Test block form',
      // This confusingly isn't a boolean, because that what its config schema dictates.
      // @see `type: block_settings`
      // @todo Remove after https://drupal.org/i/2544708 is fixed.
      // @see \Drupal\canvas_test_block_form\Plugin\Block\CanvasTestBlockForm::blockSubmit
      'label_display' => '0',
      'multiplier' => 0,
      // @see \Drupal\canvas_test_block_form\Plugin\Block\CanvasTestBlockForm::defaultConfiguration
      'canvas_page' => 0,
    ], $input);
    // @phpstan-ignore-next-line
    $input = $source->clientModelToInput($uuid, $component, [
      'resolved' => [
        'canvas_page' => \sprintf('%s (%d)', $page1->label(), $page1->id()),
      ],
    ], NULL);
    // Confirm that block validation and submit methods are called.
    self::assertEquals([
      'canvas_page' => $page1->id(),
      'label' => 'Test block form',
      // This confusingly isn't a boolean, because that what its config schema dictates.
      // @see `type: block_settings`
      // @todo Remove after https://drupal.org/i/2544708 is fixed.
      'label_display' => '0',
      // @see \Drupal\canvas_test_block_form\Plugin\Block\CanvasTestBlockForm::blockSubmit
      'multiplier' => 3,
    ], $input);
    // @todo This is wrong (it does not conform to `type: block.settings.canvas_test_block_form`) and will be fixed in https://www.drupal.org/project/canvas/issues/3541125
    self::assertFalse(\is_int($input['canvas_page']));

    // Confirm that validation errors from submitting the block plugin are
    // stored in the auto-save manager for a subsequent validation step.
    // @phpstan-ignore-next-line
    $input = $source->clientModelToInput($uuid, $component, [
      'resolved' => [
        'canvas_page' => \sprintf('%s (%d)', $page2->label(), $page2->id()),
      ],
    ], NULL);
    $violations = $source->validateComponentInput($input, $uuid, NULL);
    $violationMap = \array_map(static fn(ConstraintViolationInterface $violation) => \sprintf('%s:%s', $violation->getPropertyPath(), $violation->getMessage()), \iterator_to_array($violations));
    self::assertCount(2, $violations, \implode(', ', $violationMap));
    self::assertEquals([
      'inputs.canvas_page:This value should be of the correct primitive type.',
      'inputs.canvas_page:You better call me on the phone',
    ], $violationMap);

    // Test that the violation error bubbles to a parent entity.
    $page3 = Page::create(['title' => 'Glitter shot']);
    $page3->set('components', [
      [
        'uuid' => '922b4cbd-4b99-46ce-a253-ff80f8560e9d',
        'component_id' => 'block.' . CanvasTestBlockForm::PLUGIN_ID,
        'inputs' => [
          'label' => 'Page',
          'label_display' => '0',
          'multiplier' => 0,
          'canvas_page' => 0,
        ],
      ],
    ]);
    $item = $page3->get('components')->first();
    \assert($item instanceof ComponentTreeItem);
    $component = $item->getComponent();
    \assert($component instanceof Component);
    $source = $component->getComponentSource();
    \assert($source instanceof BlockComponent);
    // Simulate submitting invalid input.
    $item->setInput(
      // @phpstan-ignore-next-line
      $source->clientModelToInput('922b4cbd-4b99-46ce-a253-ff80f8560e9d', $component, [
        'resolved' => [
          'canvas_page' => 'There is no such place',
        ],
      ], $page3)
    );
    $violations = $page3->validate();
    $violationMap = \array_map(static fn(ConstraintViolationInterface $violation) => \sprintf('%s:%s', $violation->getPropertyPath(), $violation->getMessage()), \iterator_to_array($violations));
    self::assertCount(2, $violations, \implode(', ', $violationMap));
    self::assertEquals([
      "components.0.inputs.canvas_page:This value should be of the correct primitive type.",
      'components.0.inputs.canvas_page:There are no pages matching "There is no such place".',
    ], $violationMap);
  }

  /**
   * Tests that a context-aware block's `context_mapping` is stored and valid.
   *
   * @legacy-covers ::clientModelToInput
   */
  public function testContextMappingRoundTrip(): void {
    $this->generateComponentConfig();
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);

    $component_id = 'block.canvas_test_block_optional_contexts';
    $component = Component::load($component_id);
    \assert($component instanceof ComponentInterface);
    $source = $component->getComponentSource();
    \assert($source instanceof BlockComponent);
    $uuid = 'f8f2c0a1-1f2b-4a3c-9d4e-5a6b7c8d9e0f';

    // A `context_mapping` in the client model survives clientModelToInput, even
    // though it is not one of the block's default settings.
    $mapping = ['name' => 'canvas.host_entity'];
    // @phpstan-ignore-next-line
    $input = $source->clientModelToInput($uuid, $component, [
      'resolved' => ['context_mapping' => $mapping],
    ], NULL);
    self::assertSame($mapping, $input['context_mapping']);

    // A stored `context_mapping` that points at a global context pre-populates
    // the assignment select: it survives the round-trip back into the client
    // model, so the select is pre-selected when the instance is edited again.
    $global_mapping = ['name' => '@user.current_user_context:current_user'];
    $client_model = $source->inputToClientModel(['context_mapping' => $global_mapping] + $component->getSettings()['default_settings']);
    self::assertSame($global_mapping, $client_model['resolved']['context_mapping']);

    // Placement path: a required-context block placed with an empty client model
    // auto-assigns its single-candidate required context, so the freshly placed
    // instance's stored inputs carry the mapping without the editor opening the
    // form.
    $required_component = Component::load('block.canvas_test_block_requires_contexts');
    \assert($required_component instanceof ComponentInterface);
    $required_source = $required_component->getComponentSource();
    \assert($required_source instanceof BlockComponent);
    // @phpstan-ignore-next-line
    $placed = $required_source->clientModelToInput('c0ffee00-0000-4000-8000-000000000000', $required_component, [
      'resolved' => [],
    ], NULL);
    self::assertSame(['name' => '@user.current_user_context:current_user'], $placed['context_mapping']);

    // A stored instance WITH a `context_mapping` validates: the generated inputs
    // schema no longer strips the key.
    $with_mapping = Page::create([
      'title' => 'With mapping',
      'components' => [
        [
          'uuid' => $uuid,
          'component_id' => $component_id,
          'inputs' => ['label' => 'Ctx', 'label_display' => '0', 'context_mapping' => $mapping],
        ],
      ],
    ]);
    self::assertEntityIsValid($with_mapping);

    // Backwards compatibility: a stored instance WITHOUT a `context_mapping`
    // still validates, because the key is optional.
    $without_mapping = Page::create([
      'title' => 'Without mapping',
      'components' => [
        [
          'uuid' => '0a1b2c3d-4e5f-4061-8273-8495a6b7c8d9',
          'component_id' => $component_id,
          'inputs' => ['label' => 'No ctx', 'label_display' => '0'],
        ],
      ],
    ]);
    self::assertEntityIsValid($without_mapping);
  }

  /**
   * Tests the context UI a context-aware block's instance form exposes.
   *
   * @legacy-covers ::buildComponentInstanceForm
   */
  public function testContextAssignmentForm(): void {
    $this->generateComponentConfig();

    // An optional-context block shows an assignment select whose options are the
    // available contexts. It is not required, and there is no read-only required
    // display.
    $optional = Component::load('block.canvas_test_block_optional_contexts');
    \assert($optional instanceof ComponentInterface);
    $optional_source = $optional->getComponentSource();
    \assert($optional_source instanceof BlockComponent);
    $form = $optional_source->buildComponentInstanceForm(
      ['#parents' => ['canvas_component_props', 'optional-uuid'], '#tree' => TRUE],
      new FormState(),
      $optional,
      'optional-uuid',
    );
    self::assertArrayHasKey('context_mapping', $form['settings']);
    self::assertArrayHasKey('name', $form['settings']['context_mapping']);
    self::assertSame('select', $form['settings']['context_mapping']['name']['#type']);
    self::assertFalse($form['settings']['context_mapping']['name']['#required']);
    self::assertArrayHasKey('@user.current_user_context:current_user', $form['settings']['context_mapping']['name']['#options']);
    self::assertArrayNotHasKey('canvas_required_contexts', $form['settings']);

    // A required-context block shows a read-only display of every required
    // context and the context it resolves to. It is a non-input `item` element,
    // so it submits no value.
    $required = Component::load('block.canvas_test_block_requires_contexts');
    \assert($required instanceof ComponentInterface);
    $required_source = $required->getComponentSource();
    \assert($required_source instanceof BlockComponent);
    $form = $required_source->buildComponentInstanceForm(
      ['#parents' => ['canvas_component_props', 'required-uuid'], '#tree' => TRUE],
      new FormState(),
      $required,
      'required-uuid',
    );
    self::assertArrayHasKey('canvas_required_contexts', $form['settings']);
    self::assertArrayHasKey('name', $form['settings']['canvas_required_contexts']);
    self::assertSame('item', $form['settings']['canvas_required_contexts']['name']['#type']);
    // The required "name" context resolves to the auto-assigned current user
    // context, so the read-only display shows that context's label.
    self::assertSame('Current user', (string) $form['settings']['canvas_required_contexts']['name']['#markup']);

    // A block that declares no contexts gets no Canvas required-context display,
    // and no extra processing: its `context_mapping` element stays the empty
    // one that core's block form always adds for a context-aware plugin.
    $plain = Component::load('block.canvas_test_block_input_none');
    \assert($plain instanceof ComponentInterface);
    $plain_source = $plain->getComponentSource();
    \assert($plain_source instanceof BlockComponent);
    $form = $plain_source->buildComponentInstanceForm(
      ['#parents' => ['canvas_component_props', 'plain-uuid'], '#tree' => TRUE],
      new FormState(),
      $plain,
      'plain-uuid',
    );
    self::assertSame([], $form['settings']['context_mapping']);
    self::assertArrayNotHasKey('canvas_required_contexts', $form['settings']);
  }

  /**
   * Tests that a context-aware block renders with its mapped context applied.
   *
   * @legacy-covers ::renderComponent
   */
  public function testContextAwareBlockRendering(): void {
    $this->generateComponentConfig();
    $this->installEntitySchema('node');
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $node = Node::create(['type' => 'article', 'title' => 'Hello from the host']);
    $node->save();

    $component = Component::load('block.canvas_test_block_optional_contexts');
    \assert($component instanceof ComponentInterface);
    $source = $component->getComponentSource();
    \assert($source instanceof BlockComponent);
    $defaults = $component->getSettings()['default_settings'];

    // Mapping the block's context to the host entity: the block renders the
    // mapped node's label, and the node's cacheability bubbles into the render.
    $inputs = [
      BlockComponent::EXPLICIT_INPUT_NAME => [
        'context_mapping' => ['name' => ContextGatherer::HOST_ENTITY_CONTEXT_ID],
      ] + $defaults,
    ];
    $build = $source->renderComponent($inputs, [], 'some-uuid', FALSE, $node);
    $html = (string) $this->renderer->renderInIsolation($build);
    self::assertStringContainsString('Test Block with optional context value: Hello from the host', $html);
    self::assertContains('node:' . $node->id(), $build['#cache']['tags']);

    // With no context_mapping, the optional context stays unset: the block
    // renders its no-value output and does not error (backwards compatibility).
    $build = $source->renderComponent([BlockComponent::EXPLICIT_INPUT_NAME => $defaults], [], 'some-uuid', FALSE, $node);
    $html = (string) $this->renderer->renderInIsolation($build);
    self::assertStringContainsString('Test Block with no context value.', $html);
    self::assertNotContains('node:' . $node->id(), $build['#cache']['tags'] ?? []);
  }

  /**
   * Tests that rendering a host entity's tree threads it to context resolution.
   *
   * @legacy-covers \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList::renderify
   */
  public function testContextAwareBlockRendersViaHostEntityTree(): void {
    $this->generateComponentConfig();
    $this->installEntitySchema('node');
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_test_config_node_article']);
    $node = Node::create([
      'type' => 'article',
      'title' => 'Host node title',
      'field_canvas_test' => [
        [
          'uuid' => '6f3b8a1e-9c2d-4e7f-8a1b-2c3d4e5f6a7b',
          'component_id' => 'block.canvas_test_block_optional_contexts',
          'inputs' => [
            'label' => 'Ctx',
            'label_display' => '0',
            'context_mapping' => ['name' => ContextGatherer::HOST_ENTITY_CONTEXT_ID],
          ],
        ],
      ],
    ]);
    $node->save();

    // Rendering the node's component tree passes the node as host entity down
    // to the block, which resolves its mapped context to that node.
    $tree = $node->get('field_canvas_test');
    \assert($tree instanceof ComponentTreeItemList);
    $build = $tree->toRenderable($node);
    $html = (string) $this->renderer->renderInIsolation($build);
    self::assertStringContainsString('Test Block with optional context value: Host node title', $html);

    // When the stored mapping cannot be satisfied, applyContextMapping() throws.
    // That must degrade to a placeholder rather than fatal the whole tree: the
    // render still completes and the block's own output is gone.
    // @todo Extend to an unsatisfiable REQUIRED context in
    //   https://www.drupal.org/i/3485502 task 4, once required-context blocks
    //   are discoverable.
    $unsatisfiable = Node::create([
      'type' => 'article',
      'title' => 'Doomed instance',
      'field_canvas_test' => [
        [
          'uuid' => '7a4c9b2f-0d3e-4f8a-9b2c-3d4e5f6a7b8c',
          'component_id' => 'block.canvas_test_block_optional_contexts',
          'inputs' => [
            'label' => 'Ctx',
            'label_display' => '0',
            // A mapping for a context slot the block does not declare cannot be
            // satisfied, so applyContextMapping() throws a ContextException.
            'context_mapping' => ['not_a_real_slot' => ContextGatherer::HOST_ENTITY_CONTEXT_ID],
          ],
        ],
      ],
    ]);
    $unsatisfiable->save();
    $doomed_tree = $unsatisfiable->get('field_canvas_test');
    \assert($doomed_tree instanceof ComponentTreeItemList);
    $build = $doomed_tree->toRenderable($unsatisfiable);
    $html = (string) $this->renderer->renderInIsolation($build);
    self::assertStringNotContainsString('Test Block with optional context value', $html);
  }

  /**
   * Tests that an unsatisfiable REQUIRED context degrades to a placeholder.
   *
   * A required-context block whose context cannot be resolved makes
   * applyContextMapping() throw a MissingValueContextException. That must not
   * fatal the whole tree: the component-tree render path catches it and swaps in
   * the RenderSafeComponentContainer placeholder, so the rest of the page still
   * renders. Required-context blocks are only discoverable once the discovery
   * gate is relaxed, so this path could not be exercised before.
   *
   * @legacy-covers \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList::renderify
   */
  public function testUnsatisfiableRequiredContextDegradesToPlaceholder(): void {
    $this->generateComponentConfig();
    $this->installEntitySchema('node');
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_test_config_node_article']);

    // The stored mapping points the required "name" context at the node route
    // context, which carries no value in this render situation. The required
    // context is therefore missing, so applyContextMapping() throws a
    // MissingValueContextException that must degrade to a placeholder.
    $node = Node::create([
      'type' => 'article',
      'title' => 'Required context host',
      'field_canvas_test' => [
        [
          'uuid' => '8b5d0c3f-1e4a-4b9c-8d2e-3f4a5b6c7d8e',
          'component_id' => 'block.canvas_test_block_requires_contexts',
          'inputs' => [
            'label' => 'Ctx',
            'label_display' => '0',
            'context_mapping' => ['name' => '@node.node_route_context:node'],
          ],
        ],
      ],
    ]);
    $node->save();

    $tree = $node->get('field_canvas_test');
    \assert($tree instanceof ComponentTreeItemList);
    $build = $tree->toRenderable($node);
    $html = (string) $this->renderer->renderInIsolation($build);
    self::assertStringNotContainsString('Test Block with required context value', $html);
    // The block degrades to the RenderSafeComponentContainer placeholder. In the
    // default (non-verbose) logging state that renders the generic error message.
    // @see \Drupal\canvas\Element\RenderSafeComponentContainer::handleComponentException()
    self::assertStringContainsString('Oops, something went wrong! Site admins have been notified.', $html);
  }

  protected function triggerBrokenComponent(ComponentInterface $component): BrokenPluginManagerInterface {
    $decorator = \Drupal::service(BlockManagerInterface::class);
    \assert($decorator instanceof BlockManagerDecorator);
    /** @var \Drupal\Tests\canvas\Kernel\BrokenPluginManagerInterface */
    return (new \ReflectionProperty($decorator, 'decorated'))->getValue($decorator);
  }

  public function alter(ContainerBuilder $container): void {
    // Swap in the broken version of this class.
    // @see ::triggerBrokenComponent()
    // @see ::testIsBroken()
    $container->getDefinition('plugin.manager.block')->setClass(BrokenBlockManager::class);
  }

  protected function getExpectedVerboseErrorMessage(): string {
    return 'This block is broken or missing.';
  }

  public static function providerSymmetricallyTranslatableComponentInstanceScenarios(string $host_entity_type_id): \Generator {
    yield 'common scenario' => [
      'block.system_branding_block',
      [
        'label' => 'Branding is important, right?',
        'label_display' => 'visible',
        'use_site_logo' => FALSE,
        'use_site_name' => TRUE,
        'use_site_slogan' => TRUE,
      ],
      ['label'],
    ];

    yield 'nesting & config schema type resolution' => [
      BlockComponent::SOURCE_PLUGIN_ID . '.' . CanvasTestBlockInputTranslatability::PLUGIN_ID,
      [
        'label' => 'Translations matter!',
        'label_display' => 'visible',
        'top_level_translatable_regardless_of_type' => 'nope',
        'deeply_nested_translatable' => [
          [
            'foo' => 'Huh?',
            'bar' => 'Gitane',
          ],
        ],
      ],
      [
        'label',
        // 💡Anything can be marked translatable for block plugins' settings,
        // even `type: ignore`.
        'top_level_translatable_regardless_of_type',
        // 💡Every level of the settings is traversed; anything translatable
        // makes this top-level key eligible for translation.
        'deeply_nested_translatable',
      ],
    ];
  }

  public static function providerResolvedComponentInputs(): \Generator {
    yield 'Block missing' => [
      'block.missing_block',
      [],
      NULL,
    ];
    yield 'Block with no explicit settings' => [
      'block.canvas_test_block_input_none',
      [],
      [],
    ];
    yield 'Block with settings' => [
      'block.system_branding_block',
      [
        'use_site_logo' => TRUE,
        'use_site_name' => FALSE,
        'use_site_slogan' => TRUE,
      ],
      [
        'use_site_logo' => TRUE,
        'use_site_name' => FALSE,
        'use_site_slogan' => TRUE,
      ],
    ];
  }

}
