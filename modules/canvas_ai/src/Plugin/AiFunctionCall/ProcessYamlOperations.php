<?php

namespace Drupal\canvas_ai\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\ai_agents\PluginInterfaces\AiAgentContextInterface;
use Drupal\canvas_ai\AiResponseValidator;
use Drupal\canvas_ai\CanvasAiContentTemplateHelper;
use Drupal\canvas_ai\CanvasAiPageBuilderHelper;
use Drupal\canvas_ai\CanvasAiPermissions;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Plugin implementation of the canvas_ai:process_yaml_operations.
 */
#[FunctionCall(
  id: 'canvas_ai:process_yaml_operations',
  function_name: 'process_yaml_operations',
  name: 'Process YAML Operations',
  description: 'Processes YAML operations for content templates. Validates component structure and field mappings, then converts to Canvas UI format.',
  group: 'modification_tools',
  module_dependencies: ['canvas', 'canvas_ai'],
  context_definitions: [
    'yaml_input' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('YAML Input'),
      description: new TranslatableMarkup('The YAML string containing operations with component structure and field mappings.'),
      required: TRUE
    ),
    'entity_type' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Entity Type'),
      description: new TranslatableMarkup('The entity type ID (e.g., "node") for field mapping validation.'),
      required: TRUE
    ),
    'bundle' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Bundle'),
      description: new TranslatableMarkup('The bundle (e.g., "article") for field mapping validation.'),
      required: TRUE
    ),
  ],
)]
final class ProcessYamlOperations extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface, BuilderResponseFunctionCallInterface {

  /**
   * The Canvas page builder helper service.
   *
   * @var \Drupal\canvas_ai\CanvasAiPageBuilderHelper
   */
  protected CanvasAiPageBuilderHelper $pageBuilderHelper;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The response validator service.
   *
   * @var \Drupal\canvas_ai\AiResponseValidator
   */
  protected AiResponseValidator $responseValidator;

  /**
   * The Canvas AI content template helper service.
   *
   * @var \Drupal\canvas_ai\CanvasAiContentTemplateHelper
   */
  protected CanvasAiContentTemplateHelper $contentTemplateHelper;

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
    $instance->loggerFactory = $container->get('logger.factory');
    $instance->currentUser = $container->get('current_user');
    $instance->responseValidator = $container->get('canvas_ai.response_validator');
    $instance->contentTemplateHelper = $container->get('canvas_ai.content_template_helper');
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
      $yaml_input = $this->getContextValue('yaml_input');
      $entity_type = $this->getContextValue('entity_type');
      $bundle = $this->getContextValue('bundle');

      $input_array = Yaml::parse($yaml_input);

      // Validate that operations key exists.
      if (empty($input_array['operations'])) {
        throw new \Exception('The operations key is missing in the YAML input.');
      }

      // Validate that ADD operations exist.
      if (empty($input_array['operations']['ADD'])) {
        throw new \Exception('The ADD operations key is missing in the YAML input.');
      }

      $add_operations = $input_array['operations']['ADD'];

      // Validate operations.
      $allErrors = [];
      foreach ($add_operations as $index => $operation) {
        // Validate placement params.
        $allErrors = array_merge($allErrors, $this->responseValidator->validatePlacementParams($operation, $index));

        // Validate component structure.
        $this->responseValidator->validateComponentStructure($operation['components']);
      }

      if (!empty($allErrors)) {
        throw new \Exception(Yaml::dump($allErrors));
      }

      // Transform to expected format for customYamlToArrayMapper.
      $transformed_structure = [
        'operations' => $add_operations,
      ];

      // Convert the YAML to the format expected by Canvas UI.
      $yaml_string = Yaml::dump($transformed_structure, 10, 2);
      $processed_output = $this->pageBuilderHelper->customYamlToArrayMapper($yaml_string);

      \assert(array_keys($processed_output) === ['operations']);

      // Convert field labels to expressions in the output.
      $this->convertFieldLabelsToExpressions(
        $processed_output['operations'][0]['components'],
        $entity_type,
        $bundle
      );

      $this->setStructuredOutput($processed_output);
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('canvas_ai')->error($e->getMessage());
      $this->setOutput(sprintf('Failed to process YAML operations: %s', $e->getMessage()));
    }
  }

  /**
   * Converts field labels to expressions.
   *
   * This method processes the output from customYamlToArrayMapper and converts
   * all field labels in component maps to their corresponding expressions.
   * It validates each mapping first, collects all errors, and only converts
   * if all validations pass.
   *
   * @param array &$components
   *   Reference to array of processed components from customYamlToArrayMapper.
   * @param string $entity_type
   *   The entity type ID.
   * @param string $bundle
   *   The bundle.
   *
   * @throws \Exception
   *   If any mapping validation fails. Contains all validation errors.
   */
  private function convertFieldLabelsToExpressions(array &$components, string $entity_type, string $bundle): void {
    $validation_errors = [];

    // First pass: validate all mappings and collect errors.
    $this->validateMappings($components, $entity_type, $bundle, $validation_errors);

    // If there are validation errors, throw them all at once.
    if (!empty($validation_errors)) {
      throw new \Exception(Yaml::dump($validation_errors));
    }

    // Second pass: convert labels to expressions.
    $this->convertLabelsToExpressions($components, $entity_type, $bundle);
  }

  /**
   * Validates all mappings recursively and collects errors.
   *
   * @param array $components
   *   Array of components.
   * @param string $entity_type
   *   The entity type ID.
   * @param string $bundle
   *   The bundle.
   * @param array &$errors
   *   Reference to errors array.
   */
  private function validateMappings(array $components, string $entity_type, string $bundle, array &$errors): void {
    foreach ($components as $component_data) {
      if (!empty($component_data['map']) && is_array($component_data['map'])) {
        $sdc_id = $component_data['id'];

        foreach ($component_data['map'] as $prop_name => $label) {
          try {
            $this->responseValidator->validateMapping(
              $sdc_id,
              $prop_name,
              $label,
              $entity_type,
              $bundle
            );
          }
          catch (\Exception $e) {
            $errors[] = [
              'component' => $sdc_id,
              'prop' => $prop_name,
              'label' => $label,
              'error' => $e->getMessage(),
            ];
          }
        }
      }
    }
  }

  /**
   * Converts labels to expressions.
   *
   * @param array &$components
   *   Reference to array of components.
   * @param string $entity_type
   *   The entity type ID.
   * @param string $bundle
   *   The bundle.
   */
  private function convertLabelsToExpressions(array &$components, string $entity_type, string $bundle): void {
    foreach ($components as &$component_data) {
      if (!empty($component_data['map']) && is_array($component_data['map'])) {
        $converted_map = [];

        foreach ($component_data['map'] as $prop_name => $label) {
          // Get the expression for this label.
          $expression = $this->contentTemplateHelper->getFieldExpressionFromLabel(
            $entity_type,
            $bundle,
            $label
          );

          $converted_map[$prop_name] = $expression;
        }

        // Replace labels with expressions.
        $component_data['map'] = $converted_map;
      }
    }
  }

}
