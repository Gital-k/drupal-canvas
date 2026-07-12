<?php

declare(strict_types=1);

namespace Drupal\canvas\ClientDataModel\Operation;

use Drupal\canvas\ClientDataModel\ClientDataModel;
use Drupal\Core\Entity\FieldableEntityInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DeleteNode extends ClientDataModelOperationBase {

  public function __invoke(ClientDataModel $clientModel, FieldableEntityInterface $entity): ClientDataModel {
    if (!\array_key_exists('componentInstanceUuid', $this->requestBody)) {
      throw new BadRequestHttpException('Missing componentInstanceUuid');
    }
    $componentInstanceUuid = $this->requestBody['componentInstanceUuid'];
    if (!$clientModel->hasComponentInstance($componentInstanceUuid)) {
      throw new NotFoundHttpException('No such component in model: ' . $componentInstanceUuid);
    }
    $clientInstanceId = $this->validateAutoSaves($entity);
    self::checkRegionAccess($clientModel, $componentInstanceUuid);
    return $clientModel
      ->withoutComponent($componentInstanceUuid)
      ->withClientInstanceId($clientInstanceId);
  }

}
