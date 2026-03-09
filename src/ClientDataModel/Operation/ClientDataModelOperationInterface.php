<?php

declare(strict_types=1);

namespace Drupal\canvas\ClientDataModel\Operation;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\canvas\AutoSave\AutoSaveValidationManager;
use Drupal\canvas\ClientDataModel\ClientDataModel;

interface ClientDataModelOperationInterface {

  public function setRequestBody(array $requestBody): static;

  public function setAutoSaveValidationManager(AutoSaveValidationManager $autoSaveValidationManager): static;

  public function __invoke(ClientDataModel $clientModel, FieldableEntityInterface $entity): ClientDataModel;

  public function hasValidatedAutoSaves(): bool;

}
