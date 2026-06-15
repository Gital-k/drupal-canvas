<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\CanvasUriDefinitions;
use Drupal\canvas\Entity\Page;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DefaultContent\Exporter;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Serialization\Yaml;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP API for fetching text-based representation of a entity.
 *
 * @internal This HTTP API is intended only for the Canvas UI. These controllers
 *   and associated routes may change at any time.
 *
 * @todo https://www.drupal.org/i/3498525 should generalize this to all eligible content entity types
 */
final class ApiContentExportController extends ApiControllerBase {

  public function __construct(
    private readonly AutoSaveManager $autoSaveManager,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Returns a single Canvas page as yaml using default content exporter.
   */
  public function get(Request $request, Page $canvas_page): CacheableJsonResponse {
    $cacheability = new CacheableMetadata();
    $cacheability->addCacheContexts(['url.query_args:autoSaved']);
    $status = Response::HTTP_OK;
    // Determine if we are working with auto-save or published version of the
    // entity.
    $auto_saved = $request->query->getBoolean('autoSaved', default: TRUE);
    if ($auto_saved) {
      $cacheability->addCacheTags([AutoSaveManager::CACHE_TAG]);
      $auto_save_data = $this->autoSaveManager->getAutoSaveEntity($canvas_page);
      if (!$auto_save_data->isEmpty()) {
        $canvas_page = $auto_save_data->entity;
        \assert($canvas_page instanceof Page);
        $body['data']['updated'] = \time();
      }
      else {
        $status = Response::HTTP_NOT_FOUND;
        $body['errors'][] = [
          'detail' => \sprintf('No auto-save items for entity %s found', $canvas_page->id()),
          'source' => [
            'pointer' => AutoSaveManager::getAutoSaveKey($canvas_page),
          ],
          'code' => 404,
        ];
      }
    }
    else {
      $body['data']['updated'] = (int) $canvas_page->getRevisionCreationTime();
    }

    $cacheability->addCacheableDependency($canvas_page);

    if ($status === Response::HTTP_OK) {
      $entity_export = \Drupal::service(Exporter::class)->export($canvas_page)->data;
      $components = $entity_export['components'];
      unset($entity_export['components']);
      \array_push($entity_export, $components);
      $body['data'] += [
        'language' => $canvas_page->language()->getId(),
        'entity' => Yaml::encode($entity_export),
      ];

      if ($this->moduleHandler->moduleExists('content_translation')) {
        $available_translations = \array_keys($canvas_page->getTranslationLanguages(FALSE));

        $links = [];

        foreach ($available_translations as $langcode) {
          $translation = $canvas_page->getTranslation($langcode);
          // The delete route gates on update access, so emit the link to the
          // same users to avoid offering a link that would return 403.
          // @see canvas.api.content.translation.delete in canvas.routing.yml
          if ($translation->access('update')) {
            $links[$langcode] = [
              CanvasUriDefinitions::LINK_REL_DELETE => Url::fromRoute(
                'canvas.api.content.translation.delete',
                ['canvas_page' => $canvas_page->id()],
                ['language' => $translation->language()],
              )->toString(),
            ];
          }
          // The client should also list the default language.
          $default_langcode = $canvas_page->getUntranslated()->language()->getId();
          array_unshift($available_translations, $default_langcode);
          $body['data']['translations'] = [
            'available' => $available_translations,
            'links' => $links,
          ];
        }
      }
    }

    $json_response = new CacheableJsonResponse($body, status: $status);
    $json_response->addCacheableDependency($cacheability);

    return $json_response;
  }

}
