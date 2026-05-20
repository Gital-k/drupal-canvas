<?php

declare(strict_types=1);

namespace Drupal\canvas\EventSubscriber;

use Drupal\canvas\Config\CodeComponentSyncMode;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\StorageTransformEvent;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Controls how code components flow through Drupal config sync.
 *
 * Two modes, switched via `canvas.settings:code_components.sync_mode`:
 *
 * - `preserve_production` (default): on import, a code component that exists in
 *   active config but not in sync is preserved — the subscriber writes it into
 *   the storage passed to the transform event so the importer treats it as
 *   already present and never deletes it. Components present in both active
 *   and sync still update from sync.
 * - `cli`: on both import and export, every code component is removed from the
 *   storage passed to the transform event so neither side touches them. Canvas
 *   CLI is the sole source of truth.
 *
 * "Code components" means `canvas.js_component.*` config entities and the
 * paired `canvas.component.*` entities whose `source` is `js`. Both are
 * filtered because config sync does not regenerate the paired `component`
 * entity from `js_component` on its own — that only happens via
 * \Drupal\canvas\EntityHandlers\JavascriptComponentStorage::doPostSave().
 *
 * @see \Drupal\canvas\Config\CodeComponentSyncMode
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent::SOURCE_PLUGIN_ID
 * @see \Drupal\canvas\EntityHandlers\JavascriptComponentStorage::doPostSave()
 * @see \Drupal\canvas\EventSubscriber\ComponentTreeConfigEntityTransformer
 */
final readonly class CodeComponentSyncTransformer implements EventSubscriberInterface {

  private const string JS_COMPONENT_PREFIX = 'canvas.' . JavaScriptComponent::ENTITY_TYPE_ID . '.';
  private const string COMPONENT_PREFIX = 'canvas.' . Component::ENTITY_TYPE_ID . '.';

  public function __construct(
    private ConfigFactoryInterface $configFactory,
    #[Autowire(service: 'config.storage')]
    private StorageInterface $activeStorage,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ConfigEvents::STORAGE_TRANSFORM_IMPORT => ['onImport'],
      ConfigEvents::STORAGE_TRANSFORM_EXPORT => ['onExport'],
    ];
  }

  public function onImport(StorageTransformEvent $event): void {
    $sync = $event->getStorage();
    if ($sync->getCollectionName() !== StorageInterface::DEFAULT_COLLECTION) {
      return;
    }
    match ($this->mode()) {
      CodeComponentSyncMode::Cli => $this->stripAllCodeComponents($sync),
      CodeComponentSyncMode::PreserveProduction => $this->copyDbOnlyCodeComponentsInto($sync),
    };
  }

  public function onExport(StorageTransformEvent $event): void {
    if ($this->mode() !== CodeComponentSyncMode::Cli) {
      return;
    }
    $sync = $event->getStorage();
    if ($sync->getCollectionName() !== StorageInterface::DEFAULT_COLLECTION) {
      return;
    }
    $this->stripAllCodeComponents($sync);
  }

  private function mode(): CodeComponentSyncMode {
    $raw = $this->configFactory->get('canvas.settings')->get('code_components.sync_mode');
    return is_string($raw)
      ? (CodeComponentSyncMode::tryFrom($raw) ?? CodeComponentSyncMode::PreserveProduction)
      : CodeComponentSyncMode::PreserveProduction;
  }

  private function stripAllCodeComponents(StorageInterface $sync): void {
    foreach ($sync->listAll(self::JS_COMPONENT_PREFIX) as $name) {
      $sync->delete($name);
    }
    foreach ($sync->listAll(self::COMPONENT_PREFIX) as $name) {
      $raw = $sync->read($name);
      if (is_array($raw) && $this->isJsSourced($raw)) {
        $sync->delete($name);
      }
    }
  }

  private function copyDbOnlyCodeComponentsInto(StorageInterface $sync): void {
    foreach ($this->activeStorage->listAll(self::JS_COMPONENT_PREFIX) as $name) {
      if (!$sync->exists($name)) {
        $raw = $this->activeStorage->read($name);
        if (is_array($raw)) {
          $sync->write($name, $raw);
        }
      }
    }
    foreach ($this->activeStorage->listAll(self::COMPONENT_PREFIX) as $name) {
      if ($sync->exists($name)) {
        continue;
      }
      $raw = $this->activeStorage->read($name);
      if (is_array($raw) && $this->isJsSourced($raw)) {
        $sync->write($name, $raw);
      }
    }
  }

  private function isJsSourced(array $raw): bool {
    return ($raw['source'] ?? NULL) === JsComponent::SOURCE_PLUGIN_ID;
  }

}
