<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_layout_builder\Kernel\Plugin\Canvas\ComponentSource;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\canvas\Plugin\ComponentPluginManager;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\system\Entity\Menu;
use Drupal\Tests\canvas\Kernel\BrokenPluginManagerInterface;
use Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource\ComponentSourceTestBase;
use Drupal\canvas_layout_builder\Plugin\Canvas\ComponentSource\LayoutComponent;
use Drupal\canvas_layout_builder\Plugin\LayoutManager;
use Drupal\canvas_layout_builder_test\Plugin\Layout\TestLayout;

/**
 * @coversDefaultClass \Drupal\canvas_layout_builder\Plugin\Canvas\ComponentSource\LayoutComponent
 * @group canvas
 * @group layout_builder
 * @group #slow
 * @phpstan-import-type ComponentConfigEntityId from \Drupal\canvas\Entity\Component
 */
final class LayoutComponentTest extends ComponentSourceTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'layout_builder',
    'layout_discovery',
    'canvas_layout_builder',
    'canvas_layout_builder_test',
  ];

  public function testDiscovery(): array {
    self::assertSame([], $this->findIneligibleComponents(LayoutComponent::SOURCE_PLUGIN_ID, 'canvas_layout_builder_test'));
    self::assertSame([], $this->findCreatedComponentConfigEntities(LayoutComponent::SOURCE_PLUGIN_ID, 'canvas_layout_builder_test'));

    $layoutManager = \Drupal::service(LayoutManager::class);
    \assert($layoutManager instanceof LayoutManager);
    // Calling `getDefinitions()` will trigger setCachedDefinitions in the
    // LayoutManager class, which will auto-create the Component entities.
    $layoutManager->clearCachedDefinitions();
    $layoutManager->getDefinitions();
    $createdComponents = \array_map(static fn(ComponentInterface $component) => $component->id(), Component::loadMultiple());
    $testPlugins = \array_filter($createdComponents, static fn(string $id) => \str_starts_with($id, 'layout.canvas_layout_builder_test'));
    self::assertSame([
      'layout.canvas_layout_builder_test_layout' => 'layout.canvas_layout_builder_test_layout',
    ], $testPlugins);

    return $testPlugins;
  }

  /**
   * @depends testDiscovery
   */
  public function testGetReferencedPluginClass(array $component_ids): void {
    self::assertSame([
      'layout.canvas_layout_builder_test_layout' => TestLayout::class,
    ], $this->getReferencedPluginClasses($component_ids));
  }

  /**
   * @depends testDiscovery
   */
  public function testSettings(array $component_ids): void {
    self::assertSame([
      'layout.canvas_layout_builder_test_layout' => [
        'label' => '',
      ],
    ], $this->getAllSettings($component_ids));
  }

  /**
   * @depends testDiscovery
   */
  public function testRenderComponentLive(array $component_ids): void {
    $this->assertNotEmpty($component_ids);
    $rendered = $this->renderComponentsLive(
      $component_ids,
      get_default_input: fn (Component $component) => [],
    );

    foreach ($rendered as &$render) {
      // Do some basic whitespace normalization.
      // Makes the test more readable when comparing markup below.
      $render['html'] = \preg_replace('/[ \t]+/', ' ', $render['html']);
      $render['html'] = \trim($render['html']);
    }

    $default_render_cache_contexts = [
      'languages:language_interface',
      'theme',
      'user.permissions',
    ];
    $default_cacheability = (new CacheableMetadata())
      ->setCacheContexts($default_render_cache_contexts);
    $this->assertEquals([
      'layout.canvas_layout_builder_test_layout' => [
        'html' => <<<HTML
<div class="layout layout--canvas-layout-builder-test-layout">
 <div class="canvas-main-test layout__region layout__region--main">

 </div>
 <div class="canvas-sidebar-test layout__region layout__region--sidebar">

 </div>
 </div>
HTML,
        'cacheability' => $default_cacheability,
        'attachments' => [],
      ],
    ], $rendered);
  }

  /**
   * @depends testDiscovery
   */
  public function testCalculateDependencies(array $component_ids): void {
    $dependencies = [
      'module' => [
        'canvas_layout_builder_test',
      ],
      'config' => [
        'system.menu.menu' => 'system.menu.menu',
      ],
    ];
    self::assertSame([
      'layout.canvas_layout_builder_test_layout' => $dependencies,
    ], $this->callSourceMethodForEach('calculateDependencies', $component_ids));
  }

  public static function providerRenderComponentFailure(): \Generator {
    yield "Layout render failure" => [
      'component_id' => 'layout.canvas_layout_builder_test_layout',
      'inputs' => [],
      'expected_validation_errors' => [],
      'expected_exception' => NULL,
      'expected_output_selector' => '.column-one',
    ];
  }

  public static function getExpectedClientSideInfo(): array {
    return [
      'layout.canvas_layout_builder_test_layout' => [
        'expected_output_selectors' => ['.canvas-main-test'],
        'source' => 'Layout builder layout',
        'metadata' => [
          'slots' => [
            'main' => [
              'title' => 'main',
              'description' => 'main region for the layout.',
              'examples' => [
                '<div>Test content</div>',
              ],
            ],
            'sidebar' => [
              'title' => 'sidebar',
              'description' => 'sidebar region for the layout.',
              'examples' => [
                '<div>Test content</div>',
              ],
            ],
          ],
        ],
        'field_data' => [],
        'dynamic_prop_source_candidates' => [],
        'transforms' => [],
      ],
    ];
  }

  protected function createAndSaveInUseComponentForFallbackTesting(): ComponentInterface {
    /** @var \Drupal\canvas\Entity\ComponentInterface */
    return Component::load('layout.canvas_layout_builder_test_layout');
  }

  protected function createAndSaveUnusedComponentForFallbackTesting(): ComponentInterface {
    /** @var \Drupal\canvas\Entity\ComponentInterface */
    return Component::load('layout.canvas_layout_builder_unused_layout');
  }

  protected function forceComponentFallback(ComponentInterface $used_component, ComponentInterface $unused_component): void {
    \Drupal::service(ModuleInstallerInterface::class)->uninstall(['canvas_layout_builder_test']);
  }

  protected function recoverComponentFallback(ComponentInterface $component): void {
    \Drupal::service(ModuleInstallerInterface::class)->install(['canvas_layout_builder_test']);
    $this->generateComponentConfig();
  }

  protected function generateComponentConfig(): void {
    $layoutManager = \Drupal::service(LayoutManager::class);
    \assert($layoutManager instanceof LayoutManager);
    // Calling `getDefinitions()` will trigger setCachedDefinitions in the
    // LayoutManager class, which will auto-create the Component entities.
    $layoutManager->clearCachedDefinitions();
    $layoutManager->getDefinitions();
    // SDC components.
    $componentPluginManager = $this->container->get(ComponentPluginManager::class);
    $componentPluginManager->clearCachedDefinitions();
    $componentPluginManager->getDefinitions();
  }

  protected function generateCrashTestDummyComponentTree(string $component_id, array $inputs, bool $assertCount = TRUE): ComponentTreeItemList {
    return parent::generateCrashTestDummyComponentTree(
      $component_id,
      $inputs,
    );
  }

  protected function deleteConfigAndTriggerComponentFallback(ComponentInterface $used_component, ComponentInterface $unused_component): void {
    // Added as a dependency in TestLayout::calculateDependencies() for the
    // sole purpose of testing the fallback mechanism.
    Menu::load('menu')?->delete();
  }

  protected function createAndSaveInUseComponentForUninstallValidationTesting(): ComponentInterface {
    /** @var \Drupal\canvas\Entity\ComponentInterface */
    return Component::load('layout.canvas_layout_builder_test_layout');
  }

  protected function createAndSaveUnusedComponentForUninstallValidationTesting(): ComponentInterface {
    /** @var \Drupal\canvas\Entity\ComponentInterface */
    return Component::load('layout.canvas_layout_builder_unused_layout');
  }

  protected function getNotAllowedModuleForUninstallValidatorTesting(): string {
    return 'layout_builder';
  }

  protected function getAllowedModuleForUninstallValidatorTesting(): string {
    Menu::load('menu')?->delete();
    return 'canvas_layout_builder_test';
  }

  protected function triggerBrokenComponent(ComponentInterface $component): ?BrokenPluginManagerInterface {
    // @todo not sure what to do here.
    throw new \LogicException('Not implemented.');
  }

}
