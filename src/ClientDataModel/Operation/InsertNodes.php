<?php

declare(strict_types=1);

namespace Drupal\canvas\ClientDataModel\Operation;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\canvas\ClientDataModel\ClientDataModel;
use Drupal\canvas\Entity\PageRegion;
use Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class InsertNodes extends ClientDataModelOperationBase {

  public function __invoke(ClientDataModel $clientModel, FieldableEntityInterface $entity): ClientDataModel {
    if (!\array_key_exists('parentRegionOrUuid', $this->requestBody)) {
      throw new BadRequestHttpException('Missing parent UUID or region');
    }
    if (!\array_key_exists('fractal', $this->requestBody)) {
      throw new BadRequestHttpException('Missing position (fractal)');
    }
    if (!\is_numeric($this->requestBody['fractal'])) {
      throw new BadRequestHttpException('Invalid position (fractal)');
    }
    if (!\array_key_exists('slot', $this->requestBody)) {
      throw new BadRequestHttpException('Missing parent slot');
    }
    if (!\array_key_exists('layoutModel', $this->requestBody)) {
      throw new BadRequestHttpException('Missing layout model');
    }
    [
      'parentRegionOrUuid' => $parentRegionOrUuid,
      'slot' => $slot,
      'fractal' => $fractal,
      'layoutModel' => $layoutModel,
    ] = $this->requestBody;
    if (!\array_key_exists('model', $layoutModel)) {
      throw new BadRequestHttpException('Missing models');
    }
    if (!\array_key_exists('layout', $layoutModel)) {
      throw new BadRequestHttpException('Missing components');
    }
    if (!\array_is_list($layoutModel['layout'])) {
      throw new BadRequestHttpException('Invalid components');
    }
    $regions = PageRegion::loadForActiveTheme();
    $regionsById = \array_combine(
      \array_map(static fn (PageRegion $region): string => (string) $region->get('region'), $regions),
      $regions,
    );
    if ($parentRegionOrUuid !== NULL && $slot === NULL && $parentRegionOrUuid !== CanvasPageVariant::MAIN_CONTENT_REGION) {
      // We're dealing with a region here, i.e. being added to the root of a
      // region.
      if (!\array_key_exists($parentRegionOrUuid, $regionsById)) {
        throw new NotFoundHttpException('No such region: ' . $parentRegionOrUuid);
      }
      if (!$regionsById[$parentRegionOrUuid]->access('update')) {
        throw new AccessDeniedHttpException(sprintf('Access denied for region %s', $parentRegionOrUuid));
      }
    }

    if ($parentRegionOrUuid !== NULL && $slot !== NULL && !$clientModel->hasComponentInstance($parentRegionOrUuid)) {
      throw new NotFoundHttpException('No such parent component in model: ' . $parentRegionOrUuid);
    }
    $clientInstanceId = $this->validateAutoSaves($entity);
    if ($slot !== NULL) {
      // This is a parent component UUID.
      self::checkRegionAccess($clientModel, $parentRegionOrUuid);
    }
    try {
      return $clientModel
        ->withClientInstanceId($clientInstanceId)
        ->withInsertedComponents($parentRegionOrUuid, $slot, (float) $fractal, $layoutModel['layout'], $layoutModel['model']);
    }
    catch (\OutOfBoundsException $e) {
      throw new BadRequestHttpException($e->getMessage());
    }
  }

}
