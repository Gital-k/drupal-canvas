<?php

declare(strict_types=1);

namespace Drupal\canvas\ClientDataModel\Operation;

/**
 * Defines a class for .
 */
#[\Attribute(\Attribute::TARGET_CLASS_CONSTANT)]
final class OperationClass {

  /**
   * @param class-string $class
   */
  public function __construct(public readonly string $class) {
  }

}
