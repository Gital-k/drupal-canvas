<?php

declare(strict_types=1);

namespace Drupal\canvas\Config;

use Drupal\Core\Config\ImmutableConfig;

/**
 * Controls how code components flow through Drupal config sync.
 *
 * @see \Drupal\canvas\EventSubscriber\CodeComponentSyncTransformer
 * @see \Drupal\canvas\Form\CanvasSettingsForm
 */
enum CodeComponentSyncMode: string {

  public const string CONFIG_KEY = 'code_components.sync_mode';

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

  /**
   * Reads the sync mode from `canvas.settings`, falling back to the default.
   */
  public static function fromSettings(ImmutableConfig $settings): self {
    $raw = $settings->get(self::CONFIG_KEY);
    return is_string($raw) ? (self::tryFrom($raw) ?? self::PreserveProduction) : self::PreserveProduction;
  }

}
