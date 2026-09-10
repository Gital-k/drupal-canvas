<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional\AutoSave;

use Drupal\canvas\Entity\CanvasHttpApiEligibleConfigEntityInterface;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\Core\Url;
use Drupal\Tests\canvas\Functional\HttpApiTestBase;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;

/**
 * @todo Refactor this to stop using CanvasTestSetup in https://www.drupal.org/project/canvas/issues/3531679
 * @internal
 */
abstract class AutoSaveConflictConfigTestBase extends HttpApiTestBase {

  use AutoSaveConflictTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['dynamic_page_cache'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  protected string $updateKey;

  protected static function getPermissions(): array {
    return [
      'access administration pages',
      JavaScriptComponent::ADMIN_PERMISSION,
      'publish auto-saves',
    ];
  }

  protected function requestUpdateAutoSave(array $json): ResponseInterface {
    $json += ['clientInstanceId' => $this->randomString(100)];
    $options = $this->getRequestOptions();
    $options[RequestOptions::JSON] = $json;
    return $this->makeApiRequest('PATCH', $this->getAutoSaveUrl(), $options);
  }

  protected function getAutoSaveUrl(): Url {
    $entity_type_id = $this->entity->getEntityTypeId();
    $entity_id = $this->entity->id();
    return Url::fromUri("base:/canvas/api/v0/config/auto-save/$entity_type_id/$entity_id");
  }

  protected function modifyJsonToSendAsAutoSave(array &$json, string $text): void {
    $json['data'][$this->updateKey] = $text;
  }

  protected function assertCurrentAutoSaveText(string $text): void {
    $entity = $this->getAutoSaveManager()->getAutoSaveEntity($this->entity)->entity;
    self::assertInstanceOf(CanvasHttpApiEligibleConfigEntityInterface::class, $entity);
    self::assertSame($text, $entity->get($this->updateKey));
  }

}
