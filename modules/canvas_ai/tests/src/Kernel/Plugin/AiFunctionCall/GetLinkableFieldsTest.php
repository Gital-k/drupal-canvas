<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel\Plugin\AiFunctionCall;

use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\canvas_ai\Traits\CanvasAiContentTemplateAgentToolsTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests for the GetLinkableFields function call plugin.
 *
 * @group canvas_ai
 */
final class GetLinkableFieldsTest extends KernelTestBase {

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
   * Tests the GetLinkableFields function call.
   */
  public function testGetLinkableFields(): void {
    $this->container->get('current_user')->setAccount($this->privilegedUser);

    $function_call = $this->functionCallManager->createInstance('canvas_ai:get_linkable_fields');
    $this->assertInstanceOf(ExecutableFunctionCallInterface::class, $function_call);

    $function_call->setContextValue('entity_type', 'node');
    $function_call->setContextValue('bundle', 'article');

    $function_call->execute();
    $result = $function_call->getReadableOutput();

    $expected_result = [
      'Authored by → User → Name' => 'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝name␞␟value',
      'Authored by → User → User status' => 'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝status␞␟value',
      'Body → Processed text' => 'ℹ︎␜entity:node:article␝body␞␟processed',
      'Image' => 'ℹ︎␜entity:node:article␝field_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
      'Image → Alternative text' => 'ℹ︎␜entity:node:article␝field_image␞␟alt',
      'Image → Status' => 'ℹ︎␜entity:node:article␝field_image␞␟entity␜␜entity:file␝status␞␟value',
      'Image → Title' => 'ℹ︎␜entity:node:article␝field_image␞␟title',
      'Published' => 'ℹ︎␜entity:node:article␝status␞␟value',
      'Title' => 'ℹ︎␜entity:node:article␝title␞␟value',
    ];

    $this->assertEquals($expected_result, Yaml::parse($result));
  }

  /**
   * Tests the GetLinkableFields function call without permissions.
   */
  public function testGetLinkableFieldsWithoutPermissions(): void {
    $this->container->get('current_user')->setAccount($this->unprivilegedUser);

    $function_call = $this->functionCallManager->createInstance('canvas_ai:get_linkable_fields');
    $this->assertInstanceOf(ExecutableFunctionCallInterface::class, $function_call);

    $function_call->setContextValue('entity_type', 'node');
    $function_call->setContextValue('bundle', 'article');

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('The current user does not have the right permissions to run this tool.');
    $function_call->execute();
  }

}
