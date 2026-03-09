<?php

declare(strict_types=1);

namespace Drupal\canvas\ClientDataModel;

use Drupal\canvas\ClientDataModel\Operation\OperationClass;
use Drupal\canvas\ClientDataModel\Operation\UpdateModel;

enum SupportedClientDataModelOperations: string {

  case MoveNode = 'moveNode';
  case InsertNodes = 'insertNodes';
  case ShiftNode = 'shiftNode';
  #[OperationClass(UpdateModel::class)]
  case DeleteNode = 'deleteNode';
  case DuplicateNode = 'duplicateNode';
  case AddNode = 'addNode';
  case AddPattern = 'addPattern';
  case SortNode = 'sortNode';
  #[OperationClass(UpdateModel::class)]
  case UpdateModel = 'updateModel';

}
