<?php

declare(strict_types=1);

namespace Drupal\canvas\ClientDataModel\Operation;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\canvas\ClientDataModel\ClientDataModel;
use Drupal\canvas\Entity\Component;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class UpdateModel extends ClientDataModelOperationBase implements ContainerInjectionInterface {

  public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(EntityTypeManagerInterface::class),
    );
  }

  public function __invoke(ClientDataModel $clientModel, FieldableEntityInterface $entity): ClientDataModel {
    if (!\array_key_exists('componentInstanceUuid', $this->requestBody)) {
      throw new BadRequestHttpException('Missing componentInstanceUuid');
    }
    if (!\array_key_exists('componentType', $this->requestBody)) {
      throw new BadRequestHttpException('Missing componentType');
    }
    if (!\array_key_exists('model', $this->requestBody)) {
      throw new BadRequestHttpException('Missing model');
    }
    $clientInstanceId = $this->validateAutoSaves($entity);
    [
      'componentInstanceUuid' => $componentInstanceUuid,
      'componentType' => $componentTypeAndVersion,
      'model' => $model,
    ] = $this->requestBody;

    if (!$clientModel->hasComponentInstance($componentInstanceUuid)) {
      throw new NotFoundHttpException('No such component in model: ' . $componentInstanceUuid);
    }
    if (!str_contains($componentTypeAndVersion, '@')) {
      throw new NotFoundHttpException(\sprintf('Missing version for component %s', $componentTypeAndVersion));
    }
    [$componentType, $version] = \explode('@', $componentTypeAndVersion);
    $component = $this->entityTypeManager->getStorage(Component::ENTITY_TYPE_ID)->load($componentType);
    \assert($component instanceof Component || $component === NULL);
    if ($component === NULL) {
      throw new NotFoundHttpException('No such component: ' . $componentType);
    }
    try {
      $component->loadVersion($version);
    }
    catch (\OutOfRangeException) {
      throw new NotFoundHttpException(\sprintf('No such version %s for component %s', $version, $componentType));
    }
    static::checkRegionAccess($clientModel, $componentInstanceUuid);

    return $clientModel
      ->withModelForComponent($componentInstanceUuid, $model)
      ->withClientInstanceId($clientInstanceId);
  }

}
