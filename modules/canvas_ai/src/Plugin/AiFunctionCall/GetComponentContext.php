<?php

namespace Drupal\canvas_ai\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\ai_agents\PluginInterfaces\AiAgentContextInterface;
use Drupal\canvas_ai\CanvasAiComponentContextHelper;
use Drupal\canvas_ai\CanvasAiPermissions;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Function call plugin to get component context.
 *
 * This plugin retrieves information about all available components in the site
 * using the CanvasAiComponentContextHelper service. The information can be used by AI
 * agents to understand available components and their capabilities.
 *
 * @internal
 */
#[FunctionCall(
  id: 'canvas_ai:get_component_context',
  function_name: 'get_component_context',
  name: 'Get Component Context',
  description: 'This method gets information about all available components in the site.',
  group: 'information_tools',
  module_dependencies: ['canvas_ai'],
)]
final class GetComponentContext extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

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
    $this->setOutput($this->componentContextHelper->getLessDetailedComponentContext());
  }

}
