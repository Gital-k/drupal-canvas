<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Session\SessionConfigurationInterface;
use Drupal\Core\Url;
use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Page;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\block\Traits\BlockCreationTrait;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\canvas\Kernel\Traits\VfsPublicStreamUrlTrait;
use Drupal\Tests\canvas\TestSite\CanvasTestSetup;
use Drupal\Tests\canvas\Traits\AutoSaveManagerTestTrait;
use Drupal\Tests\canvas\Traits\AutoSaveRequestTestTrait;
use Drupal\Tests\canvas\Traits\CanvasFieldCreationTrait;
use Drupal\Tests\canvas\Traits\CanvasFieldTrait;
use Drupal\Tests\canvas\Traits\OpenApiSpecTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Drupal\Core\Cache\CacheableJsonResponse;

/**
 * @coversDefaultClass \Drupal\canvas\Controller\ApiAutoSaveController
 * @group canvas
 * @group #slow
 * @todo Refactor this to start using CanvasKernelTestBase and stop using CanvasTestSetup in https://www.drupal.org/project/canvas/issues/3531679
 */
#[RunTestsInSeparateProcesses]
final class ApiAutoSaveControllerTranslationTest extends KernelTestBase {

  use AutoSaveManagerTestTrait;
  use AutoSaveRequestTestTrait;
  use UserCreationTrait;
  use OpenApiSpecTrait;
  use BlockCreationTrait;
  use RequestTrait;
  use CanvasFieldCreationTrait;
  use CanvasFieldTrait;
  use VfsPublicStreamUrlTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'path_alias',
    'path',
    'test_user_config',
    'canvas_force_publish_error',
    'language',
    'content_translation',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('path_alias');
    $this->installConfig(['language']);
    // @todo Remove this setup once refactored https://www.drupal.org/project/canvas/issues/3531679
    (new CanvasTestSetup())->setup();
    ConfigurableLanguage::createFromLangcode('fr')->save();
  }

  /**
   * Verify GET returns auto-saved Page translations with the correct langcode.
   */
  public function testGetTranslations(): void {
    $this->installConfig(['test_user_config']);

    /** @var \Drupal\canvas\AutoSave\AutoSaveManager $autoSave */
    $autoSave = $this->container->get(AutoSaveManager::class);

    $this->setUpCurrentUser(permissions: [Page::EDIT_PERMISSION]);

    // Create a Page with an English source and a French translation.
    $page = Page::create([
      'title' => 'Original EN title',
      'status' => FALSE,
      'langcode' => 'en',
    ]);
    $page->save();

    $pageFr = $page->addTranslation('fr', [
      'title' => 'Original FR title',
      'status' => FALSE,
    ]);
    $pageFr->save();

    // Trigger auto-saves for both translations by updating the title.
    $page->set('title', 'Updated EN title');
    $autoSave->saveEntity($page);

    $pageFr->set('title', 'Updated FR title');
    $autoSave->saveEntity($pageFr);

    $request = Request::create(Url::fromRoute('canvas.api.auto-save.get')->toString());
    $response = $this->request($request);

    self::assertInstanceOf(CacheableJsonResponse::class, $response);
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());

    $content = \json_decode($response->getContent() ?: '{}', TRUE);

    $enKey = \sprintf('canvas_page:%d:en', $page->id());
    $frKey = \sprintf('canvas_page:%d:fr', $page->id());

    self::assertArrayHasKey($enKey, $content, 'GET response must include the EN translation auto-save.');
    self::assertArrayHasKey($frKey, $content, 'GET response must include the FR translation auto-save.');

    self::assertSame('en', $content[$enKey]['langcode'], 'EN auto-save entry must have langcode "en".');
    self::assertSame('fr', $content[$frKey]['langcode'], 'FR auto-save entry must have langcode "fr".');

    self::assertSame('Updated EN title', $content[$enKey]['label']);
    self::assertSame('Updated FR title', $content[$frKey]['label']);
  }

  /**
   * Verify POST publishes the correct auto-saved Page translation.
   */
  public function testPostTranslations(): void {
    /** @var \Drupal\canvas\AutoSave\AutoSaveManager $autoSave */
    $autoSave = $this->container->get(AutoSaveManager::class);

    $this->setUpCurrentUser(permissions: [
      Page::EDIT_PERMISSION,
      AutoSaveManager::PUBLISH_PERMISSION,
    ]);

    // Create a Page with EN source and FR translation, both unpublished.
    $page = Page::create([
      'title' => 'Original EN title',
      'status' => FALSE,
      'langcode' => 'en',
    ]);
    $page->save();

    $pageFr = $page->addTranslation('fr', [
      'title' => 'Original FR title',
      'status' => FALSE,
    ]);
    $pageFr->save();

    // Auto-save only the FR translation.
    $pageFr->set('title', 'Published FR title');
    $autoSave->saveEntity($pageFr);

    $autoSaveData = $this->getAutoSaveStatesFromServer();
    $frKey = \sprintf('canvas_page:%d:fr', $page->id());
    self::assertArrayHasKey($frKey, $autoSaveData, 'FR auto-save entry must exist before publishing.');

    $response = $this->makePublishAllRequest([$frKey => $autoSaveData[$frKey]]);

    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    self::assertSame(
      ['message' => 'Successfully published 1 item.'],
      \json_decode($response->getContent() ?: '', TRUE),
    );

    // Reload and verify only the FR translation was published.
    $reloadedPage = Page::load($page->id());
    \assert($reloadedPage !== NULL);

    self::assertFalse(
      $reloadedPage->getTranslation('en')->isPublished(),
      'The EN translation must remain unpublished.',
    );
    self::assertTrue(
      $reloadedPage->getTranslation('fr')->isPublished(),
      'The FR translation must be published after POST.',
    );
    self::assertSame(
      'Published FR title',
      $reloadedPage->getTranslation('fr')->label(),
      'The FR translation must carry the auto-saved title after publishing.',
    );

    // FR auto-save must be cleared after publishing; EN was never created.
    $remainingAutoSaves = $this->getAutoSaveStatesFromServer();
    $enKey = \sprintf('canvas_page:%d:en', $page->id());
    self::assertArrayNotHasKey($frKey, $remainingAutoSaves, 'FR auto-save must be cleared after publishing.');
    self::assertArrayNotHasKey($enKey, $remainingAutoSaves, 'EN auto-save must not exist as it was never created in this test.');
  }

  /**
   * Verify DELETE deletes only the specified translation auto-save.
   */
  public function testDeleteTranslations(): void {
    /** @var \Drupal\canvas\AutoSave\AutoSaveManager $autoSave */
    $autoSave = $this->container->get(AutoSaveManager::class);

    $this->setUpCurrentUser(permissions: [
      Page::EDIT_PERMISSION,
      AutoSaveManager::PUBLISH_PERMISSION,
    ]);

    // Create a Page with EN source and FR translation.
    $page = Page::create([
      'title' => 'Original EN title',
      'status' => FALSE,
      'langcode' => 'en',
    ]);
    $page->save();

    $pageFr = $page->addTranslation('fr', [
      'title' => 'Original FR title',
      'status' => FALSE,
    ]);
    $pageFr->save();

    // Auto-save both translations.
    $page->set('title', 'Updated EN title');
    $autoSave->saveEntity($page);

    $pageFr->set('title', 'Updated FR title');
    $autoSave->saveEntity($pageFr);

    $enKey = \sprintf('canvas_page:%d:en', $page->id());
    $frKey = \sprintf('canvas_page:%d:fr', $page->id());

    $autoSaveData = $this->getAutoSaveStatesFromServer();
    self::assertArrayHasKey($enKey, $autoSaveData, 'EN auto-save must exist before DELETE.');
    self::assertArrayHasKey($frKey, $autoSaveData, 'FR auto-save must exist before DELETE.');

    // Issue a DELETE targeting only the FR translation via its auto-save key.
    $url = Url::fromRoute('canvas.api.auto-save.delete', [
      'entity_type' => Page::ENTITY_TYPE_ID,
      'entity' => $page->id(),
      'langcode' => 'fr',
    ]);
    $request = $this->createAuthenticatedDeleteRequest($url->toString());
    $response = $this->request($request);

    self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
    self::assertSame(
      ['message' => 'Auto-save data deleted successfully.'],
      \json_decode((string) $response->getContent(), TRUE),
    );

    // EN auto-save must remain; FR must be gone.
    $remainingAutoSaves = $this->getAutoSaveStatesFromServer();
    self::assertArrayHasKey($enKey, $remainingAutoSaves, 'EN auto-save must remain after deleting only FR.');
    self::assertArrayNotHasKey($frKey, $remainingAutoSaves, 'FR auto-save must be removed after DELETE.');

    // Sanity check: a second DELETE for FR must return 404.
    $request = $this->createAuthenticatedDeleteRequest($url->toString());
    $response = $this->request($request);
    self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    self::assertSame(
      ['error' => 'No auto-save data found for this entity.'],
      \json_decode((string) $response->getContent(), TRUE),
    );
  }

  /**
   * Create an authenticated DELETE request with a valid CSRF token.
   */
  private function createAuthenticatedDeleteRequest(string $url): Request {
    $request = Request::create($url, 'DELETE', server: ['CONTENT_TYPE' => 'application/json']);

    $sessionConfiguration = $this->container
      ->get(SessionConfigurationInterface::class)
      ->getOptions($request);
    $request->cookies->set($sessionConfiguration['name'], 'ABCD');

    $tokenGenerator = $this->container->get(CsrfTokenGenerator::class);
    $request->headers->set(
      'X-CSRF-Token',
      $tokenGenerator->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY),
    );

    return $request;
  }

}
