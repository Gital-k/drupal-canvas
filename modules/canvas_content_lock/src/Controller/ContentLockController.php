<?php

declare(strict_types=1);

namespace Drupal\canvas_content_lock\Controller;

use Drupal\canvas\Entity\Page;
use Drupal\canvas_content_lock\ContentLockManager;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * JSON endpoints for Canvas page soft locks.
 *
 * Every endpoint responds with the lock status from the current user's point
 * of view.
 *
 * @see \Drupal\canvas_content_lock\ContentLockManager::getStatus()
 */
final class ContentLockController implements ContainerInjectionInterface {

  public function __construct(
    private readonly ContentLockManager $contentLockManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get(ContentLockManager::class));
  }

  /**
   * Returns the lock status without changing it.
   */
  public function check(Page $canvas_page): JsonResponse {
    return new JsonResponse($this->contentLockManager->getStatus($canvas_page));
  }

  /**
   * Acquires or refreshes the lock.
   *
   * A lock held by another user is not an error: the response is 200, with
   * `isOwner` set to FALSE. Browsers log every 4xx response to the console,
   * and the Canvas UI calls this every 30 seconds while a page is open.
   */
  public function acquire(Page $canvas_page): JsonResponse {
    $this->contentLockManager->acquire($canvas_page);
    return new JsonResponse($this->contentLockManager->getStatus($canvas_page));
  }

  /**
   * Releases the current user's lock. Leaves other users' locks untouched.
   */
  public function release(Page $canvas_page): JsonResponse {
    $this->contentLockManager->release($canvas_page);
    return new JsonResponse($this->contentLockManager->getStatus($canvas_page));
  }

  /**
   * Takes over the lock for the current user.
   */
  public function break(Page $canvas_page): JsonResponse {
    $this->contentLockManager->break($canvas_page);
    return new JsonResponse($this->contentLockManager->getStatus($canvas_page));
  }

}
