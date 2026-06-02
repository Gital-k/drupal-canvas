<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\Core\Entity\ContentEntityInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP API for interacting with Canvas content entity translations.
 *
 * @internal This HTTP API is intended only for the Canvas UI. These controllers
 *   and associated routes may change at any time.
 */
final class ApiContentTranslationControllers extends ApiControllerBase {

  /**
   * Deletes a single translation of a canvas_page entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $canvas_page
   *   The entity whose translation should be deleted.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   204 No Content on success, 400 if attempting to delete the default
   *   translation.
   */
  public static function delete(
    ContentEntityInterface $canvas_page,
  ): JsonResponse {

    // Guard: cannot delete the default (original/untranslated) language via
    // this endpoint. Callers should use the full entity delete route instead.
    if ($canvas_page->isDefaultTranslation()) {
      return new JsonResponse(
        ['message' => \sprintf('Cannot delete the default translation for canvas_page %s. Use the entity delete endpoint to remove the whole entity.', $canvas_page->id())],
        Response::HTTP_BAD_REQUEST,
      );
    }
    $untranslated_page = $canvas_page->getUntranslated();
    $untranslated_page->removeTranslation($canvas_page->language()->getId());
    $untranslated_page->save();

    return new JsonResponse(status: Response::HTTP_NO_CONTENT);
  }

}
