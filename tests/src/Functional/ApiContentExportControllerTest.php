<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Controller\ApiContentExportController;
use Drupal\canvas\Entity\Page;
use Drupal\Core\Url;
use Drupal\user\UserInterface;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests API content export endpoint behavior.
 *
 * @internal
 */
#[CoversClass(ApiContentExportController::class)]
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class ApiContentExportControllerTest extends HttpApiTestBase {

  private Page $page;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  protected function setUp(): void {
    parent::setUp();

    $page = Page::create([
      'title' => 'Page 1',
      'status' => TRUE,
      'path' => ['alias' => '/page-1'],
    ]);
    $page->save();
    \assert($page instanceof Page);
    $this->page = $page;
  }

  public function testGetAutoSavedExportWithoutAutoSaveReturnsNotFound(): void {
    $this->loginExportUser();

    $response = $this->makeExportRequest($this->page);
    self::assertSame(404, $response->getStatusCode());

    $response_body = json_decode((string) $response->getBody(), TRUE);
    self::assertIsArray($response_body);
    self::assertSame([
      'errors' => [[
        'detail' => \sprintf('No auto-save items for entity %s found', $this->page->id()),
        'source' => [
          'pointer' => AutoSaveManager::getAutoSaveKey($this->page),
        ],
      ],
      ],
    ], $response_body);
  }

  public function testGetPublishedExport(): void {
    $this->loginExportUser();

    $response = $this->makeExportRequest($this->page, [ApiContentExportController::AUTO_SAVED_QUERY_KEY => 'false']);
    self::assertSame(200, $response->getStatusCode());

    $response_body = json_decode((string) $response->getBody(), TRUE);
    self::assertIsArray($response_body);
    self::assertArrayHasKey('data', $response_body);
    self::assertSame('en', $response_body['data']['language']);
    self::assertIsInt($response_body['data']['updated']);
    self::assertIsString($response_body['data']['entity']);
    self::assertStringContainsString('Page 1', $response_body['data']['entity']);
  }

  public function testGetAutoSavedExport(): void {
    $this->loginExportUser();

    $auto_save_manager = $this->container->get(AutoSaveManager::class);
    \assert($auto_save_manager instanceof AutoSaveManager);

    $this->page->set('title', 'Auto-saved export title');
    $auto_save_manager->saveEntity($this->page);

    $request_start = \time();
    $response = $this->makeExportRequest($this->page);
    self::assertSame(200, $response->getStatusCode());

    $response_body = json_decode((string) $response->getBody(), TRUE);
    self::assertIsArray($response_body);
    self::assertArrayHasKey('data', $response_body);
    self::assertSame('en', $response_body['data']['language']);
    self::assertIsInt($response_body['data']['updated']);
    self::assertGreaterThanOrEqual($request_start, $response_body['data']['updated']);
    self::assertLessThanOrEqual(\time(), $response_body['data']['updated']);
    self::assertIsString($response_body['data']['entity']);
    self::assertStringContainsString('Auto-saved export title', $response_body['data']['entity']);
  }

  public function testGetRequiresPermission(): void {
    $user = $this->createUser([]);
    \assert($user instanceof UserInterface);
    $this->drupalLogin($user);

    $response = $this->makeExportRequest($this->page);
    self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
  }

  private function loginExportUser(): UserInterface {
    $user = $this->createUser([Page::EDIT_PERMISSION]);
    \assert($user instanceof UserInterface);
    $this->drupalLogin($user);
    return $user;
  }

  private function makeExportRequest(Page $page, array $query = []): ResponseInterface {
    $request_options = [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
      ],
    ];

    $url_options = $query ? ['query' => $query] : [];
    $url = Url::fromUri("base:/canvas/api/v0/export/content/canvas_page/{$page->id()}", $url_options);

    return $this->makeApiRequest('GET', $url, $request_options);
  }

}
