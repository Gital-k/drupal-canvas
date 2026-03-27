<?php

declare(strict_types=1);

namespace Drupal\canvas\AutoSave;

use Drupal\canvas\Entity\ComponentTreeEntityInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\TypedData\PrimitiveInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\canvas\AutoSaveEntity;
use Drupal\canvas\Controller\ApiContentControllers;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\StagedConfigUpdate;
use Drupal\canvas\Entity\CanvasHttpApiEligibleConfigEntityInterface;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Defines a class for storing and retrieving auto-save data.
 *
 * Auto-save data is stored forever in the key-value store. So in principle, it
 * can grow forever.
 * However, auto-save entries for a (content or config) entity are deleted when:
 * - publishing an entity's auto-save entry
 * - deleting an entity.
 *
 * @see \Drupal\canvas\Controller\ApiAutoSaveController::post()
 * @see \Drupal\canvas\Hook\AutoSaveHooks::entityDelete()
 */
class AutoSaveManager implements EventSubscriberInterface {

  public const CACHE_TAG = 'canvas__auto_save';
  public const string PUBLISH_PERMISSION = 'publish auto-saves';
  public const string AUTO_SAVE_STORE = 'canvas.auto_save';
  public const string FORM_VIOLATIONS_STORE = 'canvas.form_violations';
  public const string COMPONENT_INSTANCE_FORM_VIOLATIONS_STORE = 'canvas.component_instance_form_violations';

  const ENTITY_DUPLICATE_SUFFIX = ' (Copy)';

  /**
   * The key used for the component tree in config override translations.
   *
   * Only this key is stored for config entity translations. This assumes that
   * ContentTemplate and PageRegion — the only translatable Canvas config entity
   * types in phase 1 — have no other translatable data beyond the component
   * tree. Future translatable config entity types may need to extend this.
   */
  private const CONFIG_OVERRIDE_TRANSLATION_KEY = 'component_tree';

  /**
   * The auto-save key-value store.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreInterface
   */
  private KeyValueStoreInterface $autoSaveStore;

  /**
   * The form violations key-value store.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreInterface
   *
   * @todo Remove this in https://drupal.org/i/3505018.
   */
  private KeyValueStoreInterface $formViolationsStore;

  /**
   * The component instance form violations key-value store.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreInterface
   *
   * @todo Remove this in https://drupal.org/i/3505018.
   */
  private KeyValueStoreInterface $componentInstanceFormViolationsStore;

  /**
   * Constructs an AutoSaveManager object.
   *
   * @param \Drupal\Core\Config\ConfigManagerInterface $configManager
   *   The config manager.
   * @param \Drupal\Core\Cache\CacheTagsInvalidatorInterface $cacheTagsInvalidator
   *   The cache tags invalidator.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The static cache backend.
   * @param \Drupal\Core\KeyValueStore\KeyValueFactoryInterface $keyValueFactory
   *   The key-value factory.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler service.
   */
  public function __construct(
    private readonly ConfigManagerInterface $configManager,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'cache.static')]
    private readonly CacheBackendInterface $cache,
    #[Autowire(service: 'keyvalue')]
    KeyValueFactoryInterface $keyValueFactory,
    private readonly AccountProxyInterface $currentUser,
    private readonly TimeInterface $time,
    private readonly LanguageManagerInterface $languageManager,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {
    $this->autoSaveStore = $keyValueFactory->get(self::AUTO_SAVE_STORE);
    $this->formViolationsStore = $keyValueFactory->get(self::FORM_VIOLATIONS_STORE);
    $this->componentInstanceFormViolationsStore = $keyValueFactory->get(self::COMPONENT_INSTANCE_FORM_VIOLATIONS_STORE);
  }

  /**
   * Saves an entity's auto-save data.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to save.
   * @param string|null $clientId
   *   Optional client ID.
   * @param string|null $langcode
   *   Optional langcode. When provided for config entities with a non-default
   *   langcode, the component_tree is saved as a LanguageConfigOverride.
   *   Requires the config_translation module.
   */
  public function saveEntity(EntityInterface $entity, ?string $clientId = NULL, ?string $langcode = NULL): void {
    // Handle config entity translation via config overrides.
    if ($langcode !== NULL && $this->isConfigEntityTranslation($entity, $langcode)) {
      $this->saveConfigEntityTranslation($entity, $langcode, $clientId);
      return;
    }

    $key = $this->getAutoSaveKey($entity);
    $data = self::normalizeEntity($entity);
    $data_hash = self::generateHash($data);
    $original_hash = $this->getUnchangedHash($entity);
    $has_form_violations = FALSE;
    if ($entity instanceof FieldableEntityInterface) {
      $has_form_violations = $this->getEntityFormViolations($entity)->count() > 0;
    }
    if ($original_hash !== NULL && \hash_equals($original_hash, $data_hash) && !$has_form_violations) {
      $this->delete($entity);
      return;
    }

    $auto_save_data = [
      'entity_type' => $entity->getEntityTypeId(),
      'entity_id' => $entity->id(),
      'data' => $entity->toArray(),
      'langcode' => $entity->language()->getId(),
      'label' => $entity->label(),
      'data_hash' => $data_hash,
      'client_id' => $clientId,
      'owner' => (int) $this->currentUser->id(),
      'updated' => $this->time->getRequestTime(),
    ];
    $this->autoSaveStore->set($key, $auto_save_data);
    $this->cache->delete($key);
    $this->cacheTagsInvalidator->invalidateTags([self::CACHE_TAG]);
  }

  /**
   * Gets the auto-save entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to retrieve auto-save data for.
   * @param string|null $langcode
   *   Optional langcode. When provided for config entities with a non-default
   *   langcode, loads from LanguageConfigOverride storage.
   *   Requires the config_translation module.
   *
   * @return \Drupal\canvas\AutoSaveEntity
   *   The auto-save entity, or an empty AutoSaveEntity if none exists.
   */
  public function getAutoSaveEntity(EntityInterface $entity, ?string $langcode = NULL): AutoSaveEntity {
    // Handle config entity translation via config overrides.
    if ($langcode !== NULL && $this->isConfigEntityTranslation($entity, $langcode)) {
      return $this->getConfigEntityTranslationAutoSave($entity, $langcode);
    }

    $key = $this->getAutoSaveKey($entity);
    $cached = $this->cache->get($key);
    if ($cached) {
      \assert($cached->data instanceof AutoSaveEntity);
      return $cached->data;
    }
    $auto_save_data = $this->autoSaveStore->get($key);
    if (\is_null($auto_save_data)) {
      return AutoSaveEntity::empty();
    }

    \assert(\is_array($auto_save_data));
    \assert(\array_key_exists('data', $auto_save_data));
    \assert(\array_key_exists('entity_type', $auto_save_data));
    \assert(\is_array($auto_save_data['data']));
    // Create an entity from the stored values, but don't call ::enforceIsNew on
    // it to avoid possible issues where someone accidentally calls ::save on
    // the entity. Calling code that needs to reflect the fact that the entity
    // is not new should call ::enforceIsNew as required.
    $auto_save_entity = new AutoSaveEntity(
      $this->entityTypeManager->getStorage($auto_save_data['entity_type'])->create($auto_save_data['data']),
      $auto_save_data['data_hash'],
      $auto_save_data['client_id']
    );
    // Store in static cache to avoid the overhead of calling Entity::create
    // multiple times during layout preview rendering.
    $this->cache->set($key, $auto_save_entity, tags: [self::CACHE_TAG]);
    return $auto_save_entity;
  }

  /**
   * Returns TRUE if this is a config entity for a non-default language.
   *
   * Checks that the entity is a config entity, that config_translation is
   * enabled, and that the langcode differs from the site default.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to check.
   * @param string $langcode
   *   The language code to check against the site default.
   *
   * @return bool
   *   TRUE if this should use the config override translation path.
   */
  private function isConfigEntityTranslation(EntityInterface $entity, string $langcode): bool {
    if (!$entity instanceof ConfigEntityInterface) {
      return FALSE;
    }
    // Config entity translation requires the config_translation module.
    if (!$this->moduleHandler->moduleExists('config_translation')) {
      return FALSE;
    }
    $defaultLangcode = $this->languageManager->getDefaultLanguage()->getId();
    return $langcode !== $defaultLangcode;
  }

  /**
   * Saves a config entity translation auto-save to the key-value store.
   *
   * Stores data as a LanguageConfigOverride entry. Only the component_tree
   * key is stored (phase 1 limitation).
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The config entity to save a translation for.
   * @param string $langcode
   *   The non-default language code for the translation.
   * @param string|null $clientId
   *   Optional client ID.
   */
  private function saveConfigEntityTranslation(EntityInterface $entity, string $langcode, ?string $clientId): void {
    \assert($entity instanceof ConfigEntityInterface);
    \assert($entity instanceof ComponentTreeEntityInterface);

    $key = $this->getConfigOverrideAutoSaveKey($entity, $langcode);
    $componentTree = $entity->toArray()[self::CONFIG_OVERRIDE_TRANSLATION_KEY] ?? [];

    // Don't save if component_tree is empty — there is nothing to translate
    // yet and storing an empty entry can cause initialization errors when the
    // Canvas editor tries to reconstruct the entity from the auto-save data.
    if (empty($componentTree)) {
      return;
    }

    $data_hash = self::generateHash([self::CONFIG_OVERRIDE_TRANSLATION_KEY => $componentTree]);
    // Only the component_tree key is stored for config entity translations.
    // This is intentional for phase 1: ContentTemplate and PageRegion config
    // entities have no other translatable data. If future Canvas config entity
    // types introduce additional translatable properties, this method must be
    // updated to handle them.
    $auto_save_data = [
      'entity_type' => $entity->getEntityTypeId(),
      'entity_id' => $entity->id(),
      'config_name' => $entity->getConfigDependencyName(),
      'langcode' => $langcode,
      'data' => [self::CONFIG_OVERRIDE_TRANSLATION_KEY => $componentTree],
      'label' => $entity->label(),
      'data_hash' => $data_hash,
      'client_id' => $clientId,
      'owner' => (int) $this->currentUser->id(),
      'updated' => $this->time->getRequestTime(),
      'is_config_override' => TRUE,
    ];

    $this->autoSaveStore->set($key, $auto_save_data);
    $this->cache->delete($key);
    $this->cacheTagsInvalidator->invalidateTags([self::CACHE_TAG]);
  }

  /**
   * Loads a config entity translation auto-save from the key-value store.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The config entity.
   * @param string $langcode
   *   The language code of the translation to load.
   *
   * @return \Drupal\canvas\AutoSaveEntity
   *   The auto-save entity, or an empty AutoSaveEntity if none exists.
   */
  private function getConfigEntityTranslationAutoSave(EntityInterface $entity, string $langcode): AutoSaveEntity {
    \assert($entity instanceof ConfigEntityInterface);

    $key = $this->getConfigOverrideAutoSaveKey($entity, $langcode);
    $cached = $this->cache->get($key);
    if ($cached) {
      \assert($cached->data instanceof AutoSaveEntity);
      return $cached->data;
    }

    $auto_save_data = $this->autoSaveStore->get($key);
    if (\is_null($auto_save_data)) {
      return AutoSaveEntity::empty();
    }

    \assert(\is_array($auto_save_data));
    // Reconstruct the entity with the overridden component_tree merged in.
    $base_data = $entity->toArray();
    $base_data[self::CONFIG_OVERRIDE_TRANSLATION_KEY] = $auto_save_data['data'][self::CONFIG_OVERRIDE_TRANSLATION_KEY] ?? [];

    $auto_save_entity = new AutoSaveEntity(
      $this->entityTypeManager->getStorage($entity->getEntityTypeId())->create($base_data),
      $auto_save_data['data_hash'],
      $auto_save_data['client_id']
    );
    $this->cache->set($key, $auto_save_entity, tags: [self::CACHE_TAG]);
    return $auto_save_entity;
  }

  /**
   * Returns the key-value store key for a config override auto-save.
   *
   * Uses a distinct format from getAutoSaveKey() to avoid collisions:
   * "entity_type:entity_id:langcode:config_override"
   *
   * @param \Drupal\Core\Config\Entity\ConfigEntityInterface $entity
   *   The config entity.
   * @param string $langcode
   *   The language code.
   *
   * @return string
   *   The key-value store key.
   */
  private function getConfigOverrideAutoSaveKey(ConfigEntityInterface $entity, string $langcode): string {
    return $entity->getEntityTypeId() . ':' . $entity->id() . ':' . $langcode . ':config_override';
  }

  /**
   * Saves form violations for a fieldable entity.
   *
   * @todo Remove this in https://drupal.org/i/3505018.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity whose violations to save.
   * @param \Symfony\Component\Validator\ConstraintViolationListInterface|null $violations
   *   The violations to store, or NULL to delete existing violations.
   *
   * @return $this
   */
  public function saveEntityFormViolations(FieldableEntityInterface $entity, ?ConstraintViolationListInterface $violations = NULL): self {
    $key = self::getAutoSaveKey($entity);
    if ($violations === NULL) {
      $this->formViolationsStore->delete($key);
      return $this;
    }
    $this->formViolationsStore->set($key, $violations);
    $this->cache->delete($key);
    return $this;
  }

  /**
   * Gets form violations for a fieldable entity.
   *
   * @todo Remove this in https://drupal.org/i/3505018.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity whose violations to retrieve.
   *
   * @return \Symfony\Component\Validator\ConstraintViolationListInterface
   *   The stored violations, or an empty list if none exist.
   */
  public function getEntityFormViolations(FieldableEntityInterface $entity): ConstraintViolationListInterface {
    return $this->formViolationsStore->get(self::getAutoSaveKey($entity)) ?? new ConstraintViolationList();
  }

  /**
   * Saves a component instance form violation.
   *
   * @todo Remove this in https://drupal.org/i/3505018 and
   *    https://drupal.org/i/3500795.
   *
   * @param string $component_uuid
   *   The component instance UUID.
   * @param \Symfony\Component\Validator\ConstraintViolationListInterface|null $violations
   *   The violations to store, or NULL to delete existing violations.
   *
   * @return $this
   */
  public function saveComponentInstanceFormViolations(string $component_uuid, ?ConstraintViolationListInterface $violations = NULL): self {
    if ($violations === NULL) {
      $this->componentInstanceFormViolationsStore->delete($component_uuid);
      return $this;
    }
    $this->componentInstanceFormViolationsStore->set($component_uuid, $violations);
    return $this;
  }

  /**
   * Gets form violations for a component instance.
   *
   * @todo Remove this in https://drupal.org/i/3505018 and
   *    https://drupal.org/i/3500795.
   *
   * @param string $component_uuid
   *   The component instance UUID.
   *
   * @return \Symfony\Component\Validator\ConstraintViolationListInterface
   *   The stored violations, or an empty list if none exist.
   */
  public function getComponentInstanceFormViolations(string $component_uuid): ConstraintViolationListInterface {
    return $this->componentInstanceFormViolationsStore->get($component_uuid) ?? new ConstraintViolationList();
  }

/**
 * Normalizes an entity to a stable array for hashing and storage.
 *
 * @param \Drupal\Core\Entity\EntityInterface $entity
 *   The entity to normalize.
 *
 * @return array<string, mixed>
 *   The normalized entity data.
 */
  private static function normalizeEntity(EntityInterface $entity): array {
    if (!$entity instanceof FieldableEntityInterface) {
      if ($entity instanceof ComponentTreeEntityInterface && $entity instanceof ConfigEntityInterface) {
        $tree = $entity->getComponentTree();
        foreach ($tree as $component) {
          \assert($component instanceof ComponentTreeItem);
          $component->optimizeInputs();
        }
        $entity->setComponentTree($tree->getValue());
      }
      return $entity->toArray();
    }
    $normalized = [];
    $fields = $entity->getFields();
    if ($entity instanceof EntityChangedInterface) {
      $fields = \array_filter(
        $fields,
        static fn (FieldItemListInterface $field) => $field->getFieldDefinition()->getType() !== 'changed'
      );
      $fields = \array_filter(
        $fields,
        static fn (FieldItemListInterface $field) => $field->getFieldDefinition()->getType() !== 'externalUpdates'
      );
    }
    foreach (\array_keys($fields) as $name) {
      $items = $entity->get($name);
      if ($items->isEmpty()) {
        continue;
      }
      $normalized[$name] = \array_map(
        static function (FieldItemInterface $item): array {
          if ($item instanceof ComponentTreeItem) {
            $item->optimizeInputs();
          }
          $value = $item->toArray();
          foreach (\array_filter($item->getProperties(), static fn (TypedDataInterface $property) => $property instanceof PrimitiveInterface) as $property) {
            $value[$property->getName()] = $property->getCastedValue();
          }
          return $value;
        },
        \iterator_to_array($items)
      );
    }
    return $normalized;
  }

  /**
   * Returns the key-value store key for an entity's auto-save entry.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to generate a key for.
   *
   * @return string
   *   The auto-save store key.
   */
  public static function getAutoSaveKey(EntityInterface $entity): string {
    if ($entity instanceof TranslatableInterface) {
      return $entity->getEntityTypeId() . ':' . $entity->id() . ':' . $entity->language()->getId();
    }
    return $entity->getEntityTypeId() . ':' . $entity->id();
  }

  /**
   * Gets client auto-save data for an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to get auto-save data for.
   *
   * @return array{autoSaveStartingPoint: int|string|null, hash: string|null}
   *   The client auto-save data array.
   */
  public function getClientAutoSaveData(EntityInterface $entity): array {
    $autoSaveEntity = $this->getAutoSaveEntity($entity);

    \assert($entity->id() !== NULL);
    $savedEntity = $this->entityTypeManager->getStorage($entity->getEntityTypeId())
      ->loadUnchanged($entity->id());
    \assert($savedEntity instanceof EntityInterface);

    $autoSaveStartRevision = $savedEntity instanceof RevisionableInterface
      ? $savedEntity->getRevisionId()
      : \hash('xxh64', \json_encode($savedEntity->toArray(), JSON_THROW_ON_ERROR));
    if ($savedEntity instanceof EntityChangedInterface) {
      $autoSaveStartRevision .= '-' . $savedEntity->getChangedTime();
    }
    return [
      'autoSaveStartingPoint' => $autoSaveStartRevision,
      'hash' => $autoSaveEntity->hash,
    ];
  }

  /**
   * Gets the hash of the unchanged (currently saved) version of an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to hash.
   *
   * @return string|null
   *   The hash string, or NULL if the entity does not exist in storage.
   */
  private function getUnchangedHash(EntityInterface $entity): ?string {
    \assert(!\is_null($entity->id()));
    $original = $this->entityTypeManager->getStorage($entity->getEntityTypeId())->loadUnchanged($entity->id());
    if ($original === NULL) {
      return NULL;
    }
    return self::generateHash(self::normalizeEntity($original));
  }

  /**
   * Gets all auto-save data.
   *
   * @param bool $with_entities
   *   Whether to hydrate entity objects into the result.
   *
   * @return array<string, array<string, mixed>>
   *   The auto-save data keyed by store keys, each value an array
   *    containing metadata and optionally hydrated entity objects.
   */
  public function getAllAutoSaveList(bool $with_entities = FALSE): array {
    $entries = $this->autoSaveStore->getAll();
    \ksort($entries);
    $result = \array_map(function (array $entry) use ($with_entities) {
      // Config override entries store only component_tree, not a full entity
      // array. Attempting to call EntityStorage::create() on them would crash
      // because required properties like content_entity_type_id are missing.
      // Return NULL for the entity key and let ApiAutoSaveController::post()
      // handle them via the config override publishing path instead.
      $is_config_override = !empty($entry['is_config_override']);
      return $entry + [
        'owner' => \is_numeric($entry['owner']) ? (int) $entry['owner'] : 0,
        'entity' => ($with_entities && !$is_config_override)
          ? $this->entityTypeManager->getStorage($entry['entity_type'])->create($entry['data'])
          : NULL,
      ];
    }, $entries);
    return $result;
  }

  /**
   * Deletes the auto-save entry for an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity whose auto-save entry to delete.
   *
   * @see ::onCanvasConfigEntitySave()
   */
  public function delete(EntityInterface $entity): void {
    $this->cacheTagsInvalidator->invalidateTags([self::CACHE_TAG]);
    $key = $this->getAutoSaveKey($entity);
    $this->autoSaveStore->delete($key);
    $this->formViolationsStore->delete($key);
    if ($entity instanceof ContentEntityInterface) {
      $canvas_fields = \array_keys(
        \array_filter(
          $entity->getFields(),
          static fn(FieldItemListInterface $field): bool => $field->getItemDefinition()->getClass() === ComponentTreeItem::class
        )
      );
      $component_uuids = \array_reduce(
        $canvas_fields,
        static fn (array $carry, string $field_name): array => [
          ...$carry,
          ...\array_column($entity->get($field_name)->getValue(), 'uuid'),
        ],
        []
      );
      $this->componentInstanceFormViolationsStore->deleteMultiple(\array_unique($component_uuids));
    }
  }

  /**
   * Deletes all auto-save entries from all stores.
   */
  public function deleteAll(): void {
    $this->cacheTagsInvalidator->invalidateTags([self::CACHE_TAG]);
    $this->autoSaveStore->deleteAll();
    $this->formViolationsStore->deleteAll();
    $this->componentInstanceFormViolationsStore->deleteAll();
  }

  /**
   * Deletes an auto-save entry by its key.
   *
   * @param string $key
   *   The auto-save key.
   */
  public function deleteByKey(string $key): void {
    $this->cacheTagsInvalidator->invalidateTags([self::CACHE_TAG]);
    $this->autoSaveStore->delete($key);
    $this->formViolationsStore->delete($key);
    // Cannot delete component instance form violations without the entity.
  }

  /**
   * Generates a stable hash for an array of data.
   *
   * @param array<string, mixed> $data
   *   The data to hash.
   *
   * @return string
   *   The xxh64 hash string.
   */
  private static function generateHash(array $data): string {
    self::recursiveKsort($data);
    return \hash('xxh64', \json_encode($data, JSON_THROW_ON_ERROR));
  }

  /**
   * Recursively sorts an array by key for stable hashing.
   *
   * @param array<string, mixed> $array
   *   The array to sort in place.
   */
  private static function recursiveKsort(array &$array): void {
    ksort($array);
    foreach ($array as &$value) {
      if (\is_array($value)) {
        self::recursiveKsort($value);
      }
    }
  }

  /**
   * Reacts to a Canvas config entity save event.
   *
   * Updates or deletes the auto-save entry depending on what changed.
   *
   * @param \Drupal\Core\Config\ConfigCrudEvent $event
   *   The config save event.
   */
  public function onCanvasConfigEntitySave(ConfigCrudEvent $event): void {
    [$module] = explode('.', $event->getConfig()->getName(), 2);
    if ($module !== 'canvas') {
      return;
    }

    $entity = $this->configManager->loadConfigEntityByName($event->getConfig()->getName());
    if (!$entity) {
      return;
    }
    if (!$entity instanceof CanvasHttpApiEligibleConfigEntityInterface) {
      return;
    }

    $autoSaveData = $this->getAutoSaveEntity($entity);
    if ($autoSaveData->isEmpty()) {
      return;
    }
    $autoSaveEntity = $autoSaveData->entity;
    \assert($autoSaveEntity instanceof CanvasHttpApiEligibleConfigEntityInterface);

    $auto_save_update_needed = FALSE;
    \assert($entity->getEntityType() instanceof ConfigEntityTypeInterface);
    $properties_to_assess = $entity->getEntityType()->getPropertiesToExport();
    \assert(\is_array($properties_to_assess));
    $auto_save_updatable_properties = \array_intersect_key(
      $entity->getEntityType()->getKeys(),
      \array_flip(['status', 'label'])
    );

    $auto_save_not_updatable_properties = \array_diff_key($properties_to_assess, array_flip($auto_save_updatable_properties));
    foreach ($auto_save_not_updatable_properties as $property) {
      if ($event->isChanged($property)) {
        $this->delete($entity);
        return;
      }
    }

    foreach ($auto_save_updatable_properties as $auto_save_updatable_property) {
      if ($event->isChanged($auto_save_updatable_property)) {
        $autoSaveEntity->set($auto_save_updatable_property, $entity->get($auto_save_updatable_property));
        $auto_save_update_needed = TRUE;
      }
    }

    if ($auto_save_update_needed) {
      $this->saveEntity($autoSaveEntity, $autoSaveData->clientId);
    }
  }

  /**
   * Reacts to a Canvas config entity delete event.
   *
   * Deletes any auto-saves for staged config updates targeting the deleted
   * config item.
   *
   * @param \Drupal\Core\Config\ConfigCrudEvent $event
   *   The config delete event.
   */
  public function onCanvasConfigDelete(ConfigCrudEvent $event): void {
    $autoSaveEntities = $this->getAllAutoSaveList(TRUE);
    $autoSaveEntities = array_filter(
      $autoSaveEntities,
      fn($entityData) => $entityData['entity'] instanceof StagedConfigUpdate
    );
    foreach ($autoSaveEntities as $autoSaveEntity) {
      $staged_config_update = $autoSaveEntity['entity'];
      if ($staged_config_update->getTarget() === $event->getConfig()->getName()) {
        $this->delete($staged_config_update);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events[ConfigEvents::SAVE][] = ['onCanvasConfigEntitySave'];
    $events[ConfigEvents::DELETE][] = ['onCanvasConfigDelete'];
    return $events;
  }

  /**
   * Determines whether an entity should be considered new by Canvas.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface|\Drupal\canvas\Entity\ContentTemplate $entity
   *   The entity to check.
   *
   * @return bool
   *   TRUE if the entity is considered new.
   */
  public static function entityIsConsideredNew(ContentEntityInterface|ContentTemplate $entity): bool {
    if ($entity instanceof ContentTemplate) {
      return !$entity->status();
    }
    return (string) $entity->label() == ApiContentControllers::defaultTitle($entity->getEntityType())
      || str_ends_with((string) $entity->label(), self::ENTITY_DUPLICATE_SUFFIX);
  }

}
