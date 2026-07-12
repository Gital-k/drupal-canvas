<?php

declare(strict_types=1);

namespace Drupal\canvas\ClientDataModel;

use Drupal\canvas\ClientDataModel\Operation\DeleteNode;
use Drupal\canvas\ClientDataModel\Operation\DuplicateNode;
use Drupal\canvas\ClientDataModel\Operation\InsertNodes;
use Drupal\canvas\ClientDataModel\Operation\MoveNode;
use Drupal\canvas\ClientDataModel\Operation\OperationClass;
use Drupal\canvas\ClientDataModel\Operation\ShiftNode;
use Drupal\canvas\ClientDataModel\Operation\UpdateModel;

/**
 * Enumerates the client data model operations and their URL path slugs.
 *
 * Each case whose implementation exists is annotated with the corresponding
 * operation class via the #[OperationClass] attribute; a route is generated
 * for it. Cases without the attribute are planned but not yet implemented,
 * and get no route.
 *
 * @see \Drupal\canvas\EventSubscriber\ClientDataModelOperationRouteSubscriber
 */
enum SupportedClientDataModelOperations: string {

  #[OperationClass(MoveNode::class)]
  case MoveNode = 'move-node';
  #[OperationClass(InsertNodes::class)]
  case InsertNodes = 'insert-nodes';
  #[OperationClass(ShiftNode::class)]
  case ShiftNode = 'shift-node';
  #[OperationClass(DeleteNode::class)]
  case DeleteNode = 'delete-node';
  #[OperationClass(DuplicateNode::class)]
  case DuplicateNode = 'duplicate-node';
  case AddNode = 'add-node';
  case AddPattern = 'add-pattern';
  case SortNode = 'sort-node';
  #[OperationClass(UpdateModel::class)]
  case UpdateModel = 'update-model';

  /**
   * Gets the class implementing this operation, if any.
   *
   * @return class-string<\Drupal\canvas\ClientDataModel\Operation\ClientDataModelOperationInterface>|null
   *   The operation class, or NULL when the operation is not implemented yet.
   */
  public function getOperationClass(): ?string {
    $attributes = (new \ReflectionEnumUnitCase(self::class, $this->name))
      ->getAttributes(OperationClass::class);
    if ($attributes === []) {
      return NULL;
    }
    // @phpstan-ignore return.type
    return $attributes[0]->newInstance()->class;
  }

}
