<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\DataType;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\Plugin\DataType\EntityAdapter;
use Drupal\Core\Entity\Plugin\DataType\EntityReference;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItemInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\Attribute\DataType;
use Drupal\Core\TypedData\Plugin\DataType\StringData;

/**
 * Computed data type: UUID of the referenced entity.
 *
 * Computes the UUID from the target_id, ensuring a single source of truth.
 *
 * @internal
 */
#[DataType(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup("UUID of the referenced entity")
)]
final class ComputedEntityTargetUuid extends StringData implements CacheableDependencyInterface {

  use ComputedDataTypeWithCacheabilityTrait {
    getValue as private traitGetValue;
  }

  public const string PLUGIN_ID = 'computed_entity_target_uuid';

  private ?string $computedValue = NULL;

  /**
   * {@inheritdoc}
   */
  public function getValue(): ?string {
    return $this->traitGetValue();
  }

  /**
   * {@inheritdoc}
   */
  private function computeValue(): ?string {
    \assert($this->isComputed === FALSE);

    $this->cacheability = new CacheableMetadata();

    $field_item = $this->getParent();
    if (!$field_item instanceof EntityReferenceItemInterface) {
      throw new \LogicException('This data type must be used as a computed field property on entity references.');
    }

    // @todo Core bug: EntityReferenceItemInterface should extend FieldItemInterface
    \assert($field_item instanceof FieldItemInterface);
    $entity_reference_property = $field_item->get('entity');
    \assert($entity_reference_property instanceof EntityReference);
    $target = $entity_reference_property->getTarget();
    if ($target === NULL) {
      return NULL;
    }

    \assert($target instanceof EntityAdapter);
    $entity = $target->getEntity();
    $this->cacheability = CacheableMetadata::createFromObject($entity);

    return $entity->uuid();
  }

}
