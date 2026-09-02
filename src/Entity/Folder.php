<?php

declare(strict_types=1);

namespace Drupal\canvas\Entity;

use Drupal\canvas\ClientSideRepresentation;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

#[ConfigEntityType(
  id: self::ENTITY_TYPE_ID,
  label: new TranslatableMarkup('Folder'),
  label_singular: new TranslatableMarkup('folder'),
  label_plural: new TranslatableMarkup('folders'),
  label_collection: new TranslatableMarkup('Folders'),
  admin_permission: self::ADMIN_PERMISSION,
  handlers: [
    'access' => FolderAccessControlHandler::class,
  ],
  entity_keys: [
    'id' => 'uuid',
    'label' => 'name',
    'weight' => 'weight',
  ],
  config_export: [
    'name',
    'configEntityTypeId',
    'weight',
    'items',
  ],
  constraints: [
    'ImmutableProperties' => [
      'properties' => [
        'uuid',
        'configEntityTypeId',
      ],
    ],
  ],
  additional: [
    // The client-side representation uses `id` as the identifier, not `uuid`.
    // @see ::normalizeForClientSide()
    'canvas_client_id_key' => 'id',
  ],
)]
final class Folder extends ConfigEntityBase implements CanvasHttpApiEligibleConfigEntityInterface {

  public const string ENTITY_TYPE_ID = 'folder';
  public const string ADMIN_PERMISSION = 'administer folders';

  protected string $name;
  protected string $configEntityTypeId;
  protected int $weight = 0;
  protected array $items = [];

  public function id(): ?string {
    return $this->uuid();
  }

  public function normalizeForClientSide(): ClientSideRepresentation {
    return ClientSideRepresentation::create(
      values: [
        'name' => $this->label(),
        'id' => $this->uuid(),
        'type' => $this->configEntityTypeId,
        'weight' => $this->weight,
        'items' => $this->items,
      ],
      preview: NULL,
    )->addCacheableDependency($this);
  }

  public static function createFromClientSide(array $data): static {
    $data['configEntityTypeId'] = $data['type'];
    unset($data['id']);
    unset($data['type']);
    return static::create($data);
  }

  public function updateFromClientSide(array $data): void {
    unset($data['id']);
    unset($data['type']);
    foreach ($data as $key => $value) {
      $this->set($key, $value);
    }
  }

  public static function refineListQuery(QueryInterface &$query, RefinableCacheableDependencyInterface $cacheability): void {
    // Nothing to do.
  }

  public static function loadByNameAndConfigEntityTypeId(string $name, string $configEntityTypeId): self|NULL {
    $results = \Drupal::entityTypeManager()->getStorage(self::ENTITY_TYPE_ID)->loadByProperties([
      'name' => $name,
      'configEntityTypeId' => $configEntityTypeId,
    ]);
    // @phpstan-ignore return.type
    return !empty($results) ? reset($results) : NULL;
  }

  public static function loadByItemAndConfigEntityTypeId(string $item_id, string $configEntityTypeId): ?self {
    $query = \Drupal::entityTypeManager()->getStorage(self::ENTITY_TYPE_ID)
      ->getQuery()
      ->condition('configEntityTypeId', $configEntityTypeId)
      ->condition('items.*', $item_id)
      ->accessCheck(FALSE);
    $ids = $query->execute();
    return match (count($ids)) {
      0 => NULL,
      1 => self::load(reset($ids)),
      default => throw new \RuntimeException('It is impossible for an item to exist in multiple Folders.'),
    };
  }

  public function addItems(array $new_item_ids): self {
    $items_in_folder = $this->get('items');
    $new_items = array_unique([...$items_in_folder, ...$new_item_ids]);
    $this->set('items', array_values($new_items));
    return $this;
  }

  public function removeItem(string $remove_id): self {
    $items_in_folder = $this->get('items');
    $new_items = array_values(array_filter($items_in_folder, fn($item) => $item !== $remove_id));
    $this->set('items', $new_items);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(): static {
    parent::calculateDependencies();
    // A folder organizing content entities has no items: membership lives on
    // the content entity itself, because config must not depend on content.
    // @see \Drupal\canvas\Entity\FolderTargetInterface
    if ($this->targetsContentEntityType()) {
      return $this;
    }
    // Each item is the ID of a config entity of type $this->configEntityTypeId.
    // Declare an explicit config dependency so that core's dependency removal
    // machinery calls ::onDependencyRemoval() when any item is deleted.
    $prefix = 'canvas.' . $this->configEntityTypeId;
    foreach ($this->items as $item_id) {
      $this->addDependency('config', $prefix . '.' . $item_id);
    }
    return $this;
  }

  /**
   * Whether this folder organizes content entities rather than config.
   *
   * @return bool
   *   TRUE when the target entity type is a content entity type.
   */
  public function targetsContentEntityType(): bool {
    $entity_type = \Drupal::entityTypeManager()->getDefinition($this->configEntityTypeId, FALSE);
    return $entity_type instanceof ContentEntityTypeInterface;
  }

  /**
   * The ID of the entity type this folder organizes.
   *
   * @return string
   *   An entity type ID, e.g. `component` or `canvas_page`.
   */
  public function getConfigEntityTypeId(): string {
    return $this->configEntityTypeId;
  }

  /**
   * Whether a field stores which folder a content entity is filed into.
   *
   * Content entities reference their folder themselves, in an entity reference
   * field targeting folders; config entities are listed by the folder instead.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   A field definition.
   *
   * @return bool
   *   TRUE for an entity reference field that targets folders.
   *
   * @see \Drupal\canvas\Entity\FolderTargetInterface
   * @see \Drupal\canvas\Entity\Page::baseFieldDefinitions()
   */
  public static function isMembershipField(FieldDefinitionInterface $field_definition): bool {
    return $field_definition->getType() === 'entity_reference'
      && $field_definition->getSetting('target_type') === self::ENTITY_TYPE_ID;
  }

  /**
   * Finds the field storing which folder a content entity is filed into.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   A content entity.
   *
   * @return string|null
   *   The field name, or NULL when the entity cannot be organized into folders.
   */
  public static function membershipFieldName(FieldableEntityInterface $entity): ?string {
    if (!$entity instanceof FolderTargetInterface) {
      return NULL;
    }
    foreach ($entity->getFieldDefinitions() as $field_name => $field_definition) {
      if (self::isMembershipField($field_definition)) {
        return $field_name;
      }
    }
    return NULL;
  }

  /**
   * The ID of the folder a content entity is filed into, if any.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   A content entity.
   *
   * @return string|null
   *   The folder ID, or NULL when the entity is in no folder.
   */
  public static function membershipOf(FieldableEntityInterface $entity): ?string {
    $field_name = self::membershipFieldName($entity);
    if ($field_name === NULL) {
      return NULL;
    }
    $folder_id = $entity->get($field_name)->first()?->get('target_id')->getValue();
    return \is_scalar($folder_id) && $folder_id !== '' ? (string) $folder_id : NULL;
  }

  /**
   * {@inheritdoc}
   *
   * Removes any items from this Folder whose config entities are being deleted.
   * This covers all FolderItemInterface types (Component, JavaScriptComponent,
   * Pattern, etc.) without each of them needing their own preDelete hook.
   */
  public function onDependencyRemoval(array $dependencies): bool {
    $prefix = 'canvas.' . $this->configEntityTypeId . '.';
    $removed_ids = [];
    foreach (\array_keys($dependencies['config'] ?? []) as $config_name) {
      if (\is_string($config_name) && str_starts_with($config_name, $prefix)) {
        $removed_ids[] = substr($config_name, strlen($prefix));
      }
    }
    if (empty($removed_ids)) {
      return parent::onDependencyRemoval($dependencies);
    }
    $this->items = array_values(array_diff($this->items, $removed_ids));
    parent::onDependencyRemoval($dependencies);
    return TRUE;
  }

}
