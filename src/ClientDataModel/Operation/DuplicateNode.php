<?php

declare(strict_types=1);

namespace Drupal\canvas\ClientDataModel\Operation;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\canvas\ClientDataModel\ClientDataModel;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DuplicateNode extends ClientDataModelOperationBase {

  public function __invoke(ClientDataModel $clientModel, FieldableEntityInterface $entity): ClientDataModel {
    if (!\array_key_exists('uuid', $this->requestBody)) {
      throw new BadRequestHttpException('Missing UUID');
    }
    if (!\array_key_exists('uuidMap', $this->requestBody)) {
      throw new BadRequestHttpException('Missing UUID map');
    }
    if (!\is_array($this->requestBody['uuidMap'])) {
      throw new BadRequestHttpException('Invalid UUID map');
    }
    foreach ($this->requestBody['uuidMap'] as $uuid) {
      if (!\is_string($uuid)) {
        throw new BadRequestHttpException('Invalid UUID map');
      }
    }
    [
      'uuid' => $componentInstanceUuid,
      'uuidMap' => $uuidMap,
    ] = $this->requestBody;
    if (!$clientModel->hasComponentInstance($componentInstanceUuid)) {
      throw new NotFoundHttpException('No such component in model: ' . $componentInstanceUuid);
    }
    $clientInstanceId = $this->validateAutoSaves($entity);
    self::checkRegionAccess($clientModel, $componentInstanceUuid);
    try {
      return $clientModel
        ->withDuplicatedComponentTree($componentInstanceUuid, $uuidMap)
        ->withClientInstanceId($clientInstanceId);
    }
    catch (\InvalidArgumentException $e) {
      throw new BadRequestHttpException($e->getMessage());
    }
  }

}
