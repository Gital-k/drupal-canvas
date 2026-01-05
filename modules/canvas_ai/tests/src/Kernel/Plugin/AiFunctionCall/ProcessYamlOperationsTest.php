<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel\Plugin\AiFunctionCall;

use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\canvas\Entity\Component;
use Drupal\canvas_ai\CanvasAiContentTemplateHelper;
use Drupal\canvas_ai\CanvasAiPermissions;
use Drupal\canvas_ai\CanvasAiTempStore;
use Drupal\canvas_ai\Plugin\AiFunctionCall\BuilderResponseFunctionCallInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\KernelTests\KernelTestBase;
use Symfony\Component\Yaml\Yaml;
use Drupal\Tests\canvas_ai\Traits\FunctionalCallTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;

/**
 * Tests for the ProcessYamlOperations function call plugin.
 *
 * @group canvas_ai
 */
final class ProcessYamlOperationsTest extends KernelTestBase {

  use UserCreationTrait;
  use FunctionalCallTestTrait;

  /**
   * The function call plugin manager.
   *
   * @var \Drupal\Component\Plugin\PluginManagerInterface
   */
  protected $functionCallManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * A test user with AI permissions.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected AccountInterface $privilegedUser;

  /**
   * A test user without AI permissions.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected AccountInterface $unprivilegedUser;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'user',
    'ai',
    'ai_agents',
    'system',
    'canvas',
    'canvas_ai',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Install the necessary entity schemas.
    $this->installEntitySchema('user');

    // Set up the dependencies.
    $this->functionCallManager = $this->container->get('plugin.manager.ai.function_calls');
    $this->entityTypeManager = $this->container->get('entity_type.manager');

    // Create test users.
    $privileged_user = $this->createUser([CanvasAiPermissions::USE_CANVAS_AI]);
    $unprivileged_user = $this->createUser();
    if (!$privileged_user instanceof User || !$unprivileged_user instanceof User) {
      throw new \Exception('Failed to create test users');
    }
    $this->privilegedUser = $privileged_user;
    $this->unprivilegedUser = $unprivileged_user;

    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_test_sdc']);
    $this->container->get('theme_installer')->install(['stark']);
    $this->container->get('config.factory')
      ->getEditable('system.theme')
      ->set('default', 'stark')
      ->save();

    $this->mockContentTemplateHelper();
  }

  /**
   * Mocks the content template helper service.
   *
   * @return \Drupal\canvas_ai\CanvasAiContentTemplateHelper|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked content template helper.
   */
  protected function mockContentTemplateHelper() {
    $mock_helper = $this->createMock(CanvasAiContentTemplateHelper::class);

    // Common expressions used in tests.
    $expressions = [
      'Title' => 'ℹ︎␜entity:node:article␝title␞␟value',
      'Body → Processed text' => 'ℹ︎␜entity:node:article␝body␞␟processed',
      'Image' => 'ℹ︎␜entity:node:article␝field_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
      'Tags → Taxonomy term → Name' => 'ℹ︎␜entity:node:article␝field_tags␞␟entity␜␜entity:taxonomy_term␝name␞␟value',
    ];

    // Mock getFieldExpressionFromLabel.
    $mock_helper->method('getFieldExpressionFromLabel')
      ->willReturnCallback(function ($entity_type, $bundle, $label) use ($expressions) {
        if (isset($expressions[$label])) {
          return $expressions[$label];
        }
        throw new \Exception("The label '$label' is not a valid linkable field for $entity_type:$bundle.");
      });

    // Mock getFieldSuggestions.
    $mock_helper->method('getFieldSuggestions')
      ->willReturnCallback(function ($sdc_id, $entity_type, $bundle) use ($expressions) {
        if ($sdc_id === 'sdc.canvas_test_sdc.heading') {
          return [
            'text' => [
              ['label' => 'Title', 'expression' => $expressions['Title']],
              ['label' => 'Tags → Taxonomy term → Name', 'expression' => $expressions['Tags → Taxonomy term → Name']],
            ],
          ];
        }
        if ($sdc_id === 'sdc.canvas_test_sdc.card') {
          return [
            'heading' => [
              ['label' => 'Title', 'expression' => $expressions['Title']],
            ],
            'content' => [
              ['label' => 'Body → Processed text', 'expression' => $expressions['Body → Processed text']],
            ],
            'image' => [
              ['label' => 'Image', 'expression' => $expressions['Image']],
            ],
          ];
        }
        return [];
      });

    $this->container->set('canvas_ai.content_template_helper', $mock_helper);

    return $mock_helper;
  }

  /**
   * Tests the ProcessYamlOperations function call with valid YAML.
   *
   * This test uses mocked services and validates that the operations are
   * processed correctly and field labels are converted to expressions.
   */
  public function testProcessYamlOperations(): void {
    $this->container->get('current_user')->setAccount($this->privilegedUser);

    // Set the current layout to a valid layout.
    $this->container->get('canvas_ai.tempstore')->setData(
      CanvasAiTempStore::CURRENT_LAYOUT_KEY,
      $this->getCurrentLayout()
    );

    // If a component has an image prop and that prop is required, the component data passed to the tool must include the
    // exact default values for the image prop, which can be obtained from ContentTemplate::normalizeForClientSide(). Otherwise,
    // the validation will fail.
    $component = Component::load('sdc.canvas_test_sdc.card');
    assert($component instanceof Component);
    $clientNormalized = $component->normalizeForClientSide()->values;
    // Get the default values for the image prop of the card component.
    $default_values = $clientNormalized["propSources"]["image"]["default_values"]["resolved"];
    $operations_data = [
      'operations' => [
        'ADD' => [
          [
            'target' => 'content',
            'placement' => 'inside',
            'components' => [
              [
                'sdc.canvas_test_sdc.heading' => [
                  'props' => [
                    'text' => 'Welcome to Our Site',
                    'element' => 'h1',
                    'style' => 'primary',
                  ],
                  'maps' => [
                    'text' => 'Title',
                  ],
                ],
              ],
              [
                'sdc.canvas_test_sdc.card' => [
                  'props' => [
                    'heading' => 'Featured Article',
                    'content' => 'This is a sample content text.',
                    'loading' => 'eager',
                    'image' => $default_values,
                  ],
                  'maps' => [
                    'heading' => 'Title',
                    'content' => 'Body → Processed text',
                    'image' => 'Image',
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
    ];

    $yaml_input = Yaml::dump($operations_data, 10, 2);

    // Get the function call plugin.
    $function_call = $this->functionCallManager->createInstance('canvas_ai:process_yaml_operations');
    $this->assertInstanceOf(BuilderResponseFunctionCallInterface::class, $function_call);

    // Set the function call context.
    $function_call->setContextValue('yaml_input', $yaml_input);
    $function_call->setContextValue('entity_type', 'node');
    $function_call->setContextValue('bundle', 'article');

    // Execute the function call.
    $function_call->execute();

    // Get the structured output.
    $result = $function_call->getStructuredOutput();

    // Expected structured output.
    $expected_result = [
      'operations' => [
        [
          'operation' => 'ADD',
          'components' => [
            [
              'id' => 'sdc.canvas_test_sdc.heading',
              'nodePath' => [0, 0],
              'fieldValues' => [
                'text' => 'Welcome to Our Site',
                'element' => 'h1',
                'style' => 'primary',
              ],
              'map' => [
                'text' => 'ℹ︎␜entity:node:article␝title␞␟value',
              ],
            ],
            [
              'id' => 'sdc.canvas_test_sdc.card',
              'nodePath' => [0, 1],
              'fieldValues' => [
                'heading' => 'Featured Article',
                'content' => 'This is a sample content text.',
                'loading' => 'eager',
                'image' => $default_values,
              ],
              'map' => [
                'heading' => 'ℹ︎␜entity:node:article␝title␞␟value',
                'content' => 'ℹ︎␜entity:node:article␝body␞␟processed',
                'image' => 'ℹ︎␜entity:node:article␝field_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
              ],
            ],
          ],
        ],
      ],
    ];

    $this->assertEquals($expected_result, $result);
  }

  /**
   * Tests ProcessYamlOperations with missing operations key.
   */
  public function testProcessYamlOperationsWithMissingOperations(): void {
    $this->container->get('current_user')->setAccount($this->privilegedUser);

    // Set the current layout.
    $this->container->get('canvas_ai.tempstore')->setData(
      CanvasAiTempStore::CURRENT_LAYOUT_KEY,
      $this->getCurrentLayout()
    );

    // Create YAML without operations key.
    $yaml_input = 'invalid: data';

    // Use the trait helper to get output.
    $result = $this->getToolOutput('canvas_ai:process_yaml_operations', [
      'yaml_input' => $yaml_input,
      'entity_type' => 'node',
      'bundle' => 'article',
    ]);

    // Assert that the result contains an error message.
    $this->assertSame('Failed to process YAML operations: The operations key is missing in the YAML input.', $result);
  }

  /**
   * Tests ProcessYamlOperations with missing ADD operations.
   */
  public function testProcessYamlOperationsWithMissingAddOperations(): void {
    $this->container->get('current_user')->setAccount($this->privilegedUser);

    // Set the current layout.
    $this->container->get('canvas_ai.tempstore')->setData(
      CanvasAiTempStore::CURRENT_LAYOUT_KEY,
      $this->getCurrentLayout()
    );

    // Create YAML without ADD operations.
    $yaml_input = "operations:\n  UPDATE: []";

    // Use the trait helper to get output.
    $result = $this->getToolOutput('canvas_ai:process_yaml_operations', [
      'yaml_input' => $yaml_input,
      'entity_type' => 'node',
      'bundle' => 'article',
    ]);

    // Assert that the result contains an error message.
    $this->assertSame('Failed to process YAML operations: The ADD operations key is missing in the YAML input.', $result);
  }

  /**
   * Tests ProcessYamlOperations with invalid field mapping.
   */
  public function testProcessYamlOperationsWithInvalidMapping(): void {
    $this->container->get('current_user')->setAccount($this->privilegedUser);

    // Set the current layout.
    $this->container->get('canvas_ai.tempstore')->setData(
      CanvasAiTempStore::CURRENT_LAYOUT_KEY,
      $this->getCurrentLayout()
    );

    // Create YAML with invalid field mapping.
    $yaml_input = <<<YAML
operations:
  ADD:
    - target: content
      placement: inside
      components:
        - sdc.canvas_test_sdc.heading:
            props:
              text: "Test"
              element: "h1"
            maps:
              text: NonExistentField
YAML;

    // Use the trait helper to get output.
    $result = $this->getToolOutput('canvas_ai:process_yaml_operations', [
      'yaml_input' => $yaml_input,
      'entity_type' => 'node',
      'bundle' => 'article',
    ]);

    // Assert that the result contains an error message about invalid mapping.
    $this->assertStringContainsString("Label 'NonExistentField' is not a valid field mapping for prop 'text' in component 'sdc.canvas_test_sdc.heading'. Available labels: Title, Tags → Taxonomy term → Name", $result);
  }

  /**
   * Tests the ProcessYamlOperations function call without permissions.
   */
  public function testProcessYamlOperationsWithoutPermissions(): void {
    $this->container->get('current_user')->setAccount($this->unprivilegedUser);

    $function_call = $this->functionCallManager->createInstance('canvas_ai:process_yaml_operations');
    $this->assertInstanceOf(ExecutableFunctionCallInterface::class, $function_call);

    // Set the function call context.
    $function_call->setContextValue('yaml_input', 'operations: {}');
    $function_call->setContextValue('entity_type', 'node');
    $function_call->setContextValue('bundle', 'article');

    // Expect an exception to be thrown.
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('The current user does not have the right permissions to run this tool.');
    $function_call->execute();
  }

  /**
   * Returns a predefined layout for testing.
   *
   * @return string
   *   The JSON-encoded layout.
   */
  private function getCurrentLayout(): string {
    return json_encode([
      'regions' => [
        'content' => [
          'nodePathPrefix' => [0],
          'components' => [],
        ],
      ],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
  }

}
