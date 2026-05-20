<?php

declare(strict_types=1);

namespace Drupal\canvas\Config;

/**
 * Controls how code components flow through Drupal config sync.
 *
 * @see \Drupal\canvas\EventSubscriber\CodeComponentSyncTransformer
 * @see \Drupal\canvas\Form\CanvasSettingsForm
 */
enum CodeComponentSyncMode: string {

  /**
   * On import, code components present in active config but missing from the
   * sync directory are preserved instead of deleted. Components present in
   * both still update from sync. Export is unchanged.
   */
  case PreserveProduction = 'preserve_production';

  /**
   * Code components are excluded from both config import and config export.
   * The Canvas CLI is the sole source of truth.
   */
  case Cli = 'cli';

}
