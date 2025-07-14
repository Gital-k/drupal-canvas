<?php

declare(strict_types=1);

namespace Drupal\xb_layout_builder\Plugin;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Layout\LayoutDefinition;
use Drupal\Core\Layout\LayoutInterface;
use Drupal\Core\Layout\LayoutPluginManager;
use Drupal\experience_builder\ComponentDoesNotMeetRequirementsException;
use Drupal\experience_builder\ComponentIncompatibilityReasonRepository;
use Drupal\experience_builder\ComponentSource\ComponentSourceManager;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Entity\ComponentInterface;
use Drupal\experience_builder\Entity\VersionedConfigEntityBase;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\BlockComponent;
use Drupal\xb_layout_builder\Plugin\ExperienceBuilder\ComponentSource\LayoutComponent;

/**
 * Decorator that auto-creates/updates an Experience Builder Component entity per Layout plugin.
 *
 * @see \Drupal\experience_builder\Entity\Component
 * @see docs/components.md#3.2
 */
final class LayoutManager extends LayoutPluginManager {

  /**
   * Create a new instance.
   *
   * @phpstan-ignore-next-line pluginManagerSetsCacheBackend.missingCacheBackend
   *   This is handled by the parent.
   */
  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler,
    ThemeHandlerInterface $theme_handler,
    protected ComponentIncompatibilityReasonRepository $reasonRepository,
    protected ComponentSourceManager $componentSourceManager,
  ) {
    parent::__construct($namespaces, $cache_backend, $module_handler, $theme_handler);
  }

  protected function setCachedDefinitions($definitions): array {
    parent::setCachedDefinitions($definitions);

    // Do not auto-create/update XB configuration when syncing config/deploying.
    // @todo Introduce a "XB development mode" similar to Twig's: https://www.drupal.org/node/3359728
    // @phpstan-ignore-next-line
    if (\Drupal::isConfigSyncing()) {
      return $definitions;
    }

    foreach ($definitions as $id => $definition) {
      \assert($definition instanceof LayoutDefinition);
      $componentId = \sprintf('layout.%s', $definition->id());

      $layout = $this->createInstance($id);
      if (!$layout instanceof LayoutInterface) {
        // If the layout plugin instance cannot be created, we cannot
        // create a Component entity for it.
        continue;
      }

      $component = Component::load($componentId);
      $settings = $layout->defaultConfiguration();
      if ($component instanceof ComponentInterface) {
        // If the component already exists, we can use its settings.
        $settings['slots'] = NULL;
      }
      $source = $this->componentSourceManager
        ->createInstance(LayoutComponent::SOURCE_PLUGIN_ID, [
          'local_source_id' => \sprintf($id),
          ...$settings,
        ]);
      \assert($source instanceof LayoutComponent);
      $version = $source->generateVersionHash();
      if (!$component instanceof Component) {
        $component = Component::create([
          'id' => $componentId,
          'label' => (string) $definition->getLabel(),
          'category' => (string) $definition->getCategory(),
          'source' => LayoutComponent::SOURCE_PLUGIN_ID,
          'provider' => $definition->getProvider(),
          'settings' => [
            'plugin_id' => $id,
            'local_source_id' => \sprintf($id),
            'default_settings' => [] + $settings,
          ],
          'status' => TRUE,
          'slots' => array_map(static fn ($region) => ['title' => (string) $region], $definition->getRegionLabels()),
          'active_version' => $version,
          'source_local_id' => $id,
          'versioned_properties' => [
            VersionedConfigEntityBase::ACTIVE_VERSION => ['settings' => $settings],
          ],
        ]);
      }
      else {

        $component->createVersion($version)
          ->deleteVersionIfExists(ComponentInterface::FALLBACK_VERSION);
        $component
          ->set('source', LayoutComponent::SOURCE_PLUGIN_ID);
        $component->setSettings($settings);
      }
      try {
        $component->getComponentSource()->checkRequirements();
        $component->save();
      }
      catch (ComponentDoesNotMeetRequirementsException $e) {
        $this->reasonRepository->storeReasons(BlockComponent::SOURCE_PLUGIN_ID, $id, $e->getMessages());
      }
    }
    return $definitions;
  }

}
