<?php

declare(strict_types=1);

namespace Drupal\canvas\ClientDataModel\Operation;

use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

final class ArgumentResolver implements ValueResolverInterface {

  public const string OPERATION_CLASS = '__operation_class';

  public function __construct(
    private readonly ClassResolverInterface $classResolver,
  ) {
  }

  public function resolve(Request $request, ArgumentMetadata $argument): iterable {
    // The controller has type-hinted a ClientDataModelOperationInterface
    // argument AND the route has a value for the __operation_class.
    if ($argument->getType() === ClientDataModelOperationInterface::class && $request->attributes->has(self::OPERATION_CLASS)) {
      $operation_class = $request->attributes->get(self::OPERATION_CLASS);
      if (!\is_a($operation_class, ClientDataModelOperationInterface::class, TRUE)) {
        return [];
      }
      $operation = $this->classResolver->getInstanceFromDefinition($operation_class);
      \assert($operation instanceof ClientDataModelOperationInterface);
      $body = \json_decode($request->getContent(), TRUE, flags: JSON_THROW_ON_ERROR);
      $operation->setRequestBody($body);
      return [$operation];
    }
    return [];
  }

}
