<?php

namespace Drupal\canvas_ai\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\ai_agents\PluginInterfaces\AiAgentContextInterface;
use Drupal\canvas_ai\AiResponseValidator;
use Drupal\canvas_ai\CanvasAiPageBuilderHelper;
use Drupal\canvas_ai\CanvasAiPermissions;
use Drupal\canvas_ai\CanvasAiTempStore;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Tool that lets the agent update the props of components already on the page.
 *
 * @see \Drupal\canvas_ai\Controller\CanvasBuilder::render()
 * @see \Drupal\canvas_ai\Plugin\AiFunctionCall\PlaceComponents
 */
#[FunctionCall(
  id: 'canvas_ai:edit_components',
  function_name: 'edit_components',
  name: 'Edit Components',
  description: 'Updates the props of one or more components already on the page. The input is a single YAML string with a top-level "component_edits" map; under it, each key is a component UUID and each value lists the "prop_name: value" pairs to set on that component.',
  group: 'modification_tools',
  context_definitions: [
    'component_updates' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Component updates in yml format"),
      description: new TranslatableMarkup("The prop updates to apply, as a YAML string with a top-level 'component_edits' map keyed by component UUID with 'prop_name: value' pairs under each."),
      required: TRUE,
    ),
  ],
)]
final class EditComponents extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface, BuilderResponseFunctionCallInterface {

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The Canvas page builder helper service.
   *
   * @var \Drupal\canvas_ai\CanvasAiPageBuilderHelper
   */
  protected CanvasAiPageBuilderHelper $pageBuilderHelper;

  /**
   * The Canvas AI tempstore service.
   *
   * @var \Drupal\canvas_ai\CanvasAiTempStore
   */
  protected CanvasAiTempStore $canvasAiTempStore;

  /**
   * The response validator service.
   *
   * @var \Drupal\canvas_ai\AiResponseValidator
   */
  protected AiResponseValidator $responseValidator;

  /**
   * Load from dependency injection container.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface | static {
    $instance = new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ai.context_definition_normalizer'),
    );
    $instance->loggerFactory = $container->get('logger.factory');
    $instance->currentUser = $container->get('current_user');
    $instance->pageBuilderHelper = $container->get('canvas_ai.page_builder_helper');
    $instance->canvasAiTempStore = $container->get('canvas_ai.tempstore');
    $instance->responseValidator = $container->get('canvas_ai.response_validator');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    // Make sure that the user has the right permissions.
    if (!$this->currentUser->hasPermission(CanvasAiPermissions::USE_CANVAS_AI)) {
      throw new \Exception('The current user does not have the right permissions to run this tool.');
    }
    try {
      $component_updates = $this->getContextValue('component_updates');
      $this->loggerFactory->get('canvas_ai')->debug('Received component updates: @updates', ['@updates' => $component_updates]);
      $parsed = Yaml::parse($component_updates);
      // The agent sends the UUID map nested under a single 'component_edits'
      // root so the model does not spread the UUIDs as tool-argument names.
      // Fall back to the parsed value itself if it omitted the root key.
      $component_updates_array = $parsed['component_edits'] ?? $parsed;

      $this->validateComponentUpdates($component_updates_array);

      $this->setStructuredOutput(['component_updates' => $component_updates_array]);
      $this->setOutput("The updates were made successfully.\n" . $component_updates);
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('canvas_ai')->error($e->getMessage());
      $this->setOutput(\sprintf('Failed to edit components: %s', $e->getMessage()));
    }
  }

  /**
   * Validates the agent-supplied prop updates against the current layout.
   *
   * Each edited UUID is resolved to its component id and existing resolved
   * props from the current layout; the updates are merged over those props and
   * handed to the shared response validator so the same constraints used for
   * placement (component existence, prop names, value shapes) apply to edits.
   *
   * @param array $component_updates
   *   The parsed updates, keyed by component UUID with prop_name => value pairs.
   *
   * @throws \Exception
   *   When an edited UUID is not present on the page.
   * @throws \Drupal\canvas\Exception\ConstraintViolationException
   *   When the merged prop values fail validation.
   */
  private function validateComponentUpdates(array $component_updates): void {
    $current_layout = Json::decode($this->canvasAiTempStore->getData(CanvasAiTempStore::CURRENT_LAYOUT_KEY) ?? '');
    $components_by_uuid = $this->pageBuilderHelper->getComponentsByUuid(\is_array($current_layout) ? $current_layout : []);

    $errors = [];
    $component_groups = [];
    foreach ($component_updates as $uuid => $prop_updates) {
      if (!isset($components_by_uuid[$uuid])) {
        $errors[] = \sprintf('Component %s was not found on the page.', $uuid);
        continue;
      }
      $component = $components_by_uuid[$uuid];
      $merged_props = (\is_array($prop_updates) ? $prop_updates : []) + $component['props'];
      $component_groups[] = [$component['component_id'] => ['props' => $merged_props]];
    }

    if (!empty($errors)) {
      throw new \Exception(implode("\n", $errors));
    }

    $this->responseValidator->validateComponentStructure($component_groups);
  }

}
