<?php

declare(strict_types=1);

namespace Drupal\canvas\Hook;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponentDiscovery;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Manages the in-memory 'auto_save' version on Component entities.
 *
 * When a JsComponent has an auto-save with changed props, the 'auto_save'
 * version is created as the ACTIVE version on the Component entity. This makes
 * all code paths (UI, PATCH, validation, rendering) naturally use it.
 * The original state is restored before save so it's never persisted.
 *
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent::AUTO_SAVE_VERSION
 */
final class ComponentAutoSaveVersionHooks {

  /**
   * Stores original entity state to restore in presave.
   *
   * @var array<string, array{active_version: string, versioned_properties: array}>
   */
  private static array $originalState = [];

  public function __construct(
    private readonly AutoSaveManager $autoSaveManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Makes 'auto_save' the active version on JsComponent-backed Components.
   */
  #[Hook('entity_load')]
  public function entityLoad(array $entities, string $entity_type_id): void {
    if ($entity_type_id !== Component::ENTITY_TYPE_ID) {
      return;
    }
    foreach ($entities as $entity) {
      \assert($entity instanceof Component);
      if ($entity->get('source') !== JsComponent::SOURCE_PLUGIN_ID) {
        continue;
      }
      $jsComponentId = $entity->get('source_local_id');
      $jsComponent = $this->entityTypeManager->getStorage(JavaScriptComponent::ENTITY_TYPE_ID)->load($jsComponentId);
      if (!$jsComponent instanceof JavaScriptComponent) {
        continue;
      }
      $autoSave = $this->autoSaveManager->getAutoSaveEntity($jsComponent);
      if ($autoSave->isEmpty()) {
        continue;
      }
      \assert($autoSave->entity instanceof JavaScriptComponent);
      try {
        $ephemeralSdc = JsComponentDiscovery::buildEphemeralSdcPluginInstance($autoSave->entity);
        $propFieldDefs = GeneratedFieldExplicitInputUxComponentSourceBase::getPropsForComponentPlugin($ephemeralSdc);
        $slotDefs = $ephemeralSdc->metadata->slots;
      }
      catch (\Exception) {
        continue;
      }

      // Store original state for restoration in presave.
      self::$originalState[$entity->id()] = [
        'active_version' => $entity->getActiveVersion(),
        'versioned_properties' => $entity->get('versioned_properties'),
      ];

      // createVersion makes 'auto_save' the active version, moves the
      // original active to its hash key, and sets loadedVersion.
      $entity->createVersion(JsComponent::AUTO_SAVE_VERSION);
      // Populate the new active version's settings and
      // fallback_metadata.
      $entity->setSettings(['prop_field_definitions' => $propFieldDefs]);
      $entity->set('fallback_metadata', ['slot_definitions' => $slotDefs]);
    }
  }

  /**
   * Strips the 'auto_save' version before Component entities are saved.
   *
   * Only removes the 'auto_save' entry — preserves any new versions created
   * between load and save (e.g., by discovery during JsComponent publish).
   */
  #[Hook('entity_presave')]
  public function entityPresave(EntityInterface $entity): void {
    if (!$entity instanceof Component) {
      return;
    }
    // Strip 'auto_save' from versioned_properties — it must never be persisted.
    $vp = $entity->get('versioned_properties');
    if (\is_array($vp) && \array_key_exists(JsComponent::AUTO_SAVE_VERSION, $vp)) {
      unset($vp[JsComponent::AUTO_SAVE_VERSION]);
      $entity->set('versioned_properties', $vp);
    }
    // If active_version is still 'auto_save' (discovery didn't change it),
    // restore the original. If discovery changed it to a real hash, keep it.
    if ($entity->getActiveVersion() === JsComponent::AUTO_SAVE_VERSION && isset(self::$originalState[$entity->id()])) {
      $entity->set('active_version', self::$originalState[$entity->id()]['active_version']);
    }
    // loadedVersion must match active_version for preSave() to succeed.
    $entity->resetToActiveVersion();
    unset(self::$originalState[$entity->id()]);
  }

}
