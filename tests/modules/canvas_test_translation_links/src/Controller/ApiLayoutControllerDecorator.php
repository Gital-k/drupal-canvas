<?php

declare(strict_types=1);

namespace Drupal\canvas_test_translation_links\Controller;

use Drupal\canvas\CanvasUriDefinitions;
use Drupal\canvas\Controller\ApiLayoutController;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Render\PreviewEnvelope;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Decorates the Canvas layout controller to add test translation links.
 */
final readonly class ApiLayoutControllerDecorator {

  public function __construct(
    private ApiLayoutController $inner,
    private LanguageManagerInterface $languageManager,
    private StateInterface $state,
  ) {
  }

  /**
   * Returns JSON for the entity layout and fields that the user can edit.
   */
  public function get(
    (ContentEntityInterface&EntityPublishedInterface)|ContentTemplate $entity,
    ?ContentEntityInterface $preview_entity = NULL,
  ): PreviewEnvelope {
    $envelope = $this->inner->get($entity, $preview_entity);
    if (!$this->state->get('canvas_test_translation_links.enabled', FALSE)) {
      return $envelope;
    }

    if (!$entity instanceof ContentEntityInterface) {
      return $envelope;
    }

    $additional_data = $envelope->additionalData;
    $default_langcode = $entity->getUntranslated()->language()->getId();

    foreach ($this->languageManager->getLanguages(LanguageInterface::STATE_CONFIGURABLE) as $language) {
      $langcode = $language->getId();
      if ($langcode === $default_langcode) {
        continue;
      }

      $translate_url = Url::fromRoute(
        'canvas_test_translation_links.noop_translation',
        [
          'entity_type' => $entity->getEntityTypeId(),
          'entity_id' => (string) $entity->id(),
          'target_language' => $langcode,
        ],
      )->toString();

      $additional_data['translations']['links'][$langcode][CanvasUriDefinitions::LINK_REL_CREATE] = $translate_url;
      $additional_data['translations']['links'][$langcode][CanvasUriDefinitions::LINK_REL_EDIT] = $translate_url;
    }

    return new PreviewEnvelope(
      $envelope->previewRenderArray,
      $additional_data,
      $envelope->statusCode,
    );
  }

  /**
   * Pass-through to the inner layout controller.
   */
  public function patch(
    Request $request,
    FieldableEntityInterface|ContentTemplate $entity,
    ?ContentEntityInterface $preview_entity = NULL,
  ): PreviewEnvelope {
    return $this->inner->patch($request, $entity, $preview_entity);
  }

  /**
   * Pass-through to the inner layout controller.
   */
  public function post(
    Request $request,
    FieldableEntityInterface|ContentTemplate $entity,
    ?ContentEntityInterface $preview_entity = NULL,
  ): PreviewEnvelope {
    return $this->inner->post($request, $entity, $preview_entity);
  }

  /**
   * Pass-through to the inner layout controller.
   */
  public function getLabel(
    (ContentEntityInterface&EntityPublishedInterface)|ContentTemplate $entity,
    ?ContentEntityInterface $preview_entity = NULL,
  ): string {
    return $this->inner->getLabel($entity, $preview_entity);
  }

  /**
   * Pass-through to the inner layout controller.
   */
  public function draftContentTemplate(
    Request $request,
    string $entity_type,
    ContentEntityInterface $preview_entity,
  ): JsonResponse {
    return $this->inner->draftContentTemplate(
      $request,
      $entity_type,
      $preview_entity,
    );
  }

}
