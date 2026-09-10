<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional\AutoSave;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\CanvasHttpApiEligibleConfigEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\dynamic_page_cache\EventSubscriber\DynamicPageCacheSubscriber;
use Drupal\Tests\canvas\TestSite\CanvasTestSetup;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests that outdated auto-save updates are rejected, for one entity type.
 *
 * These are functional tests rather than kernel tests because conflict
 * detection depends on the `autoSaveStartingPoint`, which is derived from the
 * saved entity, and hence on the response being cached correctly. Kernel tests
 * cannot exercise that: they are seen as originating from the command line, so
 * caching is denied.
 *
 * @see \Drupal\canvas\Controller\AutoSaveValidateTrait::validateAutoSaves()
 * @see \Drupal\Core\PageCache\RequestPolicy\CommandLineOrUnsafeMethod
 */
trait AutoSaveConflictTestTrait {

  protected EntityInterface $entity;

  /**
   * The CSRF token for the logged-in user, for unsafe HTTP methods.
   */
  protected string $csrfToken;

  abstract protected static function getPermissions(): array;

  abstract protected function setUpEntity(): void;

  abstract protected function modifyJsonToSendAsAutoSave(array &$json, string $text): void;

  abstract protected function assertCurrentAutoSaveText(string $text): void;

  /**
   * The URL that both reads and updates this entity's auto-save state.
   */
  abstract protected function getAutoSaveUrl(): Url;

  /**
   * Performs the "update the auto-save state" request for this entity type.
   *
   * Implementations differ in HTTP method and in which parts of the GET
   * response may be sent back, so the request itself is delegated rather than
   * a Symfony Request object being built here.
   */
  abstract protected function requestUpdateAutoSave(array $json): ResponseInterface;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // @todo Refactor this away in https://www.drupal.org/project/canvas/issues/3531679
    (new CanvasTestSetup())->setup();

    $this->setUpEntity();
    $this->loginWithPermissions(static::getPermissions());
  }

  /**
   * Logs in a freshly created user, and refreshes its CSRF token.
   */
  protected function loginWithPermissions(array $permissions): void {
    $account = $this->createUser($permissions);
    // On the minimum supported core version ::createUser() returns FALSE on
    // failure; from 11.4 onwards it always returns a user object.
    self::assertInstanceOf(AccountInterface::class, $account);
    $this->drupalLogin($account);
    $this->csrfToken = $this->drupalGet('session/token');
  }

  protected function getAutoSaveManager(): AutoSaveManager {
    $autoSaveManager = $this->container->get(AutoSaveManager::class);
    \assert($autoSaveManager instanceof AutoSaveManager);
    return $autoSaveManager;
  }

  /**
   * The request options shared by every request made by this test.
   */
  protected function getRequestOptions(): array {
    return [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $this->csrfToken,
      ],
    ];
  }

  protected static function decodeResponse(ResponseInterface $response): array {
    $body = (string) $response->getBody();
    self::assertJson($body);
    return \json_decode($body, TRUE, flags: JSON_THROW_ON_ERROR);
  }

  /**
   * Whether ::getAutoSaveUrl() responses carry cacheability metadata.
   *
   * Only responses that do can ever be served by the Dynamic Page Cache.
   */
  protected function isAutoSaveUrlDynamicPageCacheable(): bool {
    return TRUE;
  }

  /**
   * Reads the auto-save state, asserting the expected Dynamic Page Cache state.
   *
   * @param bool $expectCacheHit
   *   Whether this request is expected to be served by the Dynamic Page Cache.
   */
  protected function requestAutoSaveGet(bool $expectCacheHit = FALSE): array {
    $response = $this->makeApiRequest('GET', $this->getAutoSaveUrl(), $this->getRequestOptions());
    $body = (string) $response->getBody();
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $body);
    self::assertSame(
      $this->isAutoSaveUrlDynamicPageCacheable() ? ($expectCacheHit ? 'HIT' : 'MISS') : 'UNCACHEABLE (no cacheability)',
      $response->getHeader(DynamicPageCacheSubscriber::HEADER)[0] ?? NULL,
      'Dynamic Page Cache response header'
    );
    return self::decodeResponse($response);
  }

  /**
   * Asserts that repeating the GET is served by the Dynamic Page Cache.
   *
   * For endpoints that are not cacheable, this only asserts that they keep
   * reporting themselves as uncacheable.
   */
  protected function assertRepeatedAutoSaveGetIsCached(array $expectedJson): void {
    if (!$this->isAutoSaveUrlDynamicPageCacheable()) {
      $this->requestAutoSaveGet();
      return;
    }
    $this->assertSame($expectedJson, $this->requestAutoSaveGet(TRUE));
  }

  /**
   * Asserts that updating the auto-save state with the given data conflicts.
   */
  protected function assertAutoSaveConflict(array $json): void {
    $response = $this->requestUpdateAutoSave($json);
    $body = (string) $response->getBody();
    self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode(), $body);
    self::assertStringContainsString('You do not have the latest changes, please refresh your browser.', $body);
  }

  /**
   * Publishes every pending auto-save, the way the client does.
   */
  protected function publishAllAutoSaves(): void {
    $listResponse = $this->makeApiRequest('GET', Url::fromRoute('canvas.api.auto-save.get'), $this->getRequestOptions());
    self::assertSame(Response::HTTP_OK, $listResponse->getStatusCode(), (string) $listResponse->getBody());
    $list = self::decodeResponse($listResponse);
    self::assertArrayHasKey('data', $list);

    $options = $this->getRequestOptions();
    $options[RequestOptions::JSON] = $list['data'];
    $publishResponse = $this->makeApiRequest('POST', Url::fromRoute('canvas.api.auto-save.post'), $options);
    self::assertSame(Response::HTTP_OK, $publishResponse->getStatusCode(), (string) $publishResponse->getBody());
  }

  /**
   * 💡 Debug tip: put a breakpoint in the 409-throwing ::validateAutoSaves().
   */
  public function testOutdatedAutoSave(): void {
    $autoSaveKey = AutoSaveManager::getAutoSaveKey($this->entity);

    $getJson = $this->requestAutoSaveGet();
    // Even without an auto-save entry, the `autoSaves` value will not be empty.
    // We always need the `autoSaveStartingPoint` set to determine if the client data
    // is outdated because the `hash` being empty only indicates
    // that there was no auto-save entry at the time of the GET request. It does
    // not tell us if it was created from the current version of the entity. The
    // last request of the client could have been made a long time ago.
    $this->assertNull($getJson['autoSaves'][$autoSaveKey]['hash']);
    $this->assertNotEmpty($getJson['autoSaves'][$autoSaveKey]['autoSaveStartingPoint']);

    // Repeating the request must be served by the Dynamic Page Cache, and must
    // convey the same auto-save state.
    $this->assertRepeatedAutoSaveGetIsCached($getJson);

    $originalGetJson = $getJson;
    $this->modifyJsonToSendAsAutoSave($getJson, 'Updated text');
    $originalClientId = 'known-client-id';
    $getJson['clientInstanceId'] = $originalClientId;

    // Success: auto-save using:
    // - ✅ *any* client ID
    // - ✅ initial `autoSaveStartingPoint`
    // - ✅ null `hash`
    $response = $this->requestUpdateAutoSave($getJson);
    $this->assertCurrentAutoSaveText('Updated text');
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getBody());
    $postJson = self::decodeResponse($response);
    // The auto-save hash should be set after the first auto-save update but the
    // `autoSaveStartingPoint` should be the same as the one from the GET request.
    $this->assertNotNull($postJson['autoSaves'][$autoSaveKey]['hash']);
    $this->assertSame($getJson['autoSaves'][$autoSaveKey]['autoSaveStartingPoint'], $postJson['autoSaves'][$autoSaveKey]['autoSaveStartingPoint']);

    // Conflict: auto-save using:
    // - ⚠️ different client ID than last auto-save update.
    // - ✅ initial `autoSaveStartingPoint`
    // - ❌ null `hash` (MUST match the stored auto-save entry when client ID
    // does not match!)
    // (Rationale: subsequent auto-saves must update the existing one,
    // identified by the hash, unless we know it is from the same client.)
    $this->assertAutoSaveConflict($originalGetJson);
    $this->assertCurrentAutoSaveText('Updated text');

    // Success: auto-save using:
    // - ✅ same client ID as before
    // - ✅ initial `autoSaveStartingPoint`
    // - ✅ null `hash`
    // (Rationale: the same client must be trusted to send updates, because the
    // previous auto-save request might still be ongoing.)
    $originalGetJsonWithMatchingClientId = $originalGetJson;
    $originalGetJsonWithMatchingClientId['clientInstanceId'] = $originalClientId;
    $this->modifyJsonToSendAsAutoSave($originalGetJsonWithMatchingClientId, 'Updated text-finalV2');
    $response = $this->requestUpdateAutoSave($originalGetJsonWithMatchingClientId);
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getBody());
    $this->assertCurrentAutoSaveText('Updated text-finalV2');

    // Success: auto-save using:
    // - ✅ same client ID as before
    // - ✅ initial `autoSaveStartingPoint`
    // - ✅ nonsensical `hash`
    // (Rationale: the same client must be trusted to send updates, because the
    // previous auto-save request might still be ongoing.)
    $originalGetJsonWithMatchingClientId['autoSaves'][$autoSaveKey]['hash'] = $this->randomString(8);
    $this->modifyJsonToSendAsAutoSave($originalGetJsonWithMatchingClientId, 'Updated text-finalV2a');
    $response = $this->requestUpdateAutoSave($originalGetJsonWithMatchingClientId);
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getBody());
    $this->assertCurrentAutoSaveText('Updated text-finalV2a');

    $this->publishAllAutoSaves();

    // After publishing the auto-save hash should be empty and the
    // `autoSaveStartingPoint` should be different. Publishing saves the entity,
    // so the previously cached response must have been invalidated.
    $postPublishGetJson = $this->requestAutoSaveGet();
    $this->assertNull($postPublishGetJson['autoSaves'][$autoSaveKey]['hash']);
    $this->assertNotEmpty($postPublishGetJson['autoSaves'][$autoSaveKey]['autoSaveStartingPoint']);
    $this->assertNotEquals($postPublishGetJson['autoSaves'][$autoSaveKey]['autoSaveStartingPoint'], $originalGetJson['autoSaves'][$autoSaveKey]['autoSaveStartingPoint']);

    // Conflict: auto-save using:
    // - ⚠️ no client ID
    // - ❌ initial `autoSaveStartingPoint` (non-matching!)
    // - ✅ null `hash`
    // (Rationale: auto-saves targeting a past entity revision make no sense.)
    $this->assertAutoSaveConflict($originalGetJson);

    // Conflict: auto-save using:
    // - ✅ matching client ID
    // - ❌ initial `autoSaveStartingPoint` (non-matching!)
    // - ✅ null `hash`
    // (Rationale: auto-saves targeting a past entity revision make no sense,
    // even if they come from the same client. The client must refresh to target
    // the entity that was updated after the auto-save publish.)
    self::assertTrue($this->getAutoSaveManager()->getAutoSaveEntity($this->entity)->isEmpty());
    $originalGetJsonWithMatchingClientId = $originalGetJson;
    $originalGetJsonWithMatchingClientId['clientInstanceId'] = $originalClientId;
    $this->modifyJsonToSendAsAutoSave($originalGetJsonWithMatchingClientId, 'Updated text-finalV3');
    $this->assertAutoSaveConflict($originalGetJsonWithMatchingClientId);

    // Success: auto-save using:
    // - ✅ matching client ID
    // - ✅ post-publish `autoSaveStartingPoint`
    // - ✅ null `hash`
    $postPublishGetJson['clientInstanceId'] = $originalClientId;
    $this->modifyJsonToSendAsAutoSave($postPublishGetJson, 'Updated text-finalV4');
    $response = $this->requestUpdateAutoSave($postPublishGetJson);
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getBody());
    $this->assertCurrentAutoSaveText('Updated text-finalV4');

    // Conflict: auto-save using:
    // - ✅ matching client ID
    // - ❌ initial `autoSaveStartingPoint` (non-matching!)
    // - ✅ post-publish `hash`
    $autoSaveEntity = $this->getAutoSaveManager()->getAutoSaveEntity($this->entity);
    self::assertFalse($autoSaveEntity->isEmpty());
    self::assertSame($originalClientId, $autoSaveEntity->clientId);
    $originalGetJsonWithMatchingClientId['autoSaves'][$autoSaveKey]['hash'] = $autoSaveEntity->hash;
    $this->assertAutoSaveConflict($originalGetJsonWithMatchingClientId);
    $this->assertCurrentAutoSaveText('Updated text-finalV4');

    // Ensure that the `autoSaveStartingPoint` is updated *immediately* after
    // every entity save, not just after auto-saves.
    $preEntitySaveJson = $this->requestAutoSaveGet();
    $this->assertNotNull($preEntitySaveJson['autoSaves'][$autoSaveKey]['hash']);
    $this->assertNotEmpty($preEntitySaveJson['autoSaves'][$autoSaveKey]['autoSaveStartingPoint']);
    // Nothing changed in between, so this is served by the Dynamic Page Cache.
    $this->assertRepeatedAutoSaveGetIsCached($preEntitySaveJson);

    $this->entity->save();

    // Saving the entity must invalidate the cached response: the
    // `autoSaveStartingPoint` is derived from the *saved* entity, so a stale
    // cache hit here would let the client keep auto-saving against a revision
    // that no longer exists.
    // @see \Drupal\canvas\Controller\AutoSaveValidateTrait::getClientAutoSaveData()
    $postEntitySaveJson = $this->requestAutoSaveGet();
    $this->entity instanceof CanvasHttpApiEligibleConfigEntityInterface ?
        // For CanvasHttpApiEligibleConfigEntityInterface entities the auto-save
        // will be deleted after the entity save.
        // @see \Drupal\canvas\AutoSave\AutoSaveManager::onCanvasConfigEntitySave()
        $this->assertNull($postEntitySaveJson['autoSaves'][$autoSaveKey]['hash']) :
        $this->assertSame($preEntitySaveJson['autoSaves'][$autoSaveKey]['hash'], $postEntitySaveJson['autoSaves'][$autoSaveKey]['hash']);
    $this->assertNotEmpty($postEntitySaveJson['autoSaves'][$autoSaveKey]['autoSaveStartingPoint']);

    // The `autoSaveStartingPoint` should have changed.
    self::assertNotEquals($preEntitySaveJson['autoSaves'][$autoSaveKey]['autoSaveStartingPoint'], $postEntitySaveJson['autoSaves'][$autoSaveKey]['autoSaveStartingPoint']);

    // Finally, prove that the freshly computed `autoSaveStartingPoint` is
    // actually enforced: an auto-save targeting the pre-save starting point is
    // now outdated. Were the response above served from a stale cache, the
    // client would have been handed a starting point that still passed
    // validation.
    $preEntitySaveJson['clientInstanceId'] = $originalClientId;
    $this->modifyJsonToSendAsAutoSave($preEntitySaveJson, 'Updated text-finalV5');
    $this->assertAutoSaveConflict($preEntitySaveJson);
  }

}
