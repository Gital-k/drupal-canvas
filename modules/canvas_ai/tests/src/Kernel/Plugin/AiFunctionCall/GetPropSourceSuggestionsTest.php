<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel\Plugin\AiFunctionCall;

use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\canvas_ai\Traits\CanvasAiContentTemplateAgentToolsTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests for the GetPropSourceSuggestions function call plugin.
 *
 * @group canvas_ai
 */
final class GetPropSourceSuggestionsTest extends KernelTestBase {

  use UserCreationTrait;
  use CanvasAiContentTemplateAgentToolsTestTrait;

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
   * Tests the GetPropSourceSuggestions function call.
   *
   * This test uses the canvas_test_sdc module's card component and
   * validates that suggestions are returned with the correct structure.
   */
  public function testGetPropSourceSuggestions(): void {
    $this->container->get('current_user')->setAccount($this->privilegedUser);

    $function_call = $this->functionCallManager->createInstance('canvas_ai:get_prop_source_suggestions');
    $this->assertInstanceOf(ExecutableFunctionCallInterface::class, $function_call);

    $function_call->setContextValue('entity_type', 'node');
    $function_call->setContextValue('bundle', 'article');
    $function_call->setContextValue('component_id', 'sdc.canvas_test_sdc.card');

    $function_call->execute();
    $result = $function_call->getReadableOutput();

    // Expected structured output.
    $expected_result = [
      'heading' => [
        'Title',
        'Authored by → User → Name',
        'Image → Alternative text',
        'Image → Title',
      ],
      'content' => [
        'Title',
        'Authored by → User → Name',
        'Image → Alternative text',
        'Image → Title',
      ],
      'footer' => [
        'Title',
        'Authored by → User → Name',
        'Image → Alternative text',
        'Image → Title',
      ],
      'sizes' => [
        'Title',
        'Authored by → User → Name',
        'Image → Alternative text',
        'Image → Title',
      ],
    ];

    $this->assertEquals($expected_result, Yaml::parse($result));
  }

  /**
   * Tests getting suggestions for a non-existing component.
   */
  public function testGetSuggestionsForNonExistingComponent(): void {
    $this->container->get('current_user')->setAccount($this->privilegedUser);

    $function_call = $this->functionCallManager->createInstance('canvas_ai:get_prop_source_suggestions');
    $this->assertInstanceOf(ExecutableFunctionCallInterface::class, $function_call);

    $function_call->setContextValue('entity_type', 'node');
    $function_call->setContextValue('bundle', 'article');
    $function_call->setContextValue('component_id', 'sdc.non_existing.component');

    $function_call->execute();
    $result = $function_call->getReadableOutput();

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

    $function_call->setContextValue('entity_type', 'node');
    $function_call->setContextValue('bundle', 'article');
    $function_call->setContextValue('component_id', 'sdc.canvas_test_sdc.heading');

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('The current user does not have the right permissions to run this tool.');
    $function_call->execute();
  }

}
