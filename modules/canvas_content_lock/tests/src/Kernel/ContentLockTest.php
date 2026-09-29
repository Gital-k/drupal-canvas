<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_content_lock\Kernel;

use Drupal\canvas\Entity\Page;
use Drupal\canvas_content_lock\ContentLockManager;
use Drupal\canvas_content_lock\Controller\ContentLockController;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\TempStore\SharedTempStoreFactory;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests Canvas page soft locks.
 */
#[Group('canvas_content_lock')]
#[RunTestsInSeparateProcesses]
final class ContentLockTest extends CanvasKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...self::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'canvas_content_lock',
  ];

  /**
   * The page under test.
   */
  private Page $page;

  /**
   * A user who may edit pages.
   */
  private UserInterface $alice;

  /**
   * Another user who may edit pages.
   */
  private UserInterface $bob;

  /**
   * A user who may also break locks.
   */
  private UserInterface $admin;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);

    // Creates user 1 first, so that no test user bypasses permissions.
    $this->createUser();
    $this->alice = $this->createUser([Page::EDIT_PERMISSION], 'alice');
    $this->bob = $this->createUser([Page::EDIT_PERMISSION], 'bob');
    $this->admin = $this->createUser([Page::EDIT_PERMISSION, ContentLockManager::BREAK_PERMISSION], 'admin');

    $this->page = Page::create([
      'title' => 'Locked page',
      'components' => [],
    ]);
    $this->page->save();
  }

  /**
   * Tests acquiring, refreshing, releasing and breaking a lock.
   */
  public function testLockLifecycle(): void {
    $this->setCurrentUser($this->alice);
    self::assertSame([
      'locked' => FALSE,
      'isOwner' => FALSE,
      'owner' => NULL,
      'updated' => NULL,
      'canBreak' => FALSE,
    ], $this->call('check'));

    [$status, $body] = $this->callWithStatus('acquire');
    self::assertSame(Response::HTTP_OK, $status);
    self::assertTrue($body['locked']);
    self::assertTrue($body['isOwner']);
    self::assertSame(['uid' => (int) $this->alice->id(), 'name' => 'alice'], $body['owner']);

    // Refreshing a held lock succeeds.
    [$status] = $this->callWithStatus('acquire');
    self::assertSame(Response::HTTP_OK, $status);

    // Another user cannot acquire the lock, and sees who holds it.
    $this->setCurrentUser($this->bob);
    [$status, $body] = $this->callWithStatus('acquire');
    self::assertSame(Response::HTTP_OK, $status);
    self::assertTrue($body['locked']);
    self::assertFalse($body['isOwner']);
    self::assertSame('alice', $body['owner']['name']);

    // Releasing does not remove another user's lock.
    self::assertSame('alice', $this->call('release')['owner']['name']);

    // A user with the break permission can take over the lock.
    $this->setCurrentUser($this->admin);
    self::assertTrue($this->call('check')['canBreak']);
    $body = $this->call('break');
    self::assertTrue($body['isOwner']);
    self::assertSame('admin', $body['owner']['name']);

    $this->setCurrentUser($this->alice);
    self::assertFalse($this->call('acquire')['isOwner']);

    // Releasing the own lock frees the page for others.
    $this->setCurrentUser($this->admin);
    self::assertFalse($this->call('release')['locked']);
    $this->setCurrentUser($this->bob);
    [$status] = $this->callWithStatus('acquire');
    self::assertSame(Response::HTTP_OK, $status);
  }

  /**
   * Tests that a lock that was not refreshed in time is ignored.
   */
  public function testStaleLock(): void {
    $this->setCurrentUser($this->alice);
    $this->call('acquire');

    // Backdates the lock past the timeout.
    $store = $this->container->get(KeyValueExpirableFactoryInterface::class)->get('tempstore.shared.' . ContentLockManager::COLLECTION);
    $lock = $store->get((string) $this->page->id());
    $lock->updated = $this->container->get(TimeInterface::class)->getRequestTime() - ContentLockManager::TIMEOUT - 1;
    $store->setWithExpire((string) $this->page->id(), $lock, 3600);

    $this->setCurrentUser($this->bob);
    self::assertFalse($this->call('check')['locked']);
    [$status, $body] = $this->callWithStatus('acquire');
    self::assertSame(Response::HTTP_OK, $status);
    self::assertSame('bob', $body['owner']['name']);
  }

  /**
   * Tests that publishing or deleting a page releases its lock.
   */
  public function testPublishAndDeleteReleaseLock(): void {
    $manager = $this->container->get(ContentLockManager::class);

    // Saving by the lock holder releases the lock.
    $this->setCurrentUser($this->alice);
    self::assertTrue($manager->acquire($this->page));
    $this->page->set('title', 'Published title')->save();
    self::assertNull($manager->getLock($this->page));

    // Saving by another user leaves the lock in place.
    self::assertTrue($manager->acquire($this->page));
    $this->setCurrentUser($this->bob);
    $this->page->set('title', 'Another title')->save();
    self::assertNotNull($manager->getLock($this->page));

    // Deleting removes any lock.
    $page_id = (string) $this->page->id();
    $this->page->delete();
    self::assertNull($this->container->get(SharedTempStoreFactory::class)->get(ContentLockManager::COLLECTION)->getMetadata($page_id));
  }

  /**
   * Calls a controller method and returns the decoded response.
   */
  private function call(string $operation): array {
    return $this->callWithStatus($operation)[1];
  }

  /**
   * Calls a controller method and returns the status and decoded response.
   *
   * @return array{0: int, 1: array}
   *   The status code and the decoded response body.
   */
  private function callWithStatus(string $operation): array {
    $controller = ContentLockController::create($this->container);
    $response = $controller->{$operation}($this->page);
    self::assertInstanceOf(JsonResponse::class, $response);
    return [$response->getStatusCode(), json_decode((string) $response->getContent(), TRUE)];
  }

}
