<?php
// phpcs:ignoreFile

/**
 * @file
 * Canvas DDEV settings.php.
 *
 * Copied into the docroot by .ddev/commands/host/setup. DDEV's own settings
 * management is disabled (disable_settings_management: true) because the db
 * container is omitted and SQLite is forced, so DDEV would otherwise write a
 * mariadb connection pointing at the absent db container.
 */

$settings['config_sync_directory'] = 'sites/default/files/sync';
$settings['hash_salt'] = 'canvas-ddev-local';

if (file_exists(__DIR__ . '/settings.ddev.php')) {
  include __DIR__ . '/settings.ddev.php';
}
