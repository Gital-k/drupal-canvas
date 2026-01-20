<?php

declare(strict_types=1);

namespace Drupal\canvas\Access;

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access check for content template routes.
 */
final class ContentTemplateAccessCheck implements AccessInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Checks access for content template routes.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account being checked.
   * @param string $entity_type
   *   The entity type ID (e.g., 'node').
   * @param string $bundle
   *   The bundle of the entity (e.g., 'article').
   * @param string $view_mode_name
   *   The view mode name (e.g., 'full').
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(AccountInterface $account, string $bundle, string $entity_type, string $view_mode_name): AccessResultInterface {
    $access = AccessResult::neutral();
    $storage = $this->entityTypeManager->getStorage(ContentTemplate::ENTITY_TYPE_ID);
    $template = $storage->load("$entity_type.$bundle.$view_mode_name");
    if ($template instanceof ContentTemplate) {
      // Add the ContentTemplate entity cacheability. As the access check
      // needs to rebuild when content templates are updated or deleted.
      $access->addCacheableDependency($this->getContentTemplateCacheability($template));
      if ($template->status()) {
        return $access->orIf(AccessResult::allowedIfHasPermission($account, ContentTemplate::ADMIN_PERMISSION));
      }
    }
    // Add the ContentTemplate entity storage cacheability. As the access check
    // needs to rebuild when content templates are created.
    $access->addCacheableDependency($this->getContentTemplateCacheability());
    return $access;
  }

  /**
   * Gets cache metadata for content template.
   *
   * @param \Drupal\canvas\Entity\ContentTemplate|null $template
   *   (optional) A content template entity to get bundle-specific cache tags
   *   for. If NULL, only general list cache tags will be included.
   *
   * @return \Drupal\Core\Cache\RefinableCacheableDependencyInterface
   *   The cacheable dependency.
   */
  private function getContentTemplateCacheability(?ContentTemplate $template = NULL): RefinableCacheableDependencyInterface {
    $storage = $this->entityTypeManager->getStorage(ContentTemplate::ENTITY_TYPE_ID);
    $dependency = (new CacheableMetadata())
      ->addCacheContexts($storage->getEntityType()->getListCacheContexts())
      ->addCacheTags($storage->getEntityType()->getListCacheTags());
    if ($template) {
      $dependency
        ->addCacheTags($storage->getEntityType()->getBundleListCacheTags($template->getTargetBundle()));
    }
    return $dependency;
  }

}
