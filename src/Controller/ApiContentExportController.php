<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Page;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DefaultContent\Exporter;
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

  public const string AUTO_SAVED_QUERY_KEY = 'autoSaved';

  public function __construct(
    private readonly AutoSaveManager $autoSaveManager,
    private readonly Exporter $exporter,
  ) {}

  /**
   * Returns a JSON response containing a Canvas page exported as YAML text.
   */
  public function get(Request $request, Page $canvas_page): CacheableJsonResponse {
    $cacheability = new CacheableMetadata();
    $cacheability->addCacheContexts(['url.query_args:autoSaved']);
    $status = Response::HTTP_OK;
    $body = [];
    // Determine if we are working with auto-save or published version of the
    // entity.
    $auto_saved = $request->query->getBoolean(self::AUTO_SAVED_QUERY_KEY, default: TRUE);
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
        ];
      }
    }
    else {
      $body['data']['updated'] = (int) $canvas_page->getRevisionCreationTime();
    }

    $cacheability->addCacheableDependency($canvas_page);

    if ($status === Response::HTTP_OK) {
      $entity_export = $this->exporter->export($canvas_page)->data;
      if (isset($entity_export['components'])) {
        // Move `components` to a numeric key at the end so the response matches
        // the expected text-based format for better UX.
        $components = $entity_export['components'];
        unset($entity_export['components']);
        $entity_export[] = $components;
      }

      \assert(isset($body['data']));
      $body['data'] += [
        'language' => $canvas_page->language()->getId(),
        'entity' => Yaml::encode($entity_export),
      ];
    }

    $json_response = new CacheableJsonResponse($body, status: $status);
    $json_response->addCacheableDependency($cacheability);

    return $json_response;
  }

}
