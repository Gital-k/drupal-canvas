<?php

declare(strict_types=1);

namespace Drupal\canvas\EntityHandlers;

use Drupal\canvas\Entity\StagedLanguageConfigOverride;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityHandlerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Controls access to staged language config overrides via their target.
 *
 * Anyone with access to the Canvas UI may `view` an override; changing one
 * requires permission to update the configuration being overridden.
 */
final class StagedLanguageConfigOverrideAccessControlHandler extends EntityAccessControlHandler implements EntityHandlerInterface {

  use StagedConfigEntityAccessControlTrait;

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    \assert($entity instanceof StagedLanguageConfigOverride);
    return match ($operation) {
      'view' => $this->canvasUiAccessCheck->access($account),
      default => $this->checkConfigEntityUpdateAccess($entity->getName(), $account),
    };
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return $this->checkConfigEntityUpdateAccess($context['config_name'], $account);
  }

}
