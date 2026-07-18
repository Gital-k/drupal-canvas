<?php

declare(strict_types=1);

namespace Drupal\canvas\ClientDataModel\Operation;

use Drupal\canvas\AutoSave\AutoSaveValidationManager;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Resolves ClientDataModelOperationInterface controller arguments.
 *
 * Instantiates the operation class named by the route's
 * ::OPERATION_CLASS default, primed with the decoded request body and the
 * auto-save validation manager.
 *
 * TRICKY: Drupal core hardcodes the controller argument resolver list, so
 * this resolver cannot be registered with a service tag; it is prepended to
 * that list by the service provider instead.
 *
 * @see \Drupal\canvas\EventSubscriber\ClientDataModelOperationRouteSubscriber::alterRoutes
 * @see \Drupal\canvas\CanvasServiceProvider::alter
 */
final class ArgumentResolver implements ValueResolverInterface {

  public const string OPERATION_CLASS = '__operation_class';

  public function __construct(
    private readonly ClassResolverInterface $classResolver,
    private readonly AutoSaveValidationManager $autoSaveValidationManager,
  ) {
  }

  public function resolve(Request $request, ArgumentMetadata $argument): iterable {
    // The controller has type-hinted a ClientDataModelOperationInterface
    // argument AND the route has a value for the __operation_class.
    if ($argument->getType() !== ClientDataModelOperationInterface::class || !$request->attributes->has(self::OPERATION_CLASS)) {
      return [];
    }
    $operation_class = $request->attributes->get(self::OPERATION_CLASS);
    if (!\is_string($operation_class) || !\is_a($operation_class, ClientDataModelOperationInterface::class, TRUE)) {
      return [];
    }
    $operation = $this->classResolver->getInstanceFromDefinition($operation_class);
    \assert($operation instanceof ClientDataModelOperationInterface);
    $body = \json_decode($request->getContent(), TRUE, flags: JSON_THROW_ON_ERROR);
    if (!\is_array($body)) {
      throw new BadRequestHttpException('The request body must be a JSON object.');
    }
    $operation->setRequestBody($body);
    $operation->setAutoSaveValidationManager($this->autoSaveValidationManager);
    return [$operation];
  }

}
