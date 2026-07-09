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
 * Function call plugin to get the props and slots of specific components.
 *
 * Given a list of component ids, returns each component's props and slots so an
 * agent can decide how to use the candidates it shortlisted from the catalog
 * returned by get_component_context.
 *
 * @see \Drupal\canvas_ai\Plugin\AiFunctionCall\GetComponentContext
 *
 * @internal
 */
#[FunctionCall(
  id: 'canvas_ai:get_component_details',
  function_name: 'get_component_details',
  name: 'Get Component Details',
  description: 'Returns the props and slots for specific components. Provide the ids of the components you shortlisted from get_component_context.',
  group: 'information_tools',
  context_definitions: [
    'component_ids' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Component IDs"),
      description: new TranslatableMarkup("The ids of the components to retrieve details for, e.g. ['sdc.canvas_test_sdc.my-hero', 'block.system_menu_block.footer']."),
      required: TRUE,
      multiple: TRUE,
    ),
  ],
  module_dependencies: ['canvas_ai'],
)]
final class GetComponentDetails extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

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
    $component_ids = $this->getContextValue('component_ids');
    $this->setOutput($this->componentContextHelper->getComponentDetails($component_ids));
  }

}
