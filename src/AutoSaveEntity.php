<?php

declare(strict_types=1);

namespace Drupal\canvas;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableDependencyTrait;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\RevisionableInterface;

/**
 * An auto-saved entity plus the metadata stored alongside it.
 *
 * Carries the auto-save cache tag as well as the auto-saved entity's own cache
 * tags, so anything built from an entry is invalidated both when auto-save data
 * changes and when the entity itself is saved.
 */
final class AutoSaveEntity implements CacheableDependencyInterface {

  use CacheableDependencyTrait;

  public function __construct(
    public readonly ?EntityInterface $entity,
    public readonly ?string $hash,
    public readonly ?string $clientId,
    public readonly ?int $updated,
  ) {
    $this->cacheTags = Cache::mergeTags([AutoSaveManager::CACHE_TAG], $entity?->getCacheTags() ?? []);
  }

  public static function empty(): AutoSaveEntity {
    return new self(NULL, NULL, NULL, NULL);
  }

  public function isEmpty(): bool {
    return $this->entity === NULL;
  }

  /**
   * Computes the point in an entity's history a draft builds on.
   *
   * The client receives this alongside the auto-save hash and sends it back
   * with every mutating request. The server recomputes it and refuses the
   * request when the two differ, because that means somebody else saved the
   * entity while the client was editing its draft.
   *
   * It is therefore derived from the *live* entity, never from the auto-saved
   * draft in ::$entity: a draft does not change on its own, so a draft-derived
   * value could never detect a concurrent save.
   *
   * @param \Drupal\Core\Entity\EntityInterface $liveEntity
   *   The live entity, freshly loaded from storage — neither an auto-saved
   *   draft nor an entity carrying unsaved in-memory changes.
   *
   * @return string
   *   The starting point: the revision ID for revisionable entities, a hash of
   *   the entity's values for all others. Either is suffixed with the changed
   *   time when the entity type tracks one, because not every revisionable
   *   entity type creates a new revision on every change.
   *
   * @see \Drupal\canvas\Controller\AutoSaveValidateTrait::validateAutoSaves()
   */
  public static function getLiveStartingPoint(EntityInterface $liveEntity): string {
    $startingPoint = $liveEntity instanceof RevisionableInterface
      ? $liveEntity->getRevisionId()
      : \hash('xxh64', \json_encode($liveEntity->toArray(), JSON_THROW_ON_ERROR));
    if ($liveEntity instanceof EntityChangedInterface) {
      $startingPoint .= '-' . $liveEntity->getChangedTime();
    }
    // Revision IDs are integers, and a revisionable entity type without a
    // revision key yields none at all, so cast: the client-facing contract for
    // this value is a string.
    // @see openapi.yml
    return (string) $startingPoint;
  }

}
