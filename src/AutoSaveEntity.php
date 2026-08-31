<?php

declare(strict_types=1);

namespace Drupal\canvas;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableDependencyTrait;
use Drupal\Core\Entity\EntityInterface;

/**
 * An auto-saved entity plus the metadata stored alongside it.
 *
 * Carries the auto-save cache tag, so anything built from an entry is
 * invalidated when auto-save data changes. ::empty() represents the absence of
 * an auto-save entry, which callers detect with ::isEmpty() rather than NULL.
 */
final class AutoSaveEntity implements CacheableDependencyInterface {

  use CacheableDependencyTrait;

  public function __construct(
    public readonly ?EntityInterface $entity,
    public readonly ?string $hash,
    public readonly ?string $clientId,
    public readonly ?int $updated,
  ) {
    $this->cacheTags = [AutoSaveManager::CACHE_TAG];
  }

  public static function empty(): AutoSaveEntity {
    return new self(NULL, NULL, NULL, NULL);
  }

  public function isEmpty(): bool {
    return $this->entity === NULL;
  }

}
