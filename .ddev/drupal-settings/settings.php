<?php
// phpcs:ignoreFile

/**
 * @file
 * Canvas DDEV settings.php.
 *
 * Copied into the scaffold volume's docroot by .ddev/commands/host/setup.
 * DDEV's own settings management is disabled (disable_settings_management:
 * true) because with no_project_mount the real docroot lives in the scaffold
 * volume, not on the host, so DDEV would write settings to the wrong path.
 */

$settings['config_sync_directory'] = 'sites/default/files/sync';
$settings['hash_salt'] = 'canvas-ddev-local';

if (file_exists(__DIR__ . '/settings.ddev.php')) {
  include __DIR__ . '/settings.ddev.php';
}
