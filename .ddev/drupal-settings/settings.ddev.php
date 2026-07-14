<?php
// phpcs:ignoreFile

/**
 * @file
 * Canvas DDEV environment-specific settings.
 *
 * Copied into the docroot by .ddev/commands/host/setup and included from
 * settings.php.
 */

// Allow test modules and themes to be installed.
$settings['extension_discovery_scan_tests'] = TRUE;

// Force SQLite as the database backend.
$databases['default']['default'] = [
  'driver' => 'sqlite',
  'database' => 'sites/default/files/.ht.sqlite',
];
