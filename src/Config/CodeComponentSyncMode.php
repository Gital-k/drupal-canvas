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
   * On import, code components present in the active store but missing from
   * sync are copied into the sync buffer so the importer leaves them intact.
   * Components tracked in sync still update from sync. Export is unchanged.
   */
  case PreserveProduction = 'preserve_production';

  /**
   * On both import and export, every code component is removed from the sync
   * buffer. The Canvas CLI is the sole source of truth.
   */
  case Cli = 'cli';

}
