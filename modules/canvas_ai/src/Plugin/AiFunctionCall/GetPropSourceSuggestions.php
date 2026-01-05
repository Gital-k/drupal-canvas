<?php

namespace Drupal\canvas_ai\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\ai_agents\PluginInterfaces\AiAgentContextInterface;
use Drupal\canvas_ai\CanvasAiContentTemplateHelper;
use Drupal\canvas_ai\CanvasAiPermissions;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Function call plugin to get prop source suggestions for field linking.
 *
 * This plugin retrieves field linking suggestions for a specific component.
 * Returns which component props can be linked to which entity fields.
 *
 * @internal
 */
#[FunctionCall(
  id: 'canvas_ai:get_prop_source_suggestions',
  function_name: 'get_prop_source_suggestions',
  name: 'Get Prop Source Suggestions',
  description: 'Gets field linking suggestions for a specific component. Returns which component props can be linked to which entity fields along with the expression strings needed for programmatic linking.',
  group: 'information_tools',
  module_dependencies: ['canvas_ai'],
  context_definitions: [
    'entity_type' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Entity Type'),
      description: new TranslatableMarkup('The entity type ID (e.g., "node").'),
      required: TRUE
    ),
    'bundle' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Bundle'),
      description: new TranslatableMarkup('The entity bundle (e.g., "article").'),
      required: TRUE
    ),
    'component_id' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Component ID'),
      description: new TranslatableMarkup('The component config entity ID (e.g., "sdc.mercury.button").'),
      required: TRUE
    ),
  ],
)]
final class GetPropSourceSuggestions extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

  /**
   * The Canvas AI content template helper service.
   *
   * @var \Drupal\canvas_ai\CanvasAiContentTemplateHelper
   */
  protected CanvasAiContentTemplateHelper $contentTemplateHelper;

  /**
   * The current user service.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * Load from dependency injection container.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface|static {
    $instance = new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ai.context_definition_normalizer'),
    );
    $instance->contentTemplateHelper = $container->get('canvas_ai.content_template_helper');
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

    try {
      // Get context values.
      $entity_type = $this->getContextValue('entity_type');
      $bundle = $this->getContextValue('bundle');
      $component_id = $this->getContextValue('component_id');

      // Get field suggestions for the component.
      $suggestions = $this->contentTemplateHelper->getFieldSuggestions(
        $component_id,
        $entity_type,
        $bundle
      );

      // Convert to YAML and output.
      $this->setOutput(Yaml::dump($suggestions, 10, 2));
    }
    catch (\Exception $e) {
      $this->setOutput("Error getting prop source suggestions: " . $e->getMessage());
    }
  }

}
