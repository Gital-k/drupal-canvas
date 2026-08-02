<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\BrandKit;
use Drupal\canvas\Entity\CanvasAssetInterface;
use Drupal\canvas\Entity\CanvasHttpApiEligibleConfigEntityInterface;
use Drupal\Core\Cache\CacheableJsonResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class ApiConfigAutoSaveControllers extends ApiControllerBase {

  use AutoSaveValidateTrait;

  public function __construct(
    private readonly AutoSaveManager $autoSaveManager,
  ) {}

  public function get(CanvasHttpApiEligibleConfigEntityInterface $canvas_config_entity): CacheableJsonResponse {
    $auto_save = $this->autoSaveManager->getAutoSaveEntity($canvas_config_entity);
    \assert($auto_save->entity === NULL || $auto_save->entity instanceof CanvasHttpApiEligibleConfigEntityInterface);

    $auto_save_normalization = $auto_save->entity?->normalizeForClientSide()->values;
    // When normalizing for auto-save, don't provide links to entity
    // operations. Those should only provided on this config entity's
    // canonical API route.
    if ($auto_save_normalization !== NULL) {
      unset($auto_save_normalization['links']);
    }

    return (new CacheableJsonResponse(
      data: [
        'data' => $auto_save_normalization,
        'autoSaves' => $this->getAutoSaveHashes([$canvas_config_entity]),
      ],
      status: Response::HTTP_OK,
    ))->addCacheableDependency($auto_save)
      // The `autoSaveStartingPoint` value in `autoSaves` is computed using the
      // config entity.
      ->addCacheableDependency($canvas_config_entity);

  }

  public function getCss(CanvasAssetInterface $canvas_config_entity): Response {
    $auto_save = $this->autoSaveManager->getAutoSaveEntity($canvas_config_entity);
    if (!$auto_save->isEmpty()) {
      \assert($auto_save->entity instanceof CanvasAssetInterface);
      $canvas_config_entity = $auto_save->entity;
    }
    $response = new Response($canvas_config_entity->getCss(), Response::HTTP_OK, [
      'Content-Type' => 'text/css; charset=utf-8',
    ]);
    $response->setPrivate();
    $response->headers->addCacheControlDirective('no-store');

    return $response;
  }

  public function getJs(CanvasAssetInterface $canvas_config_entity): Response {
    $auto_save = $this->autoSaveManager->getAutoSaveEntity($canvas_config_entity);
    if (!$auto_save->isEmpty()) {
      \assert($auto_save->entity instanceof CanvasAssetInterface);
      $canvas_config_entity = $auto_save->entity;
    }
    $js = $canvas_config_entity->getJs();
    if (trim((string) $js) === '') {
      // Compile-on-empty fallback: AI-created/edited code components and their
      // auto-save drafts can carry an empty compiledJs, which the canvas-island
      // renders as "[object Object]". Compile the JSX source on the fly with the
      // standalone esbuild binary (works under php-fpm, no Node on PATH needed).
      $field = $canvas_config_entity->get('js');
      $source = is_array($field) ? (string) ($field['original'] ?? '') : '';
      $js = self::compileSourceJsFallback($source);
    }
    $response = new Response($js, Response::HTTP_OK, [
      'Content-Type' => 'text/javascript; charset=utf-8',
    ]);
    $response->setPrivate();
    $response->headers->addCacheControlDirective('no-store');

    return $response;
  }

  /**
   * Compiles JSX to JS with the standalone esbuild binary (empty on failure).
   */
  protected static function compileSourceJsFallback(string $source): string {
    if (trim($source) === '') {
      return '';
    }
    $candidates = [];
    foreach (['/../node_modules', '/node_modules', '/core/node_modules'] as $base) {
      $candidates = array_merge($candidates, glob(\Drupal::root() . $base . '/@esbuild/*/bin/esbuild') ?: []);
      $candidates[] = \Drupal::root() . $base . '/.bin/esbuild';
    }
    $bin = '';
    foreach ($candidates as $candidate) {
      if (@is_executable($candidate)) {
        $bin = $candidate;
        break;
      }
    }
    if ($bin === '') {
      return '';
    }
    $process = @proc_open(escapeshellarg($bin) . ' --loader=jsx --jsx=automatic --format=esm', [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
      return '';
    }
    fwrite($pipes[0], $source);
    fclose($pipes[0]);
    $compiled = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    return ($code === 0 && is_string($compiled)) ? $compiled : '';
  }

  public function patch(Request $request, CanvasHttpApiEligibleConfigEntityInterface $canvas_config_entity): JsonResponse {
    $decoded = self::decode($request);
    if (!\array_key_exists('data', $decoded)) {
      throw new BadRequestHttpException('Missing data');
    }
    if (!\array_key_exists('autoSaves', $decoded)) {
      throw new BadRequestHttpException('Missing autoSaves');
    }
    if (!\array_key_exists('clientInstanceId', $decoded)) {
      throw new BadRequestHttpException('Missing clientInstanceId');
    }
    $this->validateAutoSaves([$canvas_config_entity], $decoded['autoSaves'], $decoded['clientInstanceId']);

    $existing_auto_save = $this->autoSaveManager->getAutoSaveEntity($canvas_config_entity);
    if (!$existing_auto_save->isEmpty()) {
      \assert($existing_auto_save->entity instanceof CanvasHttpApiEligibleConfigEntityInterface);
      $source_entity = $existing_auto_save->entity;
    }
    else {
      $source_entity = $canvas_config_entity;
    }

    $auto_save_entity = $canvas_config_entity::create($source_entity->toArray());
    $auto_save_entity->updateFromClientSide($decoded['data']);
    $this->autoSaveManager->saveEntity($auto_save_entity, $decoded['clientInstanceId']);
    \assert($auto_save_entity instanceof CanvasHttpApiEligibleConfigEntityInterface);
    BrandKit::syncAutoSaveFileUsage($source_entity, $auto_save_entity, (string) $canvas_config_entity->id());
    return new JsonResponse(data: ['autoSaves' => $this->getAutoSaveHashes([$canvas_config_entity])], status: Response::HTTP_OK);
  }

}
