<?php

declare(strict_types=1);

namespace Drupal\canvas\ClientDataModel\Operation;

use Drupal\canvas\ClientDataModel\ClientDataModel;
use Drupal\canvas\Entity\PageRegion;
use Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant;
use Drupal\Core\Entity\FieldableEntityInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class MoveNode extends ClientDataModelOperationBase {

  public function __invoke(ClientDataModel $clientModel, FieldableEntityInterface $entity): ClientDataModel {
    if (!\array_key_exists('uuid', $this->requestBody)) {
      throw new BadRequestHttpException('Missing uuid');
    }
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
    [
      'parentRegionOrUuid' => $parentRegionOrUuid,
      'slot' => $slot,
      'uuid' => $componentInstanceUuid,
      'fractal' => $fractal,
    ] = $this->requestBody;
    if (!$clientModel->hasComponentInstance($componentInstanceUuid)) {
      throw new NotFoundHttpException('No such component in model: ' . $componentInstanceUuid);
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
        throw new AccessDeniedHttpException(\sprintf('Access denied for region %s', $parentRegionOrUuid));
      }
    }

    if ($parentRegionOrUuid !== NULL && $slot !== NULL && !$clientModel->hasComponentInstance($parentRegionOrUuid)) {
      throw new NotFoundHttpException('No such parent component in model: ' . $parentRegionOrUuid);
    }
    $clientInstanceId = $this->validateAutoSaves($entity);
    self::checkRegionAccess($clientModel, $componentInstanceUuid);
    if ($slot !== NULL) {
      self::checkRegionAccess($clientModel, $parentRegionOrUuid);
    }
    try {
      return $clientModel
        ->withMovedComponent($componentInstanceUuid, $parentRegionOrUuid, $slot, (float) $fractal)
        ->withClientInstanceId($clientInstanceId);
    }
    catch (\OutOfBoundsException) {
      throw new NotFoundHttpException(\sprintf('No such slot %s in parent component %s', $slot, $parentRegionOrUuid));
    }
  }

}
