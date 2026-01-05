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
 * Function call plugin to get all linkable field labels.
 *
 * This plugin retrieves all unique field labels that can be linked to
 * component props for a given entity type and bundle, sorted alphabetically.
 *
 * @internal
 */
#[FunctionCall(
  id: 'canvas_ai:get_linkable_fields',
  function_name: 'get_linkable_fields',
  name: 'Get Linkable Fields',
  description: 'Gets all unique field labels that can be linked to component props for the specified entity type and bundle. Returns alphabetically sorted list of field labels.',
  group: 'information_tools',
  module_dependencies: ['canvas_ai'],
  context_definitions: [
    'entity_type' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Entity Type"),
      description: new TranslatableMarkup("The entity type for which to retrieve linkable fields."),
      required: TRUE
    ),
    'bundle' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Bundle"),
      description: new TranslatableMarkup("The bundle for which to retrieve linkable fields."),
      required: TRUE
    ),
  ],
)]
final class GetLinkableFields extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

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
      // Get entity type and bundle from context.
      $entity_type = $this->getContextValue('entity_type');
      $bundle = $this->getContextValue('bundle');

      // Get linkable fields for the specified entity type and bundle.
      $linkable_fields = $this->contentTemplateHelper->getLinkableFields($entity_type, $bundle);

      // Convert to YAML and output.
      $this->setOutput(Yaml::dump($linkable_fields, 10, 2));
    }
    catch (\Exception $e) {
      $this->setOutput("Error getting linkable fields: " . $e->getMessage());
    }
  }

}
