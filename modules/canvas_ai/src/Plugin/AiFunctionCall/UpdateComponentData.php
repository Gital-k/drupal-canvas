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
use Symfony\Component\Yaml\Yaml;

/**
 * Function call plugin to update component data.
 */
#[FunctionCall(
  id: 'canvas_ai:update_component_data',
  function_name: 'update_component_data',
  name: 'Update Component Data',
  description: 'Updates the property values of an existing component in the page.',
  group: 'modification_tools',
  module_dependencies: ['canvas_ai'],
  context_definitions: [
    'component_uuid' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Component UUID"),
      description: new TranslatableMarkup("The UUID of the component to update."),
      required: TRUE,
    ),
    'prop_values' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Property Values"),
      description: new TranslatableMarkup("The new property values for the component in YAML format."),
      required: TRUE,
    ),
  ],
)]
final class UpdateComponentData extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface, BuilderResponseFunctionCallInterface {

  /**
   * The response validator service.
   *
   * @var \Drupal\canvas_ai\AiResponseValidator
   */
  protected AiResponseValidator $responseValidator;

  /**
   * The page builder helper service.
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
      $prop_values = $this->getContextValue('prop_values');

      // Validate if component with the provided uuid exists in the page.
      $this->responseValidator->validateComponentExistsInPage($component_uuid);

      // Get the component ID from the UUID and validate prop values.
      $component_data = $this->pageBuilderHelper->getComponentDataFromCurrentLayout($component_uuid);
      $component_id = $component_data['name'];
      $parsed_props = Yaml::parse($prop_values);
      $this->responseValidator->validateComponentPropUpdate($component_id, $parsed_props);

      // Populate media prop values with their current media entity IDs
      // if not explicitly provided by the tool.
      $parsed_props = $this->pageBuilderHelper->populateMediaPropIfNeeded($component_id, $component_uuid, $parsed_props);

      // Return the uuid and parsed prop values as structured output.
      $this->setStructuredOutput([
        'uuid' => $component_uuid,
        'fieldValues' => $parsed_props,
      ]);

      // Set output message.
      $this->setOutput(sprintf('The component with uuid %s was updated successfully.', $component_uuid));
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('canvas_ai')->error($e->getMessage());
      $this->setOutput(sprintf('Failed to update component data: %s', $e->getMessage()));
    }
  }

}
