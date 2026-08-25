<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Exception\ConstraintViolationException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validates Code Component metadata against the target site.
 */
final class ApiCodeComponentMetadataController extends ApiControllerBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

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
