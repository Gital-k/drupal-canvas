<?php

declare(strict_types=1);

namespace Drupal\Tests\xb_layout_builder\Kernel\Plugin\ExperienceBuilder\ComponentSource;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Entity\ComponentInterface;
use Drupal\experience_builder\Plugin\ComponentPluginManager;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\system\Entity\Menu;
use Drupal\Tests\experience_builder\Kernel\Plugin\ExperienceBuilder\ComponentSource\ComponentSourceTestBase;
use Drupal\xb_layout_builder\Plugin\ExperienceBuilder\ComponentSource\LayoutComponent;
use Drupal\xb_layout_builder\Plugin\LayoutManager;
use Drupal\xb_layout_builder_test\Plugin\Layout\TestLayout;

/**
 * @coversDefaultClass \Drupal\xb_layout_builder\Plugin\ExperienceBuilder\ComponentSource\LayoutComponent
 * @group experience_builder
 * @group layout_builder
 * @group #slow
 * @phpstan-import-type ComponentConfigEntityId from \Drupal\experience_builder\Entity\Component
 */
final class LayoutComponentTest extends ComponentSourceTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'layout_builder',
    'layout_discovery',
    'xb_layout_builder',
    'xb_layout_builder_test',
  ];

  public function testDiscovery(): array {
    self::assertSame([], $this->findIneligibleComponents(LayoutComponent::SOURCE_PLUGIN_ID, 'xb_layout_builder_test'));
    self::assertSame([], $this->findCreatedComponentConfigEntities(LayoutComponent::SOURCE_PLUGIN_ID, 'xb_layout_builder_test'));

    $layoutManager = \Drupal::service(LayoutManager::class);
    \assert($layoutManager instanceof LayoutManager);
    // Calling `getDefinitions()` will trigger setCachedDefinitions in the
    // LayoutManager class, which will auto-create the Component entities.
    $layoutManager->clearCachedDefinitions();
    $layoutManager->getDefinitions();
    $createdComponents = \array_map(static fn(ComponentInterface $component) => $component->id(), Component::loadMultiple());
    $testPlugins = \array_filter($createdComponents, static fn(string $id) => \str_starts_with($id, 'layout.xb_layout_builder_test'));
    self::assertSame([
      'layout.xb_layout_builder_test_layout' => 'layout.xb_layout_builder_test_layout',
    ], $testPlugins);

    return $testPlugins;
  }

  /**
   * @depends testDiscovery
   */
  public function testGetReferencedPluginClass(array $component_ids): void {
    self::assertSame([
      'layout.xb_layout_builder_test_layout' => TestLayout::class,
    ], $this->getReferencedPluginClasses($component_ids));
  }

  /**
   * @depends testDiscovery
   */
  public function testSettings(array $component_ids): void {
    self::assertSame([
      'layout.xb_layout_builder_test_layout' => [
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
      'layout.xb_layout_builder_test_layout' => [
        'html' => <<<HTML
<div class="layout layout--xb-layout-builder-test-layout">
 <div class="xb-main-test layout__region layout__region--main">

 </div>
 <div class="xb-sidebar-test layout__region layout__region--sidebar">

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
        'xb_layout_builder_test',
      ],
      'config' => [
        'system.menu.menu' => 'system.menu.menu',
      ],
    ];
    self::assertSame([
      'layout.xb_layout_builder_test_layout' => $dependencies,
    ], $this->callSourceMethodForEach('calculateDependencies', $component_ids));
  }

  public static function providerRenderComponentFailure(): \Generator {
    yield "Layout render failure" => [
      'component_id' => 'layout.xb_layout_builder_test_layout',
      'inputs' => [],
      'expected_validation_errors' => [],
      'expected_exception' => NULL,
      'expected_output_selector' => '.column-one',
    ];
  }

  public static function getExpectedClientSideInfo(): array {
    return [
      'layout.xb_layout_builder_test_layout' => [
        'expected_output_selectors' => ['.xb-main-test'],
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
    /** @var \Drupal\experience_builder\Entity\ComponentInterface */
    return Component::load('layout.xb_layout_builder_test_layout');
  }

  protected function createAndSaveUnusedComponentForFallbackTesting(): ComponentInterface {
    /** @var \Drupal\experience_builder\Entity\ComponentInterface */
    return Component::load('layout.xb_layout_builder_unused_layout');
  }

  protected function forceComponentFallback(ComponentInterface $used_component, ComponentInterface $unused_component): void {
    \Drupal::service(ModuleInstallerInterface::class)->uninstall(['xb_layout_builder_test']);
  }

  protected function recoverComponentFallback(ComponentInterface $component): void {
    \Drupal::service(ModuleInstallerInterface::class)->install(['xb_layout_builder_test']);
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
    /** @var \Drupal\experience_builder\Entity\ComponentInterface */
    return Component::load('layout.xb_layout_builder_test_layout');
  }

  protected function createAndSaveUnusedComponentForUninstallValidationTesting(): ComponentInterface {
    /** @var \Drupal\experience_builder\Entity\ComponentInterface */
    return Component::load('layout.xb_layout_builder_unused_layout');
  }

  protected function getNotAllowedModuleForUninstallValidatorTesting(): string {
    return 'layout_builder';
  }

  protected function getAllowedModuleForUninstallValidatorTesting(): string {
    Menu::load('menu')?->delete();
    return 'xb_layout_builder_test';
  }

}
