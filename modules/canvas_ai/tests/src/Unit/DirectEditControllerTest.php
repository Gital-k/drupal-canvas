<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Unit;

use Drupal\canvas_ai\AiResponseValidator;
use Drupal\canvas_ai\CanvasAiPageBuilderHelper;
use Drupal\canvas_ai\CanvasAiTempStore;
use Drupal\canvas_ai\Controller\DirectEditController;
use Drupal\canvas_ai\Service\ComponentSchemaLoaderInterface;
use Drupal\canvas_ai\Service\DirectEditMatcher;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the direct edit controller.
 *
 * @group canvas_ai
 * @coversDefaultClass \Drupal\canvas_ai\Controller\DirectEditController
 */
#[CoversClass(DirectEditController::class)]
#[Group('canvas')]
#[Group('canvas_direct_edit')]
final class DirectEditControllerTest extends UnitTestCase {

  /**
   * Creates a DirectEditController with standard mocks.
   */
  private function buildController(
    ComponentSchemaLoaderInterface $schemaLoader,
    AiResponseValidator $responseValidator,
    CanvasAiPageBuilderHelper $pageBuilderHelper,
    CanvasAiTempStore $tempStore,
    CsrfTokenGenerator $csrfTokenGenerator,
    LoggerInterface $logger,
    ?ConfigFactoryInterface $configFactory = NULL,
  ): DirectEditController {
    if ($configFactory === NULL) {
      $config = $this->createMock(ImmutableConfig::class);
      $config->method('get')->willReturnCallback(static function (string $key) {
        if ($key === 'telemetry') {
          return FALSE;
        }
        if ($key === 'edit_verbs') {
          return ['change', 'set', 'update', 'modify', 'make', 'turn', 'switch', 'put'];
        }
        return NULL;
      });
      $configFactory = $this->createMock(ConfigFactoryInterface::class);
      $configFactory->method('get')->willReturn($config);
    }
    $matcher = new DirectEditMatcher($schemaLoader, $configFactory);

    return new DirectEditController(
      $matcher,
      $responseValidator,
      $pageBuilderHelper,
      $tempStore,
      $csrfTokenGenerator,
      $logger,
      $configFactory,
    );
  }

  /**
   * @covers ::edit
   */
  public function testEditSeedsTempstoreFromLayoutBeforeComponentValidation(): void {
    $schemaLoader = $this->createMock(ComponentSchemaLoaderInterface::class);
    $responseValidator = $this->createMock(AiResponseValidator::class);
    $pageBuilderHelper = $this->createMock(CanvasAiPageBuilderHelper::class);
    $tempStore = $this->createMock(CanvasAiTempStore::class);
    $csrfTokenGenerator = $this->createMock(CsrfTokenGenerator::class);
    $logger = $this->createMock(LoggerInterface::class);
    $config = $this->createMock(ImmutableConfig::class);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);

    $csrfTokenGenerator->expects($this->once())
      ->method('validate')
      ->with('valid-token', 'canvas_ai.canvas_builder')
      ->willReturn(TRUE);

    $schemaLoader->expects($this->once())
      ->method('getPropAliases')
      ->with('sdc.byte_theme.heading')
      ->willReturn([
        'heading' => 'heading_text',
      ]);

    $schemaLoader->expects($this->once())
      ->method('getEnumValues')
      ->with('heading_text', 'sdc.byte_theme.heading')
      ->willReturn(NULL);

    $config->method('get')->willReturnCallback(static function (string $key) {
      if ($key === 'telemetry') {
        return FALSE;
      }
      if ($key === 'edit_verbs') {
        return ['change', 'set', 'update', 'modify', 'make', 'turn', 'switch', 'put'];
      }
      return NULL;
    });
    $configFactory->method('get')->willReturn($config);

    $tempStore->expects($this->once())
      ->method('setData')
      ->with(
        CanvasAiTempStore::COMPONENTS_IN_PAGE_WITH_PROP_VALUES_KEY,
        '{"390aa880-8d99-46f8-8727-3d0c762ece8a":{"heading_text":"Old"}}'
      );

    $responseValidator->expects($this->once())
      ->method('validateComponentExistsInPage')
      ->with('390aa880-8d99-46f8-8727-3d0c762ece8a');

    $responseValidator->expects($this->once())
      ->method('validateComponentPropUpdate')
      ->with('sdc.byte_theme.heading', ['heading_text' => 'Welcome']);

    $pageBuilderHelper->expects($this->once())
      ->method('populateMediaPropIfNeeded')
      ->with('sdc.byte_theme.heading', '390aa880-8d99-46f8-8727-3d0c762ece8a', ['heading_text' => 'Welcome'])
      ->willReturn(['heading_text' => 'Welcome']);

    $pageBuilderHelper->expects($this->once())
      ->method('includeUpdateOperations')
      ->with([
        [
          'uuid' => '390aa880-8d99-46f8-8727-3d0c762ece8a',
          'fieldValues' => ['heading_text' => 'Welcome'],
        ],
      ], ['status' => TRUE])
      ->willReturn([
        'status' => TRUE,
        'operations' => [
          [
            'operation' => 'UPDATE',
            'components' => [
              [
                'uuid' => '390aa880-8d99-46f8-8727-3d0c762ece8a',
                'fieldValues' => ['heading_text' => 'Welcome'],
              ],
            ],
          ],
        ],
      ]);

    $controller = $this->buildController(
      $schemaLoader,
      $responseValidator,
      $pageBuilderHelper,
      $tempStore,
      $csrfTokenGenerator,
      $logger,
      $configFactory,
    );

    $request = Request::create(
      '/admin/api/canvas/direct-edit',
      'POST',
      server: [
        'HTTP_X_CSRF_TOKEN' => 'valid-token',
      ],
      content: json_encode([
        'message' => 'Change the heading to Welcome',
        'component_uuid' => '390aa880-8d99-46f8-8727-3d0c762ece8a',
        'component_name' => 'sdc.byte_theme.heading',
        'layout' => '{"390aa880-8d99-46f8-8727-3d0c762ece8a":{"heading_text":"Old"}}',
      ], JSON_THROW_ON_ERROR)
    );

    $response = $controller->edit($request);
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertTrue($payload['status']);
    $this->assertTrue($payload['direct_edit']);
    $this->assertSame(0, $payload['tokens_used']);
    $this->assertSame('heading_text', $payload['matched_prop']);
    $this->assertSame('Welcome', $payload['matched_value']);
  }

  /**
   * Tests that elapsed_us is always logged, even when telemetry is disabled.
   *
   * @covers ::edit
   */
  public function testTelemetryElapsedAlwaysLogged(): void {
    $schemaLoader = $this->createMock(ComponentSchemaLoaderInterface::class);
    $responseValidator = $this->createMock(AiResponseValidator::class);
    $pageBuilderHelper = $this->createMock(CanvasAiPageBuilderHelper::class);
    $tempStore = $this->createMock(CanvasAiTempStore::class);
    $csrfTokenGenerator = $this->createMock(CsrfTokenGenerator::class);
    $logger = $this->createMock(LoggerInterface::class);
    $config = $this->createMock(ImmutableConfig::class);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);

    $csrfTokenGenerator->method('validate')->willReturn(TRUE);
    $schemaLoader->method('getPropAliases')->willReturn([]);
    $config->method('get')->willReturnCallback(static function (string $key) {
      if ($key === 'telemetry') {
        return FALSE;
      }
      if ($key === 'edit_verbs') {
        return ['change', 'set', 'update', 'modify', 'make', 'turn', 'switch', 'put'];
      }
      return NULL;
    });
    $configFactory->method('get')->willReturn($config);

    // With telemetry disabled, info should be called exactly once for elapsed.
    $logger->expects($this->once())
      ->method('info')
      ->with(
        $this->stringContains('match elapsed'),
        $this->callback(function (array $ctx): bool {
          return isset($ctx['@elapsed_us']);
        })
      );

    $controller = $this->buildController(
      $schemaLoader,
      $responseValidator,
      $pageBuilderHelper,
      $tempStore,
      $csrfTokenGenerator,
      $logger,
      $configFactory,
    );

    $request = Request::create(
      '/admin/api/canvas/direct-edit',
      'POST',
      server: ['HTTP_X_CSRF_TOKEN' => 'valid-token'],
      content: json_encode([
        'message' => 'Change the heading to Welcome',
        'component_uuid' => '390aa880-8d99-46f8-8727-3d0c762ece8a',
        'component_name' => 'sdc.byte_theme.heading',
      ], JSON_THROW_ON_ERROR)
    );

    $response = $controller->edit($request);
    $this->assertSame(422, $response->getStatusCode());
  }

  /**
   * Tests that detailed telemetry is logged when the config toggle is enabled.
   *
   * @covers ::edit
   */
  public function testTelemetryDetailedWhenEnabled(): void {
    $schemaLoader = $this->createMock(ComponentSchemaLoaderInterface::class);
    $responseValidator = $this->createMock(AiResponseValidator::class);
    $pageBuilderHelper = $this->createMock(CanvasAiPageBuilderHelper::class);
    $tempStore = $this->createMock(CanvasAiTempStore::class);
    $csrfTokenGenerator = $this->createMock(CsrfTokenGenerator::class);
    $logger = $this->createMock(LoggerInterface::class);
    $config = $this->createMock(ImmutableConfig::class);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);

    $csrfTokenGenerator->method('validate')->willReturn(TRUE);
    $schemaLoader->method('getPropAliases')->willReturn([]);
    $config->method('get')->willReturnCallback(static function (string $key) {
      if ($key === 'telemetry') {
        return TRUE;
      }
      if ($key === 'edit_verbs') {
        return ['change', 'set', 'update', 'modify', 'make', 'turn', 'switch', 'put'];
      }
      return NULL;
    });
    $configFactory->method('get')->willReturn($config);

    // With telemetry enabled: 1 elapsed log + 1 detailed telemetry log.
    $infoMessages = [];
    $logger->expects($this->exactly(2))
      ->method('info')
      ->willReturnCallback(function (string $msg) use (&$infoMessages): void {
        $infoMessages[] = $msg;
      });

    $controller = $this->buildController(
      $schemaLoader,
      $responseValidator,
      $pageBuilderHelper,
      $tempStore,
      $csrfTokenGenerator,
      $logger,
      $configFactory,
    );

    $request = Request::create(
      '/admin/api/canvas/direct-edit',
      'POST',
      server: ['HTTP_X_CSRF_TOKEN' => 'valid-token'],
      content: json_encode([
        'message' => 'Change the heading to Welcome',
        'component_uuid' => '390aa880-8d99-46f8-8727-3d0c762ece8a',
        'component_name' => 'sdc.byte_theme.heading',
      ], JSON_THROW_ON_ERROR)
    );

    $response = $controller->edit($request);
    $this->assertSame(422, $response->getStatusCode());
    $this->assertCount(2, $infoMessages);
    $this->assertStringContainsString('match elapsed', $infoMessages[0]);
    $this->assertStringContainsString('telemetry', $infoMessages[1]);
  }

}
