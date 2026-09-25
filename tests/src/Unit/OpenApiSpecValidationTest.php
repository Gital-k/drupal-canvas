<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Unit;

use cebe\openapi\Reader;
use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\canvas\Traits\OpenApiSpecTrait;
use Drupal\Tests\UnitTestCase;
use DrupalFinder\DrupalFinderComposerRuntime;
use GuzzleHttp\Psr7\ServerRequest;
use JsonSchema\Validator;
use League\OpenAPIValidation\PSR7\Exception\ValidationFailed;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use League\OpenAPIValidation\Schema\SchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresMethod;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Validates this Drupal module's OpenAPI spec against the OpenAPI JSON schema.
 */
#[RequiresMethod(Reader::class, 'readFromYamlFile')]
#[RequiresMethod(DrupalFinderComposerRuntime::class, 'getVendorDir')]
#[RequiresMethod(SchemaValidator::class, 'validate')]
#[Group('canvas')]
final class OpenApiSpecValidationTest extends UnitTestCase {

  use OpenApiSpecTrait;

  /**
   * Path to OpenAPI 3.0 document.
   */
  private ?string $documentLocation = NULL;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $tested_paths = [];

    $finder = new DrupalFinderComposerRuntime();
    $vendor_directory = $finder->getVendorDir();

    if ($vendor_directory) {
      $document_location = __DIR__ . '/../../../openapi-v3.1.json';
      if (file_exists($document_location)) {
        $this->documentLocation = $document_location;
      }
      else {
        $tested_paths[] = $document_location;
      }
    }

    if (!$this->documentLocation) {
      throw new \Exception(\sprintf('Could not find OpenAPI schema at %s.', implode(' or ', $tested_paths)));
    }
  }

  /**
   * Tests OpenAPI specification is valid.
   */
  public function testSpecIsValid(): void {
    $specification = $this->getSpecification();
    $specification->validate();
    $this->assertSame([], $specification->getErrors());
    $validator = new Validator();
    $open_api_data = $specification->getSerializableData();
    $validator->validate($open_api_data, (object) ['$ref' => 'file://' . $this->documentLocation]);
    $this->assertTrue($validator->isValid(), implode(\array_map(function (array $error) {
      return \sprintf('%s:%s%s', $error['property'], $error['message'], \PHP_EOL);
    }, $validator->getErrors())));
  }

  /**
   * Tests that the requests Canvas front ends send comply with the spec.
   *
   * Not every operation receives a JSON payload: the `form/*` operations
   * receive form-encoded Drupal AJAX form submissions, of which only the
   * Drupal Form API and AJAX bookkeeping is known ahead of time. Several
   * operations receive no request body at all. These cases pin both down.
   *
   * @param \Psr\Http\Message\ServerRequestInterface $request
   *   The request to validate.
   * @param string|null $expected_violation
   *   Part of the expected validation failure message, or NULL if the request
   *   is expected to comply with the specification.
   *
   * @see \Drupal\canvas\EventSubscriber\ApiRequestValidator
   */
  #[RequiresMethod(ValidatorBuilder::class, 'getRequestValidator')]
  #[DataProvider('providerRequestsComplyWithSpecification')]
  public function testRequestsComplyWithSpecification(ServerRequestInterface $request, ?string $expected_violation): void {
    $validator = (new ValidatorBuilder())
      ->fromYamlFile($this->getSpecificationPath())
      ->getRequestValidator();

    if ($expected_violation === NULL) {
      $validator->validate($request);
      $this->addToAssertionCount(1);
      return;
    }

    try {
      $validator->validate($request);
      $this->fail('Expected this request not to comply with the specification.');
    }
    catch (ValidationFailed $e) {
      // The specific mismatch is reported by the wrapped exception, if any.
      $previous = $e->getPrevious();
      $this->assertStringContainsString(
        $expected_violation,
        $previous instanceof \Throwable ? $previous->getMessage() : $e->getMessage(),
      );
    }
  }

  /**
   * @return \Generator
   *   Test cases for ::testRequestsComplyWithSpecification().
   */
  public static function providerRequestsComplyWithSpecification(): \Generator {
    // What the Canvas UI sends to generate a component instance form.
    // @see ui/src/services/componentInstanceForm.ts
    $component_instance = [
      'form_canvas_tree' => \json_encode([
        'nodeType' => 'component',
        'slots' => [],
        'type' => 'sdc.canvas_test_sdc.image@1af4f0e04b845e4b',
        'uuid' => '5f18db31-fa2f-4f4e-a377-dc0c6a0b7dc4',
      ], \JSON_THROW_ON_ERROR),
      'form_canvas_props' => \json_encode([
        'resolved' => ['image' => NULL],
        'source' => [],
      ], \JSON_THROW_ON_ERROR),
      'form_canvas_selected' => '5f18db31-fa2f-4f4e-a377-dc0c6a0b7dc4',
    ];
    $form_encoded = ['Content-Type' => 'application/x-www-form-urlencoded'];

    yield 'component instance form for a content entity' => [
      new ServerRequest('PATCH', '/canvas/api/v0/form/component-instance/node/1', $form_encoded, \http_build_query($component_instance + [
        'latestUndoRedoActionId' => 'e6f4a3b0-0000-4000-8000-000000000000',
        'ajaxPageState' => \json_encode([
          'theme' => 'canvas_stark',
          'theme_token' => '1a2b3c4d5e6f7a8b9c0d1e2f',
          'libraries' => 'canvas/canvas.drupal.ajax,core/drupal',
        ], \JSON_THROW_ON_ERROR),
      ])),
      NULL,
    ];
    yield 'component instance form for a content template' => [
      new ServerRequest('PATCH', '/canvas/api/v0/form/component-instance/content_template/node.article.full/1', $form_encoded, \http_build_query($component_instance)),
      NULL,
    ];
    yield 'component instance form for a pattern' => [
      new ServerRequest('PATCH', '/canvas/api/v0/form/component-instance/pattern/hero', $form_encoded, \http_build_query($component_instance)),
      NULL,
    ];
    yield 'component instance form without a Content-Type' => [
      new ServerRequest('PATCH', '/canvas/api/v0/form/component-instance/node/1', [], \http_build_query($component_instance)),
      'Missing required header "Content-Type"',
    ];

    // What Drupal's AJAX system sends when such a form is updated.
    // @see Drupal.Ajax.prototype.beforeSerialize()
    $ajax_query = ['ajax_form' => '1', '_wrapper_format' => 'drupal_ajax'];
    $ajax_form_values = $component_instance + [
      'canvas_component_props' => [
        '5f18db31-fa2f-4f4e-a377-dc0c6a0b7dc4' => ['image' => ['alt' => 'A cat']],
      ],
      'form_id' => 'component_instance_form',
      'form_build_id' => 'form-3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b1c2d3e',
      'form_token' => '2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b1c2d',
      '_drupal_ajax' => '1',
      'ajax_page_state' => [
        'theme' => 'canvas_stark',
        'theme_token' => '1a2b3c4d5e6f7a8b9c0d1e2f',
        'libraries' => 'canvas/canvas.drupal.ajax,core/drupal',
      ],
    ];
    yield 'component instance form AJAX submission' => [
      (new ServerRequest('POST', '/canvas/api/v0/form/component-instance/node/1?ajax_form=1&_wrapper_format=drupal_ajax', $form_encoded, \http_build_query($ajax_form_values)))
        ->withQueryParams($ajax_query),
      NULL,
    ];
    yield 'content entity form AJAX submission' => [
      (new ServerRequest('POST', '/canvas/api/v0/form/content-entity/node/1?ajax_form=1&_wrapper_format=drupal_ajax', $form_encoded, \http_build_query($ajax_form_values)))
        ->withQueryParams($ajax_query),
      NULL,
    ];
    yield 'content entity form AJAX submission for an explicit form mode' => [
      (new ServerRequest('POST', '/canvas/api/v0/form/content-entity/node/1/full?ajax_form=1&_wrapper_format=drupal_ajax', $form_encoded, \http_build_query($ajax_form_values)))
        ->withQueryParams($ajax_query),
      NULL,
    ];
    // Forms containing a file input are submitted as multipart data instead.
    // @see core/misc/jquery.form.js
    $boundary = 'CanvasFormBoundary';
    $multipart = '';
    foreach (['form_id' => 'node_article_form', 'form_build_id' => 'form-3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b1c2d3e', '_drupal_ajax' => '1'] as $name => $value) {
      $multipart .= \sprintf("--%s\r\nContent-Disposition: form-data; name=\"%s\"\r\n\r\n%s\r\n", $boundary, $name, $value);
    }
    $multipart .= \sprintf("--%s\r\nContent-Disposition: form-data; name=\"files[field_image_0]\"; filename=\"cat.png\"\r\nContent-Type: image/png\r\n\r\n\x89PNG\r\n--%s--\r\n", $boundary, $boundary);
    yield 'content entity form AJAX submission containing a file' => [
      (new ServerRequest('POST', '/canvas/api/v0/form/content-entity/node/1/full?ajax_form=1&_wrapper_format=drupal_ajax', ['Content-Type' => 'multipart/form-data; boundary=' . $boundary], $multipart))
        ->withQueryParams($ajax_query),
      NULL,
    ];
    yield 'AJAX submission without Drupal Form API bookkeeping' => [
      (new ServerRequest('POST', '/canvas/api/v0/form/component-instance/node/1?ajax_form=1&_wrapper_format=drupal_ajax', $form_encoded, \http_build_query(['form_id' => 'component_instance_form'])))
        ->withQueryParams($ajax_query),
      "Required property 'form_build_id' must be present in the object",
    ];

    // Operations that receive no request body at all.
    // @see \Drupal\canvas\Controller\ApiPushController
    yield 'CLI push start signal' => [
      new ServerRequest('POST', '/canvas/api/v0/push/start'),
      NULL,
    ];
    yield 'CLI push completion signal' => [
      new ServerRequest('POST', '/canvas/api/v0/push/complete'),
      NULL,
    ];
    // Clients that still synchronize Page Regions send a body, which is
    // ignored: these operations always respond 410 Gone.
    // @see \Drupal\canvas\Controller\ApiConfigControllers::pageRegionGone()
    yield 'Page Region creation by an outdated client' => [
      new ServerRequest('POST', '/canvas/api/v0/config/page_region', ['Content-Type' => 'application/json'], '{"id":"stark.highlighted"}'),
      NULL,
    ];
    yield 'Page Region update by an outdated client' => [
      new ServerRequest('PATCH', '/canvas/api/v0/config/page_region/stark.highlighted', ['Content-Type' => 'application/json'], '{"id":"stark.highlighted"}'),
      NULL,
    ];
  }

  public function testForbidPatternProperties(): void {
    $file = file_get_contents(__DIR__ . '/../../../openapi.yml');
    \assert(!empty($file));
    $encoded = json_encode(Yaml::decode($file));
    \assert(\is_string($encoded));
    // Check the encoded string to allow 'patternProperties' in comments.
    $this->assertFalse(str_contains($encoded, 'patternProperties'), '`patternProperties` in the the openapi.yml file is not supported use `additionalProperties` instead.');
  }

}
