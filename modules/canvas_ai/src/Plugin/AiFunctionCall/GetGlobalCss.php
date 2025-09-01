<?php

namespace Drupal\canvas_ai\Plugin\AiFunctionCall;

use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai_agents\PluginInterfaces\AiAgentContextInterface;

/**
 * Plugin implementation of the get props types function.
 */
#[FunctionCall(
  id: 'ai_agent:get_global_css',
  function_name: 'ai_agent_get_global_css',
  name: 'Get Global CSS',
  description: 'This method gets the global Css.',
  group: 'information_tools',
  module_dependencies: ['canvas'],
  context_definitions: [
    'global_css' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Global CSS"),
      description: new TranslatableMarkup("The Global css for site."),
      required: TRUE
    ),
  ],
)]
final class GetGlobalCss extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

  /**
   * The global css information.
   *
   * @var string
   */
  protected string $globalCss = '';

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    $this->globalCss = $this->getContextValue('global_css');
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->globalCss;
  }

}
