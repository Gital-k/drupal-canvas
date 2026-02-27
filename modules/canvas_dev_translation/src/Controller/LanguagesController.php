<?php

declare(strict_types=1);

namespace Drupal\canvas_dev_translation\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Language\LanguageManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Returns available content languages for the Canvas UI language selector.
 *
 * GET /canvas/api/v0/languages.
 *
 * Response:
 * {
 *   "en": { "label": "English", "isDefault": true },
 *   "fr": { "label": "French",  "isDefault": false }
 * }
 */
final class LanguagesController extends ControllerBase {

  public function __construct(
    LanguageManagerInterface $languageManager,
  ) {
    $this->languageManager = $languageManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('language_manager'));
  }

  /**
   * Returns available content languages for the Canvas UI language selector.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response keyed by langcode with label and default flag.
   */
  public function getLanguages(): JsonResponse {
    $defaultLangcode = $this->languageManager->getDefaultLanguage()->getId();
    $languages = $this->languageManager->getLanguages();

    $response = [];
    foreach ($languages as $langcode => $language) {
      $response[$langcode] = [
        'label' => $language->getName(),
        'isDefault' => $langcode === $defaultLangcode,
      ];
    }

    return new JsonResponse($response);
  }

}
