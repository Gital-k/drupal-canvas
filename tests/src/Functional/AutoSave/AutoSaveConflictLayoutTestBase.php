<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional\AutoSave;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Tests\canvas\Functional\HttpApiTestBase;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;

/**
 * Base class for auto-save conflict tests against the layout HTTP API.
 *
 * @todo Refactor this to stop using CanvasTestSetup in https://www.drupal.org/project/canvas/issues/3531679
 * @internal
 */
abstract class AutoSaveConflictLayoutTestBase extends HttpApiTestBase {

  use AutoSaveConflictTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['dynamic_page_cache', 'node'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The entity rendered as the preview, for entity types that need one.
   */
  protected ?ContentEntityInterface $previewEntity = NULL;

  /**
   * Omit information received in the GET response that cannot be POSTed.
   */
  protected function filterLayoutForPost(array $json): array {
    unset($json['isNew'], $json['isPublished'], $json['html'], $json['hasUnsavedStatusChange'], $json['translations']);
    $json += ['clientInstanceId' => $this->randomString(100)];
    return $json;
  }

  protected function requestUpdateAutoSave(array $json): ResponseInterface {
    $options = $this->getRequestOptions();
    $options[RequestOptions::JSON] = $this->filterLayoutForPost($json);
    return $this->makeApiRequest('POST', $this->getAutoSaveUrl(), $options);
  }

}
