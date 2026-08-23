<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\Core\Url;
use Drupal\user\UserInterface;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the target-aware Code Component metadata operations.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
class ComponentMetadataValidationHttpApiTest extends HttpApiTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['canvas'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  protected UserInterface $codeComponentAdmin;

  protected UserInterface $limitedUser;

  protected function setUp(): void {
    parent::setUp();
    $admin = $this->createUser([JavaScriptComponent::ADMIN_PERMISSION]);
    $limited = $this->createUser(['access content']);
    \assert($admin instanceof UserInterface);
    \assert($limited instanceof UserInterface);
    $this->codeComponentAdmin = $admin;
    $this->limitedUser = $limited;
  }

  /**
   * Tests schema authentication, authorization, and ETag handling.
   */
  public function testSchema(): void {
    $url = Url::fromUri('base:/canvas/api/v0/code-components/metadata-schema');

    $response = $this->makeApiRequest('GET', $url, []);
    $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());

    $this->drupalLogin($this->limitedUser);
    $response = $this->makeApiRequest('GET', $url, []);
    $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());

    $this->drupalLogin($this->codeComponentAdmin);
    $response = $this->makeApiRequest('GET', $url, []);
    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertStringStartsWith('application/schema+json', $response->getHeaderLine('Content-Type'));
    $this->assertNotSame('', $response->getHeaderLine('ETag'));
    $schema = json_decode((string) $response->getBody(), associative: TRUE, flags: JSON_THROW_ON_ERROR);
    $this->assertSame('http://json-schema.org/draft-07/schema#', $schema['$schema']);

    $response = $this->makeApiRequest('GET', $url, [
      RequestOptions::HEADERS => [
        'If-None-Match' => $response->getHeaderLine('ETag'),
      ],
    ]);
    $this->assertSame(Response::HTTP_NOT_MODIFIED, $response->getStatusCode());
    $this->assertSame('', (string) $response->getBody());
  }

  /**
   * Tests authoritative validation without saving.
   */
  public function testValidationDoesNotSave(): void {
    $url = Url::fromUri('base:/canvas/api/v0/code-components/validate');
    $this->drupalLogin($this->codeComponentAdmin);

    $payload = self::validPayload('new_component');
    $response = $this->makeApiRequest('POST', $url, [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $this->drupalGet('session/token'),
      ],
      RequestOptions::JSON => $payload,
    ]);
    $this->assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), (string) $response->getBody());
    $this->assertNull(JavaScriptComponent::load('new_component'));

    $stored_payload = self::validPayload('stored_component');
    $stored_payload['props'] = [];
    $stored_payload['slots'] = [];
    $stored_payload['dataDependencies'] = [];
    $stored = JavaScriptComponent::createFromClientSide($stored_payload);
    $stored->save();
    $payload = self::validPayload('stored_component');
    $payload['name'] = 'Changed only in memory';
    $response = $this->makeApiRequest('POST', $url, [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $this->drupalGet('session/token'),
      ],
      RequestOptions::JSON => $payload,
    ]);
    $this->assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), (string) $response->getBody());
    $unchanged = JavaScriptComponent::load('stored_component');
    $this->assertInstanceOf(JavaScriptComponent::class, $unchanged);
    $this->assertSame('stored_component', $unchanged->label());

    $payload['props'] = [
      'invalid' => [
        'title' => 'Invalid',
        'type' => 'unsupported',
      ],
    ];
    $response = $this->makeApiRequest('POST', $url, [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $this->drupalGet('session/token'),
      ],
      RequestOptions::JSON => $payload,
    ]);
    $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    $body = json_decode((string) $response->getBody(), associative: TRUE, flags: JSON_THROW_ON_ERROR);
    $this->assertArrayHasKey('errors', $body);
    $unchanged = JavaScriptComponent::load('stored_component');
    $this->assertInstanceOf(JavaScriptComponent::class, $unchanged);
    $this->assertSame('stored_component', $unchanged->label());
  }

  /**
   * Creates a complete normalized API payload.
   */
  private static function validPayload(string $machine_name): array {
    return [
      'machineName' => $machine_name,
      'name' => $machine_name,
      'status' => TRUE,
      'required' => [],
      'props' => new \stdClass(),
      'slots' => new \stdClass(),
      'sourceCodeJs' => '',
      'sourceCodeCss' => '',
      'compiledJs' => '',
      'compiledCss' => '',
      'importedJsComponents' => [],
      'dataDependencies' => new \stdClass(),
    ];
  }

}
