<?php

namespace Drupal\canvas_ai\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai_agents\PluginInterfaces\AiAgentContextInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\Yaml\Yaml;

/**
 * Plugin implementation to get page data.
 */
#[FunctionCall(
  id: 'ai_agent:get_page_data',
  function_name: 'ai_agent_get_page_data',
  name: 'Get page data',
  description: 'This method allows to get page data.',
  group: 'information_tools',
  module_dependencies: ['canvas'],
  context_definitions: [
    'entity_type' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Entity Type"),
      description: new TranslatableMarkup("The entity type for which you want to perform operations."),
      required: TRUE
    ),
    'page_title' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Page Title"),
      description: new TranslatableMarkup("The title of the page."),
      required: TRUE
    ),
    'page_description' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Page Description"),
      description: new TranslatableMarkup("The description of the page."),
      required: FALSE,
    ),
  ],
)]
class GetPageData extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface, ContainerFactoryPluginInterface {

  /**
   * The entity type.
   *
   * @var string
   */
  protected string $entityType;

  /**
   * The page title.
   *
   * @var string
   */
  protected string $pageTitle;

  /**
   * The page description.
   *
   * @var string|null
   */
  protected string|null $pageDescription;

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    $this->entityType = $this->getContextValue('entity_type');
    $this->pageTitle = $this->getContextValue('page_title');
    $this->pageDescription = $this->getContextValue('page_description') ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    if ($this->entityType !== 'canvas_page') {
      return Yaml::dump(['error' => 'This operation is only valid for Drupal Canvas pages. Do not process further. Return with provided message only.'], 10, 2);
    }
    return Yaml::dump([
      'page_title' => $this->pageTitle,
      'page_description' => $this->pageDescription,
    ], 10, 2);
  }

}
