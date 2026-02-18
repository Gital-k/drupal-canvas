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
 * Plugin to get component content from the current page.
 */
#[FunctionCall(
  id: 'canvas_ai:get_component_content',
  function_name: 'get_component_content',
  name: 'Get Component Content',
  description: 'Retrieves the content and property values of components placed in the current page. Use this to get the actual content that has been set for components.',
  group: 'information_tools',
  module_dependencies: ['canvas_ai'],
  context_definitions: [
    'component_uuid' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Component UUID"),
      description: new TranslatableMarkup("The UUID of the component for which content should be extracted. If not provided, this tool will return the complete content of all components placed in the page."),
      required: FALSE,
    ),
  ],
)]
final class GetComponentContent extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

  /**
   * The Canvas AI page builder helper.
   *
   * @var \Drupal\canvas_ai\CanvasAiPageBuilderHelper
   */
  protected CanvasAiPageBuilderHelper $pageBuilderHelper;

  /**
   * The response validator service.
   *
   * @var \Drupal\canvas_ai\AiResponseValidator
   */
  protected AiResponseValidator $responseValidator;

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
    $instance->pageBuilderHelper = $container->get('canvas_ai.page_builder_helper');
    $instance->responseValidator = $container->get('canvas_ai.response_validator');
    $instance->loggerFactory = $container->get('logger.factory');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    try {
      $component_uuid = $this->getContextValue('component_uuid');
      if (!empty($component_uuid)) {
        // Validate if component with the provided uuid exists in the page.
        $this->responseValidator->validateComponentExistsInPage($component_uuid);
      }

      // Get component contents - will throw exception if UUID provided but not found.
      $contents = $this->pageBuilderHelper->getComponentContents($component_uuid);

      $this->setOutput(Yaml::dump($contents, 10, 2));
    }
    catch (\InvalidArgumentException $e) {
      $this->loggerFactory->get('canvas_ai')->error($e->getMessage());
      $this->setOutput(Yaml::dump(['error' => $e->getMessage()], 10, 2));
    }
  }

}
