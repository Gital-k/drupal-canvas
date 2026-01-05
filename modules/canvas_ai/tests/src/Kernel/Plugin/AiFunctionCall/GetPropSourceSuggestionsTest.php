<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel\Plugin\AiFunctionCall;

use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\canvas_ai\CanvasAiPermissions;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests for the GetPropSourceSuggestions function call plugin.
 *
 * @group canvas_ai
 */
final class GetPropSourceSuggestionsTest extends KernelTestBase {

  use UserCreationTrait;

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
    'node',
    'user',
    'ai',
    'ai_agents',
    'system',
    'field',
    'field_ui',
    'text',
    'image',
    'file',
    'media',
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
    $this->installEntitySchema('node');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installSchema('file', ['file_usage']);

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

    // Create article content type with fields.
    $node_type_storage = $this->entityTypeManager->getStorage('node_type');
    $node_type_storage->create([
      'type' => 'article',
      'name' => 'Article',
      'description' => 'An article content type for testing.',
    ])->save();

    // Add body field to the article content type.
    $field_storage = $this->entityTypeManager->getStorage('field_storage_config');
    $field_storage->create([
      'field_name' => 'body',
      'entity_type' => 'node',
      'type' => 'text_long',
      'cardinality' => 1,
    ])->save();
    $field_instance = $this->entityTypeManager->getStorage('field_config');
    $field_instance->create([
      'field_name' => 'body',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Body',
      'required' => FALSE,
      'settings' => [
        'max_length' => 5000,
        'text_processing' => 1,
      ],
    ])->save();

    // Add image field to the article content type.
    $field_storage->create([
      'field_name' => 'field_image',
      'entity_type' => 'node',
      'type' => 'image',
      'cardinality' => 1,
    ])->save();
    $field_instance->create([
      'field_name' => 'field_image',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Image',
      'required' => FALSE,
    ])->save();

    // Install canvas_test_sdc module and stark theme.
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_test_sdc']);
    $this->container->get('theme_installer')->install(['stark']);
    $this->container->get('config.factory')
      ->getEditable('system.theme')
      ->set('default', 'stark')
      ->save();
  }

  /**
   * Tests the GetPropSourceSuggestions function call.
   *
   * This test uses the canvas_test_sdc module's card component and
   * validates that suggestions are returned with the correct structure.
   */
  public function testGetPropSourceSuggestions(): void {
    $this->container->get('current_user')->setAccount($this->privilegedUser);

    // Get the function call plugin.
    $function_call = $this->functionCallManager->createInstance('canvas_ai:get_prop_source_suggestions');
    $this->assertInstanceOf(ExecutableFunctionCallInterface::class, $function_call);

    // Set the function call context with the card component from
    // canvas_test_sdc.
    $function_call->setContextValue('entity_type', 'node');
    $function_call->setContextValue('bundle', 'article');
    $function_call->setContextValue('component_id', 'sdc.canvas_test_sdc.card');

    // Execute the function call.
    $function_call->execute();
    $result = $function_call->getReadableOutput();

    // Expected structured output.
    $expected_result = [
      'heading' => [
        [
          'label' => 'Title',
          'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
        ],
        [
          'label' => 'Authored by → User → Name',
          'expression' => 'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝name␞␟value',
        ],
        [
          'label' => 'Image → Alternative text',
          'expression' => 'ℹ︎␜entity:node:article␝field_image␞␟alt',
        ],
        [
          'label' => 'Image → Title',
          'expression' => 'ℹ︎␜entity:node:article␝field_image␞␟title',
        ],
        [
          'label' => 'Revision user → User → Name',
          'expression' => 'ℹ︎␜entity:node:article␝revision_uid␞␟entity␜␜entity:user␝name␞␟value',
        ],
      ],
      'content' => [
        [
          'label' => 'Title',
          'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
        ],
        [
          'label' => 'Authored by → User → Name',
          'expression' => 'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝name␞␟value',
        ],
        [
          'label' => 'Image → Alternative text',
          'expression' => 'ℹ︎␜entity:node:article␝field_image␞␟alt',
        ],
        [
          'label' => 'Image → Title',
          'expression' => 'ℹ︎␜entity:node:article␝field_image␞␟title',
        ],
        [
          'label' => 'Revision user → User → Name',
          'expression' => 'ℹ︎␜entity:node:article␝revision_uid␞␟entity␜␜entity:user␝name␞␟value',
        ],
      ],
      'footer' => [
        [
          'label' => 'Title',
          'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
        ],
        [
          'label' => 'Authored by → User → Name',
          'expression' => 'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝name␞␟value',
        ],
        [
          'label' => 'Image → Alternative text',
          'expression' => 'ℹ︎␜entity:node:article␝field_image␞␟alt',
        ],
        [
          'label' => 'Image → Title',
          'expression' => 'ℹ︎␜entity:node:article␝field_image␞␟title',
        ],
        [
          'label' => 'Revision user → User → Name',
          'expression' => 'ℹ︎␜entity:node:article␝revision_uid␞␟entity␜␜entity:user␝name␞␟value',
        ],
      ],
      'sizes' => [
        [
          'label' => 'Title',
          'expression' => 'ℹ︎␜entity:node:article␝title␞␟value',
        ],
        [
          'label' => 'Authored by → User → Name',
          'expression' => 'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝name␞␟value',
        ],
        [
          'label' => 'Image → Alternative text',
          'expression' => 'ℹ︎␜entity:node:article␝field_image␞␟alt',
        ],
        [
          'label' => 'Image → Title',
          'expression' => 'ℹ︎␜entity:node:article␝field_image␞␟title',
        ],
        [
          'label' => 'Revision user → User → Name',
          'expression' => 'ℹ︎␜entity:node:article␝revision_uid␞␟entity␜␜entity:user␝name␞␟value',
        ],
      ],
    ];

    $this->assertEquals($expected_result, Yaml::parse($result));
  }

  /**
   * Tests getting suggestions for a non-existing component.
   */
  public function testGetSuggestionsForNonExistingComponent(): void {
    $this->container->get('current_user')->setAccount($this->privilegedUser);

    // Get the function call plugin.
    $function_call = $this->functionCallManager->createInstance('canvas_ai:get_prop_source_suggestions');
    $this->assertInstanceOf(ExecutableFunctionCallInterface::class, $function_call);

    // Set context for a non-existing component.
    $function_call->setContextValue('entity_type', 'node');
    $function_call->setContextValue('bundle', 'article');
    $function_call->setContextValue('component_id', 'sdc.non_existing.component');

    // Execute the function call.
    $function_call->execute();
    $result = $function_call->getReadableOutput();

    // Assert that the result contains an error message.
    $this->assertIsString($result);
    $this->assertStringContainsString('Error getting prop source suggestions', $result);
  }

  /**
   * Tests the GetPropSourceSuggestions function call without permissions.
   */
  public function testGetPropSourceSuggestionsWithoutPermissions(): void {
    $this->container->get('current_user')->setAccount($this->unprivilegedUser);

    $function_call = $this->functionCallManager->createInstance('canvas_ai:get_prop_source_suggestions');
    $this->assertInstanceOf(ExecutableFunctionCallInterface::class, $function_call);

    // Set the function call context.
    $function_call->setContextValue('entity_type', 'node');
    $function_call->setContextValue('bundle', 'article');
    $function_call->setContextValue('component_id', 'sdc.canvas_test_sdc.heading');

    // Expect an exception to be thrown.
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('The current user does not have the right permissions to run this tool.');
    $function_call->execute();
  }

}
