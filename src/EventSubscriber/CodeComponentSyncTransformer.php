<?php

declare(strict_types=1);

namespace Drupal\canvas\EventSubscriber;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\StorageTransformEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Controls how code components flow through Drupal config sync.
 *
 * Two modes, switched via `canvas.settings:code_components.sync_mode`:
 *
 * - `preserve_production` (default): on import, a code component that exists in
 *   the active store but not in the incoming sync storage is copied into the
 *   sync buffer so the importer treats it as already present and never deletes
 *   it. Tracked components (present in both) still update from sync.
 * - `cli`: on both import and export, every code component is removed from the
 *   sync buffer. Canvas CLI is the sole source of truth.
 *
 * "Code components" means `canvas.js_component.*` config entities and the
 * paired `canvas.component.*` entities whose `source` is `js` (created by
 * \Drupal\canvas\EntityHandlers\JavascriptComponentStorage::doPostSave()).
 */
final readonly class CodeComponentSyncTransformer implements EventSubscriberInterface {

  private const string MODE_PRESERVE_PRODUCTION = 'preserve_production';
  private const string MODE_CLI = 'cli';

  private const string JS_COMPONENT_PREFIX = 'canvas.' . JavaScriptComponent::ENTITY_TYPE_ID . '.';
  private const string COMPONENT_PREFIX = 'canvas.' . Component::ENTITY_TYPE_ID . '.';

  public function __construct(
    private ConfigFactoryInterface $configFactory,
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
      self::MODE_CLI => $this->stripAllCodeComponents($sync),
      self::MODE_PRESERVE_PRODUCTION => $this->copyDbOnlyCodeComponentsInto($sync),
      default => NULL,
    };
  }

  public function onExport(StorageTransformEvent $event): void {
    if ($this->mode() !== self::MODE_CLI) {
      return;
    }
    $sync = $event->getStorage();
    if ($sync->getCollectionName() !== StorageInterface::DEFAULT_COLLECTION) {
      return;
    }
    $this->stripAllCodeComponents($sync);
  }

  private function mode(): string {
    return $this->configFactory->get('canvas.settings')->get('code_components.sync_mode')
      ?? self::MODE_PRESERVE_PRODUCTION;
  }

  private function stripAllCodeComponents(StorageInterface $sync): void {
    foreach ($sync->listAll(self::JS_COMPONENT_PREFIX) as $name) {
      $sync->delete($name);
    }
    foreach ($sync->listAll(self::COMPONENT_PREFIX) as $name) {
      if ($this->isJsSourced($sync->read($name))) {
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

  private function isJsSourced(array|false $raw): bool {
    return is_array($raw) && ($raw['source'] ?? NULL) === JsComponent::SOURCE_PLUGIN_ID;
  }

}
