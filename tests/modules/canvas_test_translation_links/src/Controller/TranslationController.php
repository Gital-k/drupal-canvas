<?php

declare(strict_types=1);

namespace Drupal\canvas_test_translation_links\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Provides a no-op endpoint for translation links used by UI tests.
 */
final class TranslationController {

  /**
   * Returns a response that confirms the requested translation link.
   */
  public function noop(
    string $entity_type,
    string $entity_id,
    string $target_language,
  ): JsonResponse {
    return new JsonResponse([
      'entityType' => $entity_type,
      'entityId' => $entity_id,
      'targetLanguage' => $target_language,
      'status' => 'ok',
    ]);
  }

  /**
   * Enables translation-link decoration for test requests.
   */
  public function enable(): JsonResponse {
    \Drupal::state()->set('canvas_test_translation_links.enabled', TRUE);
    return new JsonResponse(['status' => 'enabled']);
  }

  /**
   * Disables translation-link decoration for test requests.
   */
  public function disable(): JsonResponse {
    \Drupal::state()->delete('canvas_test_translation_links.enabled');
    return new JsonResponse(['status' => 'disabled']);
  }

}
