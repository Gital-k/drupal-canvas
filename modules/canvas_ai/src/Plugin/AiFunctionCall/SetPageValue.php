<?php

namespace Drupal\canvas_ai\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai_agents\PluginInterfaces\AiAgentContextInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Function call plugin to set a value on the current canvas_page.
 *
 * This is the page-field tool used directly by the orchestrator in the flat
 * agent architecture (no title or metadata sub-agents). It consolidates setting
 * the page title and meta description behind a single tool keyed by the field
 * being set.
 *
 * The structured output uses the shapes the Canvas UI already understands:
 * - title       => ['created_content' => value]      (maps to title[0][value])
 * - description => ['metadata' => ['metatag_description' => value]]
 *                                                     (maps to description[0][value])
 *
 * @see \Drupal\canvas_ai\Plugin\AiFunctionCall\CreateFieldContent
 * @see \Drupal\canvas_ai\Plugin\AiFunctionCall\AddMetadata
 */
#[FunctionCall(
  id: 'canvas_ai:set_page_value',
  function_name: 'set_page_value',
  name: 'Set Page Value',
  description: 'Sets a value on the current page. The "key" must be either "title" or "description"; "value" is the text to set. Use this to write the page title or the SEO meta description.',
  group: 'modification_tools',
  module_dependencies: ['canvas'],
  context_definitions: [
    'key' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Page value key"),
      description: new TranslatableMarkup("The page value to set. Allowed values: 'title', 'description'."),
      required: TRUE,
    ),
    'value' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Value"),
      description: new TranslatableMarkup("The text value to set for the given key."),
      required: TRUE,
    ),
  ],
)]
final class SetPageValue extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface, BuilderResponseFunctionCallInterface {

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    $key = $this->getContextValue('key');
    $value = $this->getContextValue('value');

    // Matching the existing shape the UI expects.
    switch ($key) {
      case 'title':
        $this->setStructuredOutput([
          'created_content' => $value,
        ]);
        $this->setOutput('Page title set successfully.');
        break;

      case 'description':
        $this->setStructuredOutput([
          'metadata' => ['metatag_description' => $value],
        ]);
        $this->setOutput('Page description set successfully.');
        break;

      default:
        $this->setOutput(\sprintf('Unknown page value key "%s". Allowed keys are: title, description.', $key));
    }
  }

}
