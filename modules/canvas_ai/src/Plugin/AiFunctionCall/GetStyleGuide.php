<?php

namespace Drupal\canvas_ai\Plugin\AiFunctionCall;

use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai_agents\PluginInterfaces\AiAgentContextInterface;

/**
 * Plugin implementation of the get style guide function.
 */
#[FunctionCall(
  id: 'ai_agent:get_style_guide',
  function_name: 'ai_agent_get_style_guide',
  name: 'Get style guide',
  description: 'This method gets the style guide for selected components.',
  group: 'information_tools',
  module_dependencies: ['experience_builder'],
  context_definitions: [
    'components_to_use' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Components to use"),
      description: new TranslatableMarkup("The components to use for style guide."),
      required: TRUE
    ),
  ],
)]
final class GetStyleGuide extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

  /**
   * The style guide information.
   *
   * @var string
   */
  protected string $styleGuide = '';
  /**
   * The style guide information.
   *
   * @var string
   */
  protected string $componentsToUse = '';

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    $available_components_response = \Drupal::service('canvas_ai.apigenerator')->list('js_component');
    $available_components = json_decode((string) $available_components_response->getContent());
    $this->componentsToUse = $this->getContextValue('components_to_use');
    $styleGuideGenerator = \Drupal::service('canvas_ai.styleguidegenerator');
    $componentsArray = array_map('trim', explode(',', $this->componentsToUse));
    $requiredComponents = [];
    foreach ($componentsArray as $component) {
      if (isset($available_components->$component)) {
        $requiredComponents[$component] = $available_components->$component;
      }
    }
    $requiredComponentsJson = json_encode($requiredComponents);
    $styleGuideGenerator->analyze($requiredComponentsJson);
    $this->styleGuide = $styleGuideGenerator->generateMarkdown();
    // @todo Add the exception handling when the AI is not able to process excessive context
    // for a large number of components.
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->styleGuide;
  }

}
