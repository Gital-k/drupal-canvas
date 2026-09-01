<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel;

use ColinODell\PsrTestLogger\TestLogger;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai_agents\Entity\AiAgent;
use Drupal\ai_agents\PluginBase\AiAgentEntityWrapper;
use Drupal\ai_agents\PluginInterfaces\AiAgentInterface;
use Drupal\ai_agents\PluginManager\AiAgentManager;
use Drupal\canvas_ai\CanvasAiPermissions;
use Drupal\canvas_ai\CanvasAiTempStore;
use Drupal\canvas_ai\Plugin\AiFunctionCall\BuilderResponseFunctionCallInterface;
use Drupal\canvas_dev_ai\Controller\CanvasDevAiBuilder;
use Drupal\Core\Asset\AttachedAssets;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\canvas_ai\Kernel\Traits\CanvasAiDevHopTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Tests the canvas_dev_ai AI controller's access, settings and error paths.
 *
 * @see \Drupal\Tests\canvas_ai\Kernel\Agents\CanvasComponentAgentEndToEndTest
 */
#[Group('canvas_ai')]
#[CoversClass(CanvasDevAiBuilder::class)]
final class CanvasAiDevControllerTest extends CanvasKernelTestBase {

  use CanvasAiDevHopTrait;
  use RequestTrait;
  use UserCreationTrait;

  /**
   * Every chat history the agent under test was given, in write order.
   *
   * @var array<int, array<int, \Drupal\ai\OperationType\Chat\ChatMessage>>
   */
  private array $chatHistoryWrites = [];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_ai',
    'ai',
    'ai_agents',
    'ai_test',
    'key',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // canvas_dev_ai's shipped settings reference ai_agent entities from
    // canvas_ai's config/install, and its schema constraints require them to
    // exist, so they must be installed before canvas_dev_ai is.
    $this->installConfig(['canvas_ai', 'ai', 'ai_agents', 'ai_test']);
    $this->installEntitySchema('user');
    // Uninstalling any module fires user_module_uninstall(), which deletes from
    // the users_data table. Kernel tests do not create it unless asked.
    $this->installSchema('user', ['users_data']);
    $this->installEntitySchema('path_alias');
    $this->setUpCurrentUser(permissions: [CanvasAiPermissions::USE_CANVAS_AI]);
    // The echoai provider reads the ai_mock_provider_result table before the
    // file fixtures.
    $this->installEntitySchema('ai_mock_provider_result');
    // The controller instantiates the default chat provider before running the
    // agent, so every hop needs one even when the agent is mocked.
    $this->config('ai.settings')
      ->set('default_providers.chat', ['provider_id' => 'echoai', 'model_id' => 'gpt-test'])
      ->save();
  }

  /**
   * Tests that the `aiDevMode` flag follows the module install state.
   */
  public function testAiDevModeFlagFollowsInstallState(): void {
    $this->assertArrayNotHasKey('aiDevMode', $this->alterJsSettings()['canvas']);

    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_dev_ai']);
    $this->refreshContainer();
    $this->assertTrue($this->alterJsSettings()['canvas']['aiDevMode']);

    $this->container->get(ModuleInstallerInterface::class)->uninstall(['canvas_dev_ai']);
    $this->refreshContainer();
    $this->assertArrayNotHasKey('aiDevMode', $this->alterJsSettings()['canvas']);
  }

  /**
   * Tests that the controller rejects a request with an invalid CSRF token.
   */
  public function testControllerRejectsInvalidCsrfToken(): void {
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_dev_ai']);
    $this->refreshContainer();

    $request = Request::create('/admin/api/canvas/ai-dev', 'POST');
    $request->headers->set('X-CSRF-Token', 'invalid-token');

    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage('Invalid CSRF token');
    $this->request($request);
  }

  /**
   * A determineSolvability() failure clears the turn's stored agent state.
   *
   * @see \Drupal\canvas_dev_ai\Controller\CanvasDevAiBuilder::render()
   */
  public function testDetermineSolvabilityFailureClearsStoredAgentState(): void {
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_dev_ai']);
    $this->refreshContainer();
    $this->setUpAiDevHops();

    // Seed the state a previous hop would have parked, so deletion is
    // observable. The controller resumes it through the agent's fromArray().
    $temp_store = $this->container->get(CanvasAiTempStore::class);
    $temp_store->setStoredAgentState('test-request', ['looped' => FALSE]);
    self::assertNotNull($temp_store->getStoredAgentState('test-request'));

    $agent = $this->createMock(AiAgentEntityWrapper::class);
    $agent->method('determineSolvability')
      ->willThrowException(new \Exception('The provider exploded.'));
    $agent_manager = $this->createMock(AiAgentManager::class);
    $agent_manager->method('hasDefinition')
      ->with('canvas_component_agent')
      ->willReturn(TRUE);
    $agent_manager->method('createInstance')
      ->with('canvas_component_agent')
      ->willReturn($agent);
    $this->container->set('plugin.manager.ai_agents', $agent_manager);

    $response = $this->hop([
      'messages' => [['role' => 'user', 'text' => 'Make a red button']],
    ]);

    // The turn failed, the frontend must not send another hop, and the
    // half-serialized state is gone so the next turn starts clean.
    self::assertSame([
      'status' => FALSE,
      'message' => 'The provider exploded.',
      'should_continue' => FALSE,
      'progress' => '',
    ], $response);
    self::assertNull($temp_store->getStoredAgentState('test-request'));
  }

  /**
   * A not-solvable response gives the expected error.
   *
   * Any not-solvable response outside max-loop exhaustion triggers this error.
   *
   * @see \Drupal\canvas_dev_ai\Controller\CanvasDevAiBuilder::getNotSolvableMessage()
   * @see \Drupal\Tests\canvas_ai\Kernel\Agents\CanvasDevPageBuilderAgentEndToEndTest::testMaxLoopsOutcomeIsReported()
   * @see \Drupal\Tests\canvas_ai\Kernel\Agents\CanvasComponentAgentEndToEndTest::testMaxLoopsWithoutAConfiguredMessageUsesTheDefault()
   */
  public function testNotSolvableResponseGivesExpectedError(): void {
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_dev_ai']);
    $this->refreshContainer();
    $this->setUpAiDevHops();

    // The agent gave up on loop 1, well inside the agent's max_loops of 50.
    $agent = $this->createMock(AiAgentEntityWrapper::class);
    $agent->method('determineSolvability')->willReturn(AiAgentInterface::JOB_NOT_SOLVABLE);
    $agent->method('isFinished')->willReturn(TRUE);
    $agent->method('toArray')->willReturn(['looped' => 1]);
    $agent->method('getAiAgentEntity')->willReturnCallback(
      fn () => AiAgent::load('canvas_dev_page_builder_agent'),
    );
    $agent_manager = $this->createMock(AiAgentManager::class);
    $agent_manager->method('hasDefinition')->willReturn(TRUE);
    $agent_manager->method('createInstance')->willReturn($agent);
    $this->container->set('plugin.manager.ai_agents', $agent_manager);

    $response = $this->hop([
      'messages' => [['role' => 'user', 'text' => 'Add a hero']],
    ]);

    self::assertFalse($response['status']);
    self::assertSame('The request could not be completed. Please try again.', $response['message']);
    self::assertFalse($response['should_continue']);
  }

  /**
   * A build hop that stops narrating is nudged into placing the next section.
   *
   * The hop places a section and then answers with text and no further call,
   * which finishes the agent. Without the nudge the turn would close there,
   * leaving the narrated section unbuilt. The nudge is parked on the stored
   * chat history rather than run here, so the hop stays one model call.
   *
   * @see \Drupal\canvas_dev_ai\Controller\CanvasDevAiBuilder::appendNudge()
   */
  public function testTextOnlyBuildHopIsNudgedIntoTheNextPlacement(): void {
    $this->installCanvasDevAi();

    $placement = $this->mockPlacement(['operations' => [['operation' => 'ADD']]]);
    $agent = $this->mockAgent(
      finished: TRUE,
      answer: 'Adding the Campus life section now.',
      placements: [$placement],
    );
    $this->setUpAgentManager($agent);

    $response = $this->hop([
      'messages' => [['role' => 'user', 'text' => 'Approved']],
    ]);

    $this->assertTrue($response['status']);
    // The agent is finished, but the parked nudge keeps the turn open so the
    // frontend sends the hop that acts on it.
    $this->assertTrue($response['should_continue']);
    // The placement this hop made is reported, and the narration shows as
    // progress rather than closing the turn as the answer.
    $this->assertSame([['operation' => 'ADD']], $response['operations']);
    $this->assertArrayNotHasKey('message', $response);
    // The nudge is appended to the history the next hop resumes from.
    $history = $this->lastChatHistoryWrite();
    $this->assertCount(2, $history, 'The nudge is appended, not replacing the history.');
    $nudge = end($history);
    $this->assertInstanceOf(ChatMessage::class, $nudge);
    $this->assertSame('system', $nudge->getRole());
    $this->assertSame('Continue with the next section by calling place_components, or state that the page is complete.', $nudge->getText());
    // The next hop of this turn must know a nudge was spent.
    $temp_store = $this->container->get(CanvasAiTempStore::class);
    $this->assertSame(1, $temp_store->getNudgeCount('test-request'));
    $this->assertNotNull($temp_store->getStoredAgentState('test-request'));
  }

  /**
   * A hop that marks the end of the turn is not nudged, and hides the marker.
   *
   * Both text-only turns that legitimately follow a placement carry the
   * marker: the closing line, and the metadata gate that pauses mid-build for
   * the user. Neither may be nudged, and neither may show the marker.
   *
   * @see \Drupal\canvas_dev_ai\Controller\CanvasDevAiBuilder::endedOnPurpose()
   */
  #[DataProvider('markedEndOfTurnProvider')]
  public function testMarkedEndOfTurnIsNotNudged(string $answer, string $expected_message): void {
    $this->installCanvasDevAi();

    $agent = $this->mockAgent(
      finished: TRUE,
      answer: $answer,
      placements: [$this->mockPlacement([])],
    );
    $this->setUpAgentManager($agent);

    $response = $this->hop([
      'messages' => [['role' => 'user', 'text' => 'Approved']],
    ]);

    $this->assertFalse($response['should_continue']);
    $this->assertSame($expected_message, $response['message']);
    // The turn is over, so it leaves no nudge count behind for the next one.
    $this->assertSame(0, $this->container->get(CanvasAiTempStore::class)->getNudgeCount('test-request'));
  }

  /**
   * The marked text-only turns that come after a placement.
   */
  public static function markedEndOfTurnProvider(): array {
    return [
      'closing line' => [
        "That's the page built — every section is in place. ##END_OF_TURN##",
        "That's the page built — every section is in place.",
      ],
      'metadata gate' => [
        "The sections are in place. Shall I set the page title and description?\n##END_OF_TURN##",
        'The sections are in place. Shall I set the page title and description?',
      ],
    ];
  }

  /**
   * A text-only hop that placed nothing is left alone.
   *
   * The plan and approval gates are text-only by design. They come before any
   * placement, which is what tells them apart from a build that stopped.
   */
  public function testTextOnlyHopWithoutPlacementsIsNotNudged(): void {
    $this->installCanvasDevAi();

    $plan = 'Here is the plan: 1) Hero. 2) Programs. Shall I build it?';
    $this->setUpAgentManager($this->mockAgent(finished: TRUE, answer: $plan));

    $response = $this->hop([
      'messages' => [['role' => 'user', 'text' => 'Create me a landing page for a university site']],
    ]);

    $this->assertFalse($response['should_continue']);
    $this->assertSame($plan, $response['message']);
    $this->assertSame(0, $this->container->get(CanvasAiTempStore::class)->getNudgeCount('test-request'));
  }

  /**
   * The nudges run out after two, and the turn ends where the model stopped.
   *
   * The cap is spent across the turn, so a hop arriving with it exhausted is
   * the one that has to end the turn.
   */
  public function testNudgesAreCappedPerTurn(): void {
    $this->installCanvasDevAi();

    $logger = new TestLogger();
    $this->container->get(LoggerChannelFactoryInterface::class)
      ->get('canvas_dev_ai')
      ->addLogger($logger);

    // The two nudges the earlier hops of this turn were given.
    $temp_store = $this->container->get(CanvasAiTempStore::class);
    $temp_store->setNudgeCount('test-request', 2);

    $this->setUpAgentManager($this->mockAgent(
      finished: TRUE,
      answer: 'Adding the Campus life section now.',
      placements: [$this->mockPlacement([])],
    ));

    $response = $this->hop([
      'messages' => [['role' => 'user', 'text' => 'Approved']],
    ]);

    $this->assertFalse($response['should_continue']);
    $this->assertSame('Adding the Campus life section now.', $response['message']);
    $this->assertSame(0, $temp_store->getNudgeCount('test-request'));
    $this->assertTrue(
      $logger->hasRecordThatContains('The build hop ended without a tool call after', RfcLogLevel::WARNING),
      'Running out of nudges is logged.',
    );
  }

  /**
   * Installs canvas_dev_ai and prepares the session state the hops need.
   */
  private function installCanvasDevAi(): void {
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_dev_ai']);
    $this->refreshContainer();
    $this->setUpAiDevHops();
  }

  /**
   * Builds an agent that reports one hop's outcome.
   *
   * @param bool $finished
   *   Whether the agent has nothing parked, which ends the hop.
   * @param string $answer
   *   The text the model answered with.
   * @param array $placements
   *   The placement tool results of this hop, if any.
   *
   * @return \Drupal\ai_agents\PluginBase\AiAgentEntityWrapper&\PHPUnit\Framework\MockObject\MockObject
   *   The agent.
   */
  private function mockAgent(bool $finished, string $answer, array $placements = []): AiAgentEntityWrapper {
    $agent = $this->createMock(AiAgentEntityWrapper::class);
    $agent->method('determineSolvability')->willReturn(AiAgentInterface::JOB_SOLVABLE);
    $agent->method('isFinished')->willReturn($finished);
    $agent->method('solve')->willReturn($answer);
    $agent->method('getToolResults')->willReturn($placements);
    $agent->method('getToolResultsByPluginId')
      ->with('canvas_ai:place_components', TRUE)
      ->willReturn($placements);
    $agent->method('toArray')->willReturn(['looped' => 1]);
    // The history a nudge is appended to, so appending is observable.
    $agent->method('getChatHistory')->willReturn([new ChatMessage('user', 'Approved')]);
    $agent->method('setChatHistory')->willReturnCallback(
      function (array $history): void {
        $this->chatHistoryWrites[] = $history;
      },
    );
    return $agent;
  }

  /**
   * The chat history the agent under test was given last.
   *
   * @return array<int, \Drupal\ai\OperationType\Chat\ChatMessage>
   *   The messages of that history, in order.
   */
  private function lastChatHistoryWrite(): array {
    $this->assertNotEmpty($this->chatHistoryWrites, 'The agent was given a chat history.');
    return end($this->chatHistoryWrites);
  }

  /**
   * Builds a placement tool result carrying the given structured output.
   *
   * @param array $operations
   *   The structured output the client applies to the page.
   *
   * @return \Drupal\canvas_ai\Plugin\AiFunctionCall\BuilderResponseFunctionCallInterface&\PHPUnit\Framework\MockObject\MockObject
   *   The tool result.
   */
  private function mockPlacement(array $operations): BuilderResponseFunctionCallInterface {
    $placement = $this->createMock(BuilderResponseFunctionCallInterface::class);
    $placement->method('getPluginId')->willReturn('canvas_ai:place_components');
    $placement->method('getStructuredOutput')->willReturn($operations);
    return $placement;
  }

  /**
   * Hands the controller the agent that runs this request's single hop.
   *
   * @param \Drupal\ai_agents\PluginBase\AiAgentEntityWrapper $agent
   *   The agent. Exactly one instance is expected, which asserts that the
   *   request runs one hop however it ends.
   */
  private function setUpAgentManager(AiAgentEntityWrapper $agent): void {
    $agent_manager = $this->createMock(AiAgentManager::class);
    $agent_manager->method('hasDefinition')->willReturn(TRUE);
    $agent_manager->expects($this->once())
      ->method('createInstance')
      ->willReturn($agent);
    $this->container->set('plugin.manager.ai_agents', $agent_manager);
  }

  /**
   * Re-fetches the container after a module install or uninstall rebuild.
   */
  private function refreshContainer(): void {
    $container = \Drupal::getContainer();
    \assert($container instanceof ContainerBuilder);
    $this->container = $container;
  }

  /**
   * Runs the js_settings alter hooks on a minimal Canvas settings array.
   */
  private function alterJsSettings(): array {
    $settings = ['canvas' => ['aiExtensionAvailable' => TRUE]];
    $assets = new AttachedAssets();
    $this->container->get(ModuleHandlerInterface::class)->alter('js_settings', $settings, $assets);
    return $settings;
  }

}
