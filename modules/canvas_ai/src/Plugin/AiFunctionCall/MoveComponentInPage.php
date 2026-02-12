<?php

namespace Drupal\canvas_ai\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\ai_agents\PluginInterfaces\AiAgentContextInterface;
use Drupal\canvas_ai\AiResponseValidator;
use Drupal\canvas_ai\CanvasAiPageBuilderHelper;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Function call plugin to move a component in the page.
 */
#[FunctionCall(
  id: 'canvas_ai:move_component_in_page',
  function_name: 'move_component_in_page',
  name: 'Move Component In Page',
  description: 'Moves an existing component to a new location in the page.',
  group: 'modification_tools',
  module_dependencies: ['canvas_ai'],
  context_definitions: [
    'component_uuid' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Component UUID"),
      description: new TranslatableMarkup("The UUID of the component to move."),
      required: TRUE,
    ),
    'region' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Region"),
      description: new TranslatableMarkup("The region to move the component to. Use this only when moving to an empty region."),
      required: FALSE,
    ),
    'reference_uuid' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Reference UUID"),
      description: new TranslatableMarkup("The UUID of the reference component to position near."),
      required: FALSE,
    ),
    'placement' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Placement"),
      description: new TranslatableMarkup("Placement relative to the reference component ('above' or 'below')."),
      required: FALSE,
    ),
  ],
)]
final class MoveComponentInPage extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface, BuilderResponseFunctionCallInterface {

  /**
   * The response validator service.
   *
   * @var \Drupal\canvas_ai\AiResponseValidator
   */
  protected AiResponseValidator $responseValidator;

  /**
   * The Canvas page builder helper service.
   *
   * @var \Drupal\canvas_ai\CanvasAiPageBuilderHelper
   */
  protected CanvasAiPageBuilderHelper $pageBuilderHelper;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface | static {
    $instance = new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ai.context_definition_normalizer'),
    );
    $instance->responseValidator = $container->get('canvas_ai.response_validator');
    $instance->pageBuilderHelper = $container->get('canvas_ai.page_builder_helper');
    $instance->loggerFactory = $container->get('logger.factory');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    try {
      $component_uuid = $this->getContextValue('component_uuid');
      $region = $this->getContextValue('region');
      $reference_uuid = $this->getContextValue('reference_uuid');
      $placement = $this->getContextValue('placement');

      $this->responseValidator->validateComponentExistsInPage($component_uuid);

      if (!empty($region)) {
        if (!empty($reference_uuid)) {
          throw new \InvalidArgumentException('If region is used, reference UUID must not be provided.');
        }
        if ($this->pageBuilderHelper->hasChildComponents($region)) {
          throw new \InvalidArgumentException('Region is used only when there are no child components in that region.');
        }
      }

      if (!empty($reference_uuid)) {
        // Validate if the reference component exists in the page.
        $this->responseValidator->validateComponentExistsInPage($reference_uuid);
        if (empty($placement)) {
          throw new \InvalidArgumentException('If reference UUID is provided, then placement must be provided.');
        }
      }

      $nodePath = $this->pageBuilderHelper->calculateNodepathToMoveComponent($region, $reference_uuid, $placement);

      $this->setStructuredOutput([
        'uuid' => $component_uuid,
        'nodePath' => $nodePath,
      ]);
      $this->setOutput(sprintf('Component %s moved successfully.', $component_uuid));
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('canvas_ai')->error($e->getMessage());
      $this->setOutput(sprintf('Failed to move component: %s', $e->getMessage()));
    }
  }

}
