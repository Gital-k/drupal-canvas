<?php

declare(strict_types=1);

namespace Drupal\canvas_content_lock;

use Drupal\canvas\Entity\Page;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TempStore\Lock;
use Drupal\Core\TempStore\SharedTempStore;
use Drupal\Core\TempStore\SharedTempStoreFactory;

/**
 * Manages soft locks on Canvas pages.
 *
 * A lock records which user has a Canvas page open in the Canvas editor. It
 * does not prevent saving: server-side conflict detection remains the source
 * of truth. The lock only lets the Canvas UI warn other users.
 *
 * Locks live in a SharedTempStore collection, keyed by page ID and owned by
 * the user ID. The Canvas UI refreshes a held lock every POLL_INTERVAL
 * seconds. A lock that was not refreshed for TIMEOUT seconds is stale and is
 * treated as absent, so a closed browser tab never blocks other users for
 * longer than that.
 *
 * @see js/canvas-content-lock.js
 */
final class ContentLockManager {

  /**
   * The SharedTempStore collection that holds the locks.
   */
  public const string COLLECTION = 'canvas_content_lock';

  /**
   * The permission that allows taking over another user's lock.
   */
  public const string BREAK_PERMISSION = 'break canvas content lock';

  /**
   * The number of seconds between lock refreshes by the Canvas UI.
   */
  public const int POLL_INTERVAL = 30;

  /**
   * The number of seconds after which an unrefreshed lock is stale.
   *
   * Three missed refreshes.
   */
  public const int TIMEOUT = 90;

  public function __construct(
    private readonly SharedTempStoreFactory $tempStoreFactory,
    private readonly AccountInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Gets the non-stale lock on a page, if any.
   */
  public function getLock(Page $page): ?Lock {
    $lock = $this->store()->getMetadata(self::key($page));
    if ($lock === NULL || $this->isStale($lock)) {
      return NULL;
    }
    return $lock;
  }

  /**
   * Acquires or refreshes the current user's lock on a page.
   *
   * @return bool
   *   TRUE if the current user holds the lock afterwards, FALSE if another
   *   user holds it.
   */
  public function acquire(Page $page): bool {
    $store = $this->store();
    $key = self::key($page);
    $lock = $store->getMetadata($key);

    if ($lock !== NULL && $this->isStale($lock)) {
      $store->delete($key);
      $lock = NULL;
    }

    if ($lock === NULL) {
      // Another request may create the lock between the read above and this
      // write; setIfNotExists() then fails and the check below applies.
      if ($store->setIfNotExists($key, $this->value($page))) {
        return TRUE;
      }
      $lock = $store->getMetadata($key);
    }

    if ($lock !== NULL && $this->isOwnedByCurrentUser($lock)) {
      // Refreshes the lock's "updated" timestamp.
      return $store->setIfOwner($key, $this->value($page));
    }

    return FALSE;
  }

  /**
   * Releases the current user's lock on a page.
   *
   * @return bool
   *   TRUE if the page is no longer locked by the current user. A lock held by
   *   another user is left untouched.
   */
  public function release(Page $page): bool {
    return $this->store()->deleteIfOwner(self::key($page));
  }

  /**
   * Takes over the lock on a page for the current user.
   *
   * Callers must check self::BREAK_PERMISSION.
   */
  public function break(Page $page): void {
    $this->store()->set(self::key($page), $this->value($page));
  }

  /**
   * Removes the lock on a page, regardless of its owner.
   */
  public function delete(Page $page): void {
    $this->store()->delete(self::key($page));
  }

  /**
   * Describes the lock on a page from the current user's point of view.
   *
   * @return array{locked: bool, isOwner: bool, owner: array{uid: int, name: string}|null, updated: int|null, canBreak: bool}
   *   The lock status:
   *   - locked: whether a non-stale lock exists.
   *   - isOwner: whether the current user holds that lock.
   *   - owner: the lock holder, or NULL if unlocked.
   *   - updated: the UNIX timestamp of the last refresh, or NULL if unlocked.
   *   - canBreak: whether the current user may take over the lock.
   */
  public function getStatus(Page $page): array {
    $lock = $this->getLock($page);
    $owner = NULL;
    if ($lock !== NULL) {
      $uid = (int) $lock->getOwnerId();
      $account = $this->entityTypeManager->getStorage('user')->load($uid);
      $owner = [
        'uid' => $uid,
        'name' => $account instanceof AccountInterface ? (string) $account->getDisplayName() : (string) $uid,
      ];
    }
    return [
      'locked' => $lock !== NULL,
      'isOwner' => $lock !== NULL && $this->isOwnedByCurrentUser($lock),
      'owner' => $owner,
      'updated' => $lock?->getUpdated(),
      'canBreak' => $this->currentUser->hasPermission(self::BREAK_PERMISSION),
    ];
  }

  /**
   * Gets the lock store, owned by the current user.
   */
  private function store(): SharedTempStore {
    return $this->tempStoreFactory->get(self::COLLECTION, $this->currentUser->id());
  }

  /**
   * Gets the lock key for a page.
   */
  private static function key(Page $page): string {
    return (string) $page->id();
  }

  /**
   * Builds the stored value. SharedTempStore stores owner and time itself.
   */
  private function value(Page $page): array {
    return ['entity_type' => Page::ENTITY_TYPE_ID, 'entity_id' => $page->id()];
  }

  /**
   * Checks whether a lock was not refreshed within the timeout.
   */
  private function isStale(Lock $lock): bool {
    return $this->time->getRequestTime() - (int) $lock->getUpdated() > self::TIMEOUT;
  }

  /**
   * Checks whether the current user holds a lock.
   */
  private function isOwnedByCurrentUser(Lock $lock): bool {
    return (string) $lock->getOwnerId() === (string) $this->currentUser->id();
  }

}
