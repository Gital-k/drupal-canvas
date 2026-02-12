<?php

namespace Drupal\canvas_ai\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\ai_agents\PluginInterfaces\AiAgentContextInterface;
use Drupal\canvas_ai\CanvasAiComponentContextHelper;
use Drupal\canvas_ai\CanvasAiPermissions;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Function call plugin to get detailed metadata for specific components.
 *
 * This plugin retrieves full component information (including props and slots)
 * for a list of component IDs using the CanvasAiComponentContextHelper service.
 *
 * @internal
 */
#[FunctionCall(
  id: 'canvas_ai:get_metadata_of_components',
  function_name: 'get_metadata_of_components',
  name: 'Get Metadata of Components',
  description: 'This method gets detailed metadata (including props and slots) for specific components. Provide an array of component IDs.',
  group: 'information_tools',
  context_definitions: [
    'component_ids' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Component IDs"),
      description: new TranslatableMarkup("Array of component IDs to retrieve metadata for. Example: ['js.banner', 'block.system_menu_block.footer']"),
      required: TRUE,
      multiple: TRUE,
    ),
  ],
  module_dependencies: ['canvas_ai'],
)]
final class GetMetadataOfComponents extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

  /**
   * The Canvas AI component context helper service.
   *
   * @var \Drupal\canvas_ai\CanvasAiComponentContextHelper
   */
  protected CanvasAiComponentContextHelper $componentContextHelper;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The component metadata output.
   *
   * @var string
   */
  protected string $output = '';

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
    $instance->componentContextHelper = $container->get('canvas_ai.component_context_helper');
    $instance->currentUser = $container->get('current_user');
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

    // Get the component IDs from context (this will be an array).
    $component_ids = $this->getContextValue('component_ids');

    $this->setOutput($this->componentContextHelper->getDetailedMetadataOfComponents($component_ids));
  }

}
