<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Exception\ConstraintViolationException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides the target site's Code Component metadata contract.
 */
final class ApiCodeComponentMetadataController extends ApiControllerBase {

  public function __construct(
    private readonly ModuleExtensionList $moduleExtensionList,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns the generated authored metadata schema.
   */
  public function schema(Request $request): Response {
    $schema_path = $this->moduleExtensionList->getPath('canvas') . '/component-metadata.schema.json';
    $schema = file_get_contents($schema_path);
    if ($schema === FALSE) {
      throw new \RuntimeException('The Code Component metadata schema could not be read.');
    }

    $response = new Response($schema, Response::HTTP_OK, [
      'Content-Type' => 'application/schema+json',
    ]);
    $response->setPrivate();
    $response->setEtag(hash('sha256', $schema));
    $response->isNotModified($request);
    return $response;
  }

  /**
   * Validates a normalized payload without saving it.
   */
  public function validatePayload(Request $request): Response {
    $data = self::decode($request);
    $storage = $this->entityTypeManager->getStorage(JavaScriptComponent::ENTITY_TYPE_ID);
    $existing = isset($data['machineName']) && \is_string($data['machineName'])
      ? $storage->load($data['machineName'])
      : NULL;

    if ($existing instanceof JavaScriptComponent) {
      $candidate = clone $existing;
      $candidate->updateFromClientSide($data);
    }
    else {
      $candidate = JavaScriptComponent::createFromClientSide($data);
    }

    $violations = $candidate->getTypedData()->validate();
    if ($violations->count() > 0) {
      throw new ConstraintViolationException($violations);
    }

    return new Response(status: Response::HTTP_NO_CONTENT);
  }

}
