<?php

declare(strict_types=1);

namespace Drupal\canvas_content_lock\Hook;

use Drupal\canvas\Entity\Page;
use Drupal\canvas_content_lock\ContentLockManager;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Releases content locks when their pages are published or deleted.
 */
final class ContentLockHooks {

  public function __construct(
    private readonly ContentLockManager $contentLockManager,
  ) {}

  /**
   * Implements hook_ENTITY_TYPE_update() for canvas_page.
   *
   * Publishing auto-saved changes saves the page. That ends the current user's
   * editing session, so their lock is released. If they keep editing, the next
   * refresh by the Canvas UI acquires it again.
   *
   * @see \Drupal\canvas\Controller\ApiAutoSaveController::post()
   */
  #[Hook('canvas_page_update')]
  public function pageUpdate(Page $page): void {
    $this->contentLockManager->release($page);
  }

  /**
   * Implements hook_ENTITY_TYPE_delete() for canvas_page.
   */
  #[Hook('canvas_page_delete')]
  public function pageDelete(Page $page): void {
    $this->contentLockManager->delete($page);
  }

}
