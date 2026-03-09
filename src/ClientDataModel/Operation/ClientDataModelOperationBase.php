<?php

declare(strict_types=1);

namespace Drupal\canvas\ClientDataModel\Operation;

use Drupal\Core\Entity\EntityInterface;
use Drupal\canvas\AutoSave\AutoSaveValidationManager;
use Drupal\canvas\ClientDataModel\ClientDataModel;
use Drupal\canvas\Entity\PageRegion;
use Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

abstract class ClientDataModelOperationBase implements ClientDataModelOperationInterface {

  protected array $requestBody = [];
  protected bool $autoSaveValidated = FALSE;
  private AutoSaveValidationManager $autoSaveValidationManager;

  public function setAutoSaveValidationManager(AutoSaveValidationManager $autoSaveValidationManager): static {
    $this->autoSaveValidationManager = $autoSaveValidationManager;
    return $this;
  }

  public function setRequestBody(array $requestBody): static {
    $this->requestBody = $requestBody;
    return $this;
  }

  public function validateAutoSaves(EntityInterface $entity): string {
    // @todo Currently ::validateAutoSaves() validates all page regions as well
    //   as `$entity`, determine if here we should only validate the entity
    //   containing the component in https://drupal.org/i/3532056 or implement
    //   concurrent editing in https://drupal.org/i/3492065.
    if (!\array_key_exists('autoSaves', $this->requestBody)) {
      throw new BadRequestHttpException('Missing autoSaves');
    }
    if (!\array_key_exists('clientInstanceId', $this->requestBody)) {
      throw new BadRequestHttpException('Missing clientInstanceId');
    }

    $this->autoSaveValidationManager->validateAutoSaves(
      \array_merge([$entity], PageRegion::getEditableRegions()),
      $this->requestBody['autoSaves'],
      $this->requestBody['clientInstanceId']
    );
    $this->autoSaveValidated = TRUE;
    return $this->requestBody['clientInstanceId'];
  }

  public function hasValidatedAutoSaves(): bool {
    return $this->autoSaveValidated;
  }

  public static function checkRegionAccess(ClientDataModel $clientModel, string $componentInstanceUuid): void {
    // Validate that we have access to the page region of this component.
    $page_regions = PageRegion::loadForActiveThemeByClientSideId();
    if (!empty($page_regions)) {
      $regionForComponentId = $clientModel->getRegionForComponentInstance($componentInstanceUuid);
      if ($regionForComponentId !== CanvasPageVariant::MAIN_CONTENT_REGION && NULL !== $regionForComponentId) {
        if (!$page_regions[$regionForComponentId]->access('edit')) {
          throw new AccessDeniedHttpException(sprintf('Access denied for region %s', $regionForComponentId));
        }
      }
    }
  }

}
