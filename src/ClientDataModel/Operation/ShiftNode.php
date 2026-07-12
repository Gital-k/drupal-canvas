<?php

declare(strict_types=1);

namespace Drupal\canvas\ClientDataModel\Operation;

use Drupal\canvas\ClientDataModel\ClientDataModel;
use Drupal\canvas\ClientDataModel\ShiftComponentDirection;
use Drupal\Core\Entity\FieldableEntityInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ShiftNode extends ClientDataModelOperationBase {

  public function __invoke(ClientDataModel $clientModel, FieldableEntityInterface $entity): ClientDataModel {
    if (!\array_key_exists('uuid', $this->requestBody)) {
      throw new BadRequestHttpException('Missing uuid');
    }
    if (!\array_key_exists('direction', $this->requestBody)) {
      throw new BadRequestHttpException('Missing direction');
    }
    if (!\in_array($this->requestBody['direction'], \array_column(ShiftComponentDirection::cases(), 'value'), TRUE)) {
      throw new BadRequestHttpException('Invalid direction');
    }
    [
      'uuid' => $componentInstanceUuid,
      'direction' => $direction,
    ] = $this->requestBody;
    $direction = ShiftComponentDirection::tryFrom($direction);
    \assert($direction instanceof ShiftComponentDirection);
    if (!$clientModel->hasComponentInstance($componentInstanceUuid)) {
      throw new NotFoundHttpException('No such component in model: ' . $componentInstanceUuid);
    }
    $clientInstanceId = $this->validateAutoSaves($entity);
    self::checkRegionAccess($clientModel, $componentInstanceUuid);
    return $clientModel
      ->withShiftedComponent($componentInstanceUuid, $direction)
      ->withClientInstanceId($clientInstanceId);
  }

}
