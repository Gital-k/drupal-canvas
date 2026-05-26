<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Language\LanguageInterface;
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
   * Mirrors what ContentTranslationDeleteForm::submitForm() does: removes the
   * requested translation from the entity and saves it. The original (default)
   * translation cannot be deleted via this endpoint — use the full entity
   * delete endpoint instead.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $canvas_page
   *   The entity whose translation should be deleted.
   * @param \Drupal\Core\Language\LanguageInterface $language
   *   The language of the translation to delete, resolved from the {language}
   *   route parameter via the 'language' param converter.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   204 No Content on success, 400 if attempting to delete the default
   *   translation, 404 if the requested translation does not exist.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   When saving the entity after removing the translation fails.
   */
  public static function delete(
    ContentEntityInterface $canvas_page,
    LanguageInterface $language,
  ): JsonResponse {
    $langcode = $language->getId();

    // Guard: cannot delete the default (original/untranslated) language via
    // this endpoint. Callers should use the full entity delete route instead.
    if ($canvas_page->getUntranslated()->language()->getId() === $langcode) {
      return new JsonResponse(
        ['message' => \sprintf('Cannot delete the original translation "%s" for canvas_page %s. Use the entity delete endpoint to remove the whole entity.', $langcode, $canvas_page->id())],
        Response::HTTP_BAD_REQUEST,
      );
    }

    // Guard: the requested translation must exist.
    if (!$canvas_page->hasTranslation($langcode)) {
      return new JsonResponse(
        ['message' => \sprintf('Translation "%s" not found for canvas_page %s.', $langcode, $canvas_page->id())],
        Response::HTTP_NOT_FOUND,
      );
    }

    $canvas_page->removeTranslation($langcode);
    $canvas_page->save();

    return new JsonResponse(status: Response::HTTP_NO_CONTENT);
  }

}
